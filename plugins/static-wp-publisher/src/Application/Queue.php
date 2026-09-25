<?php
/**
 * Persistent generation queue.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use SWPP\Core\Domain\QueueJob;
use SWPP\Core\Domain\QueuePolicy;
use SWPP\Core\Domain\Origin;
use SWPP\Core\Infrastructure\Database;

final class Queue {
	private bool $staleJobsRecovered = false;

	public function __construct(
		private readonly Database $database,
		private readonly QueuePolicy $policy = new QueuePolicy(),
	) {}

	public function enqueue( string $url, string $reason = 'manual' ): bool {
		global $wpdb;
		$url   = esc_url_raw( $url, array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );
		if ( '' === $url || ! Origin::matches( $url, home_url( '/' ) ) || false === $parts || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		$table = $this->database->table( 'jobs' );
		$hash  = hash( 'sha256', $url );
		$now   = current_time( 'mysql', true );

		// active_hash is unique while a job is pending/running, making enqueue atomic.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (url_hash, active_hash, url, reason, status, attempts, available_at, created_at, updated_at) VALUES (%s, %s, %s, %s, 'pending', 0, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash,
				$hash,
				$url,
				sanitize_key( $reason ),
				$now,
				$now,
				$now
			)
		);

		return 1 === $inserted;
	}

	public function claim(): ?QueueJob {
		global $wpdb;
		if ( ! $this->staleJobsRecovered ) {
			$this->recoverStale();
			$this->staleJobsRecovered = true;
		}

		$table = $this->database->table( 'jobs' );
		$now   = current_time( 'mysql', true );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, reason, attempts FROM {$table} WHERE status = 'pending' AND available_at <= %s ORDER BY id ASC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);

		foreach ( $rows as $row ) {
			$token   = hash( 'sha256', wp_generate_uuid4() );
			$updated = $wpdb->update(
				$table,
				array(
					'status'     => 'running',
					'locked_at'  => $now,
					'locked_by'  => $token,
					'updated_at' => $now,
				),
				array(
					'id'     => (int) $row->id,
					'status' => 'pending',
				),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( 1 === $updated ) {
				return new QueueJob( (int) $row->id, (string) $row->url, (string) $row->reason, (int) $row->attempts, $token );
			}
		}

		return null;
	}

	public function complete( QueueJob $job ): bool {
		global $wpdb;
		return 1 === $wpdb->update(
			$this->database->table( 'jobs' ),
			array(
				'status'      => 'succeeded',
				'active_hash' => null,
				'locked_at'   => null,
				'locked_by'   => null,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id'        => $job->id,
				'status'    => 'running',
				'locked_by' => $job->lockToken,
			),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
	}

	public function fail( QueueJob $job, string $error ): bool {
		global $wpdb;
		$table    = $this->database->table( 'jobs' );
		$attempts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$table} WHERE id = %d AND status = 'running' AND locked_by = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$job->id,
				$job->lockToken
			)
		);
		if ( null === $attempts ) {
			return false;
		}

		$attempts = (int) $attempts + 1;
		$terminal = $this->policy->isTerminalAttempt( $attempts );
		return 1 === $wpdb->update(
			$table,
			array(
				'status'       => $terminal ? 'failed' : 'pending',
				'attempts'     => $attempts,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $this->policy->retryDelaySeconds( $attempts ) ),
				'active_hash'  => $terminal ? null : hash( 'sha256', $job->url ),
				'locked_at'    => null,
				'locked_by'    => null,
				'last_error'   => function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 4000 ) : substr( $error, 0, 4000 ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array(
				'id'        => $job->id,
				'status'    => 'running',
				'locked_by' => $job->lockToken,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
	}

	public function recoverStale(): int {
		global $wpdb;
		$table  = $this->database->table( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $this->policy->leaseSeconds );
		$now    = current_time( 'mysql', true );
		$max    = $this->policy->maxAttempts;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = CASE WHEN attempts + 1 >= %d THEN 'failed' ELSE 'pending' END, active_hash = CASE WHEN attempts + 1 >= %d THEN NULL ELSE url_hash END, attempts = attempts + 1, available_at = %s, locked_at = NULL, locked_by = NULL, last_error = 'Worker lease expired before completion.', updated_at = %s WHERE status = 'running' AND (locked_at IS NULL OR locked_at < %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$max,
				$max,
				$now,
				$now,
				$cutoff
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/** @return array<string,int> */
	public function counts(): array {
		global $wpdb;
		$table = $this->database->table( 'jobs' );
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array(
			'pending'   => 0,
			'running'   => 0,
			'failed'    => 0,
			'succeeded' => 0,
		);
		foreach ( $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->total;
		}
		return $out;
	}
}

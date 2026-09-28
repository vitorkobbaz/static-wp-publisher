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

	/** Closes a job whose URL is deterministically not publishable; it is not retried. */
	public function skip( QueueJob $job, string $reason ): bool {
		global $wpdb;
		return 1 === $wpdb->update(
			$this->database->table( 'jobs' ),
			array(
				'status'      => 'skipped',
				'active_hash' => null,
				'locked_at'   => null,
				'locked_by'   => null,
				'last_error'  => function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 4000 ) : substr( $reason, 0, 4000 ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array(
				'id'        => $job->id,
				'status'    => 'running',
				'locked_by' => $job->lockToken,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
	}

	/** Earliest retry time (UTC, MySQL format) of pending jobs that are not due yet. */
	public function nextRetryAt(): ?string {
		global $wpdb;
		$table = $this->database->table( 'jobs' );
		$next  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(available_at) FROM {$table} WHERE status = 'pending' AND available_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true )
			)
		);
		return is_string( $next ) ? $next : null;
	}

	/**
	 * Most recent jobs that need attention: retrying, failed, or skipped with a reason.
	 *
	 * @return list<array{url:string,status:string,attempts:int,available_at:string,last_error:string}>
	 */
	public function problems( int $limit = 20 ): array {
		global $wpdb;
		$table = $this->database->table( 'jobs' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// Only the latest job of each URL matters; older attempts are history.
				"SELECT job.url, job.status, job.attempts, job.available_at, job.last_error FROM {$table} job INNER JOIN (SELECT MAX(id) AS id FROM {$table} GROUP BY url_hash) latest ON latest.id = job.id WHERE job.last_error IS NOT NULL AND (job.status IN ('failed','skipped') OR (job.status = 'pending' AND job.attempts > 0)) ORDER BY job.updated_at DESC, job.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, min( 100, $limit ) )
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'url'          => (string) $row->url,
				'status'       => (string) $row->status,
				'attempts'     => (int) $row->attempts,
				'available_at' => (string) $row->available_at,
				'last_error'   => (string) $row->last_error,
			);
		}
		return $out;
	}

	/**
	 * Claims the active job of one URL immediately, ignoring its retry schedule. Used by
	 * explicit administrator actions on a single page.
	 */
	public function claimForUrl( string $url ): ?QueueJob {
		global $wpdb;
		$table = $this->database->table( 'jobs' );
		$now   = current_time( 'mysql', true );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, url, reason, attempts FROM {$table} WHERE url_hash = %s AND status = 'pending' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				hash( 'sha256', $url )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

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
				'id'     => (int) $row['id'],
				'status' => 'pending',
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return 1 === $updated ? new QueueJob( (int) $row['id'], (string) $row['url'], (string) $row['reason'], (int) $row['attempts'], $token ) : null;
	}

	/**
	 * Latest job for each of the given URL hashes.
	 *
	 * @param list<string> $hashes SHA-256 URL hashes.
	 * @return array<string,array{status:string,attempts:int,last_error:string,updated_at:string}>
	 */
	public function latestByHash( array $hashes ): array {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( $hashes, static fn( string $hash ): bool => 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ) ) ) );
		if ( array() === $hashes ) {
			return array();
		}
		$table        = $this->database->table( 'jobs' );
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT job.url_hash, job.status, job.attempts, job.last_error, job.updated_at FROM {$table} job INNER JOIN (SELECT MAX(id) AS id FROM {$table} WHERE url_hash IN ({$placeholders}) GROUP BY url_hash) latest ON latest.id = job.id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$hashes
			)
		);
		$out          = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->url_hash ] = array(
				'status'     => (string) $row->status,
				'attempts'   => (int) $row->attempts,
				'last_error' => (string) $row->last_error,
				'updated_at' => (string) $row->updated_at,
			);
		}
		return $out;
	}

	/**
	 * Counts URLs by the status of their latest job, plus waiting retries.
	 *
	 * @return array{queued:int,retrying:int,running:int,failed:int,skipped:int}
	 */
	public function latestCounts(): array {
		global $wpdb;
		$table = $this->database->table( 'jobs' );
		$rows  = $wpdb->get_results(
			"SELECT CASE WHEN job.status = 'pending' AND job.attempts > 0 THEN 'retrying' WHEN job.status = 'pending' THEN 'queued' ELSE job.status END AS state, COUNT(*) AS total FROM {$table} job INNER JOIN (SELECT MAX(id) AS id FROM {$table} GROUP BY url_hash) latest ON latest.id = job.id GROUP BY state" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$out   = array(
			'queued'   => 0,
			'retrying' => 0,
			'running'  => 0,
			'failed'   => 0,
			'skipped'  => 0,
		);
		foreach ( $rows as $row ) {
			$state = (string) $row->state;
			if ( isset( $out[ $state ] ) ) {
				$out[ $state ] = (int) $row->total;
			}
		}
		return $out;
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
			'skipped'   => 0,
			'succeeded' => 0,
		);
		foreach ( $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->total;
		}
		return $out;
	}
}

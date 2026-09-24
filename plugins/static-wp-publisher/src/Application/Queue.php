<?php
/**
 * Persistent generation queue.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Application;

use SWPP\Core\Domain\QueueJob;
use SWPP\Core\Infrastructure\Database;

final class Queue {
	public function __construct( private readonly Database $database ) {}

	public function enqueue( string $url, string $reason = 'manual' ): bool {
		global $wpdb;
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return false;
		}
		$table = $this->database->table( 'jobs' );
		$hash  = hash( 'sha256', $url );
		$open  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE url_hash = %s AND status IN ('pending','running') LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			)
		);
		if ( $open ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		return false !== $wpdb->insert(
			$table,
			array(
				'url_hash'     => $hash,
				'url'          => $url,
				'reason'       => sanitize_key( $reason ),
				'status'       => 'pending',
				'attempts'     => 0,
				'available_at' => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	public function claim(): ?QueueJob {
		global $wpdb;
		$table = $this->database->table( 'jobs' );
		$now   = current_time( 'mysql', true );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, url, reason, attempts FROM {$table} WHERE status = 'pending' AND available_at <= %s ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now
			)
		);
		if ( ! $row ) {
			return null;
		}

		$updated = $wpdb->update(
			$table,
			array( 'status' => 'running', 'locked_at' => $now, 'updated_at' => $now ),
			array( 'id' => (int) $row->id, 'status' => 'pending' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			return null;
		}

		return new QueueJob( (int) $row->id, (string) $row->url, (string) $row->reason, (int) $row->attempts );
	}

	public function complete( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->database->table( 'jobs' ),
			array( 'status' => 'succeeded', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function fail( int $id, string $error ): void {
		global $wpdb;
		$table    = $this->database->table( 'jobs' );
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		++$attempts;
		$status = $attempts >= 3 ? 'failed' : 'pending';
		$delay  = min( HOUR_IN_SECONDS, ( 2 ** $attempts ) * MINUTE_IN_SECONDS );
		$wpdb->update(
			$table,
			array(
				'status'       => $status,
				'attempts'     => $attempts,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_at'    => null,
				'last_error'   => mb_substr( $error, 0, 4000 ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', null, '%s', '%s' ),
			array( '%d' )
		);
	}

	/** @return array<string,int> */
	public function counts(): array {
		global $wpdb;
		$table = $this->database->table( 'jobs' );
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array( 'pending' => 0, 'running' => 0, 'failed' => 0, 'succeeded' => 0 );
		foreach ( $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->total;
		}
		return $out;
	}
}

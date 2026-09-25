<?php
/**
 * Database schema and names.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

final class Database {
	public const SCHEMA_VERSION = 2;

	public function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'swpp_' . $suffix;
	}

	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$jobs      = $this->table( 'jobs' );
		$builds    = $this->table( 'builds' );
		$artifacts = $this->table( 'artifacts' );

		dbDelta(
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url_hash char(64) NOT NULL,
				active_hash char(64) NULL,
				url text NOT NULL,
				reason varchar(191) NOT NULL DEFAULT 'manual',
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				available_at datetime NOT NULL,
				locked_at datetime NULL,
				locked_by char(64) NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_available (status, available_at),
				KEY url_hash (url_hash)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$builds} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(30) NOT NULL DEFAULT 'incremental',
				status varchar(20) NOT NULL DEFAULT 'running',
				started_at datetime NOT NULL,
				finished_at datetime NULL,
				published_count bigint(20) unsigned NOT NULL DEFAULT 0,
				failed_count bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$artifacts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				url_hash char(64) NOT NULL,
				url text NOT NULL,
				relative_path text NOT NULL,
				content_hash char(64) NOT NULL,
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				status_code smallint(5) unsigned NOT NULL DEFAULT 200,
				published_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY url_hash (url_hash)
			) {$charset};"
		);

		// Backfill the active-job uniqueness key when upgrading an existing install.
		$wpdb->query(
			"UPDATE {$jobs} current_job INNER JOIN {$jobs} first_job ON current_job.url_hash = first_job.url_hash AND current_job.id > first_job.id SET current_job.status = 'failed', current_job.active_hash = NULL, current_job.last_error = 'Duplicate active job removed during schema migration.' WHERE current_job.status IN ('pending','running') AND first_job.status IN ('pending','running')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$wpdb->query(
			"UPDATE {$jobs} SET active_hash = CASE WHEN status IN ('pending','running') THEN url_hash ELSE NULL END" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$active_index = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT INDEX_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'active_hash' LIMIT 1",
				$jobs
			)
		);
		if ( null === $active_index ) {
			$wpdb->query( "ALTER TABLE {$jobs} ADD UNIQUE KEY active_hash (active_hash)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		update_option( 'swpp_schema_version', self::SCHEMA_VERSION, false );
	}

	public function maybeUpgrade(): void {
		if ( (int) get_option( 'swpp_schema_version', 0 ) < self::SCHEMA_VERSION ) {
			$this->install();
		}
	}
}

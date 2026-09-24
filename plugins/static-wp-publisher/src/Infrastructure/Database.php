<?php
/**
 * Database schema and names.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

final class Database {
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
				url text NOT NULL,
				reason varchar(191) NOT NULL DEFAULT 'manual',
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				available_at datetime NOT NULL,
				locked_at datetime NULL,
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
	}
}

<?php
/**
 * Plugin Name: Static WP Publisher E2E Support
 * Description: Test-only WP-CLI controls for the Static WP Publisher E2E suite.
 * Version: 0.1.0
 * Requires PHP: 8.3
 *
 * @package StaticWPPublisherE2E
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Records which PHP SAPI wrote the schema version, proving whether an upgrade ran in a
 * real web request or in WP-CLI. Hooks are registered before plugins_loaded boots Core.
 */
$swpp_e2e_record_migration = static function (): void {
	update_option( 'swpp_e2e_migrated_by', PHP_SAPI, false );
};
add_action( 'add_option_swpp_schema_version', $swpp_e2e_record_migration );
add_action( 'update_option_swpp_schema_version', $swpp_e2e_record_migration );

/*
 * Page builders register their templates as public post types. Mirror Elementor's
 * library with a pretty permalink so exclusion is tested by type, not by query string.
 */
add_action(
	'init',
	static function (): void {
		register_post_type(
			'elementor_library',
			array(
				'public'  => true,
				'label'   => 'My Templates',
				'rewrite' => array( 'slug' => 'swpp-e2e-template' ),
			)
		);
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	final class SWPP_E2E_Command {
		private const CORE_PLUGIN   = 'static-wp-publisher/static-wp-publisher.php';
		private const EXPORT_PLUGIN = 'static-wp-publisher-export/static-wp-publisher-export.php';

		private const UPGRADE_PAGE_PREFIX = 'swpp-e2e-upgrade-';

		private const BATCH_PAGE_PREFIX = 'swpp-e2e-batch-';

		/** Resets Core and performs a clean activation. */
		public function reset(): void {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$this->deleteUpgradePages();
			$this->deleteBatchPages();
			deactivate_plugins( array( self::EXPORT_PLUGIN, self::CORE_PLUGIN ), false );
			$this->dropTables();
			foreach ( array( 'swpp_settings', 'swpp_redirects', 'swpp_schema_version', 'swpp_full_rebuild_recommended', 'swpp_inventory_scan', 'swpp_e2e_migrated_by' ) as $option ) {
				delete_option( $option );
			}

			$this->removeArtifacts();
			update_option( 'permalink_structure', '/index.php/%postname%/' );
			flush_rewrite_rules();

			$result = activate_plugin( self::CORE_PLUGIN, '', false, false );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			$result = activate_plugin( self::EXPORT_PLUGIN, '', false, false );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			$this->json( array( 'reset' => true ) );
		}

		/** Reports activation and schema state. */
		public function state(): void {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$tables = array();
			foreach ( array( 'jobs', 'builds', 'artifacts' ) as $suffix ) {
				$table             = $wpdb->prefix . 'swpp_' . $suffix;
				$tables[ $suffix ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			}

			$jobs         = $wpdb->prefix . 'swpp_jobs';
			$active_index = $wpdb->get_var( "SHOW INDEX FROM `{$jobs}` WHERE Key_name = 'active_hash'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$uploads      = wp_upload_dir();
			$root         = trailingslashit( (string) $uploads['basedir'] ) . 'static-wp-publisher/site-' . get_current_blog_id();
			$settings     = get_option( 'swpp_settings', array() );

			$this->json(
				array(
					'core_active'   => is_plugin_active( self::CORE_PLUGIN ),
					'export_active' => is_plugin_active( self::EXPORT_PLUGIN ),
					'schema'        => (int) get_option( 'swpp_schema_version', 0 ),
					'tables'        => $tables,
					'active_index'  => null !== $active_index,
					'cron'          => false !== wp_next_scheduled( 'swpp_process_queue' ),
					'enabled'       => ! empty( $settings['enabled'] ),
					'directories'   => array(
						'published' => is_dir( $root . '/published' ),
						'versions'  => is_dir( $root . '/versions' ),
						'tmp'       => is_dir( $root . '/tmp' ),
						'manifests' => is_dir( $root . '/manifests' ),
					),
				)
			);
		}

		/** Creates a published page containing a deterministic marker. */
		public function create( array $args ): void {
			$marker   = sanitize_key( (string) ( $args[0] ?? 'v1' ) );
			$existing = get_page_by_path( 'swpp-e2e-page', OBJECT, 'page' );
			if ( $existing instanceof \WP_Post ) {
				wp_delete_post( $existing->ID, true );
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Static WP Publisher E2E',
					'post_name'    => 'swpp-e2e-page',
					'post_content' => sprintf( '<p data-swpp-e2e="%1$s">SWPP E2E %1$s</p>', esc_attr( $marker ) ),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::error( $post_id->get_error_message() );
			}

			$this->json( array( 'id' => (int) $post_id, 'url' => get_permalink( (int) $post_id ), 'marker' => $marker ) );
		}

		/** Updates the E2E page and triggers normal invalidation hooks. */
		public function update( array $args ): void {
			$post_id = (int) ( $args[0] ?? 0 );
			$marker  = sanitize_key( (string) ( $args[1] ?? 'v2' ) );
			$result  = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => sprintf( '<p data-swpp-e2e="%1$s">SWPP E2E %1$s</p>', esc_attr( $marker ) ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			$this->json( array( 'id' => $post_id, 'marker' => $marker ) );
		}

		/**
		 * Creates N published pages with deterministic slugs and markers.
		 *
		 * @subcommand create-batch
		 */
		public function create_batch( array $args ): void {
			$count = max( 1, min( 20, (int) ( $args[0] ?? 8 ) ) );
			$this->deleteBatchPages();
			$pages = array();
			for ( $i = 1; $i <= $count; ++$i ) {
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => 'SWPP batch ' . $i,
						'post_name'    => self::BATCH_PAGE_PREFIX . $i,
						'post_content' => sprintf( '<p data-swpp-e2e="batch-%1$d">SWPP batch %1$d</p>', $i ),
					),
					true
				);
				if ( is_wp_error( $post_id ) ) {
					\WP_CLI::error( $post_id->get_error_message() );
				}
				$pages[] = array(
					'id'  => (int) $post_id,
					'url' => (string) get_permalink( (int) $post_id ),
				);
			}
			$this->json( array( 'pages' => $pages ) );
		}

		/**
		 * Creates a published page-builder template (not a visitor-facing page).
		 *
		 * @subcommand create-template
		 */
		public function create_template(): void {
			$existing = get_page_by_path( 'swpp-e2e-header', OBJECT, 'elementor_library' );
			if ( $existing instanceof \WP_Post ) {
				wp_delete_post( $existing->ID, true );
			}
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'elementor_library',
					'post_status'  => 'publish',
					'post_title'   => 'SWPP E2E Header Template',
					'post_name'    => 'swpp-e2e-header',
					'post_content' => '<header>Template</header>',
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				\WP_CLI::error( $post_id->get_error_message() );
			}
			$this->json(
				array(
					'id'  => (int) $post_id,
					'url' => (string) get_permalink( (int) $post_id ),
				)
			);
		}

		/** Adds a password to a page through the normal update path. */
		public function protect( array $args ): void {
			$post_id = (int) ( $args[0] ?? 0 );
			$result  = wp_update_post(
				array(
					'ID'            => $post_id,
					'post_password' => (string) ( $args[1] ?? 'swpp-e2e-secret' ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			$this->json( array( 'protected' => $post_id ) );
		}

		/** Starts a full inventory scan without rendering anything. */
		public function inventory(): void {
			$queue = new \SWPP\Core\Application\Queue( new \SWPP\Core\Infrastructure\Database() );
			$this->json( array( 'queued' => ( new \SWPP\Core\Application\Inventory( $queue ) )->enqueueAll() ) );
		}

		/** Runs the compatibility worker once. */
		public function process(): void {
			do_action( 'swpp_process_queue' );
			$this->json( array( 'processed' => true ) );
		}

		/** Enables the PHP fallback server. */
		public function enable(): void {
			$settings            = get_option( 'swpp_settings', array() );
			$settings['enabled'] = true;
			update_option( 'swpp_settings', $settings, false );
			$this->json( array( 'enabled' => true ) );
		}

		/** Reports the persisted artifact for a URL. */
		public function artifact( array $args ): void {
			global $wpdb;
			$url   = esc_url_raw( (string) ( $args[0] ?? '' ) );
			$table = $wpdb->prefix . 'swpp_artifacts';
			$row   = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT relative_path, content_hash, bytes FROM {$table} WHERE url_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					hash( 'sha256', $url )
				),
				ARRAY_A
			);

			$uploads = wp_upload_dir();
			$path    = is_array( $row ) ? trailingslashit( (string) $uploads['basedir'] ) . 'static-wp-publisher/site-' . get_current_blog_id() . '/published/' . $row['relative_path'] : '';
			$this->json(
				array(
					'exists'  => '' !== $path && is_file( $path ),
					'content' => '' !== $path && is_file( $path ) ? (string) file_get_contents( $path ) : '',
					'row'     => $row,
				)
			);
		}

		/**
		 * Rebuilds the database as the original schema v1 release left it, with legacy data.
		 *
		 * The plugin files stay active and current, so the next WordPress boot performs the
		 * same in-place upgrade a site gets after replacing the plugin files.
		 *
		 * @subcommand seed-v1
		 */
		public function seed_v1(): void {
			global $wpdb;

			$pages = array();
			$this->deleteUpgradePages();
			foreach ( array( 'a', 'b', 'c' ) as $key ) {
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => 'SWPP upgrade ' . strtoupper( $key ),
						'post_name'    => self::UPGRADE_PAGE_PREFIX . $key,
						'post_content' => sprintf( '<p data-swpp-e2e="upgrade-%1$s">SWPP upgrade %1$s</p>', $key ),
					),
					true
				);
				if ( is_wp_error( $post_id ) ) {
					\WP_CLI::error( $post_id->get_error_message() );
				}
				$pages[ $key ] = (string) get_permalink( (int) $post_id );
			}

			// Schema v1 exactly as shipped in the first release: no active_hash, no locked_by,
			// no unique active index, and no swpp_schema_version option.
			$this->dropTables();
			$charset = $wpdb->get_charset_collate();
			$jobs    = $wpdb->prefix . 'swpp_jobs';
			$builds  = $wpdb->prefix . 'swpp_builds';
			$table   = $wpdb->prefix . 'swpp_artifacts';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
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
				) {$charset}"
			);
			$wpdb->query(
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
				) {$charset}"
			);
			$wpdb->query(
				"CREATE TABLE {$table} (
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
				) {$charset}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			delete_option( 'swpp_schema_version' );
			delete_option( 'swpp_e2e_migrated_by' );
			delete_option( 'swpp_full_rebuild_recommended' );

			// v1 checked-then-inserted, so duplicate active jobs for one URL could exist.
			$legacy = '2026-01-01 00:00:00';
			$seed   = array(
				'pending'           => array( $pages['a'], 'pending', 0, null, null ),
				'duplicate_pending' => array( $pages['a'], 'pending', 0, null, null ),
				'expired_running'   => array( $pages['b'], 'running', 0, '2020-01-01 00:00:00', null ),
				'succeeded'         => array( $pages['c'], 'succeeded', 1, null, null ),
				'failed'            => array( $pages['c'], 'failed', 5, null, 'Legacy v1 failure.' ),
			);
			$ids    = array();
			foreach ( $seed as $name => list( $url, $status, $attempts, $locked_at, $error ) ) {
				$wpdb->insert(
					$jobs,
					array(
						'url_hash'     => hash( 'sha256', $url ),
						'url'          => $url,
						'reason'       => 'legacy_v1',
						'status'       => $status,
						'attempts'     => $attempts,
						'available_at' => '2020-01-01 00:00:00',
						'locked_at'    => $locked_at,
						'last_error'   => $error,
						'created_at'   => $legacy,
						'updated_at'   => $legacy,
					)
				);
				$ids[ $name ] = (int) $wpdb->insert_id;
			}
			$wpdb->insert(
				$builds,
				array(
					'kind'            => 'full',
					'status'          => 'succeeded',
					'started_at'      => $legacy,
					'finished_at'     => $legacy,
					'published_count' => 12,
					'failed_count'    => 1,
				)
			);

			$file = ( new \SWPP\Core\Infrastructure\Storage() )->write(
				$pages['a'],
				'<!doctype html><html><head><meta charset="utf-8"><title>Legacy v1</title></head><body><p data-swpp-e2e="legacy">Published by schema v1</p></body></html>'
			);
			$wpdb->insert(
				$table,
				array(
					'url_hash'      => hash( 'sha256', $pages['a'] ),
					'url'           => $pages['a'],
					'relative_path' => $file['relative'],
					'content_hash'  => $file['hash'],
					'bytes'         => $file['bytes'],
					'status_code'   => 200,
					'published_at'  => $legacy,
				)
			);

			update_option(
				'swpp_settings',
				array(
					'enabled'             => true,
					'profile'             => 'conservative',
					'retain_versions'     => 7,
					'delete_on_uninstall' => false,
				),
				false
			);

			// Keep the recurring worker registered as v1 did, but out of the way of this
			// deterministic test; scheduled execution has its own coverage.
			wp_clear_scheduled_hook( 'swpp_process_queue' );
			wp_schedule_event( time() + DAY_IN_SECONDS, 'swpp_every_minute', 'swpp_process_queue' );

			$this->json(
				array(
					'pages' => $pages,
					'jobs'  => $ids,
					'state' => $this->migrationSnapshot(),
				)
			);
		}

		/**
		 * Reports the schema, migration origin and all persisted Core data.
		 *
		 * @subcommand migration-state
		 */
		public function migration_state(): void {
			$this->json( $this->migrationSnapshot() );
		}

		/** Runs the schema installer again to prove the migration is idempotent. */
		public function reinstall(): void {
			( new \SWPP\Core\Infrastructure\Database() )->install();
			$this->json( array( 'reinstalled' => true ) );
		}

		/** Enqueues a URL through the production queue. */
		public function enqueue( array $args ): void {
			$queue = new \SWPP\Core\Application\Queue( new \SWPP\Core\Infrastructure\Database() );
			$this->json( array( 'enqueued' => $queue->enqueue( (string) ( $args[0] ?? '' ), 'e2e' ) ) );
		}

		/** @return array<string,mixed> */
		private function migrationSnapshot(): array {
			global $wpdb;
			$jobs      = $wpdb->prefix . 'swpp_jobs';
			$builds    = $wpdb->prefix . 'swpp_builds';
			$artifacts = $wpdb->prefix . 'swpp_artifacts';
			$settings  = get_option( 'swpp_settings', array() );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$index = $wpdb->get_results( "SHOW INDEX FROM `{$jobs}` WHERE Key_name = 'active_hash'", ARRAY_A );
			$state = array(
				'schema_option' => get_option( 'swpp_schema_version', null ),
				'migrated_by'   => get_option( 'swpp_e2e_migrated_by', null ),
				'columns'       => $wpdb->get_col( "SHOW COLUMNS FROM `{$jobs}`" ),
				'active_index'  => array_map(
					static fn( array $row ): array => array(
						'column' => (string) $row['Column_name'],
						'unique' => '0' === (string) $row['Non_unique'],
					),
					$index
				),
				'jobs'          => $wpdb->get_results( "SELECT * FROM `{$jobs}` ORDER BY id ASC", ARRAY_A ),
				'builds'        => $wpdb->get_results( "SELECT * FROM `{$builds}` ORDER BY id ASC", ARRAY_A ),
				'artifacts'     => $wpdb->get_results( "SELECT url, relative_path, content_hash, bytes, status_code, published_at FROM `{$artifacts}` ORDER BY id ASC", ARRAY_A ),
				'settings'      => is_array( $settings ) ? $settings : array(),
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $state;
		}

		private function deleteUpgradePages(): void {
			foreach ( array( 'a', 'b', 'c' ) as $key ) {
				$existing = get_page_by_path( self::UPGRADE_PAGE_PREFIX . $key, OBJECT, 'page' );
				if ( $existing instanceof \WP_Post ) {
					wp_delete_post( $existing->ID, true );
				}
			}
		}

		private function deleteBatchPages(): void {
			global $wpdb;
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name LIKE %s",
					$wpdb->esc_like( self::BATCH_PAGE_PREFIX ) . '%'
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		}

		private function dropTables(): void {
			global $wpdb;
			foreach ( array( 'jobs', 'builds', 'artifacts' ) as $suffix ) {
				$table = $wpdb->prefix . 'swpp_' . $suffix;
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		/** @param array<string,mixed> $value */
		private function json( array $value ): void {
			\WP_CLI::line( 'SWPP_E2E_JSON:' . (string) wp_json_encode( $value ) );
		}

		private function removeArtifacts(): void {
			$uploads = wp_upload_dir();
			$base    = wp_normalize_path( (string) $uploads['basedir'] );
			$target  = $base . '/static-wp-publisher';
			if ( ! is_dir( $target ) || ! str_starts_with( wp_normalize_path( $target ), trailingslashit( $base ) ) ) {
				return;
			}
			$this->removeDirectory( $target );
		}

		private function removeDirectory( string $directory ): void {
			$entries = scandir( $directory );
			if ( false === $entries ) {
				return;
			}
			foreach ( array_diff( $entries, array( '.', '..' ) ) as $entry ) {
				$path = $directory . '/' . $entry;
				if ( is_link( $path ) || is_file( $path ) ) {
					unlink( $path );
				} elseif ( is_dir( $path ) ) {
					$this->removeDirectory( $path );
				}
			}
			rmdir( $directory );
		}
	}

	\WP_CLI::add_command( 'swpp-e2e', SWPP_E2E_Command::class );
}

if ( defined( 'SWPP_E2E_TOKEN' ) && is_string( SWPP_E2E_TOKEN ) && '' !== SWPP_E2E_TOKEN ) {
	add_action(
		'rest_api_init',
		static function (): void {
			register_rest_route(
				'swpp-e2e/v1',
				'/process',
				array(
					'methods'             => 'POST',
					'permission_callback' => static function ( \WP_REST_Request $request ): bool {
						$provided = (string) $request->get_header( 'X-SWPP-E2E' );
						return hash_equals( SWPP_E2E_TOKEN, $provided );
					},
					'callback'            => static function (): \WP_REST_Response {
						do_action( 'swpp_process_queue' );
						return new \WP_REST_Response( array( 'processed' => true ) );
					},
				)
			);
		}
	);
}

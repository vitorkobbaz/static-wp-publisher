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

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	final class SWPP_E2E_Command {
		private const CORE_PLUGIN = 'static-wp-publisher/static-wp-publisher.php';

		/** Resets Core and performs a clean activation. */
		public function reset(): void {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			deactivate_plugins( self::CORE_PLUGIN, true );
			foreach ( array( 'jobs', 'builds', 'artifacts' ) as $suffix ) {
				$table = $wpdb->prefix . 'swpp_' . $suffix;
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			foreach ( array( 'swpp_settings', 'swpp_redirects', 'swpp_schema_version', 'swpp_full_rebuild_recommended', 'swpp_inventory_scan' ) as $option ) {
				delete_option( $option );
			}

			$this->removeArtifacts();
			update_option( 'permalink_structure', '/%postname%/' );
			flush_rewrite_rules();

			$result = activate_plugin( self::CORE_PLUGIN, '', false, true );
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
					'core_active' => is_plugin_active( self::CORE_PLUGIN ),
					'schema'      => (int) get_option( 'swpp_schema_version', 0 ),
					'tables'      => $tables,
					'active_index' => null !== $active_index,
					'cron'         => false !== wp_next_scheduled( 'swpp_process_queue' ),
					'enabled'      => ! empty( $settings['enabled'] ),
					'directories'  => array(
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

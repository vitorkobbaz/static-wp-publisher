<?php
/**
 * Plugin Name:       Static WP Publisher Export
 * Plugin URI:        https://static-wp-publisher.vitorkobbaz.com
 * Description:       Creates portable directory and ZIP exports from Static WP Publisher artifacts.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Kobbaz Estudio
 * Author URI:        https://vitorkobbaz.com
 * License:           GPL-2.0-or-later
 * Text Domain:       static-wp-publisher-export
 *
 * @package StaticWPPublisherExport
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWPP_EXPORT_VERSION', '0.1.0' );
define( 'SWPP_EXPORT_FILE', __FILE__ );
define( 'SWPP_EXPORT_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SWPP\\Export\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = SWPP_EXPORT_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'SWPP_VERSION' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Static WP Publisher Export requires the Static WP Publisher Core plugin.', 'static-wp-publisher-export' ) . '</p></div>';
				}
			);
			return;
		}
		SWPP\Export\Plugin::instance()->boot();
	}
);

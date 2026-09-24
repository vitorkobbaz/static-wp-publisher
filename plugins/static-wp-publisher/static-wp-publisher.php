<?php
/**
 * Plugin Name:       Static WP Publisher
 * Plugin URI:        https://static-wp-publisher.vitorkobbaz.com
 * Description:       Generates and serves public WordPress pages as safe, automatically refreshed static HTML.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Kobbaz Estudio
 * Author URI:        https://vitorkobbaz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       static-wp-publisher
 * Domain Path:       /languages
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWPP_VERSION', '0.1.0' );
define( 'SWPP_FILE', __FILE__ );
define( 'SWPP_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SWPP\\Core\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = SWPP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( SWPP\Core\Infrastructure\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( SWPP\Core\Infrastructure\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		SWPP\Core\Plugin::instance()->boot();
	}
);

<?php
/**
 * Activation and schema lifecycle.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

namespace SWPP\Core\Infrastructure;

final class Activator {
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			deactivate_plugins( plugin_basename( SWPP_FILE ) );
			wp_die( esc_html__( 'Static WP Publisher requires PHP 8.3 or newer.', 'static-wp-publisher' ) );
		}

		( new Database() )->install();
		( new Storage() )->ensureStructure();

		$defaults = array(
			'enabled'             => false,
			'profile'             => 'conservative',
			'retain_versions'     => 3,
			'delete_on_uninstall' => false,
		);
		add_option( 'swpp_settings', $defaults, '', false );

		if ( ! wp_next_scheduled( 'swpp_process_queue' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'swpp_every_minute', 'swpp_process_queue' );
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'swpp_process_queue' );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'swpp_process_queue' );
			$timestamp = wp_next_scheduled( 'swpp_process_queue' );
		}
	}
}

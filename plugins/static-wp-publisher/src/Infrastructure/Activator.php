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
			add_filter( 'cron_schedules', array( CronSchedule::class, 'add' ) );
			$result = wp_schedule_event( time() + MINUTE_IN_SECONDS, 'swpp_every_minute', 'swpp_process_queue', array(), true );
			remove_filter( 'cron_schedules', array( CronSchedule::class, 'add' ) );

			if ( is_wp_error( $result ) ) {
				deactivate_plugins( plugin_basename( SWPP_FILE ) );
				wp_die( esc_html( $result->get_error_message() ) );
			}
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

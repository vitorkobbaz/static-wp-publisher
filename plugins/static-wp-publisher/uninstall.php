<?php
/**
 * Optional uninstall cleanup.
 *
 * @package StaticWPPublisher
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'swpp_settings', array() );
if ( empty( $settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'swpp_jobs', 'swpp_builds', 'swpp_artifacts' ) as $suffix ) {
	$table = $wpdb->prefix . $suffix;
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'swpp_settings' );
delete_option( 'swpp_redirects' );

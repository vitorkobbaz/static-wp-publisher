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

$swpp_settings = get_option( 'swpp_settings', array() );
if ( empty( $swpp_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'swpp_jobs', 'swpp_builds', 'swpp_artifacts' ) as $swpp_suffix ) {
	$swpp_table = $wpdb->prefix . $swpp_suffix;
	$wpdb->query( "DROP TABLE IF EXISTS `{$swpp_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'swpp_settings' );
delete_option( 'swpp_redirects' );
delete_option( 'swpp_schema_version' );
delete_option( 'swpp_full_rebuild_recommended' );
delete_option( 'swpp_inventory_scan' );

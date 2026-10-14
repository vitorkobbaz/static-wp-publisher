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
	$wpdb->query( "DROP TABLE IF EXISTS `{$swpp_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned custom tables. Table names come from $wpdb->prefix; values are prepared.
}

delete_option( 'swpp_settings' );
delete_option( 'swpp_redirects' );
delete_option( 'swpp_schema_version' );
delete_option( 'swpp_full_rebuild_recommended' );
delete_option( 'swpp_inventory_scan' );
delete_option( 'swpp_speed_check' );
delete_option( 'swpp_storage_key' );
delete_site_option( 'swpp_export_storage_key' );
delete_metadata( 'user', 0, 'swpp_pro_panel_dismissed', '', true );
delete_option( 'swpp_last_full_sweep' );
delete_option( 'swpp_jobs_pruned_at' );
delete_option( 'swpp_storage_layout' );

// Static copies, history and generated assets: nothing withdrawn may outlive the plugin.
$swpp_uploads = wp_upload_dir( null, false );
if ( empty( $swpp_uploads['error'] ) ) {
	$swpp_remove = static function ( string $directory ) use ( &$swpp_remove ): void {
		if ( ! is_dir( $directory ) || is_link( $directory ) ) {
			return;
		}
		$swpp_entries = scandir( $directory );
		foreach ( false === $swpp_entries ? array() : array_diff( $swpp_entries, array( '.', '..' ) ) as $swpp_entry ) {
			$swpp_path = $directory . '/' . $swpp_entry;
			if ( is_dir( $swpp_path ) && ! is_link( $swpp_path ) ) {
				$swpp_remove( $swpp_path );
			} else {
				@unlink( $swpp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin storage inside uploads.
			}
		}
		@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin storage inside uploads.
	};

	$swpp_base = trailingslashit( (string) $swpp_uploads['basedir'] ) . 'static-wp-publisher';
	$swpp_remove( $swpp_base . '/site-' . get_current_blog_id() );
	@rmdir( $swpp_base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only removed when no other site uses it.
}

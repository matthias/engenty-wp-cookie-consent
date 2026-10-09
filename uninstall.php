<?php
/**
 * Deleting the plugin removes what it kept: the configuration, the log and its versions, the scan,
 * the copies of third-party files, the jobs. Thumbnails stay in the media library.
 *
 * @package Cookie_Dialog
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-config.php';
require_once __DIR__ . '/includes/class-html.php';
require_once __DIR__ . '/includes/class-resources.php';

/**
 * Removes one site's data.
 */
function cookie_dialog_uninstall_site() {
	global $wpdb;
	foreach ( array( 'cookie_dialog', 'cookie_dialog_history', 'cookie_dialog_scan', 'cookie_dialog_thumbs', 'cookie_dialog_salt', 'cookie_dialog_db', 'cookie_dialog_ai_usage', 'cookie_dialog_drafted' ) as $option ) {
		delete_option( $option );
	}
	Cookie_Dialog_Resources::delete_all();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cookie_dialog_consents" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cookie_dialog_versions" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_cookie\\_dialog\\_%' OR option_name LIKE '\\_transient\\_timeout\\_cookie\\_dialog\\_%'" );
	// phpcs:enable
	wp_clear_scheduled_hook( 'cookie_dialog_daily' );
	wp_clear_scheduled_hook( 'cookie_dialog_weekly' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $cookie_dialog_site ) {
		switch_to_blog( $cookie_dialog_site );
		cookie_dialog_uninstall_site();
		restore_current_blog();
	}
} else {
	cookie_dialog_uninstall_site();
}

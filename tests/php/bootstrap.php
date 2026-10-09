<?php
/**
 * The tests run inside a real WordPress with the plugin active (the SQLite one of bin/smoke.sh will do):
 *   WP_PATH=/path/to/wordpress vendor/bin/phpunit
 *
 * @package Cookie_Dialog
 */

$cookie_dialog_wp = getenv( 'WP_PATH' );
if ( ! $cookie_dialog_wp || ! file_exists( $cookie_dialog_wp . '/wp-load.php' ) ) {
	fwrite( STDERR, "WP_PATH must point to a WordPress with the plugin active.\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
define( 'WP_USE_THEMES', false );
require $cookie_dialog_wp . '/wp-load.php';
if ( ! class_exists( 'Cookie_Dialog_Config' ) ) {
	fwrite( STDERR, "The plugin is not active in that WordPress.\n" );
	exit( 1 );
}
require __DIR__ . '/TestCase.php';

<?php
/**
 * Plugin Name:       Cookie Dialog
 * Plugin URI:        https://engenty.ai/cookie-dialog
 * Description:       A simple, beautiful cookie dialog for the EU: categories and services, scripts and embeds only after consent, a consent log, Google Consent Mode v2 – and an assistant that sets it up.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            engenty
 * Author URI:        https://engenty.ai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cookie-dialog
 * Domain Path:       /languages
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

define( 'COOKIE_DIALOG_VERSION', '0.1.0' );
define( 'COOKIE_DIALOG_FILE', __FILE__ );
define( 'COOKIE_DIALOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'COOKIE_DIALOG_URL', plugin_dir_url( __FILE__ ) );

/** The visitor's choice: a first-party cookie, read by the dialog and by the Consent Mode default in the head. */
const COOKIE_DIALOG_COOKIE = 'cookie_dialog';

require_once COOKIE_DIALOG_DIR . 'includes/class-config.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-html.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-consent.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-front.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-blocker.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-embeds.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-resources.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-scanner.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-checks.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-assistant.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-rest.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-admin.php';
require_once COOKIE_DIALOG_DIR . 'includes/class-blocks.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once COOKIE_DIALOG_DIR . 'includes/class-cli.php';
}

/**
 * The plugin's configuration as the site uses it: design, texts, categories, services, Google, behaviour.
 *
 * @return array
 */
function cookie_dialog() {
	return Cookie_Dialog_Config::get();
}

/**
 * Whether the visitor of this request agreed to a category (`statistics`) or a service (`ga4`).
 * Reads the cookie, so it only tells the truth on pages a cache does not keep; in the browser,
 * `cookieDialog.has( 'statistics' )` always does.
 *
 * @param string $key A category or service ID.
 * @return bool
 */
function cookie_dialog_has( $key ) {
	return Cookie_Dialog_Consent::visitor_has( (string) $key );
}

register_activation_hook(
	__FILE__,
	static function ( $network_wide ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( $site_id );
				Cookie_Dialog_Consent::install();
				restore_current_blog();
			}
		} else {
			Cookie_Dialog_Consent::install();
		}
		Cookie_Dialog_Consent::schedule();
		// Start opens once after activation.
		set_transient( 'cookie_dialog_activated', 1, 60 );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'cookie_dialog_daily' );
		wp_clear_scheduled_hook( 'cookie_dialog_weekly' );
	}
);

// A site added to a network gets its own tables.
add_action(
	'wp_initialize_site',
	static function ( $site ) {
		if ( is_plugin_active_for_network( plugin_basename( COOKIE_DIALOG_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			Cookie_Dialog_Consent::install();
			restore_current_blog();
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'cookie-dialog', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		Cookie_Dialog_Consent::init();
		Cookie_Dialog_Front::init();
		Cookie_Dialog_Blocker::init();
		Cookie_Dialog_Embeds::init();
		Cookie_Dialog_Resources::init();
		Cookie_Dialog_Scanner::init();
		Cookie_Dialog_Rest::init();
		Cookie_Dialog_Admin::init();
		Cookie_Dialog_Blocks::init();
	}
);

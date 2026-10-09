<?php
/**
 * The menu „Cookies“ with Start, Dialog, Dienste, Protokoll, Einstellungen – open points as
 * WordPress' own count bubbles – the admin app's assets, the notice before the dialog is on, the CSV.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each page is one `<div id="cookie-dialog-app">` the admin app (src/admin) draws into.
 */
class Cookie_Dialog_Admin {

	const PAGES = array(
		'cookie-dialog'          => 'start',
		'cookie-dialog-dialog'   => 'dialog',
		'cookie-dialog-services' => 'services',
		'cookie-dialog-log'      => 'log',
		'cookie-dialog-settings' => 'settings',
	);

	/** Hooks the menu, the assets, the notices and the CSV. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'after_activation' ) );
		add_action( 'admin_post_cookie_dialog_csv', array( __CLASS__, 'csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( COOKIE_DIALOG_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_guide' ) );
	}

	/**
	 * The menu icon: a cookie, in WordPress' grey.
	 *
	 * @return string
	 */
	private static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7aaad" fill-rule="evenodd" d="M12 2.5a9.5 9.5 0 1 0 9.5 9.5 1 1 0 0 0-1.3-.95 2.6 2.6 0 0 1-3.2-3.1 1 1 0 0 0-1.1-1.2A2.6 2.6 0 0 1 13 4a1 1 0 0 0-1-1.5zM8.5 9.5a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zm4 5a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6zm3.6-2.4a1 1 0 1 0 0 2 1 1 0 0 0 0-2zM8 15a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * WordPress' count bubble.
	 *
	 * @param int $n Count.
	 * @return string
	 */
	private static function bubble( $n ) {
		return $n > 0 ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', (int) $n ) : '';
	}

	/** The menu below „Einstellungen“. */
	public static function menu() {
		$render   = array( __CLASS__, 'render' );
		$open     = 0;
		$services = 0;
		if ( current_user_can( 'manage_options' ) ) {
			$open     = Cookie_Dialog_Checks::open_count();
			$services = count( Cookie_Dialog_Checks::unassigned() );
		}
		add_menu_page( __( 'Cookies', 'cookie-dialog' ), __( 'Cookies', 'cookie-dialog' ) . self::bubble( $open ), 'manage_options', 'cookie-dialog', $render, self::icon(), 81 );
		add_submenu_page( 'cookie-dialog', __( 'Start', 'cookie-dialog' ), __( 'Start', 'cookie-dialog' ) . self::bubble( $open ), 'manage_options', 'cookie-dialog', $render );
		add_submenu_page( 'cookie-dialog', __( 'Dialog', 'cookie-dialog' ), __( 'Dialog', 'cookie-dialog' ), 'manage_options', 'cookie-dialog-dialog', $render );
		add_submenu_page( 'cookie-dialog', __( 'Dienste', 'cookie-dialog' ), __( 'Dienste', 'cookie-dialog' ) . self::bubble( $services ), 'manage_options', 'cookie-dialog-services', $render );
		add_submenu_page( 'cookie-dialog', __( 'Protokoll', 'cookie-dialog' ), __( 'Protokoll', 'cookie-dialog' ), 'manage_options', 'cookie-dialog-log', $render );
		add_submenu_page( 'cookie-dialog', __( 'Einstellungen', 'cookie-dialog' ), __( 'Einstellungen', 'cookie-dialog' ), 'manage_options', 'cookie-dialog-settings', $render );
	}

	/** One page: the app draws into it. */
	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the view.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'cookie-dialog';
		printf(
			'<div class="wrap cd-wrap"><div id="cookie-dialog-app" data-view="%s"><p class="cd-loading">%s</p></div></div>',
			esc_attr( self::PAGES[ $page ] ?? 'start' ),
			esc_html__( 'Cookie Dialog lädt …', 'cookie-dialog' )
		);
	}

	/** The admin app on its pages. */
	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the view.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! isset( self::PAGES[ $page ] ) ) {
			return;
		}
		$asset = COOKIE_DIALOG_DIR . 'build/admin.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => COOKIE_DIALOG_VERSION,
		);
		wp_enqueue_media();
		wp_enqueue_style( 'cookie-dialog-admin', COOKIE_DIALOG_URL . 'build/admin.css', array(), $meta['version'] );
		wp_enqueue_script( 'cookie-dialog-admin', COOKIE_DIALOG_URL . 'build/admin.js', $meta['dependencies'], $meta['version'], true );
		$boot = array(
			'view'      => self::PAGES[ $page ],
			'admin'     => admin_url( 'admin.php' ),
			'version'   => COOKIE_DIALOG_VERSION,
			'home'      => home_url( '/' ),
			'preview'   => add_query_arg( 'cookie-dialog-preview', '1', home_url( '/' ) ),
			'csv'       => wp_nonce_url( admin_url( 'admin-post.php?action=cookie_dialog_csv' ), 'cookie_dialog_csv' ),
			'locale'    => get_user_locale(),
			'wpVersion' => get_bloginfo( 'version' ),
			'user'      => wp_get_current_user()->display_name,
		);
		wp_add_inline_script( 'cookie-dialog-admin', 'window.cookieDialogAdmin = ' . wp_json_encode( $boot ) . ';', 'before' );
		wp_set_script_translations( 'cookie-dialog-admin', 'cookie-dialog', COOKIE_DIALOG_DIR . 'languages' );
	}

	/** After activation Start opens once. */
	public static function after_activation() {
		if ( ! get_transient( 'cookie_dialog_activated' ) ) {
			return;
		}
		delete_transient( 'cookie_dialog_activated' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- several plugins activated at once: no redirect.
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) || Cookie_Dialog_Config::live() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=cookie-dialog' ) );
		exit;
	}

	/**
	 * Start beside „Deaktivieren“.
	 *
	 * @param array $links The links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			Cookie_Dialog_Config::live()
				? sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=cookie-dialog' ) ), esc_html__( 'Start', 'cookie-dialog' ) )
				: sprintf( '<a href="%s" style="font-weight:600">%s</a>', esc_url( admin_url( 'admin.php?page=cookie-dialog' ) ), esc_html__( 'Einrichten', 'cookie-dialog' ) )
		);
		return $links;
	}

	/** Until the dialog is on: one notice on the dashboard and the plugins page. */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) || Cookie_Dialog_Config::live() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s <a class="button button-primary" style="margin-left:8px" href="%s">%s</a></p></div>',
			esc_html__( 'Cookie Dialog ist noch aus.', 'cookie-dialog' ),
			esc_html__( 'Bis du ihn einschaltest, ändert sich nichts an deiner Website.', 'cookie-dialog' ),
			esc_url( admin_url( 'admin.php?page=cookie-dialog' ) ),
			esc_html__( 'Einrichten', 'cookie-dialog' )
		);
	}

	/** The log as CSV. */
	public static function csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nicht erlaubt.', 'cookie-dialog' ), 403 );
		}
		check_admin_referer( 'cookie_dialog_csv' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
		$args = array(
			'range'  => isset( $_GET['range'] ) ? Cookie_Dialog_Config::one_of( sanitize_key( wp_unslash( $_GET['range'] ) ), array( '30d', '12m', 'all' ), 'all' ) : 'all',
			'source' => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '',
		);
		// phpcs:enable
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=cookie-dialog-' . gmdate( 'Y-m-d' ) . '.csv' );
		Cookie_Dialog_Consent::csv( $args );
		exit;
	}

	/** A suggested text for WordPress' privacy policy guide. */
	public static function privacy_guide() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				__( 'Cookie Dialog', 'cookie-dialog' ),
				wp_kses_post(
					wpautop(
						__( 'Cookie Dialog stores the visitor’s choice in the first-party cookie „cookie_dialog“ (consent ID, version, time, categories and services) for as long as set under Cookies › Einstellungen (6 months by default). Every decision is logged with the consent ID, the version of the dialog, language, source, the browser family and the IP address as a SHA-256 hash with a salt that changes daily; the log is deleted after 12 months by default. Insert the block „Cookie-Tabelle“ to list all services and cookies.', 'cookie-dialog' )
					)
				)
			);
		}
	}
}

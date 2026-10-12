<?php
/**
 * The engenty plugins for WordPress, and how far each is here: active, installed or missing, with
 * the way to activate or get it. Every engenty plugin carries a copy of this file under its own
 * class name and text domain (bin/family.sh of engenty.goose writes it); change it there.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * The family.
 */
class Cookie_Dialog_Family {

	/**
	 * The plugins: name, text domain (how WordPress finds them, whatever their folder), main file,
	 * page on engenty.ai, the engenty that stands for it.
	 */
	const PLUGINS = array(
		'engenty-goose'           => array(
			'name'   => 'engenty.goose',
			'domain' => 'engenty-goose',
			'file'   => 'engenty-goose/engenty-goose.php',
			'page'   => 'https://engenty.ai/wordpress-plugins/engenty-goose',
			'kind'   => 'goose',
		),
		'cookie-dialog'           => array(
			'name'   => 'Cookie Dialog',
			'domain' => 'cookie-dialog',
			'file'   => 'cookie-dialog/cookie-dialog.php',
			'page'   => 'https://engenty.ai/wordpress-plugins/cookie-dialog',
			'kind'   => 'incognito',
		),
		'ai-act-labels'           => array(
			'name'   => 'AI Act Image Labels',
			'domain' => 'ai-act-labels',
			'file'   => 'ai-act-labels/ai-act-labels.php',
			'page'   => 'https://engenty.ai/wordpress-plugins/ai-act-image-labels',
			'kind'   => 'round',
		),
		'engenty-wizards'         => array(
			'name'   => 'engenty wizards',
			'domain' => 'engenty-wizards',
			'file'   => 'engenty-wizards/engenty-wizards.php',
			'page'   => 'https://engenty.ai/wordpress-plugins/engenty-wizards',
			'kind'   => 'drop',
		),
		'ai-provider-for-engenty' => array(
			'name'   => 'AI Provider for engenty',
			'domain' => 'ai-provider-for-engenty',
			'file'   => 'ai-provider-for-engenty/plugin.php',
			'page'   => 'https://engenty.ai/wordpress-plugins',
			'kind'   => 'dome',
		),
	);

	/**
	 * What each one does, in a sentence.
	 *
	 * @param string $slug The plugin.
	 * @return string
	 */
	public static function line( $slug ) {
		switch ( $slug ) {
			case 'engenty-goose':
				return __( 'Der Copilot in deinem WordPress: erklärt, zeigt und erledigt es – im Admin und auf der Website.', 'cookie-dialog' );
			case 'cookie-dialog':
				return __( 'Ein schlichter Cookie-Dialog für die EU: Dienste erst nach Zustimmung, Protokoll, Google Consent Mode.', 'cookie-dialog' );
			case 'ai-act-labels':
				return __( 'Kennzeichnet KI-Bilder, wie es der AI Act verlangt – mit den Symbolen der EU auf der Website.', 'cookie-dialog' );
			case 'engenty-wizards':
				return __( 'Eigene KI-Assistenten auf deiner Website: als Block, Shortcode oder Bubble auf jeder Seite.', 'cookie-dialog' );
			case 'ai-provider-for-engenty':
				return __( 'engenty als KI-Anbieter für WordPress, mit Gratis-Guthaben.', 'cookie-dialog' );
		}
		return '';
	}

	/**
	 * One plugin and how far it is here.
	 *
	 * @param string $slug The plugin.
	 * @return array|null `slug`, `name`, `line`, `page`, `kind`, `state` (active, inactive, missing),
	 *                    `activate` (a URL, when it is installed and the person may activate),
	 *                    `install` (where to get it, when it is missing).
	 */
	public static function get( $slug ) {
		$plugin = self::PLUGINS[ $slug ] ?? null;
		if ( ! $plugin ) {
			return null;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = null;
		foreach ( get_plugins() as $path => $data ) {
			if ( ( $data['TextDomain'] ?? '' ) === $plugin['domain'] || $path === $plugin['file'] ) {
				$file = $path;
				break;
			}
		}
		$state = 'missing';
		if ( $file ) {
			$state = is_plugin_active( $file ) ? 'active' : 'inactive';
		}
		$activate = null;
		if ( 'inactive' === $state && current_user_can( 'activate_plugin', $file ) ) {
			$activate = add_query_arg(
				array(
					'action'   => 'activate',
					'plugin'   => rawurlencode( $file ),
					'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $file ),
				),
				admin_url( 'plugins.php' )
			);
		}
		return array(
			'slug'     => $slug,
			'name'     => $plugin['name'],
			'line'     => self::line( $slug ),
			'page'     => $plugin['page'],
			'kind'     => $plugin['kind'],
			'state'    => $state,
			'activate' => $activate,
			'install'  => 'missing' === $state && current_user_can( 'install_plugins' ) ? $plugin['page'] : null,
		);
	}

	/**
	 * All of them.
	 *
	 * @return array[]
	 */
	public static function listing() {
		return array_values( array_filter( array_map( array( self::class, 'get' ), array_keys( self::PLUGINS ) ) ) );
	}
}

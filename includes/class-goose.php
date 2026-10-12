<?php
/**
 * Cookie Dialog in engenty.goose, the copilot: a skill with the instructions for this plugin and
 * three WordPress abilities – read the configuration, change it, take a change back. On Cookie
 * Dialog's own pages the skill is loaded from the start; elsewhere Goose loads it when the
 * conversation is about cookies. Without Goose the abilities are still there for other clients.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * The skill and its abilities.
 */
class Cookie_Dialog_Goose {

	/**
	 * Hooks in.
	 */
	public static function boot() {
		add_action( 'engenty_goose_init', array( self::class, 'skill' ) );
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'abilities' ) );
	}

	/**
	 * Whether Goose is here and this person may use it.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'engenty_goose' ) && class_exists( 'Engenty_Goose' ) && Engenty_Goose::allowed();
	}

	/**
	 * The skill.
	 *
	 * @param Engenty_Goose $goose The copilot.
	 */
	public static function skill( $goose ) {
		$goose->register_skill(
			array(
				'name'         => 'cookie-dialog',
				'label'        => 'Cookie Dialog',
				'description'  => 'The cookie consent dialog of this site (the plugin Cookie Dialog): its texts, look and languages, services and categories, Google Consent Mode, the consent log, and what the last scan saw the site load from third parties. Load it for anything about cookies, consent, the GDPR or what the site loads.',
				'instructions' => array( self::class, 'instructions' ),
				'abilities'    => array( 'cookie-dialog/get-config', 'cookie-dialog/change', 'cookie-dialog/undo' ),
				'where'        => array( 'admin', 'site' ),
				'auto'         => static fn( $page ) => str_contains( (string) ( $page['screen'] ?? '' ), 'cookie-dialog' ),
				'plugin'       => 'cookie-dialog',
			)
		);
	}

	/**
	 * What Goose reads once the skill is loaded.
	 *
	 * @return string
	 */
	public static function instructions() {
		$library = array();
		foreach ( Cookie_Dialog_Config::library() as $preset ) {
			$library[] = $preset['id'];
		}
		return implode(
			"\n",
			array(
				'Cookie Dialog is the EU cookie consent dialog of this site. Its admin pages: admin.php?page=cookie-dialog (Start: checks and what to do), page=cookie-dialog-dialog (texts and look), page=cookie-dialog-services (services and categories), page=cookie-dialog-log (consent log), page=cookie-dialog-settings (Google, validity, scan, export and import). The site preview as a new visitor: ' . home_url( '/?cookie-dialog=preview' ) . ' (end it with ?cookie-dialog=end).',
				'Read the configuration with wpab__cookie-dialog__get-config before you answer questions about it or change it. What the site loads and where is in its scan (the last scan: hosts with the pages and URLs they load on); a service or host that is not there was not seen on the site, say so. The library only lists services the plugin knows, not what the site uses.',
				'Change it with wpab__cookie-dialog__change: a list of operations, all applied at once and each change can be taken back by the person. Operations: {"op":"set","path":"design.colors.button","value":"#1f3d2b"} for settings (paths: design.layout box|bar|modal, design.position left|right|center, design.size s|m|l, design.scheme light|dark|auto, design.radius 0-32, design.colors.{background,text,button,buttonText,link} and design.dark.* as hex, design.buttons same|accept|outline, design.showTitle, design.showLinks and design.showLanguages true|false, design.backdrop, design.lock, tone.address du|Sie, tone.style, google.mode basic|advanced, google.gtm, google.consentMode, google.regions [EEA,UK,CH], google.everywhere, validity.months 1-12, reopen.button, reopen.position left|right, log.months, thirdParty.*); {"op":"text","lang":"de","key":"title","value":"…"} for one dialog text (keys: title, text, accept, reject, settings, save, layerTitle, layerText, privacy, imprint); {"op":"texts","lang":"it","texts":{…}} to add or translate a language (all keys above); {"op":"remove_language","lang":"it"}; {"op":"add_service","preset":"hotjar"} from the library, or {"op":"add_service","name":"…","category":"…","provider":"…","purpose":"…","hosts":["…"]} for one the library lacks; {"op":"remove_service","id":"…"}; {"op":"service","id":"…","category":"…","purpose":"…","lang":"de"}; {"op":"category","id":"statistics","lang":"de","name":"…","description":"…"}; {"op":"privacy_text","lang":"de","value":"<h2>…</h2><p>…</p>"} for the cookie paragraph of the privacy policy.',
				'Library presets: ' . implode( ', ', $library ) . '.',
				'Rules you never break, even when asked: rejecting stays as easy as accepting (the plugin always shows "accept all" and "only necessary" as two equal buttons on the first layer); nothing is pre-selected; texts do not pressure, guilt or mislead; necessary services stay necessary only when the site cannot work without them; Consent Mode stays on when Google services are used. When asked for something that breaks them, explain why in one sentence and offer what is allowed.',
				'Texts on the site use {site} for the site name. Keep dialog texts short: a title of a few words, a text of two sentences. After a change, say in one sentence what changed; the person sees an undo button.',
			)
		);
	}

	/**
	 * The category `cookie-dialog`.
	 */
	public static function category() {
		wp_register_ability_category(
			'cookie-dialog',
			array(
				'label'       => 'Cookie Dialog',
				'description' => __( 'Der Cookie-Dialog der Website: Texte, Aussehen, Dienste, Protokoll.', 'cookie-dialog' ),
			)
		);
	}

	/**
	 * The abilities.
	 */
	public static function abilities() {
		$admin = static fn() => current_user_can( 'manage_options' );
		wp_register_ability(
			'cookie-dialog/get-config',
			array(
				'label'               => __( 'Cookie-Dialog lesen', 'cookie-dialog' ),
				'description'         => 'The configuration of the cookie dialog (design, texts per language, categories, services, Google, validity, reopen, third parties, log), what the last scan saw the site load, hosts no service covers yet, and the decisions of the last 30 days.',
				'category'            => 'cookie-dialog',
				'execute_callback'    => static fn() => array(
					'config'     => Cookie_Dialog_Assistant::context(),
					'unassigned' => array_map( static fn( $e ) => $e['host'], Cookie_Dialog_Checks::unassigned() ),
					'stats30d'   => Cookie_Dialog_Consent::stats( array( 'range' => '30d' ) ),
				),
				'permission_callback' => $admin,
				'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
			)
		);
		wp_register_ability(
			'cookie-dialog/change',
			array(
				'label'               => __( 'Cookie-Dialog ändern', 'cookie-dialog' ),
				'description'         => 'Changes the cookie dialog: a list of operations (see the skill), applied together as one change the person can take back; what is not allowed is skipped.',
				'category'            => 'cookie-dialog',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'changes' => array(
							'type'  => 'array',
							'items' => Cookie_Dialog_Assistant::change_schema(),
						),
						'note'    => array(
							'type'        => 'string',
							'description' => 'What the person asked for, in a few words (the version\'s note).',
						),
					),
					'required'   => array( 'changes' ),
				),
				'execute_callback'    => array( self::class, 'change' ),
				'permission_callback' => $admin,
				'meta'                => array( 'annotations' => array( 'readonly' => false ) ),
			)
		);
		wp_register_ability(
			'cookie-dialog/undo',
			array(
				'label'               => __( 'Änderung zurücknehmen', 'cookie-dialog' ),
				'description'         => 'Takes a change of cookie-dialog/change back, by the token it returned.',
				'category'            => 'cookie-dialog',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'token' => array( 'type' => 'string' ) ),
					'required'   => array( 'token' ),
				),
				'execute_callback'    => static function ( $input ) {
					$out = Cookie_Dialog_Assistant::undo( (string) ( $input['token'] ?? '' ) );
					return is_wp_error( $out ) ? $out : array(
						'undone'  => true,
						'version' => (int) Cookie_Dialog_Config::get()['version'],
					);
				},
				'permission_callback' => $admin,
				'meta'                => array( 'annotations' => array( 'readonly' => false ) ),
			)
		);
	}

	/**
	 * `cookie-dialog/change`: applies the operations, and says how to take them back.
	 *
	 * @param array $input `changes`, `note`.
	 * @return array
	 */
	public static function change( $input ) {
		$changes = is_array( $input['changes'] ?? null ) ? $input['changes'] : array();
		$token   = Cookie_Dialog_Assistant::keep_undo();
		$done    = Cookie_Dialog_Assistant::apply( $changes, sanitize_text_field( (string) ( $input['note'] ?? '' ) ) );
		$out     = array(
			'applied' => (int) $done['applied'],
			'skipped' => $done['skipped'],
			'version' => (int) Cookie_Dialog_Config::get()['version'],
		);
		if ( $done['applied'] ) {
			$out['undo'] = array(
				'ability' => 'cookie-dialog/undo',
				'input'   => array( 'token' => $token ),
				'label'   => __( 'Cookie-Dialog geändert', 'cookie-dialog' ),
			);
		} else {
			delete_transient( 'cookie_dialog_undo_' . get_current_user_id() . '_' . $token );
		}
		return $out;
	}
}

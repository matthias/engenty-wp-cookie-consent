<?php
/**
 * The one configuration (option `cookie_dialog`): design, texts per language, categories, services,
 * Google, third parties, behaviour, pages, log, assistant. Also the presets library and the
 * dialog's default texts.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes and saves the configuration; a change of what visitors are told becomes a new
 * version once the dialog is live.
 */
class Cookie_Dialog_Config {

	const OPTION  = 'cookie_dialog';
	const HISTORY = 'cookie_dialog_history';

	const CATEGORIES = array( 'necessary', 'functional', 'media', 'statistics', 'marketing' );

	/** The preset of WordPress' own cookies. */
	const CORE = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit -- an ID.

	/** Kinds of a service that the site serves itself instead of a third party. */
	const LOCAL_KINDS = array( 'font', 'style', 'library', 'image', 'avatar' );

	/** Consent Mode v2 signals; security_storage is always granted. */
	const SIGNALS = array( 'ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'personalization_storage' );

	/** What a whole category grants in Consent Mode, on top of each service's own signals. */
	const CATEGORY_SIGNALS = array(
		'functional' => array( 'functionality_storage', 'personalization_storage' ),
		'statistics' => array( 'analytics_storage' ),
		'marketing'  => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
	);

	/**
	 * The configuration of this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * The presets library.
	 *
	 * @var array|null
	 */
	private static $library = null;

	/**
	 * The configuration, with defaults for what was never set.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/** Forgets the cached configuration (after a save elsewhere, in tests). */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Whether the dialog is switched on.
	 *
	 * @return bool
	 */
	public static function live() {
		return ! empty( self::get()['live'] );
	}

	/**
	 * Everything as it is before the setup.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'live'        => false,
			'version'     => 0,
			'reask'       => 0,
			'setup'       => false,
			'design'      => array(
				'layout'        => 'box',
				'position'      => 'left',
				'size'          => 'm',
				'scheme'        => 'light',
				'colors'        => array(
					'background' => '#ffffff',
					'text'       => '#1b1b1b',
					'button'     => '#1f3d2b',
					'buttonText' => '#ffffff',
					'link'       => '#1f3d2b',
				),
				'dark'          => array(
					'background' => '#1e1f1c',
					'text'       => '#f4f1ea',
					'button'     => '#e6c76f',
					'buttonText' => '#1b1b1b',
					'link'       => '#e6c76f',
				),
				'radius'        => 16,
				'font'          => 'theme',
				'fontFamily'    => '',
				'headFont'      => '',
				'logo'          => '',
				'backdrop'      => false,
				'lock'          => false,
				'branding'      => false,
				'buttons'       => 'accept',
				'showTitle'     => true,
				'showLinks'     => true,
				'showLanguages' => false,
			),
			'tone'        => array(
				'address' => 'du',
				'style'   => 'freundlich',
			),
			'languages'   => array( self::site_language() ),
			'texts'       => array(),
			'privacyText' => array(),
			'categories'  => self::default_categories(),
			'services'    => array(),
			'google'      => array(
				'consentMode'    => true,
				'mode'           => 'basic',
				'gtm'            => '',
				'regions'        => array( 'EEA', 'UK', 'CH' ),
				'everywhere'     => false,
				'urlPassthrough' => false,
				'adsRedaction'   => true,
				'waitForUpdate'  => 500,
			),
			'thirdParty'  => array(
				'unknownIframes' => true,
				'thumbnails'     => true,
				'always'         => true,
				'nocookie'       => true,
				'gravatar'       => 'proxy',
				'emoji'          => false,
				'hints'          => true,
			),
			'validity'    => array(
				'months'        => 6,
				'reaskOnChange' => true,
				'gpc'           => true,
			),
			'reopen'      => array(
				'button'   => true,
				'position' => 'left',
			),
			'pages'       => array(
				'privacy' => (int) get_option( 'wp_page_for_privacy_policy', 0 ),
				'imprint' => 0,
				'small'   => true,
			),
			'log'         => array(
				'months' => 12,
			),
			'assistant'   => array(
				'browser' => true,
				'model'   => 'cheap',
				'custom'  => '',
			),
			'scan'        => array(
				'weekly' => true,
			),
		);
	}

	/**
	 * The five categories every dialog starts with; they are always there.
	 *
	 * @return array
	 */
	public static function default_categories() {
		return array(
			array(
				'id'          => 'necessary',
				'locked'      => true,
				'enabled'     => true,
				'name'        => array(
					'de' => 'Notwendig',
					'en' => 'Necessary',
				),
				'description' => array(
					'de' => 'Anmeldung, Formulare, dieser Dialog. Ohne sie funktioniert die Seite nicht.',
					'en' => 'Sign-in, forms, this dialog. The site does not work without them.',
				),
			),
			array(
				'id'          => 'functional',
				'locked'      => false,
				'enabled'     => true,
				'name'        => array(
					'de' => 'Funktional',
					'en' => 'Functional',
				),
				'description' => array(
					'de' => 'Funktionen anderer Anbieter, etwa Spam-Schutz, Chat oder Terminbuchung.',
					'en' => 'Features from other providers, such as spam protection, chat or booking.',
				),
			),
			array(
				'id'          => 'media',
				'locked'      => false,
				'enabled'     => true,
				'name'        => array(
					'de' => 'Externe Medien',
					'en' => 'External media',
				),
				'description' => array(
					'de' => 'Videos, Karten und Beiträge von anderen Plattformen. Erst mit Zustimmung laden sie vom Anbieter.',
					'en' => 'Videos, maps and posts from other platforms. Only with consent do they load from the provider.',
				),
			),
			array(
				'id'          => 'statistics',
				'locked'      => false,
				'enabled'     => true,
				'name'        => array(
					'de' => 'Statistik',
					'en' => 'Statistics',
				),
				'description' => array(
					'de' => 'Sehen, welche Seiten gelesen werden und was gut ankommt.',
					'en' => 'See which pages are read and what works.',
				),
			),
			array(
				'id'          => 'marketing',
				'locked'      => false,
				'enabled'     => true,
				'name'        => array(
					'de' => 'Marketing',
					'en' => 'Marketing',
				),
				'description' => array(
					'de' => 'Messen, wie unsere Anzeigen wirken, und Werbung, die passt.',
					'en' => 'Measuring how our ads work, and ads that fit.',
				),
			),
		);
	}

	/**
	 * The dialog's texts as the library has them, per language and form of address. Other
	 * languages start from English until the assistant or the browser's model translates them.
	 *
	 * @param string $lang    Two-letter language.
	 * @param string $address `du` or `Sie` (German only).
	 * @return array
	 */
	public static function default_texts( $lang, $address = 'du' ) {
		if ( 'de' === $lang ) {
			$sie = 'Sie' === $address;
			return array(
				'title'        => 'Cookies bei {site}',
				'text'         => $sie
					? 'Wir nutzen Cookies, damit die Seite funktioniert, und – nur mit Ihrer Zustimmung – für {purposes}. Sie können Ihre Auswahl jederzeit ändern.'
					: 'Wir nutzen Cookies, damit die Seite funktioniert, und – nur mit deiner Zustimmung – für {purposes}. Du kannst deine Auswahl jederzeit ändern.',
				'accept'       => 'Alle akzeptieren',
				'reject'       => 'Nur notwendige',
				'settings'     => 'Einstellungen',
				'save'         => 'Auswahl speichern',
				'layerTitle'   => $sie ? 'Ihre Auswahl' : 'Deine Auswahl',
				'layerText'    => $sie
					? 'Notwendige Cookies sind immer an. Alles andere entscheiden Sie – pro Kategorie oder pro Dienst.'
					: 'Notwendige Cookies sind immer an. Alles andere entscheidest du – pro Kategorie oder pro Dienst.',
				'privacy'      => 'Datenschutz',
				'imprint'      => 'Impressum',
				'close'        => 'Schließen',
				'consentId'    => $sie ? 'Ihre Consent-ID' : 'Deine Consent-ID',
				'alwaysOn'     => 'Immer aktiv',
				'service'      => '1 Dienst',
				'services'     => '%d Dienste',
				'provider'     => 'Anbieter',
				'purpose'      => 'Zweck',
				'policy'       => 'Datenschutz',
				'cookie'       => 'Cookie',
				'duration'     => 'Dauer',
				'type'         => 'Art',
				'gpc'          => $sie
					? 'Ihr Browser sendet „Global Privacy Control“: Marketing bleibt aus, solange Sie es nicht selbst einschalten.'
					: 'Dein Browser sendet „Global Privacy Control“: Marketing bleibt aus, solange du es nicht selbst einschaltest.',
				'reopen'       => 'Cookie-Einstellungen',
				'phVideo'      => 'Dieses Video kommt von {service}.',
				'phMap'        => 'Diese Karte kommt von {service}.',
				'phSocial'     => 'Dieser Beitrag kommt von {service}.',
				'phFeed'       => 'Diese Beiträge kommen von {service}.',
				'phFeedLoad'   => 'Beiträge laden',
				'phAudio'      => 'Dieser Inhalt kommt von {service}.',
				'phOther'      => 'Dieser Inhalt kommt von {service}.',
				'phText'       => 'Mit dem Laden werden Daten an {provider} übertragen.',
				'phCookies'    => 'Mit dem Laden werden Daten an {provider} übertragen, und {service} setzt Cookies.',
				'phLoad'       => 'Inhalt laden',
				'phAlways'     => 'Immer laden',
				'phAlwaysHint' => '„Immer laden“ erlaubt {service} auf der ganzen Website.',
				'phThumbHint'  => 'Vorschaubild über unseren Server geladen – keine Verbindung zu {service}.',
				'phRoute'      => 'Route öffnen',
				'phAddress'    => $sie ? 'Sie können die Adresse auch so verwenden:' : 'Du kannst die Adresse auch so verwenden:',
				'units'        => array( 'Sitzung', 'Minute', 'Minuten', 'Stunde', 'Stunden', 'Tag', 'Tage', 'Woche', 'Wochen', 'Monat', 'Monate', 'Jahr', 'Jahre', 'dauerhaft' ),
				'types'        => array( 'HTTP', 'Local Storage', 'Session Storage' ),
			);
		}
		return array(
			'title'        => 'Cookies at {site}',
			'text'         => 'We use cookies so the site works and, only with your consent, for {purposes}. You can change your choice at any time.',
			'accept'       => 'Accept all',
			'reject'       => 'Only necessary',
			'settings'     => 'Settings',
			'save'         => 'Save choice',
			'layerTitle'   => 'Your choice',
			'layerText'    => 'Necessary cookies are always on. You decide the rest – per category or per service.',
			'privacy'      => 'Privacy',
			'imprint'      => 'Imprint',
			'close'        => 'Close',
			'consentId'    => 'Your consent ID',
			'alwaysOn'     => 'Always on',
			'service'      => '1 service',
			'services'     => '%d services',
			'provider'     => 'Provider',
			'purpose'      => 'Purpose',
			'policy'       => 'Privacy policy',
			'cookie'       => 'Cookie',
			'duration'     => 'Duration',
			'type'         => 'Type',
			'gpc'          => 'Your browser sends “Global Privacy Control”: marketing stays off unless you switch it on yourself.',
			'reopen'       => 'Cookie settings',
			'phVideo'      => 'This video comes from {service}.',
			'phMap'        => 'This map comes from {service}.',
			'phSocial'     => 'This post comes from {service}.',
			'phFeed'       => 'These posts come from {service}.',
			'phFeedLoad'   => 'Load posts',
			'phAudio'      => 'This content comes from {service}.',
			'phOther'      => 'This content comes from {service}.',
			'phText'       => 'Loading it sends data to {provider}.',
			'phCookies'    => 'Loading it sends data to {provider}, and {service} sets cookies.',
			'phLoad'       => 'Load content',
			'phAlways'     => 'Always load',
			'phAlwaysHint' => '“Always load” allows {service} on the whole site.',
			'phThumbHint'  => 'Preview image loaded through our server – no connection to {service}.',
			'phRoute'      => 'Open route',
			'phAddress'    => 'You can also use the address:',
			'units'        => array( 'Session', 'minute', 'minutes', 'hour', 'hours', 'day', 'days', 'week', 'weeks', 'month', 'months', 'year', 'years', 'persistent' ),
			'types'        => array( 'HTTP', 'Local storage', 'Session storage' ),
		);
	}

	/**
	 * The texts of one language as visitors see them: the stored ones over the library's, with
	 * {site} and {purposes} filled in.
	 *
	 * @param string     $lang   Two-letter language.
	 * @param array|null $config A configuration; the current one by default.
	 * @return array
	 */
	public static function texts( $lang, $config = null ) {
		$config   = $config ?? self::get();
		$base     = self::default_texts( $lang, $config['tone']['address'] ?? 'du' );
		$fallback = 'de' !== $lang && 'en' !== $lang ? self::default_texts( 'en' ) : array();
		$stored   = is_array( $config['texts'][ $lang ] ?? null ) ? $config['texts'][ $lang ] : array();
		$texts    = array_merge( $base, $fallback, array_filter( $stored, static fn( $v ) => '' !== $v && null !== $v ) );
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$purposes = self::purposes( $lang, $config );
		foreach ( $texts as $key => $value ) {
			if ( is_string( $value ) ) {
				$texts[ $key ] = strtr(
					$value,
					array(
						'{site}'     => $site,
						'{purposes}' => $purposes,
					)
				);
			}
		}
		return $texts;
	}

	/**
	 * The purposes the default text names: the categories beyond the necessary one that have services.
	 *
	 * @param string $lang   Language.
	 * @param array  $config Configuration.
	 * @return string
	 */
	private static function purposes( $lang, $config ) {
		$names = array();
		foreach ( self::dialog_categories( $config ) as $cat ) {
			if ( 'necessary' === $cat['id'] ) {
				continue;
			}
			$names[] = self::pick( $cat['name'], $lang );
		}
		if ( ! $names ) {
			return 'de' === $lang ? 'eingebettete Inhalte' : 'embedded content';
		}
		$last = array_pop( $names );
		$and  = 'de' === $lang ? ' und ' : ' and ';
		$text = $names ? implode( ', ', $names ) . $and . $last : $last;
		return 'de' === $lang ? $text : strtolower( $text );
	}

	/**
	 * The categories the dialog shows: enabled, with at least one service (necessary always).
	 *
	 * @param array|null $config Configuration.
	 * @return array
	 */
	public static function dialog_categories( $config = null ) {
		$config = $config ?? self::get();
		$used   = array();
		foreach ( self::dialog_services( $config ) as $service ) {
			$used[ $service['category'] ] = true;
		}
		return array_values(
			array_filter(
				$config['categories'],
				static fn( $cat ) => 'necessary' === $cat['id'] || ( ! empty( $cat['enabled'] ) && isset( $used[ $cat['id'] ] ) )
			)
		);
	}

	/**
	 * The services the dialog names: all but third-party files the site now serves itself.
	 *
	 * @param array|null $config Configuration.
	 * @return array
	 */
	public static function dialog_services( $config = null ) {
		$config = $config ?? self::get();
		return array_values( array_filter( $config['services'], static fn( $s ) => ! self::is_local( $s ) ) );
	}

	/**
	 * Whether a service's files now come from the site's own server.
	 *
	 * @param array $service A service.
	 * @return bool
	 */
	public static function is_local( $service ) {
		$kind = $service['kind'] ?? '';
		if ( ! in_array( $kind, self::LOCAL_KINDS, true ) ) {
			return false;
		}
		if ( ! empty( $service['local'] ) ) {
			return true;
		}
		// Avatars through the server and the emoji script switched off reach no third party either.
		$third = ( self::$cache ?? self::get() )['thirdParty'];
		if ( 'avatar' === $kind ) {
			return 'allow' !== $third['gravatar'];
		}
		return 'wp-emoji' === ( $service['preset'] ? $service['preset'] : $service['id'] ) && ! $third['emoji'];
	}

	/**
	 * Whether a service waits for consent: everything but necessary services and local files.
	 *
	 * @param array $service A service.
	 * @return bool
	 */
	public static function is_held( $service ) {
		return 'necessary' !== ( $service['category'] ?? '' ) && ! self::is_local( $service );
	}

	/**
	 * A text of a `{ de, en }` map in a language, falling back to English, then German, then any.
	 *
	 * @param mixed  $map  Map or string.
	 * @param string $lang Language.
	 * @return string
	 */
	public static function pick( $map, $lang ) {
		if ( ! is_array( $map ) ) {
			return (string) $map;
		}
		foreach ( array( $lang, 'en', 'de' ) as $l ) {
			if ( ! empty( $map[ $l ] ) ) {
				return (string) $map[ $l ];
			}
		}
		$first = reset( $map );
		return false === $first ? '' : (string) $first;
	}

	/**
	 * The site's language, two letters.
	 *
	 * @return string
	 */
	public static function site_language() {
		$lang = substr( (string) get_locale(), 0, 2 );
		return $lang ? $lang : 'en';
	}

	/**
	 * The languages of the site: Polylang's, WPML's or the one locale.
	 *
	 * @return array
	 */
	public static function site_languages() {
		if ( function_exists( 'pll_languages_list' ) ) {
			$list = pll_languages_list( array( 'fields' => 'slug' ) );
			if ( $list ) {
				return array_values( array_map( 'strval', $list ) );
			}
		}
		$wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $wpml ) && $wpml ) {
			return array_values( array_map( 'strval', array_keys( $wpml ) ) );
		}
		return array( self::site_language() );
	}

	/**
	 * The language of this request, among the dialog's languages.
	 *
	 * @return string
	 */
	public static function lang() {
		$config = self::get();
		$lang   = '';
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = (string) pll_current_language( 'slug' );
		}
		if ( ! $lang ) {
			$wpml = apply_filters( 'wpml_current_language', null );
			$lang = is_string( $wpml ) ? $wpml : '';
		}
		if ( ! $lang ) {
			$lang = substr( (string) determine_locale(), 0, 2 );
		}
		$langs = $config['languages'] ? $config['languages'] : array( 'en' );
		return in_array( $lang, $langs, true ) ? $lang : $langs[0];
	}

	/**
	 * The presets library: one JSON file per service in presets/.
	 *
	 * @return array Presets by ID.
	 */
	public static function library() {
		if ( null !== self::$library ) {
			return self::$library;
		}
		$cache = wp_cache_get( 'library-' . COOKIE_DIALOG_VERSION, 'cookie_dialog' );
		if ( is_array( $cache ) ) {
			self::$library = $cache;
			return $cache;
		}
		$library = array();
		foreach ( (array) glob( COOKIE_DIALOG_DIR . 'presets/*.json' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file of the plugin.
			$preset = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $preset ) && ! empty( $preset['id'] ) ) {
				$library[ $preset['id'] ] = $preset;
			}
		}
		ksort( $library );
		/**
		 * Filters the presets library: add a preset of your own or correct one.
		 *
		 * @param array $library Presets by ID.
		 */
		self::$library = (array) apply_filters( 'cookie_dialog_library', $library );
		wp_cache_set( 'library-' . COOKIE_DIALOG_VERSION, self::$library, 'cookie_dialog' );
		return self::$library;
	}

	/**
	 * One preset.
	 *
	 * @param string $id Preset ID.
	 * @return array|null
	 */
	public static function preset( $id ) {
		return self::library()[ $id ] ?? null;
	}

	/**
	 * A service as the configuration keeps it, from a preset.
	 *
	 * @param array       $preset   The preset.
	 * @param string|null $category Another category than the preset's.
	 * @param string      $source   Where it came from: library, scan, assistant.
	 * @return array
	 */
	public static function service_from_preset( $preset, $category = null, $source = 'library' ) {
		$service = array(
			'id'       => $preset['id'],
			'preset'   => $preset['id'],
			'name'     => $preset['name'],
			'category' => $category ?? $preset['category'],
			'kind'     => $preset['kind'] ?? 'script',
			'provider' => $preset['provider'] ?? array(
				'name'    => '',
				'address' => '',
			),
			'purpose'  => $preset['purpose'] ?? array(),
			'privacy'  => $preset['privacy'] ?? '',
			'cookies'  => $preset['cookies'] ?? array(),
			'hosts'    => $preset['hosts'] ?? array(),
			'patterns' => $preset['patterns'] ?? array(),
			'handles'  => $preset['handles'] ?? array(),
			'strict'   => ! empty( $preset['strict'] ),
			'signals'  => $preset['signals'] ?? array(),
			'snippet'  => '',
			'source'   => $source,
			'added'    => time(),
		);
		if ( ! empty( $preset['embed'] ) ) {
			$service['embed'] = array(
				'type'    => $preset['embed']['type'] ?? 'other',
				'label'   => $preset['embed']['label'] ?? array(),
				'thumb'   => ! empty( $preset['embed']['thumb'] ),
				'address' => '',
			);
		}
		if ( in_array( $service['kind'], self::LOCAL_KINDS, true ) ) {
			$service['local'] = false;
		}
		if ( self::CORE === $preset['id'] ) {
			$service = self::core( $service );
		}
		return self::sanitize_service( $service );
	}

	/**
	 * WordPress' own cookies as far as the site uses them: the comment cookies (and the sentence about
	 * them) only where the last scan saw a comment form, wp-postpass_* only with a password-protected
	 * post. Before any scan the comments count as offered. Purposes someone wrote stay.
	 *
	 * @param array $service The service of the preset CORE.
	 * @return array
	 */
	public static function core( array $service ) {
		global $wpdb;
		$preset = self::preset( self::CORE );
		if ( ! $preset ) {
			return $service;
		}
		$scan     = Cookie_Dialog_Scanner::result();
		$comments = ! $scan['at'] || ! empty( $scan['comments'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one row, whether any exists.
		$passwords = (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password <> '' LIMIT 1" );
		$optional  = static fn( $name ) => str_starts_with( $name, 'comment_author' ) || str_starts_with( $name, 'wp-postpass' );
		$cookies   = array_values( array_filter( (array) ( $service['cookies'] ?? array() ), static fn( $c ) => ! $optional( (string) ( $c['name'] ?? '' ) ) ) );
		foreach ( $preset['cookies'] as $cookie ) {
			if ( ( $comments && str_starts_with( $cookie['name'], 'comment_author' ) ) || ( $passwords && str_starts_with( $cookie['name'], 'wp-postpass' ) ) ) {
				$cookies[] = $cookie;
			}
		}
		$service['cookies'] = $cookies;
		foreach ( $preset['purpose'] as $lang => $base ) {
			$with = $base . ' ' . ( $preset['comments'][ $lang ] ?? '' );
			$now  = $service['purpose'][ $lang ] ?? $base;
			if ( $now === $base || $now === $with ) {
				$service['purpose'][ $lang ] = $comments ? $with : $base;
			}
		}
		return $service;
	}

	/** After a scan: the WordPress service follows what the site uses. */
	public static function sync_core() {
		$config = self::get();
		foreach ( $config['services'] as $i => $service ) {
			if ( self::CORE === $service['preset'] ) {
				$core = self::sanitize_service( self::core( $service ) );
				if ( $core['cookies'] !== $service['cookies'] || $core['purpose'] !== $service['purpose'] ) {
					$config['services'][ $i ] = $core;
					self::save( $config, __( 'WordPress-Cookies an die Website angepasst', 'cookie-dialog' ) );
				}
				return;
			}
		}
	}

	/**
	 * A service of the configuration.
	 *
	 * @param string     $id     Service ID.
	 * @param array|null $config Configuration.
	 * @return array|null
	 */
	public static function service( $id, $config = null ) {
		foreach ( ( $config ?? self::get() )['services'] as $service ) {
			if ( $service['id'] === $id ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * Saves a whole configuration. What visitors are told changed while the dialog is live: a new
	 * version, and visitors are asked again when the settings say so.
	 *
	 * @param array  $config The configuration.
	 * @param string $note   What changed, for the history.
	 * @return array The saved configuration.
	 */
	public static function save( array $config, $note = '' ) {
		$old = self::get();
		$new = self::sanitize( self::merge( self::defaults(), $config ) );
		if ( $new['live'] && ( ! $old['live'] || 0 === (int) $new['version'] || self::fingerprint( $old ) !== self::fingerprint( $new ) ) ) {
			$new = Cookie_Dialog_Consent::new_version( $new, $old, $note );
		}
		update_option( self::OPTION, $new, true );
		self::$cache = null;
		/**
		 * After the configuration was saved.
		 *
		 * @param array $new The new configuration.
		 * @param array $old The one before.
		 */
		do_action( 'cookie_dialog_saved', $new, $old );
		return self::get();
	}

	/**
	 * Changes part of the configuration: maps merge, lists are replaced.
	 *
	 * @param array  $patch What changes.
	 * @param string $note  What changed, for the history.
	 * @return array
	 */
	public static function patch( array $patch, $note = '' ) {
		return self::save( self::merge( self::get(), $patch ), $note );
	}

	/**
	 * The configuration as a file for another site, e.g. from staging to live. The privacy policy and
	 * the imprint go by their address: their IDs differ from site to site.
	 *
	 * @return array
	 */
	public static function export() {
		$config = self::get();
		$pages  = array();
		foreach ( array( 'privacy', 'imprint' ) as $key ) {
			$id            = (int) $config['pages'][ $key ];
			$pages[ $key ] = $id && get_post( $id ) ? get_page_uri( $id ) : '';
		}
		return array(
			'plugin'   => 'cookie-dialog',
			'version'  => COOKIE_DIALOG_VERSION,
			'exported' => gmdate( 'c' ),
			'site'     => home_url( '/' ),
			'pages'    => $pages,
			'config'   => $config,
		);
	}

	/**
	 * Takes the configuration of an export. The site keeps whether the dialog is on, its version and
	 * the log; a page only where one with the same address is published here, else the site's own.
	 *
	 * @param mixed  $data An export.
	 * @param string $note What changed, for the history.
	 * @return array|WP_Error The saved configuration.
	 */
	public static function import( $data, $note = '' ) {
		if ( ! is_array( $data ) || 'cookie-dialog' !== ( $data['plugin'] ?? '' ) || ! is_array( $data['config'] ?? null ) ) {
			return new WP_Error( 'cookie_dialog_import', __( 'In dieser Datei sind keine Einstellungen von Cookie Dialog.', 'cookie-dialog' ), array( 'status' => 400 ) );
		}
		$config  = $data['config'];
		$current = self::get();
		foreach ( array( 'live', 'version', 'reask' ) as $key ) {
			$config[ $key ] = $current[ $key ];
		}
		$config['pages'] = is_array( $config['pages'] ?? null ) ? $config['pages'] : array();
		foreach ( array( 'privacy', 'imprint' ) as $key ) {
			$path                    = (string) ( $data['pages'][ $key ] ?? '' );
			$page                    = '' !== $path ? get_page_by_path( $path ) : null;
			$config['pages'][ $key ] = $page && 'publish' === $page->post_status ? (int) $page->ID : (int) $current['pages'][ $key ];
		}
		return self::save( $config, $note );
	}

	/**
	 * A line in "Zuletzt".
	 *
	 * @param string $text What happened.
	 */
	public static function remember( $text ) {
		$history = get_option( self::HISTORY, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift(
			$history,
			array(
				't'    => time(),
				'text' => sanitize_text_field( $text ),
			)
		);
		update_option( self::HISTORY, array_slice( $history, 0, 30 ), false );
	}

	/**
	 * What visitors are told, in every language: what a version holds.
	 *
	 * @param array $config Configuration.
	 * @return array
	 */
	public static function snapshot( $config ) {
		$texts = array();
		foreach ( $config['languages'] as $lang ) {
			$texts[ $lang ] = self::texts( $lang, $config );
			unset( $texts[ $lang ]['units'], $texts[ $lang ]['types'] );
		}
		$services = array();
		foreach ( self::dialog_services( $config ) as $s ) {
			$services[] = array(
				'id'       => $s['id'],
				'name'     => $s['name'],
				'category' => $s['category'],
				'provider' => $s['provider'],
				'purpose'  => $s['purpose'],
				'privacy'  => $s['privacy'],
				'cookies'  => $s['cookies'],
			);
		}
		return array(
			'languages'  => $config['languages'],
			'texts'      => $texts,
			'categories' => array_map(
				static fn( $c ) => array_intersect_key( $c, array_flip( array( 'id', 'name', 'description', 'locked' ) ) ),
				self::dialog_categories( $config )
			),
			'services'   => $services,
		);
	}

	/**
	 * A hash of what visitors are told.
	 *
	 * @param array $config Configuration.
	 * @return string
	 */
	public static function fingerprint( $config ) {
		return md5( (string) wp_json_encode( self::snapshot( $config ) ) );
	}

	/**
	 * Maps merge key by key, lists and scalars are replaced.
	 *
	 * @param array $base  The base.
	 * @param array $patch What is laid over it.
	 * @return array
	 */
	public static function merge( array $base, array $patch ) {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Every value in its type and range.
	 *
	 * @param array $c A configuration.
	 * @return array
	 */
	public static function sanitize( array $c ) {
		$d = self::defaults();

		$c['live']    = ! empty( $c['live'] );
		$c['version'] = max( 0, (int) $c['version'] );
		$c['reask']   = max( 0, min( (int) $c['reask'], $c['version'] ) );
		$c['setup']   = ! empty( $c['setup'] );

		$design             = (array) $c['design'];
		$design['layout']   = self::one_of( $design['layout'] ?? '', array( 'box', 'bar', 'modal' ), 'box' );
		$design['position'] = self::one_of( $design['position'] ?? '', array( 'left', 'right', 'center' ), 'left' );
		$design['size']     = self::one_of( $design['size'] ?? '', array( 's', 'm', 'l' ), 'm' );
		$design['scheme']   = self::one_of( $design['scheme'] ?? '', array( 'light', 'dark', 'auto' ), 'light' );
		foreach ( array( 'colors', 'dark' ) as $set ) {
			foreach ( $d['design'][ $set ] as $key => $fallback ) {
				$color                  = sanitize_hex_color( (string) ( $design[ $set ][ $key ] ?? '' ) );
				$design[ $set ][ $key ] = $color ? $color : $fallback;
			}
			$design[ $set ] = array_intersect_key( $design[ $set ], $d['design'][ $set ] );
		}
		$design['radius']     = max( 0, min( 32, (int) ( $design['radius'] ?? 16 ) ) );
		$design['font']       = self::one_of( $design['font'] ?? '', array( 'theme', 'system', 'custom' ), 'theme' );
		$design['fontFamily'] = self::font_family( $design['fontFamily'] ?? '' );
		$design['headFont']   = self::font_family( $design['headFont'] ?? '' );
		$design['logo']       = esc_url_raw( (string) ( $design['logo'] ?? '' ) );
		foreach ( array( 'backdrop', 'lock', 'branding', 'showTitle', 'showLinks', 'showLanguages' ) as $flag ) {
			$design[ $flag ] = ! empty( $design[ $flag ] );
		}
		$design['buttons'] = self::one_of( $design['buttons'] ?? '', array( 'same', 'accept', 'outline' ), 'accept' );
		$c['design']       = array_intersect_key( $design, $d['design'] );

		$c['tone'] = array(
			'address' => self::one_of( $c['tone']['address'] ?? '', array( 'du', 'Sie' ), 'du' ),
			'style'   => sanitize_text_field( (string) ( $c['tone']['style'] ?? 'freundlich' ) ),
		);

		$langs = array();
		foreach ( (array) $c['languages'] as $lang ) {
			$lang = self::lang_code( $lang );
			if ( $lang && ! in_array( $lang, $langs, true ) ) {
				$langs[] = $lang;
			}
		}
		$c['languages'] = $langs ? $langs : $d['languages'];

		$texts = array();
		foreach ( (array) $c['texts'] as $lang => $set ) {
			$lang = self::lang_code( $lang );
			if ( ! $lang || ! is_array( $set ) ) {
				continue;
			}
			foreach ( $set as $key => $value ) {
				if ( is_string( $value ) && preg_match( '/^[a-zA-Z]{2,20}$/', (string) $key ) ) {
					$texts[ $lang ][ $key ] = self::text( $value );
				}
			}
		}
		$c['texts'] = $texts;

		$privacy = array();
		foreach ( (array) ( $c['privacyText'] ?? array() ) as $lang => $html ) {
			$lang = self::lang_code( $lang );
			if ( $lang && is_string( $html ) ) {
				$privacy[ $lang ] = wp_kses_post( $html );
			}
		}
		$c['privacyText'] = $privacy;

		$c['categories'] = self::sanitize_categories( (array) $c['categories'] );
		$ids             = wp_list_pluck( $c['categories'], 'id' );
		$services        = array();
		$seen            = array();
		foreach ( (array) $c['services'] as $service ) {
			if ( ! is_array( $service ) ) {
				continue;
			}
			$service = self::sanitize_service( $service );
			if ( ! $service['id'] || isset( $seen[ $service['id'] ] ) ) {
				continue;
			}
			if ( ! in_array( $service['category'], $ids, true ) ) {
				$service['category'] = 'functional';
			}
			$seen[ $service['id'] ] = true;
			$services[]             = $service;
		}
		$c['services'] = $services;

		$g           = (array) $c['google'];
		$c['google'] = array(
			'consentMode'    => ! empty( $g['consentMode'] ),
			'mode'           => self::one_of( $g['mode'] ?? '', array( 'basic', 'advanced' ), 'basic' ),
			'gtm'            => preg_match( '/^GTM-[A-Z0-9]{4,12}$/', strtoupper( trim( (string) ( $g['gtm'] ?? '' ) ) ) ) ? strtoupper( trim( (string) $g['gtm'] ) ) : '',
			'regions'        => array_values( array_intersect( (array) ( $g['regions'] ?? array() ), array( 'EEA', 'UK', 'CH' ) ) ),
			'everywhere'     => ! empty( $g['everywhere'] ),
			'urlPassthrough' => ! empty( $g['urlPassthrough'] ),
			'adsRedaction'   => ! empty( $g['adsRedaction'] ),
			'waitForUpdate'  => max( 0, min( 2000, (int) ( $g['waitForUpdate'] ?? 500 ) ) ),
		);

		$t               = (array) $c['thirdParty'];
		$c['thirdParty'] = array(
			'unknownIframes' => ! empty( $t['unknownIframes'] ),
			'thumbnails'     => ! empty( $t['thumbnails'] ),
			'always'         => ! empty( $t['always'] ),
			'nocookie'       => ! empty( $t['nocookie'] ),
			'gravatar'       => self::one_of( $t['gravatar'] ?? '', array( 'proxy', 'off', 'allow' ), 'proxy' ),
			'emoji'          => ! empty( $t['emoji'] ),
			'hints'          => ! empty( $t['hints'] ),
		);

		$c['validity']  = array(
			'months'        => max( 1, min( 12, (int) ( $c['validity']['months'] ?? 6 ) ) ),
			'reaskOnChange' => ! empty( $c['validity']['reaskOnChange'] ),
			'gpc'           => ! empty( $c['validity']['gpc'] ),
		);
		$c['reopen']    = array(
			'button'   => ! empty( $c['reopen']['button'] ),
			'position' => self::one_of( $c['reopen']['position'] ?? '', array( 'left', 'right' ), 'left' ),
		);
		$c['pages']     = array(
			'privacy' => max( 0, (int) ( $c['pages']['privacy'] ?? 0 ) ),
			'imprint' => max( 0, (int) ( $c['pages']['imprint'] ?? 0 ) ),
			'small'   => ! empty( $c['pages']['small'] ),
		);
		$c['log']       = array( 'months' => max( 1, min( 36, (int) ( $c['log']['months'] ?? 12 ) ) ) );
		$c['assistant'] = array(
			'browser' => ! empty( $c['assistant']['browser'] ),
			'model'   => self::one_of( $c['assistant']['model'] ?? '', array( 'cheap', 'strong', 'custom' ), 'cheap' ),
			'custom'  => sanitize_text_field( (string) ( $c['assistant']['custom'] ?? '' ) ),
		);
		$c['scan']      = array( 'weekly' => ! empty( $c['scan']['weekly'] ) );

		return array_intersect_key( $c, $d );
	}

	/**
	 * The categories: known fields, unique IDs, the necessary one first and locked.
	 *
	 * @param array $categories Categories.
	 * @return array
	 */
	private static function sanitize_categories( array $categories ) {
		$out  = array();
		$seen = array();
		foreach ( $categories as $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			$id = sanitize_key( (string) ( $cat['id'] ?? '' ) );
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'id'          => $id,
				'locked'      => 'necessary' === $id,
				'enabled'     => 'necessary' === $id || ! array_key_exists( 'enabled', $cat ) || ! empty( $cat['enabled'] ),
				'name'        => self::lang_map( $cat['name'] ?? array() ),
				'description' => self::lang_map( $cat['description'] ?? array() ),
			);
		}
		foreach ( self::default_categories() as $default ) {
			if ( ! isset( $seen[ $default['id'] ] ) ) {
				$out[] = $default;
			}
		}
		usort( $out, static fn( $a, $b ) => ( 'necessary' === $b['id'] ) <=> ( 'necessary' === $a['id'] ) );
		return $out;
	}

	/**
	 * One service: known fields in their types.
	 *
	 * @param array $s A service.
	 * @return array
	 */
	public static function sanitize_service( array $s ) {
		$kinds   = array( 'script', 'embed', 'font', 'style', 'library', 'image', 'avatar', 'infrastructure', 'core' );
		$cookies = array();
		foreach ( (array) ( $s['cookies'] ?? array() ) as $cookie ) {
			if ( ! is_array( $cookie ) || empty( $cookie['name'] ) ) {
				continue;
			}
			$cookies[] = array(
				'name'     => sanitize_text_field( (string) $cookie['name'] ),
				'duration' => preg_match( '/^(session|persistent|\d+(min|h|d|w|mo|y))$/', (string) ( $cookie['duration'] ?? '' ) ) ? (string) $cookie['duration'] : 'session',
				'type'     => self::one_of( $cookie['type'] ?? '', array( 'http', 'local', 'session' ), 'http' ),
			);
		}
		$strings = static fn( $values ) => array_values( array_filter( array_map( static fn( $v ) => sanitize_text_field( (string) $v ), (array) $values ) ) );
		$out     = array(
			'id'       => sanitize_key( (string) ( $s['id'] ?? '' ) ),
			'preset'   => sanitize_key( (string) ( $s['preset'] ?? '' ) ),
			'name'     => sanitize_text_field( (string) ( $s['name'] ?? '' ) ),
			'category' => sanitize_key( (string) ( $s['category'] ?? 'functional' ) ),
			'kind'     => self::one_of( $s['kind'] ?? '', $kinds, 'script' ),
			'provider' => array(
				'name'    => sanitize_text_field( (string) ( $s['provider']['name'] ?? '' ) ),
				'address' => sanitize_text_field( (string) ( $s['provider']['address'] ?? '' ) ),
			),
			'purpose'  => self::lang_map( $s['purpose'] ?? array() ),
			'privacy'  => esc_url_raw( (string) ( $s['privacy'] ?? '' ) ),
			'cookies'  => $cookies,
			'hosts'    => array_map( 'strtolower', $strings( $s['hosts'] ?? array() ) ),
			'patterns' => array_values( array_filter( array_map( static fn( $v ) => trim( wp_check_invalid_utf8( (string) $v ) ), (array) ( $s['patterns'] ?? array() ) ) ) ),
			'handles'  => array_values( array_filter( array_map( 'sanitize_key', (array) ( $s['handles'] ?? array() ) ) ) ),
			'strict'   => ! empty( $s['strict'] ),
			'signals'  => array_values( array_intersect( (array) ( $s['signals'] ?? array() ), self::SIGNALS ) ),
			// Who may change a snippet is checked where it comes in (Cookie_Dialog_Rest), not on every save.
			'snippet'  => (string) ( $s['snippet'] ?? '' ),
			'source'   => self::one_of( $s['source'] ?? '', array( 'library', 'scan', 'assistant', 'custom' ), 'custom' ),
			'added'    => (int) ( $s['added'] ?? time() ),
		);
		if ( ! empty( $s['embed'] ) && is_array( $s['embed'] ) ) {
			$out['embed'] = array(
				'type'    => self::one_of( $s['embed']['type'] ?? '', array( 'video', 'audio', 'map', 'social', 'calendar', 'form', 'other' ), 'other' ),
				'label'   => self::lang_map( $s['embed']['label'] ?? array() ),
				'thumb'   => ! empty( $s['embed']['thumb'] ),
				'address' => sanitize_text_field( (string) ( $s['embed']['address'] ?? '' ) ),
			);
		}
		if ( in_array( $out['kind'], self::LOCAL_KINDS, true ) ) {
			$out['local'] = ! empty( $s['local'] );
		}
		return $out;
	}

	/**
	 * A `{ lang: text }` map with sanitized texts.
	 *
	 * @param mixed $map Map or a string (taken as English).
	 * @return array
	 */
	private static function lang_map( $map ) {
		if ( is_string( $map ) ) {
			$map = array( 'en' => $map );
		}
		$out = array();
		foreach ( (array) $map as $lang => $text ) {
			$lang = self::lang_code( $lang );
			if ( $lang && is_string( $text ) ) {
				$out[ $lang ] = self::text( $text );
			}
		}
		return $out;
	}

	/**
	 * A dialog text: plain, with links, emphasis and line breaks.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text( $text ) {
		return trim(
			wp_kses(
				$text,
				array(
					'a'      => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
					'strong' => array(),
					'em'     => array(),
					'br'     => array(),
				)
			)
		);
	}

	/**
	 * A language code: two or three letters, optionally a region (`pt-br`).
	 *
	 * @param mixed $lang Code.
	 * @return string
	 */
	public static function lang_code( $lang ) {
		$lang = strtolower( str_replace( '_', '-', trim( (string) $lang ) ) );
		return preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,4})?$/', $lang ) ? $lang : '';
	}

	/**
	 * A CSS font-family list without anything that could leave the declaration.
	 *
	 * @param mixed $family Family.
	 * @return string
	 */
	private static function font_family( $family ) {
		return trim( (string) preg_replace( '/[^a-zA-Z0-9 ,\'"\-_.]/', '', (string) $family ) );
	}

	/**
	 * The value when it is one of the allowed, else the fallback.
	 *
	 * @param mixed  $value    Value.
	 * @param array  $allowed  Allowed values.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function one_of( $value, array $allowed, $fallback ) {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * A cookie's duration in words: `13mo` → „13 Monate“.
	 *
	 * @param string $duration Duration code.
	 * @param array  $texts    The language's texts (for the units).
	 * @return string
	 */
	public static function duration( $duration, $texts ) {
		$u = $texts['units'];
		if ( 'persistent' === $duration ) {
			return $u[13] ?? 'persistent';
		}
		if ( ! preg_match( '/^(\d+)(min|h|d|w|mo|y)$/', (string) $duration, $m ) ) {
			return $u[0];
		}
		$n     = (int) $m[1];
		$index = array(
			'min' => 1,
			'h'   => 3,
			'd'   => 5,
			'w'   => 7,
			'mo'  => 9,
			'y'   => 11,
		)[ $m[2] ];
		return $n . ' ' . $u[ 1 === $n ? $index : $index + 1 ];
	}

	/**
	 * The registrable domain of a host: `cdn.example.co.uk` → `example.co.uk`.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	public static function base_domain( $host ) {
		$host  = strtolower( (string) $host );
		$parts = explode( '.', $host );
		if ( count( $parts ) <= 2 ) {
			return $host;
		}
		$two = implode( '.', array_slice( $parts, -2 ) );
		if ( preg_match( '/^(co|com|org|net|gv|or|ac|gov|edu)\.[a-z]{2}$/', $two ) ) {
			return implode( '.', array_slice( $parts, -3 ) );
		}
		return $two;
	}

	/**
	 * Whether a host belongs to the site: its own host or a subdomain of its domain.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_own_host( $host ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return true;
		}
		static $own = null;
		if ( null === $own ) {
			$own = array();
			foreach ( array( home_url(), site_url(), content_url() ) as $url ) {
				$h = (string) wp_parse_url( $url, PHP_URL_HOST );
				if ( $h ) {
					$own[ strtolower( $h ) ] = self::base_domain( $h );
				}
			}
		}
		if ( isset( $own[ $host ] ) ) {
			return true;
		}
		$base = self::base_domain( $host );
		foreach ( $own as $own_base ) {
			if ( $base === $own_base && ! preg_match( '/^(localhost|\d+\.\d+\.\d+\.\d+)$/', $base ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The configured service a URL or an inline script belongs to.
	 *
	 * @param string     $subject  A URL, or a script's body.
	 * @param bool       $is_url   Whether the subject is a URL.
	 * @param array|null $services Services to look in; the configured ones by default.
	 * @return array|null
	 */
	public static function match( $subject, $is_url = true, $services = null ) {
		$subject = (string) $subject;
		if ( '' === $subject ) {
			return null;
		}
		$services = $services ?? self::get()['services'];
		$host     = $is_url ? strtolower( (string) wp_parse_url( self::absolute( $subject ), PHP_URL_HOST ) ) : '';
		if ( $is_url && ( '' === $host || self::is_own_host( $host ) ) ) {
			// The site's own files belong to a service only by a pattern (a plugin's tracking script).
			$host = '';
		}
		$best       = null;
		$best_score = 0;
		foreach ( $services as $service ) {
			$score = self::score( $service, $subject, $host, $is_url );
			if ( $score > $best_score ) {
				$best       = $service;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * How well a service matches: a pattern counts more than a host; strict services need a pattern.
	 *
	 * @param array  $service A service or preset.
	 * @param string $subject URL or script body.
	 * @param string $host    The URL's host, empty for own files and inline scripts.
	 * @param bool   $is_url  Whether the subject is a URL.
	 * @return int
	 */
	public static function score( $service, $subject, $host, $is_url ) {
		$pattern = 0;
		foreach ( (array) ( $service['patterns'] ?? array() ) as $p ) {
			if ( '' !== $p && false !== stripos( $subject, $p ) ) {
				$pattern = max( $pattern, 100 + strlen( $p ) );
			}
		}
		if ( ! empty( $service['strict'] ) ) {
			return $pattern;
		}
		$by_host = 0;
		foreach ( (array) ( $service['hosts'] ?? array() ) as $h ) {
			if ( '' === $h ) {
				continue;
			}
			if ( $is_url ) {
				if ( $host && ( $host === $h || str_ends_with( $host, '.' . $h ) ) ) {
					$by_host = max( $by_host, 10 + strlen( $h ) );
				}
			} elseif ( false !== stripos( $subject, $h ) ) {
				$by_host = max( $by_host, 10 + strlen( $h ) );
			}
		}
		return max( $pattern, $by_host );
	}

	/**
	 * The best preset of the library for a URL or script, for a host nobody assigned yet.
	 *
	 * @param string $subject URL or script body.
	 * @param bool   $is_url  Whether it is a URL.
	 * @return array|null
	 */
	public static function match_preset( $subject, $is_url = true ) {
		return self::match( $subject, $is_url, array_values( self::library() ) );
	}

	/**
	 * A protocol-relative or root-relative URL made absolute.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function absolute( $url ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES ) );
		if ( str_starts_with( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return home_url( $url );
		}
		return $url;
	}
}

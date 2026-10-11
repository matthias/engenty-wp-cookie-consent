<?php
/**
 * The site's side: the Consent Mode default first in the head, the dialog's configuration, its
 * script and style, Tag Manager, the snippets of services, the legal pages.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nothing changes on the site until the dialog is switched on – except in the previews, which only
 * the person who manages the site sees: the admin's frame and `?cookie-dialog=preview` on the site.
 */
class Cookie_Dialog_Front {

	/** The session cookie that keeps the preview on the site while the admin clicks around. */
	const PREVIEW_COOKIE = 'cookie_dialog_preview';

	/** Hooks the head, the scripts and the footer. */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'preview_switch' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), -1000 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_snippets' ), 5 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
	}

	/**
	 * Whether this request shows the dialog: live, or the admin's preview.
	 *
	 * @return bool
	 */
	public static function active() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_embed() ) {
			return false;
		}
		/**
		 * Filters whether the dialog shows on this request (e.g. not on a landing page for an app).
		 *
		 * @param bool $active Whether it shows.
		 */
		return (bool) apply_filters( 'cookie_dialog_active', Cookie_Dialog_Config::live() || self::preview() || self::site_preview() );
	}

	/**
	 * The preview on the site: the dialog as a new visitor sees it – also before it is switched on,
	 * whatever the admin chose – and nothing is stored. `?cookie-dialog=preview` starts it,
	 * `?cookie-dialog=end` ends it; in between a session cookie keeps it on every page.
	 *
	 * @return bool
	 */
	public static function site_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view for administrators.
		$ask = isset( $_GET['cookie-dialog'] ) ? sanitize_key( wp_unslash( $_GET['cookie-dialog'] ) ) : '';
		if ( 'end' === $ask ) {
			return false;
		}
		return 'preview' === $ask || ! empty( $_COOKIE[ self::PREVIEW_COOKIE ] );
	}

	/** Starts or ends the preview on the site. */
	public static function preview_switch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only switches a view for administrators.
		$ask = isset( $_GET['cookie-dialog'] ) ? sanitize_key( wp_unslash( $_GET['cookie-dialog'] ) ) : '';
		if ( ! in_array( $ask, array( 'preview', 'end' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cookie = array(
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( 'preview' === $ask ) {
			setcookie( self::PREVIEW_COOKIE, '1', array( 'expires' => 0 ) + $cookie );
			$_COOKIE[ self::PREVIEW_COOKIE ] = '1';
			return;
		}
		setcookie( self::PREVIEW_COOKIE, '', array( 'expires' => time() - YEAR_IN_SECONDS ) + $cookie );
		unset( $_COOKIE[ self::PREVIEW_COOKIE ] );
		wp_safe_redirect( remove_query_arg( 'cookie-dialog' ) );
		exit;
	}

	/**
	 * The admin's preview: `?cookie-dialog-preview=1`, only for those who manage the site.
	 *
	 * @return array|false `layer`, `edit`, `lang`.
	 */
	public static function preview() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view for administrators.
		if ( empty( $_GET['cookie-dialog-preview'] ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return array(
			'layer' => isset( $_GET['layer'] ) ? Cookie_Dialog_Config::one_of( sanitize_key( wp_unslash( $_GET['layer'] ) ), array( '1', '2', 'ph' ), '1' ) : '1',
			'edit'  => ! empty( $_GET['edit'] ),
			'lang'  => isset( $_GET['lang'] ) ? Cookie_Dialog_Config::lang_code( sanitize_key( wp_unslash( $_GET['lang'] ) ) ) : '',
		);
		// phpcs:enable
	}

	/**
	 * In the preview, the admin bar stays away: the frame shows the site as a visitor sees it.
	 *
	 * @param bool $show Whether it shows.
	 * @return bool
	 */
	public static function admin_bar( $show ) {
		return self::preview() || self::site_preview() ? false : $show;
	}

	/**
	 * First thing in the head: Google's Consent Mode default – everything denied until a choice –
	 * and the choice of a returning visitor, then the dialog's configuration and Tag Manager.
	 */
	public static function head() {
		if ( ! self::active() ) {
			return;
		}
		$config = Cookie_Dialog_Config::get();
		$data   = self::data( $config );
		$g      = $config['google'];
		if ( $g['consentMode'] ) {
			$denied  = array_fill_keys( Cookie_Dialog_Config::SIGNALS, 'denied' ) + array( 'security_storage' => 'granted' );
			$granted = array_fill_keys( Cookie_Dialog_Config::SIGNALS, 'granted' ) + array( 'security_storage' => 'granted' );
			$js      = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}';
			if ( ! $g['everywhere'] && $g['regions'] ) {
				// Outside the listed regions Google may measure; the dialog still holds the tags back.
				$js     .= 'gtag("consent","default",' . wp_json_encode( $granted ) . ');';
				$regions = array();
				foreach ( $g['regions'] as $region ) {
					$regions = array_merge( $regions, self::REGIONS[ $region ] ?? array() );
				}
				$js .= 'gtag("consent","default",' . wp_json_encode(
					$denied + array(
						'region'          => $regions,
						'wait_for_update' => $g['waitForUpdate'],
					)
				) . ');';
			} else {
				$js .= 'gtag("consent","default",' . wp_json_encode( $denied + array( 'wait_for_update' => $g['waitForUpdate'] ) ) . ');';
			}
			$js .= 'gtag("set","ads_data_redaction",' . ( $g['adsRedaction'] ? 'true' : 'false' ) . ');';
			$js .= 'gtag("set","url_passthrough",' . ( $g['urlPassthrough'] ? 'true' : 'false' ) . ');';
			// A returning visitor's choice, before any tag: the same rules the dialog applies. Not in the
			// preview on the site, where the admin is a new visitor.
			$js .= self::site_preview() ? '' : sprintf(
				'(function(){try{var m=document.cookie.match(/(?:^|; )%1$s=([^;]*)/);if(!m)return;var c=JSON.parse(decodeURIComponent(m[1]));if(!c||c.v<%2$d||c.t<Date.now()/1e3-%3$d)return;var cs=%4$s,ss=%5$s,u={};(c.c||[]).forEach(function(k){(cs[k]||[]).forEach(function(s){u[s]="granted"})});(c.s||[]).forEach(function(k){(ss[k]||[]).forEach(function(s){u[s]="granted"})});if(Object.keys(u).length)gtag("consent","update",u)}catch(e){}})();',
				COOKIE_DIALOG_COOKIE,
				(int) $config['reask'],
				(int) $config['validity']['months'] * MONTH_IN_SECONDS,
				wp_json_encode( (object) $data['catSignals'] ),
				wp_json_encode( (object) $data['serviceSignals'] )
			);
			wp_print_inline_script_tag( $js, array( 'data-cookie-dialog' => 'consent-mode' ) );
		}
		printf(
			'<script type="application/json" id="cookie-dialog-config" data-cookie-dialog="config">%s</script>' . "\n",
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
		if ( $g['gtm'] ) {
			$gtm = sprintf(
				'(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer",%s);',
				wp_json_encode( $g['gtm'] )
			);
			if ( 'advanced' === $g['mode'] ) {
				// Advanced: tags load and send cookieless pings until consent.
				wp_print_inline_script_tag( $gtm, array( 'data-cookie-dialog' => 'gtm' ) );
			} else {
				// Basic: the container waits for consent to statistics or marketing.
				printf( '<script type="text/plain" data-cookie-category="statistics marketing" data-cookie-dialog-held="gtm">%s</script>' . "\n", $gtm ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a sanitized container ID.
			}
		}
		self::snippets( 'head' );
	}

	/** Snippets that belong before `</body>`. */
	public static function footer_snippets() {
		if ( self::active() ) {
			self::snippets( 'footer' );
		}
	}

	/**
	 * The snippets pasted in the admin: printed held back, released on consent without a reload.
	 * A snippet with `<noscript>` loses it: a pixel there would fire without consent.
	 *
	 * @param string $where `head` or `footer`.
	 */
	private static function snippets( $where ) {
		foreach ( Cookie_Dialog_Config::get()['services'] as $service ) {
			$snippet = trim( (string) $service['snippet'] );
			if ( '' === $snippet ) {
				continue;
			}
			$in_footer = (bool) preg_match( '/<(iframe|div|img)\b/i', $snippet );
			if ( ( 'footer' === $where ) !== $in_footer ) {
				continue;
			}
			echo Cookie_Dialog_Blocker::hold_html( $snippet, $service ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the site owner's snippet, held back.
		}
	}

	/** Region codes Google's Consent Mode takes for the EEA, the UK and Switzerland. */
	const REGIONS = array(
		'EEA' => array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO' ),
		'UK'  => array( 'GB' ),
		'CH'  => array( 'CH' ),
	);

	/** The dialog's script (deferred) and style. */
	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		$asset = COOKIE_DIALOG_DIR . 'build/dialog.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array(),
			'version'      => COOKIE_DIALOG_VERSION,
		);
		wp_enqueue_style( 'cookie-dialog', COOKIE_DIALOG_URL . 'build/dialog.css', array(), $meta['version'] );
		// Placeholders sit on the page and take the dialog's button colours.
		$design = Cookie_Dialog_Config::get()['design'];
		wp_add_inline_style(
			'cookie-dialog',
			sprintf(
				':root{--cookie-dialog-btn:%s;--cookie-dialog-btn-fg:%s;--cookie-dialog-r:%dpx}',
				$design['colors']['button'],
				$design['colors']['buttonText'],
				(int) $design['radius']
			)
		);
		wp_enqueue_script(
			'cookie-dialog',
			COOKIE_DIALOG_URL . 'build/dialog.js',
			array(),
			$meta['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Whether this page is the privacy policy or the imprint: there the dialog shows small, so the
	 * texts can be read before deciding.
	 *
	 * @param array $config Configuration.
	 * @return bool
	 */
	private static function legal_page( $config ) {
		$ids = array_filter( array( (int) $config['pages']['privacy'], (int) $config['pages']['imprint'] ) );
		return $ids && is_page( $ids );
	}

	/**
	 * A page's address in the current language (Polylang, WPML).
	 *
	 * @param int $id Page ID.
	 * @return string
	 */
	private static function page_url( $id ) {
		if ( ! $id ) {
			return '';
		}
		$translated = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $id ) : (int) apply_filters( 'wpml_object_id', $id, 'page', true );
		$url        = get_permalink( $translated ? $translated : $id );
		return $url ? $url : '';
	}

	/**
	 * The words of one language: the dialog's texts, the services and categories as visitors read them.
	 *
	 * @param array  $config Configuration.
	 * @param string $lang   Language.
	 * @return array `texts`, `services`, `categories`, `serviceSignals`.
	 */
	private static function localized( $config, $lang ) {
		$texts = Cookie_Dialog_Config::texts( $lang, $config );
		$type  = array_combine( array( 'http', 'local', 'session' ), $texts['types'] );

		$services        = array();
		$service_signals = array();
		foreach ( Cookie_Dialog_Config::dialog_services( $config ) as $s ) {
			$services[] = array(
				'id'       => $s['id'],
				'name'     => $s['name'],
				'cat'      => $s['category'],
				'provider' => $s['provider']['name'],
				'address'  => $s['provider']['address'],
				'purpose'  => Cookie_Dialog_Config::pick( $s['purpose'], $lang ),
				'privacy'  => $s['privacy'],
				'cookies'  => array_map(
					static fn( $c ) => array( $c['name'], Cookie_Dialog_Config::duration( $c['duration'], $texts ), $type[ $c['type'] ] ?? 'HTTP' ),
					$s['cookies']
				),
				'embed'    => isset( $s['embed'] ) ? array(
					'type'  => $s['embed']['type'],
					'label' => Cookie_Dialog_Config::pick( $s['embed']['label'], $lang ),
				) : null,
			);
			// Hosts of embeds that wait: an iframe a script adds later becomes a placeholder too.
			if ( 'embed' === $s['kind'] && Cookie_Dialog_Config::is_held( $s ) ) {
				$services[ count( $services ) - 1 ]['hosts'] = array_values( array_filter( $s['hosts'], static fn( $h ) => ! str_contains( $h, '/' ) ) );
			}
			if ( $s['signals'] ) {
				$service_signals[ $s['id'] ] = $s['signals'];
			}
		}
		unset( $texts['units'], $texts['types'] );

		$categories = array();
		foreach ( Cookie_Dialog_Config::dialog_categories( $config ) as $cat ) {
			$categories[] = array(
				'id'     => $cat['id'],
				'name'   => Cookie_Dialog_Config::pick( $cat['name'], $lang ),
				'desc'   => Cookie_Dialog_Config::pick( $cat['description'], $lang ),
				'locked' => (bool) $cat['locked'],
			);
		}

		return array(
			'texts'          => $texts,
			'services'       => $services,
			'categories'     => $categories,
			'serviceSignals' => $service_signals,
		);
	}

	/**
	 * What the dialog needs on the site: texts of this language, design, categories and services
	 * as visitors see them, the Consent Mode mapping. Nothing about the visitor – page caches keep it.
	 *
	 * @param array       $config Configuration.
	 * @param string|null $lang   Language; the request's by default.
	 * @return array
	 */
	public static function data( $config, $lang = null ) {
		$preview = self::preview();
		$lang    = $lang ?? ( $preview && $preview['lang'] && in_array( $preview['lang'], $config['languages'], true ) ? $preview['lang'] : Cookie_Dialog_Config::lang() );
		$here    = self::localized( $config, $lang );

		// The language choice in the dialog switches without a reload: every language's words come along.
		$langs = false;
		if ( count( $config['languages'] ) > 1 && ( $config['design']['showLanguages'] || $preview ) ) {
			$langs = array();
			foreach ( $config['languages'] as $code ) {
				$langs[ $code ] = $code === $lang ? $here : self::localized( $config, $code );
				unset( $langs[ $code ]['serviceSignals'] );
			}
		}

		return array(
			'v'              => (int) $config['version'],
			'reask'          => (int) $config['reask'],
			'live'           => (bool) $config['live'],
			'lang'           => $lang,
			'site'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'texts'          => $here['texts'],
			'langs'          => $langs,
			'design'         => $config['design'],
			'categories'     => $here['categories'],
			'services'       => $here['services'],
			'catSignals'     => Cookie_Dialog_Config::CATEGORY_SIGNALS,
			'serviceSignals' => $here['serviceSignals'],
			'consentMode'    => (bool) $config['google']['consentMode'],
			'months'         => (int) $config['validity']['months'],
			'gpc'            => (bool) $config['validity']['gpc'],
			'reopen'         => $config['reopen'],
			'privacy'        => self::page_url( (int) $config['pages']['privacy'] ),
			'imprint'        => self::page_url( (int) $config['pages']['imprint'] ),
			'small'          => $config['pages']['small'] && self::legal_page( $config ),
			'unknownIframes' => (bool) $config['thirdParty']['unknownIframes'],
			'nocookie'       => (bool) $config['thirdParty']['nocookie'],
			'rest'           => rest_url( 'cookie-dialog/v1/consent' ),
			'preview'        => $preview ? $preview : false,
			// Only for a logged-in admin, whom page caches leave out.
			'sitePreview'    => self::site_preview() ? array(
				'label'   => __( 'Vorschau', 'cookie-dialog' ),
				'note'    => __( 'wie ein neuer Besucher – nichts wird gespeichert', 'cookie-dialog' ),
				'title'   => __( 'Vorschau: So sieht ein neuer Besucher den Dialog. Deine Wahl hier wird weder gespeichert noch protokolliert.', 'cookie-dialog' ),
				'restart' => __( 'Neu starten', 'cookie-dialog' ),
				'end'     => __( 'Beenden', 'cookie-dialog' ),
				'endUrl'  => add_query_arg( 'cookie-dialog', 'end' ),
			) : false,
		);
	}
}

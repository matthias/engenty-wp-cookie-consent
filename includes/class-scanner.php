<?php
/**
 * Reads the site as a visitor without consent: the home page and up to 20 more, every host a
 * browser would reach, cookies set before a choice, the active plugins, the theme's colours,
 * fonts and logo, the legal pages. Then a proposal for the whole configuration.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * The scan parses HTML (no real browser): what scripts inject later is found by the library's
 * patterns where the injecting script is known.
 */
class Cookie_Dialog_Scanner {

	const RESULT = 'cookie_dialog_scan';

	/** Hooks the weekly re-scan. */
	public static function init() {
		add_action( 'cookie_dialog_weekly', array( __CLASS__, 'weekly' ) );
	}

	/** Once a week, when switched on: a new scan; new hosts show under „Zu prüfen“. */
	public static function weekly() {
		if ( Cookie_Dialog_Config::get()['scan']['weekly'] ) {
			self::run();
		}
	}

	/**
	 * The last scan.
	 *
	 * @return array
	 */
	public static function result() {
		$result = get_option( self::RESULT, array() );
		return array_merge(
			array(
				'at'          => 0,
				'pages'       => array(),
				'hosts'       => array(),
				'cookies'     => array(),
				'plugins'     => array(),
				'theme'       => array(),
				'consentMode' => null,
				'reopen'      => false,
				'privacy'     => array(),
				'errors'      => array(),
				'counts'      => array(),
			),
			is_array( $result ) ? $result : array()
		);
	}

	/**
	 * The pages to read: home, legal pages, the menus' pages, recent posts, posts with embeds.
	 *
	 * @param int $limit At most.
	 * @return array URLs.
	 */
	public static function pages( $limit = 20 ) {
		$urls   = array( home_url( '/' ) );
		$config = Cookie_Dialog_Config::get();
		foreach ( array( $config['pages']['privacy'], $config['pages']['imprint'], self::find_imprint() ) as $id ) {
			if ( $id && 'publish' === get_post_status( $id ) ) {
				$urls[] = get_permalink( $id );
			}
		}
		foreach ( get_nav_menu_locations() as $menu_id ) {
			foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
				if ( isset( $item->url ) && ! Cookie_Dialog_Html::third_party( $item->url ) && ! str_contains( (string) $item->url, '#' ) ) {
					$urls[] = $item->url;
				}
			}
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a one-off search for embeds.
		$with_embeds = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND (post_content LIKE '%<iframe%' OR post_content LIKE '%wp:embed%' OR post_content LIKE '%[embed%' OR post_content LIKE '%<script%') ORDER BY post_modified DESC LIMIT 6" );
		foreach ( $with_embeds as $id ) {
			$urls[] = get_permalink( (int) $id );
		}
		$recent = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'posts_per_page' => 12,
				'orderby'        => 'menu_order modified',
				'fields'         => 'ids',
			)
		);
		foreach ( $recent as $id ) {
			$urls[] = get_permalink( $id );
		}
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout' ) as $page ) {
				$id = wc_get_page_id( $page );
				if ( $id > 0 ) {
					$urls[] = get_permalink( $id );
				}
			}
		}
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		return array_slice( $urls, 0, max( 1, (int) $limit ) );
	}

	/**
	 * The imprint page, found by its slug or title.
	 *
	 * @return int
	 */
	public static function find_imprint() {
		foreach ( array( 'impressum', 'imprint', 'legal-notice', 'offenlegung', 'mentions-legales', 'colofon', 'aviso-legal', 'note-legali' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				return (int) $page->ID;
			}
		}
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'posts_per_page' => 1,
				's'              => 'Impressum',
				'search_columns' => array( 'post_title' ),
				'fields'         => 'ids',
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Fetches pages in parallel, without cookies and past page caches.
	 *
	 * @param array $urls URLs.
	 * @return array URL → [ status, body, cookies ].
	 */
	private static function fetch( array $urls ) {
		$token    = wp_generate_password( 12, false );
		$requests = array();
		foreach ( $urls as $url ) {
			$requests[ $url ] = array(
				'url'     => add_query_arg( 'cookie-dialog-scan', $token, $url ),
				'type'    => 'GET',
				'headers' => array(
					'Cache-Control' => 'no-cache',
					'Accept'        => 'text/html',
				),
			);
		}
		$options = array(
			'timeout'   => 25,
			'verify'    => false,
			'useragent' => 'Mozilla/5.0 (compatible; CookieDialogScan/' . COOKIE_DIALOG_VERSION . '; +' . home_url( '/' ) . ')',
		);
		/**
		 * Filters the HTTP options of the scan (timeouts, a proxy, basic auth of a staging site).
		 *
		 * @param array $options Requests options.
		 */
		$options = (array) apply_filters( 'cookie_dialog_scan_options', $options );
		$out     = array();
		try {
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
		} catch ( \Throwable $e ) {
			$responses = array();
		}
		foreach ( $urls as $url ) {
			$r = $responses[ $url ] ?? null;
			if ( ! $r instanceof \WpOrg\Requests\Response ) {
				$out[ $url ] = array(
					'status'  => 0,
					'body'    => '',
					'cookies' => array(),
					'error'   => $r instanceof \Throwable ? $r->getMessage() : 'no response',
				);
				continue;
			}
			$cookies = array();
			$header  = $r->headers->getValues( 'set-cookie' );
			foreach ( (array) $header as $line ) {
				$name = trim( (string) strtok( (string) $line, '=' ) );
				if ( $name ) {
					$cookies[] = $name;
				}
			}
			$out[ $url ] = array(
				'status'  => (int) $r->status_code,
				'body'    => (string) $r->body,
				'cookies' => $cookies,
				'error'   => '',
			);
		}
		return $out;
	}

	/**
	 * Reads the site and keeps the result.
	 *
	 * @param int        $limit Pages at most.
	 * @param array|null $pages Fetched pages for a test: URL → [ status, body, cookies ].
	 * @return array The result.
	 */
	public static function run( $limit = 20, $pages = null ) {
		$before  = self::result();
		$urls    = $pages ? array_keys( $pages ) : self::pages( $limit );
		$pages   = $pages ?? self::fetch( $urls );
		$config  = Cookie_Dialog_Config::get();
		$hosts   = array();
		$cookies = array();
		$errors  = array();
		$found   = array();
		$cm      = null;
		$reopen  = false;
		$inline  = array();
		$home    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		foreach ( $pages as $url => $page ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$path = $path ? $path : '/';
			if ( $page['error'] || $page['status'] >= 400 || '' === $page['body'] ) {
				$errors[] = array(
					'url'     => $url,
					'message' => $page['error'] ? $page['error'] : (string) $page['status'],
				);
				continue;
			}
			$body    = $page['body'];
			$found[] = array(
				'url'   => $url,
				'title' => preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ? wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES ) ) : $path,
			);
			foreach ( $page['cookies'] as $name ) {
				if ( ! in_array( $name, array( COOKIE_DIALOG_COOKIE, 'wordpress_test_cookie' ), true ) ) {
					$cookies[ $name ]            = $cookies[ $name ] ?? array(
						'name'  => $name,
						'pages' => array(),
					);
					$cookies[ $name ]['pages'][] = $path;
				}
			}
			if ( null === $cm || '/' === $path ) {
				$cm = self::consent_mode_first( $body );
			}
			if ( preg_match( '/#cookie-settings|data-cookie-settings|cookie-dialog-settings/', $body ) || ( $config['live'] && $config['reopen']['button'] ) ) {
				$reopen = true;
			}
			foreach ( Cookie_Dialog_Html::assets( $body ) as $asset ) {
				if ( 'inline' === $asset['kind'] ) {
					$preset = Cookie_Dialog_Config::match_preset( $asset['body'], false );
					if ( ! $preset ) {
						continue;
					}
					$inline[ $preset['id'] ][] = array(
						'path'    => $path,
						'held'    => $asset['held'],
						'service' => Cookie_Dialog_Config::match( $asset['body'], false ),
						'preset'  => $preset,
					);
					continue;
				}
				$host = Cookie_Dialog_Html::host( $asset['url'] );
				if ( '' === $host || $host === $home ) {
					continue;
				}
				$abs     = Cookie_Dialog_Config::absolute( $asset['url'] );
				$own     = Cookie_Dialog_Config::is_own_host( $host );
				$service = $asset['service'] ? Cookie_Dialog_Config::service( $asset['service'] ) : Cookie_Dialog_Config::match( $abs );
				$preset  = Cookie_Dialog_Config::match_preset( $abs );
				$local   = Cookie_Dialog_Resources::local( $abs );
				self::add( $hosts, $host, $asset['kind'], $abs, $path, $asset['held'] || (bool) $local, $service, $preset, $own );
			}
		}

		// Inline code belongs to the host its service loads from (the pixel's loader, gtag's config).
		foreach ( $inline as $preset_id => $items ) {
			$host = '';
			foreach ( $hosts as $h => $entry ) {
				if ( $entry['preset'] === $preset_id ) {
					$host = $h;
					break;
				}
			}
			$host = $host ? $host : (string) ( $items[0]['preset']['hosts'][0] ?? $preset_id );
			foreach ( $items as $item ) {
				self::add( $hosts, $host, 'script', '', $item['path'], $item['held'], $item['service'], $item['preset'], false );
			}
		}

		// New since the last scan.
		foreach ( $hosts as $host => &$entry ) {
			$prev             = $before['hosts'][ $host ] ?? null;
			$entry['first']   = $prev['first'] ?? time();
			$entry['new']     = $before['at'] && ! $prev;
			$entry['suggest'] = $prev['suggest'] ?? null;
		}
		unset( $entry );

		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			foreach ( Cookie_Dialog_Config::library() as $preset ) {
				if ( in_array( $file, (array) ( $preset['plugins'] ?? array() ), true ) ) {
					$plugins[ $file ][] = $preset['id'];
				}
			}
		}

		$result = array(
			'at'          => time(),
			'pages'       => $found,
			'hosts'       => $hosts,
			'cookies'     => array_values( $cookies ),
			'plugins'     => $plugins,
			'theme'       => self::theme(),
			'consentMode' => $cm,
			'reopen'      => $reopen,
			'privacy'     => self::privacy_mentions( $config ),
			'errors'      => $errors,
			'counts'      => array(
				'pages'   => count( $found ),
				'scripts' => self::count_scripts( $pages ),
				'embeds'  => count( array_filter( $hosts, static fn( $h ) => in_array( 'iframe', $h['kinds'], true ) ) ),
				'plugins' => count( (array) get_option( 'active_plugins', array() ) ),
			),
		);
		update_option( self::RESULT, $result, false );
		return $result;
	}

	/**
	 * Adds one found URL to its host.
	 *
	 * @param array      $hosts   The hosts.
	 * @param string     $host    Host.
	 * @param string     $kind    script, style, font, image, iframe, hint, media.
	 * @param string     $url     URL ('' for an inline script).
	 * @param string     $path    The page's path.
	 * @param bool       $held    Whether it waits for consent (or comes from the site).
	 * @param array|null $service The configured service.
	 * @param array|null $preset  The library's preset.
	 * @param bool       $own     Whether the host belongs to the site.
	 */
	private static function add( array &$hosts, $host, $kind, $url, $path, $held, $service, $preset, $own ) {
		$entry = $hosts[ $host ] ?? array(
			'host'    => $host,
			'kinds'   => array(),
			'urls'    => array(),
			'pages'   => array(),
			'held'    => true,
			'loaded'  => false,
			'service' => null,
			'preset'  => null,
			'own'     => $own,
		);
		if ( ! in_array( $kind, $entry['kinds'], true ) ) {
			$entry['kinds'][] = $kind;
		}
		if ( $url && count( $entry['urls'] ) < 25 && ! in_array( $url, array_column( $entry['urls'], 'url' ), true ) ) {
			$entry['urls'][] = array(
				'url'  => $url,
				'kind' => $kind,
			);
		}
		if ( ! in_array( $path, $entry['pages'], true ) && count( $entry['pages'] ) < 12 ) {
			$entry['pages'][] = $path;
		}
		if ( 'hint' !== $kind ) {
			$entry['held']   = $entry['held'] && $held;
			$entry['loaded'] = $entry['loaded'] || ! $held;
		}
		if ( $service && ! $entry['service'] ) {
			$entry['service'] = $service['id'];
		}
		if ( $preset && ! $entry['preset'] ) {
			$entry['preset'] = $preset['id'];
		}
		$hosts[ $host ] = $entry;
	}

	/**
	 * How many different scripts with a source all pages load.
	 *
	 * @param array $pages Pages.
	 * @return int
	 */
	private static function count_scripts( $pages ) {
		$set = array();
		foreach ( $pages as $page ) {
			if ( preg_match_all( '#<script\b[^>]*\bsrc=["\']([^"\']+)#i', (string) $page['body'], $m ) ) {
				foreach ( $m[1] as $src ) {
					$set[ $src ] = true;
				}
			}
		}
		return count( $set );
	}

	/**
	 * Whether Google's Consent Mode default comes before every Google tag of a page; null when the
	 * page has no Google tag.
	 *
	 * @param string $html The page.
	 * @return bool|null
	 */
	public static function consent_mode_first( $html ) {
		$default = strpos( $html, 'gtag("consent","default"' );
		if ( false === $default ) {
			$default = strpos( $html, "gtag('consent','default'" );
		}
		if ( ! preg_match( '#<script\b(?![^>]*type=["\']text/plain)[^>]*(googletagmanager\.com|google-analytics\.com|googleadservices\.com)#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		return false !== $default && $default < $m[0][1];
	}

	/**
	 * Which services the privacy page names, and whether it holds the cookie table.
	 *
	 * @param array $config Configuration.
	 * @return array `id`, `table`, `missing`.
	 */
	public static function privacy_mentions( $config ) {
		$id = (int) $config['pages']['privacy'];
		if ( ! $id || ! get_post( $id ) ) {
			return array(
				'id'      => 0,
				'table'   => false,
				'missing' => array(),
			);
		}
		$content = (string) get_post_field( 'post_content', $id );
		$table   = has_block( 'cookie-dialog/cookie-table', $id ) || has_shortcode( $content, 'cookie_table' );
		$missing = array();
		if ( ! $table ) {
			foreach ( Cookie_Dialog_Config::dialog_services( $config ) as $service ) {
				if ( 'necessary' !== $service['category'] && false === stripos( $content, $service['name'] ) ) {
					$missing[] = $service['id'];
				}
			}
		}
		return array(
			'id'      => $id,
			'table'   => $table,
			'missing' => $missing,
		);
	}

	/**
	 * The theme's colours, fonts and logo, from theme.json where it has them.
	 *
	 * @return array
	 */
	public static function theme() {
		$out = array(
			'name'     => wp_get_theme()->get( 'Name' ),
			'colors'   => array(),
			'font'     => '',
			'headFont' => '',
			'logo'     => '',
		);
		if ( function_exists( 'wp_get_global_styles' ) ) {
			$styles          = wp_get_global_styles();
			$settings        = wp_get_global_settings();
			$resolve         = static function ( $value ) use ( $settings ) {
				$value = (string) $value;
				if ( preg_match( '/^var:preset\|color\|(.+)$/', $value, $m ) || preg_match( '/var\(--wp--preset--color--([a-z0-9-]+)\)/', $value, $m ) ) {
					foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
						foreach ( (array) ( $settings['color']['palette'][ $origin ] ?? array() ) as $c ) {
							if ( ( $c['slug'] ?? '' ) === $m[1] ) {
								return (string) $c['color'];
							}
						}
					}
				}
				return preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) ? $value : '';
			};
			$font            = static function ( $value ) use ( $settings ) {
				$value = (string) $value;
				if ( preg_match( '/^var:preset\|font-family\|(.+)$/', $value, $m ) || preg_match( '/var\(--wp--preset--font-family--([a-z0-9-]+)\)/', $value, $m ) ) {
					foreach ( array( 'theme', 'custom' ) as $origin ) {
						foreach ( (array) ( $settings['typography']['fontFamilies'][ $origin ] ?? array() ) as $f ) {
							if ( ( $f['slug'] ?? '' ) === $m[1] ) {
								return (string) $f['fontFamily'];
							}
						}
					}
				}
				return str_starts_with( $value, 'var' ) ? '' : $value;
			};
			$out['colors']   = array_filter(
				array(
					'background' => $resolve( $styles['color']['background'] ?? '' ),
					'text'       => $resolve( $styles['color']['text'] ?? '' ),
					'button'     => $resolve( $styles['elements']['button']['color']['background'] ?? '' ),
					'buttonText' => $resolve( $styles['elements']['button']['color']['text'] ?? '' ),
					'link'       => $resolve( $styles['elements']['link']['color']['text'] ?? '' ),
				)
			);
			$out['font']     = $font( $styles['typography']['fontFamily'] ?? '' );
			$out['headFont'] = $font( $styles['elements']['heading']['typography']['fontFamily'] ?? ( $styles['elements']['h1']['typography']['fontFamily'] ?? '' ) );
		}
		if ( empty( $out['colors']['background'] ) && get_theme_mod( 'background_color' ) ) {
			$out['colors']['background'] = '#' . ltrim( (string) get_theme_mod( 'background_color' ), '#' );
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$out['logo'] = (string) wp_get_attachment_image_url( $logo, 'medium' );
		}
		return $out;
	}

	/**
	 * The proposal: services from what was found, the look from the theme, the legal pages, the
	 * site's languages. Nothing changes on the site until the dialog is switched on.
	 *
	 * @param array|null $scan A scan; the last one by default.
	 * @return array The configuration.
	 */
	public static function propose( $scan = null ) {
		$scan     = $scan ?? self::result();
		$config   = Cookie_Dialog_Config::get();
		$library  = Cookie_Dialog_Config::library();
		$existing = wp_list_pluck( $config['services'], 'id' );
		$ids      = array( 'wordpress', 'cookie-dialog' );
		foreach ( $scan['hosts'] as $entry ) {
			if ( $entry['preset'] && ! $entry['own'] ) {
				$ids[] = $entry['preset'];
			}
		}
		foreach ( $scan['plugins'] as $presets ) {
			foreach ( $presets as $id ) {
				$ids[] = $id;
			}
		}
		foreach ( $scan['cookies'] as $cookie ) {
			if ( preg_match( '/^(__cf_bm|cf_clearance|_cfuvid)$/', $cookie['name'] ) ) {
				$ids[] = 'cloudflare';
			}
		}
		foreach ( array_unique( $ids ) as $id ) {
			if ( isset( $library[ $id ] ) && ! in_array( $id, $existing, true ) ) {
				// A script library from a CDN stays where it is unless copied per file: necessary, its host named.
				$category             = 'library' === ( $library[ $id ]['kind'] ?? '' ) ? 'necessary' : null;
				$config['services'][] = Cookie_Dialog_Config::service_from_preset( $library[ $id ], $category, 'scan' );
			}
		}

		$theme = $scan['theme'];
		if ( ! empty( $theme['colors'] ) ) {
			$colors = $config['design']['colors'];
			if ( ! empty( $theme['colors']['button'] ) ) {
				$colors['button'] = $theme['colors']['button'];
				$colors['link']   = $theme['colors']['link'] ?? $theme['colors']['button'];
			} elseif ( ! empty( $theme['colors']['link'] ) ) {
				$colors['button'] = $theme['colors']['link'];
				$colors['link']   = $theme['colors']['link'];
			}
			if ( ! empty( $theme['colors']['buttonText'] ) ) {
				$colors['buttonText'] = $theme['colors']['buttonText'];
			}
			if ( ! empty( $theme['colors']['text'] ) ) {
				$colors['text'] = $theme['colors']['text'];
			}
			if ( ! empty( $theme['colors']['background'] ) && self::light( $theme['colors']['background'] ) ) {
				$colors['background'] = $theme['colors']['background'];
			}
			if ( Cookie_Dialog_Checks::contrast( $colors['buttonText'], $colors['button'] ) < 4.5 ) {
				$colors['buttonText'] = Cookie_Dialog_Checks::contrast( '#ffffff', $colors['button'] ) >= Cookie_Dialog_Checks::contrast( '#1b1b1b', $colors['button'] ) ? '#ffffff' : '#1b1b1b';
			}
			$config['design']['colors'] = $colors;
		}
		if ( ! empty( $theme['font'] ) ) {
			$config['design']['font'] = 'theme';
		}
		$config['design']['headFont'] = (string) ( $theme['headFont'] ?? '' );
		$config['design']['logo']     = (string) ( $theme['logo'] ?? '' );
		if ( ! $config['pages']['privacy'] ) {
			$config['pages']['privacy'] = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		}
		if ( ! $config['pages']['imprint'] ) {
			$config['pages']['imprint'] = self::find_imprint();
		}
		$config['languages'] = array_values( array_unique( array_merge( $config['setup'] ? $config['languages'] : array(), Cookie_Dialog_Config::site_languages() ) ) );
		$config['setup']     = true;
		return Cookie_Dialog_Config::save( $config );
	}

	/**
	 * Whether a colour is light enough to be the dialog's background.
	 *
	 * @param string $hex Colour.
	 * @return bool
	 */
	private static function light( $hex ) {
		return Cookie_Dialog_Checks::luminance( $hex ) > 0.6;
	}
}

<?php
/**
 * Third-party files from the site's own server: fonts, stylesheets, images and – per file – script
 * libraries are copied once and the references rewritten; avatars come through the server; the
 * emoji script and hints to third parties go. Nothing to consent to, no IP leaves the site.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the map of copied files (option `cookie_dialog_local`): third-party URL → local URL.
 */
class Cookie_Dialog_Resources {

	const MAP = 'cookie_dialog_local';

	/** A browser that gets woff2 from Google Fonts. */
	const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

	/**
	 * The map of this request.
	 *
	 * @var array|null
	 */
	private static $map = null;

	/** Hooks the avatar route, the weekly check and the site's side. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'cookie_dialog_weekly', array( __CLASS__, 'check' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 0 );
	}

	/** On a page with the dialog: emoji, hints and avatars as configured. */
	public static function start() {
		if ( ! Cookie_Dialog_Front::active() ) {
			return;
		}
		$t = Cookie_Dialog_Config::get()['thirdParty'];
		if ( ! $t['emoji'] ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}
		if ( $t['hints'] ) {
			add_filter( 'wp_resource_hints', array( __CLASS__, 'hints' ), 999, 2 );
		}
		if ( 'proxy' === $t['gravatar'] ) {
			add_filter( 'get_avatar_url', array( __CLASS__, 'avatar_url' ), 999 );
		} elseif ( 'off' === $t['gravatar'] ) {
			add_filter( 'option_show_avatars', '__return_false' );
		}
	}

	/**
	 * Resource hints without third parties.
	 *
	 * @param array  $urls The hints.
	 * @param string $type dns-prefetch, preconnect, prefetch, prerender.
	 * @return array
	 */
	public static function hints( $urls, $type ) {
		if ( ! in_array( $type, array( 'dns-prefetch', 'preconnect' ), true ) ) {
			return $urls;
		}
		return array_values(
			array_filter(
				(array) $urls,
				static function ( $hint ) {
					$href = is_array( $hint ) ? (string) ( $hint['href'] ?? '' ) : (string) $hint;
					return ! Cookie_Dialog_Html::third_party( $href );
				}
			)
		);
	}

	/**
	 * The map of copied files.
	 *
	 * @return array
	 */
	public static function map() {
		if ( null === self::$map ) {
			$map       = get_option( self::MAP, array() );
			self::$map = is_array( $map ) ? $map : array();
		}
		return self::$map;
	}

	/**
	 * The key of a URL in the map: absolute, without WordPress' `ver`.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function key( $url ) {
		$url = Cookie_Dialog_Config::absolute( (string) $url );
		$url = (string) preg_replace( '/([?&])ver=[^&#]*(&|$)/', '$1', $url );
		return rtrim( $url, '?&' );
	}

	/**
	 * The local copy of a third-party URL, if there is one.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	public static function local( $url ) {
		if ( ! Cookie_Dialog_Html::third_party( (string) $url ) ) {
			return null;
		}
		$map = self::map();
		return $map[ self::key( $url ) ]['local'] ?? null;
	}

	/**
	 * `script_loader_src` and `style_loader_src`: the local copy where there is one.
	 *
	 * @param string $src Source.
	 * @return string
	 */
	public static function src( $src ) {
		$local = is_string( $src ) ? self::local( $src ) : null;
		return $local ? $local : $src;
	}

	/**
	 * A srcset with local copies.
	 *
	 * @param string $srcset The set.
	 * @return string
	 */
	public static function srcset( $srcset ) {
		return implode(
			', ',
			array_map(
				static function ( $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
					$local = self::local( $parts[0] );
					return $local ? $local . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ) : trim( $candidate );
				},
				explode( ',', (string) $srcset )
			)
		);
	}

	/**
	 * A link tag: a stylesheet or preload from the local copy, a hint to a third party gone.
	 *
	 * @param array  $attrs  Attributes.
	 * @param string $full   The tag.
	 * @param array  $config Configuration.
	 * @return string|null
	 */
	public static function link( $attrs, $full, $config ) {
		$href = is_string( $attrs['href'] ?? null ) ? $attrs['href'] : '';
		if ( '' === $href || ! Cookie_Dialog_Html::third_party( $href ) ) {
			return null;
		}
		$rel = strtolower( (string) ( $attrs['rel'] ?? '' ) );
		if ( preg_match( '/\b(preconnect|dns-prefetch)\b/', $rel ) ) {
			return $config['thirdParty']['hints'] ? '' : null;
		}
		$local = self::local( $href );
		if ( $local ) {
			$attrs['href'] = $local;
			unset( $attrs['crossorigin'], $attrs['integrity'] );
			return Cookie_Dialog_Html::tag( 'link', $attrs );
		}
		return null;
	}

	/**
	 * An inline style: `@import` and `url()` of copied files from the site.
	 *
	 * @param array  $attrs Attributes.
	 * @param string $css   CSS.
	 * @return string|null
	 */
	public static function style( $attrs, $css ) {
		$new = self::rewrite_css( (string) $css );
		return $new === $css ? null : Cookie_Dialog_Html::tag( 'style', $attrs, $new );
	}

	/**
	 * CSS with the URLs of copied files replaced.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	public static function rewrite_css( $css ) {
		if ( false === stripos( $css, 'url(' ) && false === stripos( $css, '@import' ) ) {
			return $css;
		}
		foreach ( Cookie_Dialog_Html::css_urls( $css ) as $url ) {
			$local = self::local( $url );
			if ( $local ) {
				$css = str_replace( $url, $local, $css );
			}
		}
		return $css;
	}

	/**
	 * Where copies live: uploads/cookie-dialog/<kind>.
	 *
	 * @param string $kind css, fonts, js.
	 * @return array [ dir, url ]
	 */
	private static function dir( $kind ) {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'cookie-dialog/' . $kind;
		wp_mkdir_p( $dir );
		return array( $dir, set_url_scheme( trailingslashit( $uploads['baseurl'] ) . 'cookie-dialog/' . $kind ) );
	}

	/**
	 * Fetches a file through the server.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error The body.
	 */
	private static function fetch( $url ) {
		$response = wp_safe_remote_get(
			Cookie_Dialog_Config::absolute( $url ),
			array(
				'timeout'             => 15,
				'user-agent'          => self::UA,
				'limit_response_size' => 8 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: 1: HTTP status, 2: URL */
			return new WP_Error( 'cookie_dialog_fetch', sprintf( __( '%1$d bei %2$s', 'cookie-dialog' ), $code, $url ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Saves a file under uploads/cookie-dialog.
	 *
	 * @param string $kind  css, fonts, js, files.
	 * @param string $url   Where it came from (names the file).
	 * @param string $body  Content.
	 * @param string $ext   Extension when the URL has none.
	 * @return string The local URL.
	 */
	private static function store( $kind, $url, $body, $ext ) {
		[ $dir, $base ] = self::dir( $kind );
		$path           = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name           = sanitize_file_name( basename( $path ) );
		if ( ! preg_match( '/\.[a-z0-9]{2,5}$/i', $name ) ) {
			$name = ( $name ? $name : 'file' ) . '.' . $ext;
		}
		$name = substr( md5( $url ), 0, 10 ) . '-' . substr( $name, -60 );
		file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a copy in uploads.
		return $base . '/' . $name;
	}

	/**
	 * Copies one third-party file and remembers it: CSS with the fonts and images it uses, a script,
	 * a font, an image into the media library.
	 *
	 * @param string $url  URL.
	 * @param string $kind style, script, font, image.
	 * @return string|WP_Error The local URL.
	 */
	public static function localize( $url, $kind ) {
		$abs = Cookie_Dialog_Config::absolute( (string) $url );
		if ( ! Cookie_Dialog_Html::third_party( $abs ) ) {
			return new WP_Error( 'cookie_dialog_own', __( 'Die Datei liegt schon auf deinem Server.', 'cookie-dialog' ) );
		}
		if ( 'image' === $kind ) {
			$id    = self::sideload( $abs, basename( (string) wp_parse_url( $abs, PHP_URL_PATH ) ) );
			$local = $id ? wp_get_attachment_url( $id ) : false;
			if ( ! $local ) {
				return new WP_Error( 'cookie_dialog_fetch', __( 'Das Bild ließ sich nicht laden.', 'cookie-dialog' ) );
			}
		} elseif ( 'style' === $kind ) {
			$local = self::localize_css( $abs, 0 );
		} else {
			$body = self::fetch( $abs );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$local = self::store( 'script' === $kind ? 'js' : 'fonts', $abs, $body, 'script' === $kind ? 'js' : 'woff2' );
		}
		if ( is_wp_error( $local ) ) {
			return $local;
		}
		self::remember( $abs, (string) $local, $kind );
		return (string) $local;
	}

	/**
	 * Copies a stylesheet: every font, image and imported sheet it refers to first.
	 *
	 * @param string $url   The sheet.
	 * @param int    $depth How deep in `@import`s.
	 * @return string|WP_Error
	 */
	private static function localize_css( $url, $depth ) {
		$css = self::fetch( $url );
		if ( is_wp_error( $css ) ) {
			return $css;
		}
		foreach ( Cookie_Dialog_Html::css_urls( $css ) as $ref ) {
			$abs = WP_Http::make_absolute_url( html_entity_decode( $ref, ENT_QUOTES ), $url );
			if ( ! $abs || ! Cookie_Dialog_Html::third_party( $abs ) ) {
				continue;
			}
			$is_css = (bool) preg_match( '#\.css(\?|$)|fonts\.googleapis\.com/css#i', $abs ) && preg_match( '#@import[^;]*' . preg_quote( $ref, '#' ) . '#i', $css );
			if ( $is_css ) {
				$local = $depth < 2 ? self::localize_css( $abs, $depth + 1 ) : null;
				if ( is_string( $local ) ) {
					self::remember( $abs, $local, 'style' );
				}
			} else {
				$body  = self::fetch( $abs );
				$local = is_wp_error( $body ) ? null : self::store( 'fonts', $abs, $body, 'bin' );
			}
			if ( is_string( $local ) ) {
				$css = str_replace( $ref, $local, $css );
			}
		}
		return self::store( 'css', $url, "/* Copied by Cookie Dialog from {$url} */\n" . $css, 'css' );
	}

	/**
	 * Remembers a copy.
	 *
	 * @param string $url   The third-party URL.
	 * @param string $local The local URL.
	 * @param string $kind  style, script, font, image.
	 */
	private static function remember( $url, $local, $kind ) {
		$map                      = self::map();
		$map[ self::key( $url ) ] = array(
			'local' => $local,
			'kind'  => $kind,
			'host'  => Cookie_Dialog_Html::host( $url ),
			'at'    => time(),
		);
		update_option( self::MAP, $map, true );
		self::$map = $map;
	}

	/**
	 * Copies everything the scan found on a host and marks its service as served by the site.
	 *
	 * @param string $host Host.
	 * @return array `copied`, `errors`.
	 */
	public static function localize_host( $host ) {
		$scan   = Cookie_Dialog_Scanner::result();
		$entry  = $scan['hosts'][ $host ] ?? null;
		$preset = $entry && $entry['preset'] ? Cookie_Dialog_Config::preset( $entry['preset'] ) : null;
		if ( $preset && 'avatar' === ( $preset['kind'] ?? '' ) ) {
			// Avatars are fetched per visitor's request through the avatar route, not copied.
			Cookie_Dialog_Config::patch( array( 'thirdParty' => array( 'gravatar' => 'proxy' ) ) );
			return array(
				'copied' => 1,
				'errors' => array(),
			);
		}
		$copied = 0;
		$errors = array();
		foreach ( (array) ( $entry['urls'] ?? array() ) as $item ) {
			$kind = self::kind_of( $item['kind'], $item['url'] );
			if ( ! $kind ) {
				continue;
			}
			$result = self::localize( $item['url'], $kind );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$copied;
			}
		}
		if ( $copied ) {
			self::mark_local( $host );
		}
		return array(
			'copied' => $copied,
			'errors' => $errors,
		);
	}

	/**
	 * What kind of copy a found URL becomes.
	 *
	 * @param string $found The scan's kind: style, font, image, script, hint.
	 * @param string $url   URL.
	 * @return string|null
	 */
	public static function kind_of( $found, $url ) {
		if ( 'style' === $found || preg_match( '#fonts\.googleapis\.com/css|\.css(\?|$)#i', (string) $url ) ) {
			return 'style';
		}
		if ( in_array( $found, array( 'font', 'image', 'script' ), true ) ) {
			return $found;
		}
		return null;
	}

	/**
	 * The service of a host – from the library when there is none yet – marked as served locally.
	 *
	 * @param string $host Host.
	 */
	public static function mark_local( $host ) {
		$config  = Cookie_Dialog_Config::get();
		$url     = 'https://' . $host . '/';
		$service = Cookie_Dialog_Config::match( $url );
		if ( ! $service || ! in_array( $service['kind'], Cookie_Dialog_Config::LOCAL_KINDS, true ) ) {
			$preset = Cookie_Dialog_Config::match_preset( $url );
			if ( ! $preset || ! in_array( $preset['kind'] ?? '', Cookie_Dialog_Config::LOCAL_KINDS, true ) ) {
				return;
			}
			$service              = Cookie_Dialog_Config::service_from_preset( $preset, null, 'scan' );
			$config['services'][] = $service;
		}
		foreach ( $config['services'] as &$s ) {
			if ( $s['id'] === $service['id'] ) {
				$s['local'] = true;
			}
		}
		unset( $s );
		/* translators: %s: service, e.g. Google Fonts */
		Cookie_Dialog_Config::save( $config, sprintf( __( '%s kommt von deinem Server', 'cookie-dialog' ), $service['name'] ) );
		/* translators: %s: service */
		Cookie_Dialog_Config::remember( sprintf( __( '%s lokal eingebunden', 'cookie-dialog' ), $service['name'] ) );
	}

	/**
	 * Once a week: copies whose file went missing are made again.
	 *
	 * @return int Copies made again.
	 */
	public static function check() {
		$uploads = wp_upload_dir( null, false );
		$again   = 0;
		foreach ( self::map() as $url => $entry ) {
			$path = str_replace( set_url_scheme( $uploads['baseurl'] ), $uploads['basedir'], set_url_scheme( (string) $entry['local'] ) );
			if ( ! file_exists( $path ) && ! is_wp_error( self::localize( $url, (string) $entry['kind'] ) ) ) {
				++$again;
			}
		}
		return $again;
	}

	/**
	 * A file into the media library.
	 *
	 * @param string $url   Where it is.
	 * @param string $title Its title.
	 * @return int The attachment, or 0.
	 */
	public static function sideload( $url, $title ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		$name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $name ) ) {
			$name = sanitize_title( $title ) . '.jpg';
		}
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			0,
			$title
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}
		update_post_meta( $id, '_cookie_dialog_source', esc_url_raw( $url ) );
		return (int) $id;
	}

	/**
	 * A Gravatar address as one of the site's: the avatar route fetches it.
	 *
	 * @param string $url The Gravatar URL.
	 * @return string
	 */
	public static function avatar_url( $url ) {
		if ( ! preg_match( '#^https?://(?:[a-z0-9]+\.)?gravatar\.com/avatar/([a-f0-9]{32,64})#i', (string) $url, $m ) ) {
			return $url;
		}
		$query = (string) wp_parse_url( html_entity_decode( (string) $url, ENT_QUOTES ), PHP_URL_QUERY );
		parse_str( $query, $q );
		$size           = max( 16, min( 512, (int) ( $q['s'] ?? 96 ) ) );
		[ $dir, $base ] = self::dir( 'avatars' );
		foreach ( array( 'jpg', 'png' ) as $ext ) {
			$file = sprintf( '%s/%s-%d.%s', $dir, strtolower( $m[1] ), $size, $ext );
			if ( file_exists( $file ) && filemtime( $file ) > time() - WEEK_IN_SECONDS ) {
				return sprintf( '%s/%s-%d.%s', $base, strtolower( $m[1] ), $size, $ext );
			}
		}
		return add_query_arg(
			array(
				's' => $size,
				'd' => is_string( $q['d'] ?? null ) ? $q['d'] : 'mm',
				'r' => is_string( $q['r'] ?? null ) ? $q['r'] : 'g',
			),
			rest_url( 'cookie-dialog/v1/avatar/' . strtolower( $m[1] ) )
		);
	}

	/** The avatar route: public, cached files in uploads. */
	public static function routes() {
		register_rest_route(
			'cookie-dialog/v1',
			'/avatar/(?P<hash>[a-f0-9]{32,64})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'avatar' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Fetches an avatar from Gravatar through the server, keeps it a week, sends the visitor there.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function avatar( WP_REST_Request $request ) {
		$hash           = strtolower( (string) $request['hash'] );
		$size           = max( 16, min( 512, (int) ( $request->get_param( 's' ) ?? 96 ) ) );
		$d              = (string) ( $request->get_param( 'd' ) ?? 'mm' );
		$r              = Cookie_Dialog_Config::one_of( (string) ( $request->get_param( 'r' ) ?? 'g' ), array( 'g', 'pg', 'r', 'x' ), 'g' );
		[ $dir, $base ] = self::dir( 'avatars' );
		$response       = wp_safe_remote_get(
			add_query_arg(
				array(
					's' => $size,
					'd' => preg_match( '/^(mm|mp|identicon|monsterid|wavatar|retro|robohash|blank|404)$/', $d ) ? $d : 'mm',
					'r' => $r,
				),
				'https://secure.gravatar.com/avatar/' . $hash
			),
			array( 'timeout' => 8 )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'cookie_dialog_avatar', 'No avatar.', array( 'status' => 404 ) );
		}
		$ext  = str_contains( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'png' ) ? 'png' : 'jpg';
		$name = sprintf( '%s-%d.%s', $hash, $size, $ext );
		file_put_contents( $dir . '/' . $name, wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a cached avatar.
		$out = new WP_REST_Response( null, 302 );
		$out->header( 'Location', $base . '/' . $name );
		$out->header( 'Cache-Control', 'public, max-age=86400' );
		return $out;
	}

	/** Deletes every copy (uninstall). */
	public static function delete_all() {
		$uploads = wp_upload_dir( null, false );
		$root    = trailingslashit( $uploads['basedir'] ) . 'cookie-dialog';
		if ( is_dir( $root ) ) {
			$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $items as $item ) {
				if ( $item->isDir() ) {
					rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				} else {
					wp_delete_file( $item->getPathname() );
				}
			}
			rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		delete_option( self::MAP );
	}
}

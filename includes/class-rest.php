<?php
/**
 * The admin app's routes (`cookie-dialog/v1`): the state of everything, config, import, live, scan,
 * fixes, services, log, assistant. Only for those who manage the site.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin: each route calls the class that does the work and answers with what the page needs next.
 */
class Cookie_Dialog_Rest {

	const NS = 'cookie-dialog/v1';

	/** Hooks the routes. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Whether the person may change the dialog.
	 *
	 * @return bool
	 */
	public static function can() {
		return current_user_can( 'manage_options' );
	}

	/** The routes. */
	public static function routes() {
		$routes = array(
			'/state'                       => array( 'GET', 'state' ),
			'/config'                      => array( 'POST', 'config' ),
			'/ignore'                      => array( 'POST', 'ignore' ),
			'/import'                      => array( 'POST', 'import' ),
			'/live'                        => array( 'POST', 'live' ),
			'/scan'                        => array( 'POST', 'scan' ),
			'/fix'                         => array( 'POST', 'fix' ),
			'/service'                     => array( 'POST', 'service' ),
			'/service/(?P<id>[a-z0-9_-]+)' => array( 'DELETE', 'remove_service' ),
			'/local'                       => array( 'POST', 'local' ),
			'/log'                         => array( 'GET', 'log' ),
			'/version/(?P<v>\d+)'          => array( 'GET', 'version' ),
			'/assist'                      => array( 'POST', 'assist' ),
			'/job'                         => array( 'POST', 'job' ),
			'/undo'                        => array( 'POST', 'undo' ),
		);
		foreach ( $routes as $path => [ $method, $callback ] ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $method,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'can' ),
				)
			);
		}
	}

	/**
	 * Everything the admin's pages show.
	 *
	 * @return array
	 */
	public static function state() {
		$config = Cookie_Dialog_Config::get();
		$scan   = Cookie_Dialog_Scanner::result();
		$checks = Cookie_Dialog_Checks::run( $config, $scan );
		$texts  = array();
		foreach ( $config['languages'] as $lang ) {
			$t = Cookie_Dialog_Config::texts( $lang, $config );
			unset( $t['units'], $t['types'] );
			$texts[ $lang ] = $t;
		}
		$library = array();
		foreach ( Cookie_Dialog_Config::library() as $preset ) {
			$library[] = array(
				'id'       => $preset['id'],
				'name'     => $preset['name'],
				'category' => $preset['category'],
				'kind'     => $preset['kind'] ?? 'script',
				'provider' => $preset['provider']['name'] ?? '',
				'keywords' => $preset['keywords'] ?? array(),
			);
		}
		$unassigned = array();
		foreach ( Cookie_Dialog_Checks::unassigned( $config, $scan ) as $entry ) {
			$unassigned[] = $entry + array( 'suggestion' => Cookie_Dialog_Checks::suggestion( $entry ) );
		}
		$hosts = array();
		foreach ( $scan['hosts'] as $entry ) {
			$entry['name']  = Cookie_Dialog_Checks::host_name( $entry['host'] );
			$entry['local'] = (bool) Cookie_Dialog_Resources::local( $entry['urls'][0]['url'] ?? ( 'https://' . $entry['host'] . '/' ) );
			$hosts[]        = $entry;
		}
		$page    = static function ( $id ) {
			$post = $id ? get_post( $id ) : null;
			return $post ? array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
				'edit'  => get_edit_post_link( $post->ID, 'raw' ),
			) : null;
		};
		$history = get_option( Cookie_Dialog_Config::HISTORY, array() );
		return array(
			'config'        => $config,
			'texts'         => $texts,
			'scan'          => array(
				'at'      => $scan['at'],
				'pages'   => $scan['pages'],
				'counts'  => $scan['counts'],
				'hosts'   => $hosts,
				'cookies' => $scan['cookies'],
				'errors'  => $scan['errors'],
				'theme'   => $scan['theme'],
				'plugins' => $scan['plugins'],
			),
			'checks'        => $checks,
			'open'          => count( array_filter( $checks, static fn( $c ) => ! $c['ok'] && ! $c['ignored'] ) ),
			'unassigned'    => $unassigned,
			'library'       => $library,
			'assistant'     => Cookie_Dialog_Assistant::status(),
			'stats'         => Cookie_Dialog_Consent::stats( array( 'range' => '30d' ) ),
			'history'       => is_array( $history ) ? array_slice( $history, 0, 8 ) : array(),
			'versions'      => array_slice( Cookie_Dialog_Consent::versions(), 0, 10 ),
			'siteLanguages' => Cookie_Dialog_Config::site_languages(),
			'pages'         => array(
				'privacy' => $page( (int) $config['pages']['privacy'] ),
				'imprint' => $page( (int) $config['pages']['imprint'] ),
			),
			'local'         => array_values( array_unique( array_column( Cookie_Dialog_Resources::map(), 'host' ) ) ),
			'thumbs'        => count( (array) get_option( Cookie_Dialog_Embeds::THUMBS, array() ) ),
			'drafted'       => (int) get_option( 'cookie_dialog_drafted', 0 ),
			'site'          => array(
				'name' => get_bloginfo( 'name' ),
				'home' => home_url( '/' ),
				'host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			),
		);
	}

	/**
	 * Saves part of the configuration. A snippet only from someone who may write HTML with scripts.
	 *
	 * @param WP_REST_Request $request `patch`, `note`.
	 * @return array|WP_Error
	 */
	public static function config( WP_REST_Request $request ) {
		$patch = (array) $request->get_param( 'patch' );
		if ( isset( $patch['services'] ) && is_array( $patch['services'] ) ) {
			$patch['services'] = self::guard_snippets( $patch['services'] );
		}
		unset( $patch['live'], $patch['version'], $patch['reask'] );
		Cookie_Dialog_Config::patch( $patch, sanitize_text_field( (string) $request->get_param( 'note' ) ) );
		return self::state();
	}

	/**
	 * Ignores a check of the Prüfung as it stands, or shows it again.
	 *
	 * @param WP_REST_Request $request `id`, `ignore`.
	 * @return array|WP_Error
	 */
	public static function ignore( WP_REST_Request $request ) {
		if ( ! Cookie_Dialog_Checks::ignore( (string) $request->get_param( 'id' ), false !== $request->get_param( 'ignore' ) ) ) {
			return new WP_Error( 'cookie_dialog_check', __( 'Diese Prüfung gibt es gerade nicht.', 'cookie-dialog' ), array( 'status' => 404 ) );
		}
		return self::state();
	}

	/**
	 * Takes the configuration of an export file. Its snippets only from someone who may write HTML with
	 * scripts; the others keep the snippets this site has.
	 *
	 * @param WP_REST_Request $request `data`: the file's content.
	 * @return array|WP_Error
	 */
	public static function import( WP_REST_Request $request ) {
		$data = $request->get_param( 'data' );
		if ( is_array( $data ) && is_array( $data['config']['services'] ?? null ) ) {
			$data['config']['services'] = self::guard_snippets( $data['config']['services'] );
		}
		$saved = Cookie_Dialog_Config::import( $data, __( 'Importiert', 'cookie-dialog' ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$from = is_array( $data ) ? (string) wp_parse_url( (string) ( $data['site'] ?? '' ), PHP_URL_HOST ) : '';
		Cookie_Dialog_Config::remember(
			$from
				/* translators: %s: the host the file comes from, e.g. staging.example.com */
				? sprintf( __( 'Einstellungen von %s importiert', 'cookie-dialog' ), $from )
				: __( 'Einstellungen importiert', 'cookie-dialog' )
		);
		return self::state();
	}

	/**
	 * Snippets as they were, unless the person may write unfiltered HTML.
	 *
	 * @param array $services Services from the request.
	 * @return array
	 */
	private static function guard_snippets( array $services ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $services;
		}
		foreach ( $services as &$service ) {
			$old                = is_array( $service ) ? Cookie_Dialog_Config::service( (string) ( $service['id'] ?? '' ) ) : null;
			$service['snippet'] = $old['snippet'] ?? '';
		}
		unset( $service );
		return $services;
	}

	/**
	 * Switches the dialog on or off. On: fonts, styles and images the proposal named are copied first.
	 *
	 * @param WP_REST_Request $request `on`.
	 * @return array
	 */
	public static function live( WP_REST_Request $request ) {
		$on     = (bool) $request->get_param( 'on' );
		$config = Cookie_Dialog_Config::get();
		if ( $on && ! $config['live'] ) {
			foreach ( Cookie_Dialog_Checks::run( $config ) as $check ) {
				if ( 'third_party' === $check['id'] && ! $check['ok'] && false !== $request->get_param( 'localize' ) ) {
					foreach ( (array) ( $check['fixes'][0]['args']['hosts'] ?? array() ) as $host ) {
						Cookie_Dialog_Resources::localize_host( $host );
					}
				}
			}
			$config         = Cookie_Dialog_Config::get();
			$config['live'] = true;
			Cookie_Dialog_Config::save( $config, __( 'Dialog eingeschaltet', 'cookie-dialog' ) );
			Cookie_Dialog_Config::remember( __( 'Dialog eingeschaltet', 'cookie-dialog' ) );
			Cookie_Dialog_Scanner::run();
		} elseif ( ! $on && $config['live'] ) {
			$config['live'] = false;
			Cookie_Dialog_Config::save( $config );
			Cookie_Dialog_Config::remember( __( 'Dialog ausgeschaltet', 'cookie-dialog' ) );
		}
		return self::state();
	}

	/**
	 * Reads the site; the first time (or when asked) the proposal follows.
	 *
	 * @param WP_REST_Request $request `propose`.
	 * @return array
	 */
	public static function scan( WP_REST_Request $request ) {
		Cookie_Dialog_Scanner::run();
		if ( ! Cookie_Dialog_Config::get()['setup'] || $request->get_param( 'propose' ) ) {
			Cookie_Dialog_Scanner::propose();
		}
		return self::state();
	}

	/**
	 * One fix of the Prüfung.
	 *
	 * @param WP_REST_Request $request `action`, `args`.
	 * @return array|WP_Error
	 */
	public static function fix( WP_REST_Request $request ) {
		$result = Cookie_Dialog_Checks::fix( sanitize_key( (string) $request->get_param( 'action' ) ), (array) $request->get_param( 'args' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'result' => $result ) + self::state();
	}

	/**
	 * Adds a service from the library, or saves one.
	 *
	 * @param WP_REST_Request $request `preset` and `category`, or `service`.
	 * @return array|WP_Error
	 */
	public static function service( WP_REST_Request $request ) {
		$config = Cookie_Dialog_Config::get();
		$preset = $request->get_param( 'preset' );
		if ( $preset ) {
			$p = Cookie_Dialog_Config::preset( sanitize_key( (string) $preset ) );
			if ( ! $p ) {
				return new WP_Error( 'cookie_dialog_preset', __( 'Diesen Dienst kennt die Bibliothek nicht.', 'cookie-dialog' ), array( 'status' => 404 ) );
			}
			if ( Cookie_Dialog_Config::service( $p['id'], $config ) ) {
				return self::state() + array( 'added' => $p['id'] );
			}
			$category             = $request->get_param( 'category' ) ? sanitize_key( (string) $request->get_param( 'category' ) ) : null;
			$config['services'][] = Cookie_Dialog_Config::service_from_preset( $p, $category, 'library' );
			/* translators: %s: service */
			Cookie_Dialog_Config::save( $config, sprintf( __( '%s hinzugefügt', 'cookie-dialog' ), $p['name'] ) );
			/* translators: %s: service */
			Cookie_Dialog_Config::remember( sprintf( __( '%s hinzugefügt', 'cookie-dialog' ), $p['name'] ) );
			return self::state() + array( 'added' => $p['id'] );
		}
		$service = (array) $request->get_param( 'service' );
		if ( empty( $service['name'] ) ) {
			return new WP_Error( 'cookie_dialog_service', __( 'Ein Dienst braucht einen Namen.', 'cookie-dialog' ), array( 'status' => 400 ) );
		}
		$service['id'] = sanitize_key( (string) ( $service['id'] ?? '' ) );
		if ( ! $service['id'] ) {
			$service['id'] = sanitize_key( sanitize_title( (string) $service['name'] ) );
			$base          = $service['id'];
			$i             = 2;
			while ( Cookie_Dialog_Config::service( $service['id'], $config ) ) {
				$service['id'] = $base . '-' . $i;
				++$i;
			}
			$service['source'] = $service['source'] ?? 'custom';
		}
		[ $service ] = self::guard_snippets( array( $service ) );
		$found       = false;
		foreach ( $config['services'] as $i => $s ) {
			if ( $s['id'] === $service['id'] ) {
				$config['services'][ $i ] = array_merge( $s, $service );
				$found                    = true;
			}
		}
		if ( ! $found ) {
			$config['services'][] = $service;
		}
		/* translators: %s: service */
		Cookie_Dialog_Config::save( $config, sprintf( __( '%s geändert', 'cookie-dialog' ), sanitize_text_field( (string) $service['name'] ) ) );
		return self::state() + array( 'added' => $service['id'] );
	}

	/**
	 * Removes a service (not the dialog's own).
	 *
	 * @param WP_REST_Request $request `id`.
	 * @return array
	 */
	public static function remove_service( WP_REST_Request $request ) {
		$id     = sanitize_key( (string) $request['id'] );
		$config = Cookie_Dialog_Config::get();
		$gone   = Cookie_Dialog_Config::service( $id, $config );
		if ( $gone && 'cookie-dialog' !== $id ) {
			$config['services'] = array_values( array_filter( $config['services'], static fn( $s ) => $s['id'] !== $id ) );
			/* translators: %s: service */
			Cookie_Dialog_Config::save( $config, sprintf( __( '%s entfernt', 'cookie-dialog' ), $gone['name'] ) );
			/* translators: %s: service */
			Cookie_Dialog_Config::remember( sprintf( __( '%s entfernt', 'cookie-dialog' ), $gone['name'] ) );
		}
		return self::state();
	}

	/**
	 * Copies a host's files or one file to the site.
	 *
	 * @param WP_REST_Request $request `host`, or `url` and `kind`.
	 * @return array|WP_Error
	 */
	public static function local( WP_REST_Request $request ) {
		if ( $request->get_param( 'url' ) ) {
			$result = Cookie_Dialog_Resources::localize( esc_url_raw( (string) $request->get_param( 'url' ) ), sanitize_key( (string) $request->get_param( 'kind' ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			Cookie_Dialog_Resources::mark_local( Cookie_Dialog_Html::host( (string) $request->get_param( 'url' ) ) );
		} else {
			$result = Cookie_Dialog_Resources::localize_host( sanitize_text_field( (string) $request->get_param( 'host' ) ) );
			if ( ! $result['copied'] && $result['errors'] ) {
				return new WP_Error( 'cookie_dialog_local', implode( ' ', array_slice( $result['errors'], 0, 3 ) ), array( 'status' => 502 ) );
			}
		}
		Cookie_Dialog_Scanner::run();
		return self::state();
	}

	/**
	 * The log: a page of decisions and the numbers of the range.
	 *
	 * @param WP_REST_Request $request `range`, `source`, `q`, `page`.
	 * @return array
	 */
	public static function log( WP_REST_Request $request ) {
		$args = array(
			'range'  => Cookie_Dialog_Config::one_of( (string) $request->get_param( 'range' ), array( '30d', '12m', 'all' ), '30d' ),
			'source' => sanitize_key( (string) $request->get_param( 'source' ) ),
			'q'      => sanitize_text_field( (string) $request->get_param( 'q' ) ),
			'page'   => max( 1, (int) $request->get_param( 'page' ) ),
			'per'    => 25,
		);
		return Cookie_Dialog_Consent::rows( $args ) + array( 'stats' => Cookie_Dialog_Consent::stats( $args ) );
	}

	/**
	 * One version: what visitors were told.
	 *
	 * @param WP_REST_Request $request `v`.
	 * @return array|WP_Error
	 */
	public static function version( WP_REST_Request $request ) {
		$version = Cookie_Dialog_Consent::version( (int) $request['v'] );
		return $version ? $version : new WP_Error( 'cookie_dialog_version', __( 'Diese Version gibt es nicht.', 'cookie-dialog' ), array( 'status' => 404 ) );
	}

	/**
	 * One turn of the chat.
	 *
	 * @param WP_REST_Request $request `message`, `history`, `view`.
	 * @return array|WP_Error
	 */
	public static function assist( WP_REST_Request $request ) {
		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( '' === $message ) {
			return new WP_Error( 'cookie_dialog_empty', __( 'Was soll anders sein?', 'cookie-dialog' ), array( 'status' => 400 ) );
		}
		$answer = Cookie_Dialog_Assistant::ask( $message, (array) $request->get_param( 'history' ), sanitize_key( (string) $request->get_param( 'view' ) ) );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		return array( 'answer' => $answer ) + self::state();
	}

	/**
	 * A job for the provider, or the answer of the browser's model.
	 *
	 * @param WP_REST_Request $request `job`, `input`, `result`.
	 * @return array|WP_Error
	 */
	public static function job( WP_REST_Request $request ) {
		$result = $request->get_param( 'result' );
		$input  = (array) $request->get_param( 'input' );
		if ( null !== $result ) {
			$input['by'] = 'browser';
		}
		$out = Cookie_Dialog_Assistant::job( sanitize_key( (string) $request->get_param( 'job' ) ), $input, null === $result ? null : (array) $result );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( ! empty( $out['browser'] ) ) {
			return array( 'run' => $out );
		}
		return array( 'result' => $out ) + self::state();
	}

	/**
	 * Takes back the assistant's last changes.
	 *
	 * @param WP_REST_Request $request `token`.
	 * @return array|WP_Error
	 */
	public static function undo( WP_REST_Request $request ) {
		$result = Cookie_Dialog_Assistant::undo( (string) $request->get_param( 'token' ) );
		return is_wp_error( $result ) ? $result : self::state();
	}
}

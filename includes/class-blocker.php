<?php
/**
 * Scripts only after consent: enqueued scripts by handle and source (`script_loader_tag`), scripts
 * printed into the head, the body's start and the footer, and what the content holds. A held
 * script gets `type="text/plain"` and its service; the dialog releases it on consent.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Holds back what belongs to a service that waits for consent; also hands iframes to the
 * placeholders and third-party files to the self-hosting.
 */
class Cookie_Dialog_Blocker {

	/** Script types a browser runs. */
	const RUNNABLE = array( '', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript' );

	/** Hooks once the request's page is known. */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 0 );
	}

	/** On a page that shows the dialog: the filters and the buffers around head, body start and footer. */
	public static function start() {
		if ( ! Cookie_Dialog_Front::active() ) {
			return;
		}
		add_filter( 'script_loader_tag', array( __CLASS__, 'enqueued' ), 999, 3 );
		add_filter( 'script_loader_src', array( 'Cookie_Dialog_Resources', 'src' ), 999 );
		add_filter( 'style_loader_src', array( 'Cookie_Dialog_Resources', 'src' ), 999 );
		foreach ( array( 'wp_head', 'wp_body_open', 'wp_footer' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'buffer_start' ), PHP_INT_MIN );
			add_action( $hook, array( __CLASS__, 'buffer_end' ), PHP_INT_MAX );
		}
		foreach ( array( 'the_content', 'widget_text_content', 'widget_block_content', 'comment_text' ) as $filter ) {
			add_filter( $filter, array( __CLASS__, 'process' ), 999 );
		}
		add_filter( 'render_block', array( __CLASS__, 'block' ), 999, 2 );
	}

	/** Starts catching what a hook prints. */
	public static function buffer_start() {
		ob_start();
	}

	/** Prints what the hook printed, rewritten. */
	public static function buffer_end() {
		if ( ob_get_level() < 1 ) {
			return;
		}
		echo self::process( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page's own markup, rewritten.
	}

	/**
	 * A block rendered outside the content (template parts of a block theme); the content itself is
	 * rewritten as a whole.
	 *
	 * @param string $html  The block's HTML.
	 * @param array  $block The block.
	 * @return string
	 */
	public static function block( $html, $block ) {
		if ( doing_filter( 'the_content' ) || '' === (string) $html ) {
			return $html;
		}
		$name = (string) ( $block['blockName'] ?? '' );
		if ( in_array( $name, array( 'core/post-content', 'core/template-part', 'core/group', 'core/columns', 'core/column' ), true ) ) {
			return $html; // Their inner blocks were rewritten already.
		}
		return self::process( $html );
	}

	/**
	 * An enqueued script (with its inline before and after) whose handle or source belongs to a
	 * service that waits: every script tag of it held.
	 *
	 * @param string $tag    The tags.
	 * @param string $handle The handle.
	 * @param string $src    The source.
	 * @return string
	 */
	public static function enqueued( $tag, $handle, $src ) {
		$service = self::by_handle( (string) $handle ) ?? Cookie_Dialog_Config::match( (string) $src );
		if ( ! $service || ! Cookie_Dialog_Config::is_held( $service ) ) {
			return $tag;
		}
		return Cookie_Dialog_Html::rewrite(
			$tag,
			array(
				'script' => static function ( $attrs, $body ) use ( $service ) {
					if ( str_ends_with( (string) ( $attrs['id'] ?? '' ), '-js-translations' ) ) {
						return null;
					}
					return self::hold_script( $attrs, $body, $service );
				},
			)
		);
	}

	/**
	 * The service a script handle belongs to.
	 *
	 * @param string $handle Handle.
	 * @return array|null
	 */
	public static function by_handle( $handle ) {
		if ( '' === $handle ) {
			return null;
		}
		foreach ( Cookie_Dialog_Config::get()['services'] as $service ) {
			if ( in_array( $handle, $service['handles'], true ) ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * Rewrites a fragment: scripts of waiting services held, iframes as placeholders, third-party
	 * files from the site's own server, hints to third parties gone.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function process( $html ) {
		$html = (string) $html;
		if ( '' === $html || ( false === stripos( $html, '<script' ) && false === stripos( $html, '<iframe' ) && false === stripos( $html, '<link' ) && false === stripos( $html, '<style' ) && false === stripos( $html, '<img' ) ) ) {
			return $html;
		}
		$config = Cookie_Dialog_Config::get();
		$html   = Cookie_Dialog_Embeds::social( $html );
		return Cookie_Dialog_Html::rewrite(
			$html,
			array(
				'script' => array( __CLASS__, 'script' ),
				'iframe' => array( 'Cookie_Dialog_Embeds', 'iframe' ),
				'link'   => static fn( $attrs, $body, $full ) => Cookie_Dialog_Resources::link( $attrs, $full, $config ),
				'style'  => static fn( $attrs, $body ) => Cookie_Dialog_Resources::style( $attrs, $body ),
				'img'    => array( __CLASS__, 'img' ),
			)
		);
	}

	/**
	 * One script tag of the page.
	 *
	 * @param array  $attrs Attributes.
	 * @param string $body  Inline code.
	 * @return string|null
	 */
	public static function script( $attrs, $body ) {
		$type = strtolower( trim( (string) ( $attrs['type'] ?? '' ) ) );
		if ( ! in_array( $type, self::RUNNABLE, true ) ) {
			return null; // Held already, JSON, templates.
		}
		$service = null;
		$id      = (string) ( $attrs['id'] ?? '' );
		if ( preg_match( '/^(.+)-js(-before|-after)?$/', $id, $m ) ) {
			$service = self::by_handle( $m[1] );
		}
		if ( ! empty( $attrs['src'] ) && is_string( $attrs['src'] ) ) {
			$service = $service ?? Cookie_Dialog_Config::match( $attrs['src'] );
			if ( ! $service || ! Cookie_Dialog_Config::is_held( $service ) ) {
				$local = Cookie_Dialog_Resources::local( $attrs['src'] );
				if ( $local ) {
					$attrs['src'] = $local;
					return Cookie_Dialog_Html::tag( 'script', $attrs, $body );
				}
				return null;
			}
		} else {
			$service = $service ?? Cookie_Dialog_Config::match( (string) $body, false );
		}
		if ( ! $service || ! Cookie_Dialog_Config::is_held( $service ) ) {
			return null;
		}
		return self::hold_script( $attrs, $body, $service );
	}

	/**
	 * A script held for a service.
	 *
	 * @param array  $attrs   Attributes.
	 * @param string $body    Inline code.
	 * @param array  $service The service.
	 * @return string
	 */
	public static function hold_script( $attrs, $body, $service ) {
		$type = strtolower( (string) ( $attrs['type'] ?? '' ) );
		unset( $attrs['type'] );
		$attrs = array_merge(
			array(
				'type'                 => 'text/plain',
				'data-cookie-service'  => $service['id'],
				'data-cookie-category' => $service['category'],
			),
			'module' === $type ? array( 'data-cookie-type' => 'module' ) : array(),
			$attrs
		);
		return Cookie_Dialog_Html::tag( 'script', $attrs, (string) $body );
	}

	/**
	 * An image of a waiting service (a tracking pixel) loses its source until consent; one from a
	 * host the site serves itself comes from the site.
	 *
	 * @param array $attrs Attributes.
	 * @return string|null
	 */
	public static function img( $attrs ) {
		$src = is_string( $attrs['src'] ?? null ) ? $attrs['src'] : '';
		if ( '' === $src || ! Cookie_Dialog_Html::third_party( $src ) ) {
			return null;
		}
		$service = Cookie_Dialog_Config::match( $src );
		if ( $service && Cookie_Dialog_Config::is_held( $service ) && 'embed' !== $service['kind'] ) {
			$attrs['data-cookie-src']     = $src;
			$attrs['data-cookie-service'] = $service['id'];
			unset( $attrs['src'], $attrs['srcset'] );
			return Cookie_Dialog_Html::tag( 'img', $attrs );
		}
		$changed = false;
		$local   = Cookie_Dialog_Resources::local( $src );
		if ( $local ) {
			$attrs['src'] = $local;
			$changed      = true;
		}
		if ( ! empty( $attrs['srcset'] ) && is_string( $attrs['srcset'] ) ) {
			$set = Cookie_Dialog_Resources::srcset( $attrs['srcset'] );
			if ( $set !== $attrs['srcset'] ) {
				$attrs['srcset'] = $set;
				$changed         = true;
			}
		}
		return $changed ? Cookie_Dialog_Html::tag( 'img', $attrs ) : null;
	}

	/**
	 * A snippet of the admin, held for its service: scripts held, iframes as placeholders, images
	 * without source, `<noscript>` dropped.
	 *
	 * @param string $html    The snippet.
	 * @param array  $service The service.
	 * @return string
	 */
	public static function hold_html( $html, $service ) {
		$html = (string) preg_replace( '#<noscript\b.*?</noscript\s*>#is', '', (string) $html );
		if ( ! Cookie_Dialog_Config::is_held( $service ) ) {
			return $html;
		}
		return Cookie_Dialog_Html::rewrite(
			$html,
			array(
				'script' => static function ( $attrs, $body ) use ( $service ) {
					$type = strtolower( trim( (string) ( $attrs['type'] ?? '' ) ) );
					return in_array( $type, self::RUNNABLE, true ) ? self::hold_script( $attrs, $body, $service ) : null;
				},
				'iframe' => static fn( $attrs, $body, $full ) => Cookie_Dialog_Embeds::placeholder( $full, $service, (string) ( $attrs['src'] ?? '' ), $attrs ),
				'img'    => static function ( $attrs ) use ( $service ) {
					if ( empty( $attrs['src'] ) ) {
						return null;
					}
					$attrs['data-cookie-src']     = $attrs['src'];
					$attrs['data-cookie-service'] = $service['id'];
					unset( $attrs['src'] );
					return Cookie_Dialog_Html::tag( 'img', $attrs );
				},
			)
		);
	}
}

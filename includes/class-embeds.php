<?php
/**
 * Placeholders for embeds: YouTube, Vimeo, Maps, Instagram, X, TikTok, Spotify and every other
 * iframe from a third party. The embed waits in a `<template>`; „Laden“ loads it once, „Immer
 * laden“ is consent for the service. Thumbnails and titles come through the site's server.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a placeholder in the size of the content, and fetches thumbnails into the media library.
 */
class Cookie_Dialog_Embeds {

	const THUMBS = 'cookie_dialog_thumbs';

	/** Hooks oEmbed output and the thumbnail job. */
	public static function init() {
		add_filter( 'embed_oembed_html', array( __CLASS__, 'oembed' ), 999, 2 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'feed' ), 999, 2 );
		add_action( 'cookie_dialog_thumb', array( __CLASS__, 'fetch_thumb' ), 10, 2 );
	}

	/**
	 * An oEmbed (a blockquote with the provider's script, or an iframe) as one placeholder.
	 *
	 * @param string $html The embed's HTML.
	 * @param string $url  The URL that was embedded.
	 * @return string
	 */
	public static function oembed( $html, $url ) {
		if ( ! Cookie_Dialog_Front::active() || false !== strpos( (string) $html, 'data-cookie-dialog' ) ) {
			return $html;
		}
		$service = Cookie_Dialog_Config::match( (string) $url ) ?? Cookie_Dialog_Config::match( (string) $html, false );
		if ( ! $service || ! Cookie_Dialog_Config::is_held( $service ) ) {
			return $html;
		}
		$src = (string) $url;
		if ( preg_match( '#<iframe\b[^>]*\bsrc=["\']([^"\']+)#i', (string) $html, $m ) ) {
			$src = html_entity_decode( $m[1], ENT_QUOTES );
		}
		$attrs = preg_match( '#<iframe\b([^>]*)>#i', (string) $html, $m ) ? Cookie_Dialog_Html::attrs( $m[1] ) : array();
		return self::placeholder( (string) $html, $service, $src, $attrs );
	}

	/**
	 * The feed of Smash Balloon's Instagram Feed as one placeholder. Its script stays: without the feed
	 * on the page it asks nothing of Instagram; loaded, the feed starts it again (sbi_init).
	 *
	 * @param string $output The shortcode's HTML.
	 * @param string $tag    The shortcode.
	 * @return string
	 */
	public static function feed( $output, $tag ) {
		if ( 'instagram-feed' !== $tag || ! Cookie_Dialog_Front::active() || false === strpos( (string) $output, 'sb_instagram' ) ) {
			return $output;
		}
		$service = Cookie_Dialog_Config::service( 'instagram' );
		if ( ! $service || ! Cookie_Dialog_Config::is_held( $service ) ) {
			return $output;
		}
		$start = '<script>window.sbi_init&&window.sbi_init();</script>';
		return self::placeholder( (string) $output . $start, $service, 'https://www.instagram.com/', array(), true );
	}

	/**
	 * Social posts pasted as HTML (a blockquote and the platform's script) become one placeholder.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function social( $html ) {
		if ( false === stripos( (string) $html, '<blockquote' ) ) {
			return $html;
		}
		$out = preg_replace_callback(
			'#<blockquote\b[^>]*class="[^"]*\b(instagram-media|twitter-tweet|tiktok-embed|bluesky-embed|threads-post)\b[^"]*"[^>]*>.*?</blockquote>\s*(?:<script\b[^>]*></script>)?#is',
			static function ( $m ) {
				if ( preg_match( '#<script\b[^>]*\bsrc=["\']([^"\']+)#i', $m[0], $src ) ) {
					$service = Cookie_Dialog_Config::match( Cookie_Dialog_Config::absolute( html_entity_decode( $src[1], ENT_QUOTES ) ) );
				} else {
					$service = Cookie_Dialog_Config::match( $m[0], false );
				}
				if ( ! $service || ! Cookie_Dialog_Config::is_held( $service ) ) {
					return $m[0];
				}
				return self::placeholder( $m[0], $service, $src[1] ?? '', array() );
			},
			(string) $html
		);
		return null === $out ? $html : $out;
	}

	/**
	 * An iframe of the page: a placeholder when it belongs to a service that waits, or to an unknown
	 * third party while unknown iframes wait.
	 *
	 * @param array  $attrs Attributes.
	 * @param string $body  What is inside the iframe tag.
	 * @param string $full  The whole tag.
	 * @return string|null
	 */
	public static function iframe( $attrs, $body, $full ) {
		if ( isset( $attrs['data-cookie-dialog'] ) || isset( $attrs['data-cookie-service'] ) ) {
			return null;
		}
		$src = (string) ( $attrs['src'] ?? ( $attrs['data-src'] ?? '' ) );
		if ( '' === $src || ! Cookie_Dialog_Html::third_party( $src ) ) {
			return null;
		}
		$service = Cookie_Dialog_Config::match( $src );
		if ( $service ) {
			return Cookie_Dialog_Config::is_held( $service ) ? self::placeholder( $full, $service, $src, $attrs ) : null;
		}
		if ( ! Cookie_Dialog_Config::get()['thirdParty']['unknownIframes'] ) {
			return null;
		}
		return self::placeholder( $full, null, $src, $attrs );
	}

	/**
	 * A placeholder in the content's size, with the embed in a template.
	 *
	 * @param string     $html    The embed.
	 * @param array|null $service The service, or null for an unknown third party.
	 * @param string     $src     The embed's address.
	 * @param array      $attrs   The iframe's attributes (width, height, title).
	 * @param bool       $feed    Several posts of the service (a feed), not one embed.
	 * @return string
	 */
	public static function placeholder( $html, $service, $src, $attrs = array(), $feed = false ) {
		$config = Cookie_Dialog_Config::get();
		$lang   = Cookie_Dialog_Config::lang();
		$texts  = Cookie_Dialog_Config::texts( $lang );
		$host   = Cookie_Dialog_Html::host( $src );
		$type   = $service['embed']['type'] ?? self::guess_type( $host );
		$name   = $service ? $service['name'] : $host;
		$prov   = $service && $service['provider']['name'] ? $service['provider']['name'] : $name;
		$fill   = static fn( $text ) => strtr(
			(string) $text,
			array(
				'{service}'  => $name,
				'{provider}' => $prov,
			)
		);

		if ( $service && 'youtube' === ( $service['preset'] ? $service['preset'] : $service['id'] ) && $config['thirdParty']['nocookie'] ) {
			$html = (string) preg_replace( '#(https?:)?//(www\.)?youtube\.com/embed/#i', 'https://www.youtube-nocookie.com/embed/', (string) $html );
		}

		$thumb = null;
		if ( $service && ! empty( $service['embed']['thumb'] ) && $config['thirdParty']['thumbnails'] ) {
			$thumb = self::thumb( $src );
		}
		$title = $thumb && ! empty( $thumb['title'] ) ? $thumb['title'] : $fill( $texts[ $feed ? 'phFeed' : ( self::TITLE[ $type ] ?? 'phOther' ) ] );
		$text  = $fill( $service && $service['cookies'] ? $texts['phCookies'] : $texts['phText'] );

		$width  = isset( $attrs['width'] ) && is_numeric( $attrs['width'] ) ? (int) $attrs['width'] : 0;
		$height = isset( $attrs['height'] ) && is_numeric( $attrs['height'] ) ? (int) $attrs['height'] : 0;
		$style  = '';
		if ( $width > 0 && $height > 0 ) {
			$style = sprintf( '--cookie-dialog-ratio:%d/%d;', $width, $height );
		} elseif ( in_array( $type, array( 'video', 'map' ), true ) ) {
			$style = '--cookie-dialog-ratio:16/9;';
		}
		if ( $width > 0 && ! str_contains( (string) ( $attrs['width'] ?? '' ), '%' ) ) {
			$style .= sprintf( 'max-width:%dpx;', $width );
		}

		$address = '';
		if ( 'map' === $type ) {
			$address = $service['embed']['address'] ?? '';
			if ( '' === $address ) {
				$query = (string) wp_parse_url( html_entity_decode( $src, ENT_QUOTES ), PHP_URL_QUERY );
				parse_str( $query, $q );
				$address = is_string( $q['q'] ?? null ) ? sanitize_text_field( $q['q'] ) : '';
			}
		}

		$label  = $service && ! empty( $service['embed']['label'] ) ? Cookie_Dialog_Config::pick( $service['embed']['label'], $lang ) : $texts['phLoad'];
		$label  = $feed ? $texts['phFeedLoad'] : $label;
		$always = $service && $config['thirdParty']['always'];

		$out  = sprintf(
			'<div class="cookie-dialog-ph cookie-dialog-ph--%1$s%2$s" data-cookie-service="%3$s" data-cookie-host="%4$s" data-cookie-src="%5$s"%6$s>',
			esc_attr( $type ),
			$thumb ? ' has-thumb' : '',
			esc_attr( $service ? $service['id'] : '' ),
			esc_attr( $host ),
			esc_attr( $src ),
			$style ? ' style="' . esc_attr( $style ) . '"' : ''
		);
		$out .= '<template data-cookie-dialog="embed">' . $html . '</template>';
		if ( $thumb ) {
			$out .= sprintf( '<img class="cookie-dialog-ph__img" src="%s" alt="" loading="lazy" decoding="async">', esc_url( $thumb['url'] ) );
		}
		$out .= '<div class="cookie-dialog-ph__over">';
		$out .= '<span class="cookie-dialog-ph__icon" aria-hidden="true">' . self::icon( $type ) . '</span>';
		$out .= '<b class="cookie-dialog-ph__title">' . esc_html( $title ) . '</b>';
		$out .= '<p class="cookie-dialog-ph__text">' . esc_html( $text ) . '</p>';
		if ( $address ) {
			$out .= '<p class="cookie-dialog-ph__text">' . esc_html( $texts['phAddress'] ) . '</p><p class="cookie-dialog-ph__address">' . esc_html( $address ) . '</p>';
		}
		$out .= '<span class="cookie-dialog-ph__buttons">';
		$out .= '<button type="button" class="cookie-dialog-ph__btn" data-cookie-load>' . esc_html( $label ) . '</button>';
		if ( $always ) {
			$out .= '<button type="button" class="cookie-dialog-ph__btn is-secondary" data-cookie-always>' . esc_html( $texts['phAlways'] ) . '</button>';
		}
		if ( $address ) {
			$out .= sprintf(
				'<a class="cookie-dialog-ph__btn is-secondary" href="%s" target="_blank" rel="noopener noreferrer">%s ↗</a>',
				esc_url( 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $address ) ),
				esc_html( $texts['phRoute'] )
			);
		}
		$out .= '</span>';
		if ( $thumb ) {
			$out .= '<small class="cookie-dialog-ph__hint">' . esc_html( $fill( $texts['phThumbHint'] ) ) . '</small>';
		} elseif ( $always ) {
			$out .= '<small class="cookie-dialog-ph__hint">' . esc_html( $fill( $texts['phAlwaysHint'] ) ) . '</small>';
		}
		$out .= '</div></div>';
		return $out;
	}

	/** The title text of a placeholder per kind of embed. */
	const TITLE = array(
		'video'    => 'phVideo',
		'audio'    => 'phAudio',
		'map'      => 'phMap',
		'social'   => 'phSocial',
		'calendar' => 'phOther',
		'form'     => 'phOther',
		'other'    => 'phOther',
	);

	/**
	 * What an unknown host probably embeds.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function guess_type( $host ) {
		if ( preg_match( '/youtube|vimeo|wistia|loom|dailymotion|twitch/', $host ) ) {
			return 'video';
		}
		if ( preg_match( '/maps|openstreetmap|mapbox/', $host ) ) {
			return 'map';
		}
		if ( preg_match( '/spotify|soundcloud|podcast|anchor/', $host ) ) {
			return 'audio';
		}
		return 'other';
	}

	/**
	 * A small line icon per kind of embed.
	 *
	 * @param string $type Kind.
	 * @return string
	 */
	public static function icon( $type ) {
		$paths = array(
			'video' => '<path d="M8 5l11 7-11 7z"/>',
			'audio' => '<path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/>',
			'map'   => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
		);
		$path  = $paths[ $type ] ?? '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18"/>';
		return '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
	}

	/**
	 * The oEmbed endpoint and a canonical URL for an embed's address.
	 *
	 * @param string $src The iframe's address.
	 * @return array|null [ key, oEmbed URL ]
	 */
	public static function source( $src ) {
		$src = html_entity_decode( (string) $src, ENT_QUOTES );
		if ( preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#', $src, $m ) ) {
			return array( 'youtube-' . $m[1], 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $m[1] ) );
		}
		if ( preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $src, $m ) ) {
			return array( 'vimeo-' . $m[1], 'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $m[1] ) );
		}
		if ( preg_match( '#open\.spotify\.com/(?:embed/)?(track|album|playlist|episode|show)/([A-Za-z0-9]+)#', $src, $m ) ) {
			return array( 'spotify-' . $m[1] . '-' . $m[2], 'https://open.spotify.com/oembed?url=' . rawurlencode( 'https://open.spotify.com/' . $m[1] . '/' . $m[2] ) );
		}
		return null;
	}

	/**
	 * The thumbnail and title of an embed, when the server fetched them; else the fetch is queued.
	 *
	 * @param string $src The embed's address.
	 * @return array|null `url`, `title`.
	 */
	public static function thumb( $src ) {
		$source = self::source( $src );
		if ( ! $source ) {
			return null;
		}
		$thumbs = get_option( self::THUMBS, array() );
		$entry  = is_array( $thumbs ) ? ( $thumbs[ $source[0] ] ?? null ) : null;
		if ( is_array( $entry ) && ! empty( $entry['url'] ) ) {
			return $entry;
		}
		if ( ! is_array( $entry ) || ( ! empty( $entry['pending'] ) && $entry['pending'] < time() - DAY_IN_SECONDS ) ) {
			$thumbs               = is_array( $thumbs ) ? $thumbs : array();
			$thumbs[ $source[0] ] = array( 'pending' => time() );
			update_option( self::THUMBS, $thumbs, false );
			wp_schedule_single_event( time(), 'cookie_dialog_thumb', array( $src, $source[0] ) );
		}
		return null;
	}

	/**
	 * Fetches an embed's thumbnail and title into the media library (the cron job).
	 *
	 * @param string $src The embed's address.
	 * @param string $key Its key.
	 * @return array|null
	 */
	public static function fetch_thumb( $src, $key = '' ) {
		$source = self::source( $src );
		if ( ! $source ) {
			return null;
		}
		$key      = $key ? $key : $source[0];
		$response = wp_safe_remote_get( $source[1], array( 'timeout' => 10 ) );
		$data     = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$thumbs   = get_option( self::THUMBS, array() );
		$thumbs   = is_array( $thumbs ) ? $thumbs : array();
		if ( ! is_array( $data ) || empty( $data['thumbnail_url'] ) ) {
			$thumbs[ $key ] = array( 'pending' => time() );
			update_option( self::THUMBS, $thumbs, false );
			return null;
		}
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		$id    = Cookie_Dialog_Resources::sideload( (string) $data['thumbnail_url'], $title ? $title : $key );
		if ( ! $id ) {
			return null;
		}
		$thumbs[ $key ] = array(
			'url'   => (string) wp_get_attachment_image_url( $id, 'large' ),
			'id'    => $id,
			'title' => $title,
		);
		update_option( self::THUMBS, $thumbs, false );
		return $thumbs[ $key ];
	}
}

<?php
/**
 * Finds and rewrites tags in HTML: scripts, iframes, links, styles, images and media. Used by the
 * blocker, the placeholders, the self-hosting and the scan.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Regular expressions over the tags this plugin cares about, with what is already held back (a
 * placeholder's template, a held script, a comment) kept out of reach of the next rewrite.
 */
class Cookie_Dialog_Html {

	/**
	 * Rewrites the tags of a fragment or a whole page. Each handler gets the tag's attributes (and
	 * body) and returns the new markup, or null to keep the tag.
	 *
	 * @param string $html     HTML.
	 * @param array  $handlers `script`, `iframe`, `link`, `style`, `img`, `media` → callable.
	 * @return string
	 */
	public static function rewrite( $html, array $handlers ) {
		if ( '' === $html || false === strpos( $html, '<' ) ) {
			return $html;
		}
		$original = $html;
		// Large pages (a builder's inline JSON, a data: font) need room for the lazy expressions.
		if ( strlen( $html ) > 200000 && (int) ini_get( 'pcre.backtrack_limit' ) < 20000000 ) {
			ini_set( 'pcre.backtrack_limit', '20000000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- raised for this request only.
		}
		$kept = array();
		$keep = static function ( $markup ) use ( &$kept ) {
			$kept[] = $markup;
			return "\x1Acd" . ( count( $kept ) - 1 ) . "\x1A";
		};

		// What is held already, and comments (conditional comments may hold scripts for old browsers).
		$html = preg_replace_callback(
			'#<template\b[^>]*\bdata-cookie-dialog\b[^>]*>.*?</template\s*>|<!--.*?-->#is',
			static fn( $m ) => $keep( $m[0] ),
			$html
		);
		if ( null === $html ) {
			// A page too big for the expressions (backtrack limit): left as it was rather than lost.
			return $original;
		}

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			static function ( $m ) use ( $handlers, $keep ) {
				$attrs = self::attrs( $m[1] );
				$out   = isset( $handlers['script'] ) && ! isset( $attrs['data-cookie-dialog'] ) ? $handlers['script']( $attrs, $m[2], $m[0] ) : null;
				return $keep( null === $out ? $m[0] : $out );
			},
			$html
		);
		if ( null === $html ) {
			// A page too big for the expressions (backtrack limit): left as it was rather than lost.
			return $original;
		}

		$html = preg_replace_callback(
			'#<iframe\b([^>]*?)(?:/>|>(.*?)</iframe\s*>)#is',
			static function ( $m ) use ( $handlers, $keep ) {
				$attrs = self::attrs( $m[1] );
				$out   = isset( $handlers['iframe'] ) ? $handlers['iframe']( $attrs, $m[2] ?? '', $m[0] ) : null;
				return $keep( null === $out ? $m[0] : $out );
			},
			$html
		);
		if ( null === $html ) {
			// A page too big for the expressions (backtrack limit): left as it was rather than lost.
			return $original;
		}

		$html = preg_replace_callback(
			'#<style\b([^>]*)>(.*?)</style\s*>#is',
			static function ( $m ) use ( $handlers, $keep ) {
				$attrs = self::attrs( $m[1] );
				$out   = isset( $handlers['style'] ) ? $handlers['style']( $attrs, $m[2], $m[0] ) : null;
				return $keep( null === $out ? $m[0] : $out );
			},
			$html
		);
		if ( null === $html ) {
			// A page too big for the expressions (backtrack limit): left as it was rather than lost.
			return $original;
		}

		foreach ( array(
			'link'  => '#<link\b([^>]*?)/?>#is',
			'img'   => '#<img\b([^>]*?)/?>#is',
			'media' => '#<(?:source|video|audio)\b([^>]*?)/?>#is',
		) as $kind => $pattern ) {
			if ( ! isset( $handlers[ $kind ] ) ) {
				continue;
			}
			$html = preg_replace_callback(
				$pattern,
				static function ( $m ) use ( $handlers, $kind ) {
					$out = $handlers[ $kind ]( self::attrs( $m[1] ), '', $m[0] );
					return null === $out ? $m[0] : $out;
				},
				$html
			);
			if ( null === $html ) {
				// A page too big for the expressions (backtrack limit): left as it was rather than lost.
				return $original;
			}
		}

		// Kept parts may contain kept parts (a held script inside a placeholder's template).
		for ( $i = 0; $i < 3; $i++ ) {
			if ( false === strpos( $html, "\x1Acd" ) ) {
				break;
			}
			$html = preg_replace_callback( "#\x1Acd(\d+)\x1A#", static fn( $m ) => $kept[ (int) $m[1] ] ?? '', $html );
			if ( null === $html ) {
				// A page too big for the expressions (backtrack limit): left as it was rather than lost.
				return $original;
			}
		}
		return $html;
	}

	/**
	 * The attributes of a tag: names lowercased, valueless ones as true.
	 *
	 * @param string $source What is between the tag's name and its `>`.
	 * @return array
	 */
	public static function attrs( $source ) {
		$attrs = array();
		if ( preg_match_all( '#([^\s=/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?#s', (string) $source, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$name = strtolower( $m[1] );
				if ( isset( $m[4] ) && '' !== $m[4] ) {
					$value = $m[4];
				} elseif ( isset( $m[3] ) && '' !== $m[3] ) {
					$value = $m[3];
				} elseif ( isset( $m[2] ) ) {
					$value = $m[2];
				} else {
					$value = true;
				}
				$attrs[ $name ] = is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES | ENT_HTML5 ) : $value;
			}
		}
		return $attrs;
	}

	/**
	 * A tag from attributes; true prints the name alone, false and null leave it out.
	 *
	 * @param string      $tag   Tag name.
	 * @param array       $attrs Attributes.
	 * @param string|null $body  The body, or null for a tag without a closing tag.
	 * @return string
	 */
	public static function tag( $tag, array $attrs, $body = null ) {
		$out = '<' . $tag;
		foreach ( $attrs as $name => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$out .= true === $value ? ' ' . $name : sprintf( ' %s="%s"', $name, esc_attr( (string) $value ) );
		}
		$out .= '>';
		return null === $body ? $out : $out . $body . '</' . $tag . '>';
	}

	/**
	 * The host of a URL, lowercased; empty for relative URLs and data.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || str_starts_with( $url, 'data:' ) || str_starts_with( $url, '#' ) ) {
			return '';
		}
		return strtolower( (string) wp_parse_url( Cookie_Dialog_Config::absolute( $url ), PHP_URL_HOST ) );
	}

	/**
	 * Whether a URL points to a third party.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function third_party( $url ) {
		$host = self::host( $url );
		return '' !== $host && ! Cookie_Dialog_Config::is_own_host( $host );
	}

	/**
	 * The URLs inside CSS: `@import` and `url()`.
	 *
	 * @param string $css CSS.
	 * @return array
	 */
	public static function css_urls( $css ) {
		$urls = array();
		if ( preg_match_all( '#@import\s+(?:url\()?\s*["\']?([^"\')\s;]+)#i', (string) $css, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}
		if ( preg_match_all( '#url\(\s*["\']?([^"\')]+)["\']?\s*\)#i', (string) $css, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}
		return array_values( array_unique( array_filter( array_map( 'trim', $urls ), static fn( $u ) => ! str_starts_with( $u, 'data:' ) ) ) );
	}

	/**
	 * Everything a page fetches, for the scan: one entry per tag (or URL in CSS), with whether it
	 * is held back for consent.
	 *
	 * @param string $html A whole page.
	 * @return array List of `kind`, `url`, `held`, `service`, `id`, `body`.
	 */
	public static function assets( $html ) {
		$found = array();
		$add   = static function ( $kind, $url, $extra = array() ) use ( &$found ) {
			$found[] = array_merge(
				array(
					'kind'    => $kind,
					'url'     => (string) $url,
					'held'    => false,
					'service' => '',
					'id'      => '',
					'body'    => '',
				),
				$extra
			);
		};

		// Placeholders: the embed waits for consent.
		if ( preg_match_all( '#<[a-z]+\b[^>]*\bdata-cookie-src="([^"]*)"[^>]*>#i', (string) $html, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $m ) {
				$attrs = self::attrs( $m[0] );
				$add(
					'iframe',
					html_entity_decode( $m[1], ENT_QUOTES ),
					array(
						'held'    => true,
						'service' => (string) ( $attrs['data-cookie-service'] ?? '' ),
					)
				);
			}
		}

		self::rewrite(
			(string) $html,
			array(
				'script' => static function ( $attrs, $body ) use ( $add ) {
					$held = isset( $attrs['data-cookie-service'] ) || isset( $attrs['data-cookie-category'] );
					$type = strtolower( (string) ( $attrs['type'] ?? '' ) );
					if ( ! $held && $type && ! in_array( $type, array( 'text/javascript', 'module', 'application/javascript' ), true ) ) {
						return null; // JSON, templates: nothing the browser runs.
					}
					$extra = array(
						'held'    => $held,
						'service' => (string) ( $attrs['data-cookie-service'] ?? '' ),
						'id'      => (string) ( $attrs['id'] ?? '' ),
					);
					if ( ! empty( $attrs['src'] ) ) {
						$add( 'script', $attrs['src'], $extra );
					} elseif ( '' !== trim( (string) $body ) ) {
						$add( 'inline', '', $extra + array( 'body' => substr( (string) $body, 0, 4000 ) ) );
					}
					return null;
				},
				'iframe' => static function ( $attrs ) use ( $add ) {
					$src = $attrs['src'] ?? ( $attrs['data-src'] ?? '' );
					if ( $src && ! str_starts_with( (string) $src, 'about:' ) ) {
						$add( 'iframe', $src, array( 'id' => (string) ( $attrs['id'] ?? '' ) ) );
					}
					return null;
				},
				'link'   => static function ( $attrs ) use ( $add ) {
					$rel  = strtolower( (string) ( $attrs['rel'] ?? '' ) );
					$href = (string) ( $attrs['href'] ?? '' );
					if ( '' === $href ) {
						return null;
					}
					if ( str_contains( $rel, 'stylesheet' ) ) {
						$add( 'style', $href, array( 'id' => (string) ( $attrs['id'] ?? '' ) ) );
					} elseif ( str_contains( $rel, 'preload' ) || str_contains( $rel, 'modulepreload' ) ) {
						$as = strtolower( (string) ( $attrs['as'] ?? '' ) );
						$add( in_array( $as, array( 'font', 'style', 'script', 'image' ), true ) ? ( 'image' === $as ? 'image' : $as ) : 'hint', $href );
					} elseif ( preg_match( '/preconnect|dns-prefetch|prefetch|prerender/', $rel ) ) {
						$add( 'hint', $href );
					} elseif ( str_contains( $rel, 'icon' ) ) {
						$add( 'image', $href );
					}
					return null;
				},
				'style'  => static function ( $attrs, $body ) use ( $add ) {
					foreach ( self::css_urls( $body ) as $url ) {
						$add( preg_match( '/\.(woff2?|ttf|otf|eot)(\?|#|$)/i', $url ) ? 'font' : ( preg_match( '/\.(png|jpe?g|gif|webp|avif|svg)(\?|#|$)/i', $url ) ? 'image' : 'style' ), $url );
					}
					return null;
				},
				'img'    => static function ( $attrs ) use ( $add ) {
					foreach ( array( 'src', 'data-src' ) as $name ) {
						if ( ! empty( $attrs[ $name ] ) && is_string( $attrs[ $name ] ) ) {
							$add( 'image', $attrs[ $name ] );
						}
					}
					if ( ! empty( $attrs['srcset'] ) && is_string( $attrs['srcset'] ) ) {
						foreach ( explode( ',', $attrs['srcset'] ) as $candidate ) {
							$url = trim( (string) strtok( trim( $candidate ), ' ' ) );
							if ( $url ) {
								$add( 'image', $url );
							}
						}
					}
					return null;
				},
				'media'  => static function ( $attrs ) use ( $add ) {
					foreach ( array( 'src', 'poster' ) as $name ) {
						if ( ! empty( $attrs[ $name ] ) && is_string( $attrs[ $name ] ) ) {
							$add( 'media', $attrs[ $name ] );
						}
					}
					return null;
				},
			)
		);
		return $found;
	}
}

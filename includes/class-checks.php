<?php
/**
 * The Prüfung: what the law and Google ask, checked against the configuration and the last scan.
 * Each open point has a fix that one click does.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Green only when every third-party host is a service behind the dialog, served by the site, or
 * necessary with a reason – and the dialog itself plays fair.
 */
class Cookie_Dialog_Checks {

	/**
	 * All checks.
	 *
	 * @param array|null $config Configuration.
	 * @param array|null $scan   Scan.
	 * @return array List of `id`, `ok`, `title`, `text`, `fixes`.
	 */
	public static function run( $config = null, $scan = null ) {
		$config = $config ?? Cookie_Dialog_Config::get();
		$scan   = $scan ?? Cookie_Dialog_Scanner::result();
		$checks = array();
		$names  = static fn( $hosts ) => implode( ', ', array_map( array( __CLASS__, 'host_name' ), $hosts ) );

		// Third-party files that reach the visitor before consent and could come from the site.
		$files = array();
		foreach ( $scan['hosts'] as $host => $entry ) {
			if ( $entry['own'] || ! $entry['loaded'] ) {
				continue;
			}
			$kinds   = array_diff( $entry['kinds'], array( 'hint' ) );
			$service = $entry['service'] ? Cookie_Dialog_Config::service( $entry['service'], $config ) : null;
			$preset  = $entry['preset'] ? Cookie_Dialog_Config::preset( $entry['preset'] ) : null;
			// Avatars go through the server and the emoji script goes away by their own switches.
			if ( $preset && 'avatar' === ( $preset['kind'] ?? '' ) && 'allow' !== $config['thirdParty']['gravatar'] ) {
				continue;
			}
			if ( $preset && 'wp-emoji' === $preset['id'] && ! $config['thirdParty']['emoji'] ) {
				continue;
			}
			if ( $service && ! Cookie_Dialog_Config::is_held( $service ) && 'necessary' === $service['category'] && ! in_array( $service['kind'], Cookie_Dialog_Config::LOCAL_KINDS, true ) ) {
				continue;
			}
			if ( $kinds && ! array_diff( $kinds, array( 'style', 'font', 'image' ) ) && ! Cookie_Dialog_Resources::local( 'https://' . $host . '/' ) ) {
				$files[] = $host;
			}
		}
		$checks[] = array(
			'id'    => 'third_party',
			'ok'    => ! $files,
			'title' => $files
				/* translators: %d: number of hosts */
				? sprintf( _n( '%d Host bekommt vor der Einwilligung die IP-Adresse', '%d Hosts bekommen vor der Einwilligung die IP-Adresse', count( $files ), 'cookie-dialog' ), count( $files ) )
				: __( 'Schriften, Styles und Bilder kommen von deinem Server', 'cookie-dialog' ),
			'text'  => $files
				/* translators: %s: hosts */
				? sprintf( __( '%s – kommen dann von deinem Server', 'cookie-dialog' ), $names( $files ) )
				: __( 'Kein Dritter sieht die IP-Adresse, bevor jemand zustimmt.', 'cookie-dialog' ),
			'fixes' => $files ? array(
				array(
					'action'  => 'localize',
					'label'   => __( 'Lokal einbinden', 'cookie-dialog' ),
					'primary' => true,
					'args'    => array( 'hosts' => $files ),
				),
			) : array(),
		);

		// Scripts and embeds of hosts nobody assigned yet.
		foreach ( self::unassigned( $config, $scan ) as $entry ) {
			$suggest  = self::suggestion( $entry );
			$since    = $entry['new'] ? wp_date( 'j. M', (int) $entry['first'] ) : '';
			$checks[] = array(
				'id'    => 'unknown:' . $entry['host'],
				'ok'    => false,
				'title' => $since
					/* translators: 1: date, 2: host */
					? sprintf( __( 'Neues Skript seit %1$s: %2$s', 'cookie-dialog' ), $since, $entry['host'] )
					/* translators: %s: host */
					: sprintf( __( 'Skript unbekannt: %s', 'cookie-dialog' ), $entry['host'] ),
				'text'  => $suggest
					/* translators: 1: service, 2: purpose, 3: category */
					? sprintf( __( 'Vorschlag: %1$s, %2$s → %3$s', 'cookie-dialog' ), $suggest['name'], $suggest['purpose'], self::category_name( $suggest['category'], $config ) )
					: __( 'Lädt bis zur Zuordnung ohne Einwilligung. Ordne es einem Dienst zu.', 'cookie-dialog' ),
				'host'  => $entry['host'],
				'fixes' => array_values(
					array_filter(
						array(
							$suggest ? array(
								'action' => 'adopt',
								'label'  => __( 'Übernehmen', 'cookie-dialog' ),
								'args'   => array( 'host' => $entry['host'] ),
							) : null,
							array(
								'action' => 'edit',
								'label'  => __( 'Ändern', 'cookie-dialog' ),
								'args'   => array( 'host' => $entry['host'] ),
							),
						)
					)
				),
			);
		}

		// Services that should wait but were seen loading.
		if ( $config['live'] ) {
			$leaking = array();
			foreach ( $scan['hosts'] as $entry ) {
				$service = $entry['service'] ? Cookie_Dialog_Config::service( $entry['service'], $config ) : null;
				if ( $service && $entry['loaded'] && Cookie_Dialog_Config::is_held( $service ) && array_intersect( $entry['kinds'], array( 'script', 'iframe' ) ) ) {
					$leaking[ $service['id'] ] = $service['name'];
				}
			}
			$checks[] = array(
				'id'    => 'held',
				'ok'    => ! $leaking,
				'title' => $leaking
					/* translators: %s: services */
					? sprintf( __( 'Lädt trotzdem vor der Einwilligung: %s', 'cookie-dialog' ), implode( ', ', $leaking ) )
					: __( 'Dienste warten auf die Einwilligung', 'cookie-dialog' ),
				'text'  => $leaking
					? __( 'Ein Skript lädt sie nach, das der Dialog nicht kennt. Trage das Einbinden als Snippet ein oder markiere das Tag mit data-cookie-service.', 'cookie-dialog' )
					: __( 'Kein Dienst lädt, bevor jemand zustimmt.', 'cookie-dialog' ),
				'fixes' => $leaking ? array(
					array(
						'action' => 'edit',
						'label'  => __( 'Ansehen', 'cookie-dialog' ),
						'args'   => array( 'service' => (string) array_key_first( $leaking ) ),
					),
				) : array(),
			);

			$unknown_cookies = self::unknown_cookies( $config, $scan );
			$checks[]        = array(
				'id'    => 'cookies',
				'ok'    => ! $unknown_cookies,
				'title' => $unknown_cookies
					/* translators: %s: cookie names */
					? sprintf( __( 'Cookies vor der Einwilligung: %s', 'cookie-dialog' ), implode( ', ', $unknown_cookies ) )
					: __( 'Vor der Einwilligung nur notwendige Cookies', 'cookie-dialog' ),
				'text'  => $unknown_cookies
					? __( 'Der Server setzt sie beim ersten Besuch. Gehören sie zu einem notwendigen Dienst, trage sie dort ein.', 'cookie-dialog' )
					: __( 'Gelesen ohne Einwilligung: keine anderen Cookies.', 'cookie-dialog' ),
				'fixes' => $unknown_cookies ? array(
					array(
						'action' => 'edit',
						'label'  => __( 'Zuordnen', 'cookie-dialog' ),
						'args'   => array( 'service' => 'wordpress' ),
					),
				) : array(),
			);
		}

		$google = array_filter( $config['services'], static fn( $s ) => (bool) $s['signals'] );
		if ( $google || $config['google']['gtm'] ) {
			$first    = false !== $scan['consentMode'];
			$checks[] = array(
				'id'    => 'consent_mode',
				'ok'    => $config['google']['consentMode'] && $first,
				'title' => __( 'Google Consent Mode v2', 'cookie-dialog' ),
				'text'  => $config['google']['consentMode']
					? ( $first ? __( 'Vor jedem Google-Tag · Tags warten auf Einwilligung', 'cookie-dialog' ) : __( 'Ein Google-Tag steht vor dem Consent-Mode-Standard – es kommt von einem anderen Plugin oder dem Theme.', 'cookie-dialog' ) )
					: __( 'Aus. Google verlangt ihn für Messung und Anzeigen im EWR.', 'cookie-dialog' ),
				'fixes' => $config['google']['consentMode'] ? array() : array(
					array(
						'action'  => 'consent_mode',
						'label'   => __( 'Einschalten', 'cookie-dialog' ),
						'primary' => true,
					),
				),
			);
		}

		// Read now: the services may have changed since the last scan.
		$privacy  = Cookie_Dialog_Scanner::privacy_mentions( $config );
		$pid      = (int) $config['pages']['privacy'];
		$missing  = array_values( array_filter( array_map( static fn( $id ) => Cookie_Dialog_Config::service( $id, $config )['name'] ?? null, (array) ( $privacy['missing'] ?? array() ) ) ) );
		$table    = ! empty( $privacy['table'] ) && (int) ( $privacy['id'] ?? 0 ) === $pid;
		$checks[] = array(
			'id'    => 'privacy',
			'ok'    => $pid && ( $table || ( ! $missing && ! empty( $privacy['id'] ) ) ),
			'title' => ! $pid
				? __( 'Keine Datenschutzerklärung gewählt', 'cookie-dialog' )
				: ( $table || ! $missing
					? __( 'Datenschutzerklärung nennt alle Dienste', 'cookie-dialog' )
					/* translators: %s: services */
					: sprintf( __( 'Die Datenschutzerklärung nennt %s nicht', 'cookie-dialog' ), implode( ', ', array_slice( $missing, 0, 3 ) ) . ( count( $missing ) > 3 ? ' …' : '' ) ) ),
			'text'  => ! $pid
				? __( 'Der Dialog verlinkt sie, und dort steht die Cookie-Tabelle.', 'cookie-dialog' )
				: ( $table ? __( 'Die Cookie-Tabelle hält sich selbst aktuell.', 'cookie-dialog' ) : __( 'Die Cookie-Tabelle und ein Absatz werden eingefügt.', 'cookie-dialog' ) ),
			'fixes' => ! $pid ? array(
				array(
					'action' => 'pages',
					'label'  => __( 'Seite wählen', 'cookie-dialog' ),
				),
			) : ( $table || ! $missing ? array() : array(
				array(
					'action'  => 'insert_privacy',
					'label'   => __( 'Einfügen', 'cookie-dialog' ),
					'primary' => false,
				),
				array(
					'action' => 'read_privacy',
					'label'  => __( 'Lesen', 'cookie-dialog' ),
				),
			) ),
		);

		$checks[] = array(
			'id'    => 'imprint',
			'ok'    => (bool) $config['pages']['imprint'],
			'title' => $config['pages']['imprint'] ? __( 'Impressum verlinkt', 'cookie-dialog' ) : __( 'Kein Impressum gewählt', 'cookie-dialog' ),
			'text'  => __( 'Datenschutz und Impressum bleiben lesbar, der Dialog zeigt sich dort klein.', 'cookie-dialog' ),
			'fixes' => $config['pages']['imprint'] ? array() : array(
				array(
					'action' => 'pages',
					'label'  => __( 'Seite wählen', 'cookie-dialog' ),
				),
			),
		);

		$reachable = $config['reopen']['button'] || ! empty( $scan['reopen'] );
		$checks[]  = array(
			'id'    => 'reopen',
			'ok'    => $reachable,
			'title' => $reachable ? __( 'Widerruf so einfach wie die Einwilligung', 'cookie-dialog' ) : __( 'Der Dialog lässt sich nicht wieder öffnen', 'cookie-dialog' ),
			'text'  => $reachable ? __( 'Button, Link oder Block öffnen die Auswahl jederzeit.', 'cookie-dialog' ) : __( 'Kein Button und kein Link zu #cookie-settings gefunden.', 'cookie-dialog' ),
			'fixes' => $reachable ? array() : array(
				array(
					'action'  => 'reopen',
					'label'   => __( 'Button einschalten', 'cookie-dialog' ),
					'primary' => true,
				),
			),
		);

		$c        = $config['design']['colors'];
		$ratio    = min( self::contrast( $c['buttonText'], $c['button'] ), self::contrast( $c['text'], $c['background'] ) );
		$checks[] = array(
			'id'    => 'contrast',
			'ok'    => $ratio >= 4.5,
			/* translators: %s: contrast ratio, e.g. 7.2 */
			'title' => sprintf( __( 'Kontrast %s : 1', 'cookie-dialog' ), number_format_i18n( $ratio, 1 ) ),
			'text'  => $ratio >= 4.5 ? __( 'Texte und Buttons gut lesbar.', 'cookie-dialog' ) : __( 'Unter 4,5 : 1 – schwer lesbar.', 'cookie-dialog' ),
			'fixes' => $ratio >= 4.5 ? array() : array(
				array(
					'action'  => 'contrast',
					'label'   => __( 'Farben anpassen', 'cookie-dialog' ),
					'primary' => true,
				),
			),
		);

		foreach ( $config['languages'] as $lang ) {
			if ( in_array( $lang, array( 'de', 'en' ), true ) || ! empty( $config['texts'][ $lang ]['text'] ) ) {
				continue;
			}
			$checks[] = array(
				'id'    => 'language:' . $lang,
				'ok'    => false,
				/* translators: %s: language code */
				'title' => sprintf( __( 'Sprache %s zeigt noch Englisch', 'cookie-dialog' ), strtoupper( $lang ) ),
				'text'  => __( 'Der Assistent oder das Modell deines Browsers übersetzt die Texte.', 'cookie-dialog' ),
				'fixes' => array(
					array(
						'action'  => 'translate',
						'label'   => __( 'Übersetzen', 'cookie-dialog' ),
						'primary' => true,
						'args'    => array( 'lang' => $lang ),
					),
				),
			);
		}

		$checks[] = array(
			'id'    => 'fair',
			'ok'    => true,
			'title' => __( 'Ablehnen so leicht wie Zustimmen', 'cookie-dialog' ),
			'text'  => __( 'Zwei gleiche Buttons auf der ersten Ebene, nichts vorausgewählt.', 'cookie-dialog' ),
			'fixes' => array(),
		);
		$checks[] = array(
			'id'    => 'log',
			'ok'    => true,
			'title' => __( 'Protokoll mit Version', 'cookie-dialog' ),
			/* translators: %d: months */
			'text'  => sprintf( _n( 'IP-Adressen nur gehasht, gelöscht nach %d Monat.', 'IP-Adressen nur gehasht, gelöscht nach %d Monaten.', (int) $config['log']['months'], 'cookie-dialog' ), (int) $config['log']['months'] ),
			'fixes' => array(),
		);

		$age      = $scan['at'] ? time() - (int) $scan['at'] : PHP_INT_MAX;
		$checks[] = array(
			'id'    => 'scan',
			'ok'    => $age < 8 * DAY_IN_SECONDS,
			'title' => $scan['at']
				/* translators: %s: time since the last scan, e.g. "2 days" */
				? sprintf( __( 'Website gelesen vor %s', 'cookie-dialog' ), human_time_diff( (int) $scan['at'] ) )
				: __( 'Website noch nicht gelesen', 'cookie-dialog' ),
			'text'  => __( 'Jede Woche neu, damit neue Skripte auffallen.', 'cookie-dialog' ),
			'fixes' => $age < 8 * DAY_IN_SECONDS ? array() : array(
				array(
					'action'  => 'scan',
					'label'   => __( 'Jetzt lesen', 'cookie-dialog' ),
					'primary' => true,
				),
			),
		);
		foreach ( $checks as &$check ) {
			$check['ignored'] = ! $check['ok'] && ( $config['ignored'][ $check['id'] ] ?? '' ) === self::said( $check );
		}
		unset( $check );
		return $checks;
	}

	/**
	 * How many points are open: WordPress' count bubble on Start.
	 *
	 * @return int
	 */
	public static function open_count() {
		return count( array_filter( self::run(), static fn( $c ) => ! $c['ok'] && empty( $c['ignored'] ) ) );
	}

	/**
	 * What a check says, as a fingerprint: an ignored check comes back when it says something else
	 * (another host, another cookie).
	 *
	 * @param array $check A check.
	 * @return string
	 */
	public static function said( array $check ) {
		return md5( $check['id'] . '|' . $check['title'] . '|' . $check['text'] );
	}

	/**
	 * Ignores a check as it stands, or shows it again.
	 *
	 * @param string $id     The check's ID.
	 * @param bool   $ignore Whether to ignore it.
	 * @return bool Whether there was such a check.
	 */
	public static function ignore( $id, $ignore ) {
		$config = Cookie_Dialog_Config::get();
		foreach ( self::run( $config ) as $check ) {
			if ( $check['id'] !== $id ) {
				continue;
			}
			if ( $ignore ) {
				$config['ignored'][ $id ] = self::said( $check );
			} else {
				unset( $config['ignored'][ $id ] );
			}
			Cookie_Dialog_Config::save( $config );
			return true;
		}
		return false;
	}

	/**
	 * Hosts with scripts or embeds that load before consent and belong to no service.
	 *
	 * @param array|null $config Configuration.
	 * @param array|null $scan   Scan.
	 * @return array Host entries.
	 */
	public static function unassigned( $config = null, $scan = null ) {
		$config = $config ?? Cookie_Dialog_Config::get();
		$scan   = $scan ?? Cookie_Dialog_Scanner::result();
		$out    = array();
		foreach ( $scan['hosts'] as $host => $entry ) {
			if ( $entry['own'] || ! $entry['loaded'] || ! array_intersect( $entry['kinds'], array( 'script', 'iframe' ) ) ) {
				continue;
			}
			$service = Cookie_Dialog_Config::match( 'https://' . $host . '/' );
			foreach ( $entry['urls'] as $item ) {
				$service = $service ?? Cookie_Dialog_Config::match( $item['url'] );
			}
			if ( $service || $entry['service'] ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * What a host probably is: the library's preset, or what a model guessed.
	 *
	 * @param array $entry Host entry.
	 * @return array|null `name`, `category`, `purpose`, `preset`.
	 */
	public static function suggestion( $entry ) {
		if ( ! empty( $entry['preset'] ) ) {
			$preset = Cookie_Dialog_Config::preset( $entry['preset'] );
			if ( $preset ) {
				return array(
					'name'     => $preset['name'],
					'category' => $preset['category'],
					'purpose'  => self::short( Cookie_Dialog_Config::pick( $preset['purpose'], 'de' ) ),
					'preset'   => $preset['id'],
				);
			}
		}
		if ( ! empty( $entry['suggest']['name'] ) ) {
			return array(
				'name'     => (string) $entry['suggest']['name'],
				'category' => (string) ( $entry['suggest']['category'] ?? 'marketing' ),
				'purpose'  => self::short( (string) ( $entry['suggest']['purpose'] ?? '' ) ),
				'preset'   => '',
			);
		}
		return null;
	}

	/**
	 * The first clause of a purpose, for a line.
	 *
	 * @param string $text Purpose.
	 * @return string
	 */
	private static function short( $text ) {
		$text = trim( (string) preg_replace( '/[.:;].*$/s', '', $text ) );
		return mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 58 ) . '…' : $text;
	}

	/**
	 * A category's name in the admin's language.
	 *
	 * @param string $id     Category.
	 * @param array  $config Configuration.
	 * @return string
	 */
	public static function category_name( $id, $config ) {
		foreach ( $config['categories'] as $cat ) {
			if ( $cat['id'] === $id ) {
				return Cookie_Dialog_Config::pick( $cat['name'], substr( get_user_locale(), 0, 2 ) );
			}
		}
		return $id;
	}

	/**
	 * A host as people know it: the library's name where it has one.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	public static function host_name( $host ) {
		$preset = Cookie_Dialog_Config::match_preset( 'https://' . $host . '/' );
		return $preset ? $preset['name'] : $host;
	}

	/**
	 * Cookies the server set before consent that no necessary service names.
	 *
	 * @param array $config Configuration.
	 * @param array $scan   Scan.
	 * @return array Names.
	 */
	public static function unknown_cookies( $config, $scan ) {
		$known = array();
		foreach ( $config['services'] as $service ) {
			if ( 'necessary' === $service['category'] ) {
				foreach ( $service['cookies'] as $cookie ) {
					$known[] = $cookie['name'];
				}
			}
		}
		$out = array();
		foreach ( $scan['cookies'] as $cookie ) {
			$hit = false;
			foreach ( $known as $pattern ) {
				if ( $pattern === $cookie['name'] || ( str_ends_with( $pattern, '*' ) && str_starts_with( $cookie['name'], rtrim( $pattern, '*' ) ) ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit && ! preg_match( '/^(PHPSESSID|wp-wpml_current_language|pll_language)$/', $cookie['name'] ) ) {
				$out[] = $cookie['name'];
			}
		}
		return array_slice( $out, 0, 6 );
	}

	/**
	 * The relative luminance of a colour (WCAG).
	 *
	 * @param string $hex Colour.
	 * @return float
	 */
	public static function luminance( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}/i', $hex ) ) {
			return 1.0;
		}
		$c = array();
		foreach ( array( 0, 2, 4 ) as $i ) {
			$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$c[] = $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/**
	 * The contrast ratio of two colours.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Does one fix.
	 *
	 * @param string $action What.
	 * @param array  $args   With what.
	 * @return array|WP_Error `message`, and for jobs the browser runs `job`.
	 */
	public static function fix( $action, array $args = array() ) {
		$config = Cookie_Dialog_Config::get();
		switch ( $action ) {
			case 'localize':
				$copied = 0;
				$errors = array();
				foreach ( (array) ( $args['hosts'] ?? array() ) as $host ) {
					$result  = Cookie_Dialog_Resources::localize_host( sanitize_text_field( (string) $host ) );
					$copied += $result['copied'];
					$errors  = array_merge( $errors, $result['errors'] );
				}
				Cookie_Dialog_Scanner::run();
				return array(
					/* translators: %d: number of files */
					'message' => sprintf( _n( '%d Datei kommt jetzt von deinem Server.', '%d Dateien kommen jetzt von deinem Server.', $copied, 'cookie-dialog' ), $copied ),
					'errors'  => array_slice( $errors, 0, 5 ),
				);

			case 'adopt':
				$host  = sanitize_text_field( (string) ( $args['host'] ?? '' ) );
				$scan  = Cookie_Dialog_Scanner::result();
				$entry = $scan['hosts'][ $host ] ?? null;
				if ( ! $entry ) {
					return new WP_Error( 'cookie_dialog_host', __( 'Diesen Host kennt die letzte Prüfung nicht.', 'cookie-dialog' ) );
				}
				$suggest = self::suggestion( $entry );
				// Picked in the admin: a preset of the library, or a service of its own.
				if ( ! empty( $args['preset'] ) && Cookie_Dialog_Config::preset( sanitize_key( (string) $args['preset'] ) ) ) {
					$preset  = Cookie_Dialog_Config::preset( sanitize_key( (string) $args['preset'] ) );
					$suggest = array(
						'name'     => $preset['name'],
						'category' => $preset['category'],
						'purpose'  => '',
						'preset'   => $preset['id'],
					);
				} elseif ( ! empty( $args['name'] ) ) {
					$suggest = array(
						'name'     => sanitize_text_field( (string) $args['name'] ),
						'category' => sanitize_key( (string) ( $args['category'] ?? 'marketing' ) ),
						'purpose'  => sanitize_text_field( (string) ( $args['purpose'] ?? '' ) ),
						'provider' => sanitize_text_field( (string) ( $args['provider'] ?? '' ) ),
						'preset'   => '',
					);
				}
				if ( ! $suggest ) {
					return new WP_Error( 'cookie_dialog_host', __( 'Wähle einen Dienst oder gib ihm einen Namen.', 'cookie-dialog' ) );
				}
				$service = self::adopt( $host, $suggest, empty( $args['category'] ) ? null : sanitize_key( (string) $args['category'] ) );
				/* translators: 1: service, 2: category */
				return array( 'message' => sprintf( __( '%1$s → %2$s', 'cookie-dialog' ), $service['name'], self::category_name( $service['category'], Cookie_Dialog_Config::get() ) ) );

			case 'consent_mode':
				Cookie_Dialog_Config::patch( array( 'google' => array( 'consentMode' => true ) ) );
				return array( 'message' => __( 'Consent Mode v2 ist an.', 'cookie-dialog' ) );

			case 'reopen':
				Cookie_Dialog_Config::patch( array( 'reopen' => array( 'button' => true ) ) );
				return array( 'message' => __( 'Der Button zum Öffnen ist da.', 'cookie-dialog' ) );

			case 'contrast':
				$c = $config['design']['colors'];
				foreach ( array(
					'buttonText' => 'button',
					'text'       => 'background',
				) as $fg => $bg ) {
					if ( self::contrast( $c[ $fg ], $c[ $bg ] ) < 4.5 ) {
						$c[ $fg ] = self::contrast( '#ffffff', $c[ $bg ] ) >= self::contrast( '#1b1b1b', $c[ $bg ] ) ? '#ffffff' : '#1b1b1b';
					}
				}
				Cookie_Dialog_Config::patch( array( 'design' => array( 'colors' => $c ) ) );
				return array( 'message' => __( 'Farben angepasst.', 'cookie-dialog' ) );

			case 'insert_privacy':
				return self::insert_privacy( $config );

			case 'scan':
				Cookie_Dialog_Scanner::run();
				return array( 'message' => __( 'Website neu gelesen.', 'cookie-dialog' ) );
		}
		return new WP_Error( 'cookie_dialog_fix', __( 'Diese Aktion gibt es nicht.', 'cookie-dialog' ) );
	}

	/**
	 * A host becomes a service: the library's preset, or a service of its own from a guess.
	 *
	 * @param string      $host     Host.
	 * @param array|null  $suggest  The suggestion.
	 * @param string|null $category Another category.
	 * @return array The service.
	 */
	public static function adopt( $host, $suggest, $category = null ) {
		$config = Cookie_Dialog_Config::get();
		$preset = $suggest && $suggest['preset'] ? Cookie_Dialog_Config::preset( $suggest['preset'] ) : null;
		if ( $preset ) {
			$service = Cookie_Dialog_Config::service( $preset['id'], $config ) ?? Cookie_Dialog_Config::service_from_preset( $preset, $category, 'scan' );
		} else {
			$name    = $suggest['name'] ?? $host;
			$service = Cookie_Dialog_Config::sanitize_service(
				array(
					'id'       => sanitize_key( str_replace( '.', '-', $host ) ),
					'name'     => $name,
					'category' => $category ?? ( $suggest['category'] ?? 'marketing' ),
					'kind'     => 'script',
					'provider' => array(
						'name'    => $suggest['provider'] ?? '',
						'address' => '',
					),
					'purpose'  => array( 'de' => $suggest['purpose'] ?? '' ),
					'source'   => 'scan',
				)
			);
		}
		if ( ! in_array( $host, $service['hosts'], true ) ) {
			$service['hosts'][] = $host;
		}
		if ( $category ) {
			$service['category'] = $category;
		}
		$replaced = false;
		foreach ( $config['services'] as $i => $s ) {
			if ( $s['id'] === $service['id'] ) {
				$config['services'][ $i ] = $service;
				$replaced                 = true;
			}
		}
		if ( ! $replaced ) {
			$config['services'][] = $service;
		}
		/* translators: %s: service */
		Cookie_Dialog_Config::save( $config, sprintf( __( '%s hinzugefügt', 'cookie-dialog' ), $service['name'] ) );
		return $service;
	}

	/**
	 * The privacy page gets the cookie table and a paragraph: the assistant's when there is one,
	 * else the library's template.
	 *
	 * @param array $config Configuration.
	 * @return array|WP_Error
	 */
	public static function insert_privacy( $config ) {
		$id   = (int) $config['pages']['privacy'];
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'cookie_dialog_page', __( 'Wähle zuerst die Datenschutzerklärung.', 'cookie-dialog' ) );
		}
		$lang      = Cookie_Dialog_Config::site_language();
		$paragraph = $config['privacyText'][ $lang ] ?? self::privacy_template( $lang, $config );
		$content   = (string) $post->post_content;
		if ( ! has_block( 'cookie-dialog/cookie-table', $post ) && ! has_shortcode( $content, 'cookie_table' ) ) {
			$content .= "\n\n" . self::as_blocks( $paragraph ) . "\n\n<!-- wp:cookie-dialog/cookie-table /-->\n";
		}
		$result = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Cookie_Dialog_Scanner::run( 3 );
		Cookie_Dialog_Config::remember( __( 'Cookie-Tabelle in die Datenschutzerklärung eingefügt', 'cookie-dialog' ) );
		return array( 'message' => __( 'Eingefügt. Die Tabelle hält sich selbst aktuell.', 'cookie-dialog' ) );
	}

	/**
	 * The paragraph about cookies without an assistant.
	 *
	 * @param string $lang   Language.
	 * @param array  $config Configuration.
	 * @return string HTML.
	 */
	public static function privacy_template( $lang, $config ) {
		if ( 'de' === $lang ) {
			$sie = 'Sie' === $config['tone']['address'];
			return '<h2>Cookies und eingebundene Dienste</h2><p>Diese Website verwendet Cookies und ähnliche Techniken. Notwendige Cookies setzen wir ein, weil die Website ohne sie nicht funktioniert (§ 165 Abs. 3 TKG 2021, § 25 Abs. 2 TDDDG). Alle anderen Cookies und Dienste laden erst mit ' . ( $sie ? 'Ihrer' : 'deiner' ) . ' Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die ' . ( $sie ? 'Sie' : 'du' ) . ' über die Cookie-Einstellungen jederzeit widerrufen ' . ( $sie ? 'können' : 'kannst' ) . '. Welche Dienste wir nutzen, wer sie anbietet und welche Cookies sie setzen, zeigt die folgende Tabelle.</p>';
		}
		return '<h2>Cookies and embedded services</h2><p>This website uses cookies and similar technologies. We use necessary cookies because the website does not work without them. All other cookies and services only load with your consent (Art. 6(1)(a) GDPR), which you can withdraw at any time in the cookie settings. The following table shows which services we use, who provides them and which cookies they set.</p>';
	}

	/**
	 * HTML of headings and paragraphs as blocks.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function as_blocks( $html ) {
		$html = (string) preg_replace( '#<h2>(.*?)</h2>#s', "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">$1</h2>\n<!-- /wp:heading -->\n\n", (string) $html );
		return (string) preg_replace( '#<p>(.*?)</p>#s', "<!-- wp:paragraph -->\n<p>$1</p>\n<!-- /wp:paragraph -->\n\n", $html );
	}
}

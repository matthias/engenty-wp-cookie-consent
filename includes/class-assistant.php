<?php
/**
 * The assistant thinks through WordPress: `wp_ai_client_prompt()` with a JSON answer – a message,
 * changes and cards – that PHP checks and applies. No key of its own: the provider is the one set
 * up under Einstellungen › Konnektoren. Simple jobs (translating, shortening, naming an unknown host,
 * the privacy paragraph) can also run in the admin's browser, in Chrome's or Edge's built-in model:
 * the admin app gets the same prompt and schema and posts the answer back.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prompts, schemas, applying changes and undo.
 */
class Cookie_Dialog_Assistant {

	const USAGE = 'cookie_dialog_ai_usage';

	/** Jobs the browser's model may run. */
	const SIMPLE = array( 'translate', 'shorten', 'classify', 'privacy' );

	/** Models tried in order; the AI Client falls back to the provider's first model. */
	// The provider's first model it has from these. Newest first, then older ones for accounts
	// without them (2026-10: GPT-6 Luna, Claude Haiku 5.5 and Gemini 3.5 Flash-Lite cost $0.10 / $0.50
	// per million tokens; GPT-6.1 Sol and Claude Sonnet 5.5 $2 / $10).
	const MODELS = array(
		'cheap'  => array( 'wizards/standard', 'openai/gpt-6-luna', 'gpt-6-luna', 'anthropic/claude-haiku-5.5', 'claude-haiku-5-5', 'google/gemini-3.5-flash-lite', 'gemini-3.5-flash-lite', 'gpt-5.4-mini', 'claude-haiku-4-5', 'gemini-2.5-flash-lite', 'deepseek/deepseek-v3.2' ),
		'strong' => array( 'wizards/high', 'anthropic/claude-sonnet-5.5', 'claude-sonnet-5-5', 'openai/gpt-6.1-sol', 'gpt-6.1-sol', 'google/gemini-3.8-flash', 'gemini-3.8-flash', 'gpt-5.5', 'gemini-2.5-pro' ),
	);

	/** Settings the assistant may change with `set`. */
	const PATHS = '/^(design\.(layout|position|size|scheme|radius|font|fontFamily|headFont|backdrop|lock|branding|buttons|showTitle|showLinks|showLanguages|colors\.(background|text|button|buttonText|link)|dark\.(background|text|button|buttonText|link))|tone\.(address|style)|google\.(consentMode|mode|gtm|regions|everywhere|urlPassthrough|adsRedaction)|validity\.(months|reaskOnChange|gpc)|reopen\.(button|position)|log\.months|pages\.small|thirdParty\.(unknownIframes|thumbnails|always|nocookie|gravatar|emoji|hints)|scan\.weekly)$/';

	/**
	 * What the admin shows: whether WordPress has an AI Client, whether a provider is set up, which.
	 *
	 * @return array
	 */
	public static function status() {
		$config = Cookie_Dialog_Config::get();
		$has_wp = function_exists( 'wp_ai_client_prompt' );
		$ready  = false;
		$names  = array();
		if ( $has_wp && function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			try {
				$registry = \WordPress\AiClient\AiClient::defaultRegistry();
				foreach ( $registry->getRegisteredProviderIds() as $id ) {
					if ( $registry->isProviderConfigured( $id ) ) {
						$class   = $registry->getProviderClassName( $id );
						$names[] = $class::metadata()->getName();
					}
				}
				$ready = $names && (bool) wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
			} catch ( \Throwable $e ) {
				$ready = false;
			}
		}
		$usage = get_option( self::USAGE, array() );
		return array(
			'wp'         => $has_wp,
			'enabled'    => $has_wp && function_exists( 'wp_supports_ai' ) && wp_supports_ai(),
			'ready'      => $ready,
			'providers'  => $names,
			'offers'     => self::offers(),
			'last'       => is_array( $usage ) ? ( $usage['last'] ?? '' ) : '',
			'tokens'     => is_array( $usage ) && ( $usage['month'] ?? '' ) === gmdate( 'Y-m' ) ? (int) ( $usage['tokens'] ?? 0 ) : 0,
			'browser'    => (bool) $config['assistant']['browser'],
			'model'      => $config['assistant']['model'],
			'connectors' => admin_url( 'options-connectors.php' ),
			'vercel'     => admin_url( 'plugin-install.php?s=' . rawurlencode( 'AI Provider for Vercel' ) . '&tab=search&type=term' ),
			'update'     => admin_url( 'update-core.php' ),
		);
	}

	/**
	 * The AI providers WordPress lists under Einstellungen › Konnektoren, each with how far it is set
	 * up: `ready`, `key` (the plugin is there, the API Key is missing), `offline` (needs no key but
	 * does not answer, like Ollama when it is not running) or `plugin` (its plugin is not installed).
	 *
	 * @return array[] `id`, `name`, `logo`, `state`.
	 */
	private static function offers() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return array();
		}
		$offers = array();
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			foreach ( wp_get_connectors() as $id => $connector ) {
				if ( 'ai_provider' !== ( $connector['type'] ?? '' ) ) {
					continue;
				}
				$active = is_callable( $connector['plugin']['is_active'] ?? null ) ? (bool) call_user_func( $connector['plugin']['is_active'] ) : true;
				$state  = 'plugin';
				if ( $active && $registry->hasProvider( $id ) ) {
					if ( $registry->isProviderConfigured( $id ) ) {
						$state = 'ready';
					} else {
						$state = 'none' === ( $connector['authentication']['method'] ?? '' ) ? 'offline' : 'key';
					}
				}
				$offers[] = array(
					'id'    => (string) $id,
					'name'  => (string) $connector['name'],
					'logo'  => (string) ( $connector['logo_url'] ?? '' ),
					'state' => $state,
				);
			}
		} catch ( \Throwable $e ) {
			return $offers;
		}
		return $offers;
	}

	/**
	 * Asks the provider for a JSON answer.
	 *
	 * @param string $system The system instruction.
	 * @param string $prompt The prompt.
	 * @param array  $schema The answer's JSON schema.
	 * @return array|WP_Error The decoded answer.
	 */
	public static function generate( $system, $prompt, array $schema ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'cookie_dialog_no_ai', __( 'Der Assistent braucht WordPress 7.0 oder neuer.', 'cookie-dialog' ) );
		}
		$config  = Cookie_Dialog_Config::get()['assistant'];
		$models  = 'custom' === $config['model'] && $config['custom'] ? array( $config['custom'] ) : self::MODELS[ 'strong' === $config['model'] ? 'strong' : 'cheap' ];
		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system )
			->using_model_preference( ...$models )
			// No temperature: OpenAI's reasoning models (gpt-5, gpt-5-mini) refuse the parameter. Room for
			// their reasoning, which counts against the limit too.
			->using_max_tokens( 16000 )
			->as_json_response( self::strict( $schema ) );
		$result = $builder->generate_text_result();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		try {
			$text             = $result->toText();
			$usage            = get_option( self::USAGE, array() );
			$usage            = is_array( $usage ) && ( $usage['month'] ?? '' ) === gmdate( 'Y-m' ) ? $usage : array(
				'month'  => gmdate( 'Y-m' ),
				'tokens' => 0,
			);
			$usage['tokens'] += $result->getTokenUsage()->getTotalTokens();
			$usage['last']    = trim( $result->getProviderMetadata()->getName() . ', ' . $result->getModelMetadata()->getName(), ', ' );
			update_option( self::USAGE, $usage, false );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'cookie_dialog_ai', $e->getMessage() );
		}
		$data = self::decode( $text );
		return null === $data ? new WP_Error( 'cookie_dialog_ai_json', __( 'Die Antwort war kein JSON. Versuch es noch einmal.', 'cookie-dialog' ) ) : (array) self::loose( $data, $schema );
	}

	/**
	 * A schema as providers check it strictly (OpenAI's structured outputs, and the strictest the
	 * others ask): every object closed and with all its properties required, an optional one may be
	 * null, a map becomes a list of key and value, a union of types an anyOf.
	 *
	 * @param array $schema   The schema as written here.
	 * @param bool  $nullable Whether the value may be missing (null).
	 * @return array
	 */
	public static function strict( array $schema, $nullable = false ) {
		$type = $schema['type'] ?? null;
		if ( 'object' === $type && is_array( $schema['additionalProperties'] ?? null ) && empty( $schema['properties'] ) ) {
			$out = array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'properties'           => array(
						'key'   => array( 'type' => 'string' ),
						'value' => self::strict( $schema['additionalProperties'] ),
					),
					'required'             => array( 'key', 'value' ),
					'additionalProperties' => false,
				),
			);
		} elseif ( 'object' === $type ) {
			$required   = (array) ( $schema['required'] ?? array() );
			$properties = array();
			foreach ( (array) ( $schema['properties'] ?? array() ) as $key => $property ) {
				$properties[ $key ] = self::strict( $property, ! in_array( $key, $required, true ) );
			}
			$out = array(
				'type'                 => 'object',
				'properties'           => $properties ? $properties : new stdClass(),
				'required'             => array_keys( $properties ),
				'additionalProperties' => false,
			);
		} elseif ( 'array' === $type ) {
			$out          = $schema;
			$out['items'] = self::strict( (array) ( $schema['items'] ?? array( 'type' => 'string' ) ) );
		} elseif ( is_array( $type ) ) {
			$out = array(
				'anyOf' => array_map(
					static fn( $t ) => 'array' === $t ? array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					) : array( 'type' => $t ),
					$type
				),
			);
		} else {
			$out = $schema;
		}
		if ( ! $nullable ) {
			return $out;
		}
		if ( isset( $out['anyOf'] ) ) {
			$out['anyOf'][] = array( 'type' => 'null' );
			return $out;
		}
		if ( in_array( $out['type'] ?? '', array( 'object', 'array' ), true ) ) {
			return array( 'anyOf' => array( $out, array( 'type' => 'null' ) ) );
		}
		$out['type'] = array( $out['type'] ?? 'string', 'null' );
		if ( isset( $out['enum'] ) ) {
			$out['enum'][] = null;
		}
		return $out;
	}

	/**
	 * An answer to a strict() schema in the shape the schema here describes: lists of key and value
	 * back to maps, missing (null) values left out.
	 *
	 * @param mixed $value  The answer.
	 * @param array $schema The schema as written here.
	 * @return mixed
	 */
	public static function loose( $value, array $schema ) {
		$type = $schema['type'] ?? null;
		if ( 'object' === $type && is_array( $schema['additionalProperties'] ?? null ) && empty( $schema['properties'] ) ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				return $value;
			}
			$map = array();
			foreach ( $value as $pair ) {
				if ( is_array( $pair ) && isset( $pair['key'] ) && array_key_exists( 'value', $pair ) ) {
					$map[ (string) $pair['key'] ] = self::loose( $pair['value'], $schema['additionalProperties'] );
				}
			}
			return $map;
		}
		if ( 'object' === $type && is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( null === $item ) {
					continue;
				}
				$property    = $schema['properties'][ $key ] ?? null;
				$out[ $key ] = is_array( $property ) ? self::loose( $item, $property ) : $item;
			}
			return $out;
		}
		if ( 'array' === $type && is_array( $value ) && is_array( $schema['items'] ?? null ) ) {
			return array_map( static fn( $item ) => self::loose( $item, $schema['items'] ), $value );
		}
		return $value;
	}

	/**
	 * JSON from a model's text, also inside a code fence.
	 *
	 * @param string $text Text.
	 * @return array|null
	 */
	public static function decode( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/```(?:json)?\s*(.*?)```/s', $text, $m ) ) {
			$text = trim( $m[1] );
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) && preg_match( '/\{.*\}/s', $text, $m ) ) {
			$data = json_decode( $m[0], true );
		}
		return is_array( $data ) ? $data : null;
	}

	/**
	 * The admin's language for answers.
	 *
	 * @return string
	 */
	private static function admin_language() {
		return str_starts_with( get_user_locale(), 'de' ) ? 'German (informal "du")' : 'English';
	}

	/**
	 * The configuration as the model needs it.
	 *
	 * @return array
	 */
	private static function context() {
		$config = Cookie_Dialog_Config::get();
		$scan   = Cookie_Dialog_Scanner::result();
		$texts  = array();
		foreach ( $config['languages'] as $lang ) {
			$t              = Cookie_Dialog_Config::texts( $lang, $config );
			$texts[ $lang ] = array_intersect_key( $t, array_flip( array( 'title', 'text', 'accept', 'reject', 'settings', 'save', 'layerTitle', 'layerText' ) ) );
		}
		return array(
			'site'       => get_bloginfo( 'name' ),
			'url'        => home_url( '/' ),
			'live'       => $config['live'],
			'version'    => $config['version'],
			'design'     => $config['design'],
			'tone'       => $config['tone'],
			'languages'  => $config['languages'],
			'texts'      => $texts,
			'categories' => array_map(
				static fn( $c ) => array(
					'id'      => $c['id'],
					'name'    => Cookie_Dialog_Config::pick( $c['name'], 'de' ),
					'enabled' => $c['enabled'],
				),
				$config['categories']
			),
			'services'   => array_map(
				static fn( $s ) => array(
					'id'       => $s['id'],
					'name'     => $s['name'],
					'category' => $s['category'],
					'kind'     => $s['kind'],
					'local'    => $s['local'] ?? null,
				),
				$config['services']
			),
			'google'     => $config['google'],
			'validity'   => $config['validity'],
			'reopen'     => $config['reopen'],
			'thirdParty' => $config['thirdParty'],
			'log'        => $config['log'],
			// What the last scan saw the site load from third parties, and where.
			'scan'       => array(
				'at'      => $scan['at'] ? gmdate( 'Y-m-d H:i', (int) $scan['at'] ) . ' UTC' : null,
				'pages'   => count( $scan['pages'] ),
				'hosts'   => array_values(
					array_map(
						static fn( $h ) => array(
							'host'    => $h['host'],
							'service' => $h['service'],
							'kinds'   => $h['kinds'],
							'pages'   => array_slice( (array) $h['pages'], 0, 3 ),
							'urls'    => array_slice( array_column( (array) $h['urls'], 'url' ), 0, 2 ),
							'held'    => (bool) $h['held'],
						),
						$scan['hosts']
					)
				),
				'cookies' => array_column( $scan['cookies'], 'name' ),
			),
		);
	}

	/**
	 * The schema of a chat answer.
	 *
	 * @return array
	 */
	public static function answer_schema() {
		$str = array( 'type' => 'string' );
		return array(
			'type'       => 'object',
			'properties' => array(
				'message' => $str,
				'changes' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'op'          => array(
								'type' => 'string',
								'enum' => array( 'set', 'text', 'texts', 'add_service', 'remove_service', 'service', 'remove_language', 'category', 'privacy_text' ),
							),
							'path'        => $str,
							'value'       => array( 'type' => array( 'string', 'number', 'boolean', 'array' ) ),
							'lang'        => $str,
							'key'         => $str,
							'texts'       => array(
								'type'                 => 'object',
								'additionalProperties' => $str,
							),
							'preset'      => $str,
							'id'          => $str,
							'name'        => $str,
							'category'    => $str,
							'provider'    => $str,
							'purpose'     => $str,
							'hosts'       => array(
								'type'  => 'array',
								'items' => $str,
							),
							'description' => $str,
						),
						'required'   => array( 'op' ),
					),
				),
				'cards'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title' => $str,
							'text'  => $str,
						),
						'required'   => array( 'title', 'text' ),
					),
				),
			),
			'required'   => array( 'message', 'changes', 'cards' ),
		);
	}

	/**
	 * One turn of the chat.
	 *
	 * @param string $message What the person wrote.
	 * @param array  $history Earlier turns: `role` (me, assistant), `text`.
	 * @param string $view    The admin page the person is on.
	 * @return array|WP_Error `message`, `cards`, `undo`, `version`.
	 */
	public static function ask( $message, array $history = array(), $view = 'start' ) {
		$library = array();
		foreach ( Cookie_Dialog_Config::library() as $preset ) {
			$library[] = $preset['id'] . ' (' . $preset['name'] . ', ' . $preset['category'] . ')';
		}
		$stats  = Cookie_Dialog_Consent::stats( array( 'range' => '30d' ) );
		$system = implode(
			"\n",
			array(
				'You are the assistant inside the WordPress plugin "Cookie Dialog", an EU cookie consent dialog. You help the site owner by changing the plugin\'s configuration and by answering questions about it.',
				'Answer with one JSON object: "message" (your reply, short, in ' . self::admin_language() . '), "changes" (operations the plugin applies), "cards" (one card per change you made: title and a short text; empty when nothing changed).',
				'Operations: {"op":"set","path":"design.colors.button","value":"#1f3d2b"} for settings (paths: design.layout box|bar|modal, design.position left|right|center, design.size s|m|l, design.scheme light|dark|auto, design.radius 0-32, design.colors.{background,text,button,buttonText,link} and design.dark.* as hex, design.buttons same|accept|outline (both buttons filled, accept filled and only necessary outlined, both outlined), design.showTitle and design.showLinks true|false (the title and the privacy and imprint links on the first layer), design.showLanguages true|false (visitors pick the language of the dialog when there are several), design.backdrop, design.lock, tone.address du|Sie, tone.style, google.mode basic|advanced, google.gtm, google.consentMode, google.regions [EEA,UK,CH], google.everywhere, validity.months 1-12, reopen.button, reopen.position left|right, log.months, thirdParty.*); {"op":"text","lang":"de","key":"title","value":"…"} for one dialog text (keys: title, text, accept, reject, settings, save, layerTitle, layerText, privacy, imprint); {"op":"texts","lang":"it","texts":{…}} to add or translate a language (all keys above); {"op":"remove_language","lang":"it"}; {"op":"add_service","preset":"hotjar"} from the library, or {"op":"add_service","name":"…","category":"…","provider":"…","purpose":"…","hosts":["…"]} for one the library lacks; {"op":"remove_service","id":"…"}; {"op":"service","id":"…","category":"…","purpose":"…","lang":"de"}; {"op":"category","id":"statistics","lang":"de","name":"…","description":"…"}; {"op":"privacy_text","lang":"de","value":"<h2>…</h2><p>…</p>"} for the cookie paragraph of the privacy policy.',
				'Rules you never break, even when asked: rejecting stays as easy as accepting (the plugin always shows "accept all" and "only necessary" as two equal buttons on the first layer); nothing is pre-selected; texts do not pressure, guilt or mislead; necessary services stay necessary only when the site cannot work without them; Consent Mode stays on when Google services are used. When asked for something that breaks them, explain why in one sentence and offer what is allowed.',
				'Texts on the site use {site} for the site name. Keep dialog texts short: a title of a few words, a text of two sentences.',
				'For questions (numbers, what loads, what a setting does) answer from the context and return no changes. Do not invent services, cookies or numbers. What the site loads and where is in context.config.scan (the last scan: hosts with the pages and URLs they load on); a service or host that is not there was not seen on the site, say so. The library only lists services the plugin knows, not what the site uses.',
				'The person is on the admin page "' . $view . '".',
			)
		);
		$turns  = array();
		foreach ( array_slice( $history, -8 ) as $turn ) {
			$turns[] = ( 'me' === ( $turn['role'] ?? '' ) ? 'Owner: ' : 'Assistant: ' ) . wp_strip_all_tags( (string) ( $turn['text'] ?? '' ) );
		}
		$prompt = 'Context (JSON): ' . wp_json_encode(
			array(
				'config'     => self::context(),
				'library'    => $library,
				'unassigned' => array_map( static fn( $e ) => $e['host'], Cookie_Dialog_Checks::unassigned() ),
				'stats30d'   => $stats,
			)
		) . "\n\n" . ( $turns ? "Earlier:\n" . implode( "\n", $turns ) . "\n\n" : '' ) . 'Owner: ' . $message;

		$answer = self::generate( $system, $prompt, self::answer_schema() );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$changes = is_array( $answer['changes'] ?? null ) ? $answer['changes'] : array();
		$undo    = $changes ? self::keep_undo() : null;
		$applied = $changes ? self::apply( $changes, wp_strip_all_tags( (string) $message ) ) : array( 'cards' => array() );
		$cards   = array();
		foreach ( (array) ( $answer['cards'] ?? array() ) as $card ) {
			if ( is_array( $card ) && ! empty( $card['title'] ) ) {
				$cards[] = array(
					'title' => sanitize_text_field( (string) $card['title'] ),
					'text'  => sanitize_text_field( (string) ( $card['text'] ?? '' ) ),
				);
			}
		}
		if ( ! $cards ) {
			$cards = $applied['cards'];
		}
		$config = Cookie_Dialog_Config::get();
		return array(
			'message' => sanitize_textarea_field( (string) ( $answer['message'] ?? '' ) ),
			'cards'   => ! empty( $applied['applied'] ) ? $cards : array(),
			'skipped' => $applied['skipped'] ?? array(),
			'undo'    => ! empty( $applied['applied'] ) ? $undo : null,
			'version' => (int) $config['version'],
		);
	}

	/**
	 * Applies operations of an answer; what is not allowed is skipped.
	 *
	 * @param array  $changes Operations.
	 * @param string $note    What the person asked, for the version's note.
	 * @return array `applied`, `skipped`, `cards`.
	 */
	public static function apply( array $changes, $note = '' ) {
		$config  = Cookie_Dialog_Config::get();
		$applied = 0;
		$skipped = array();
		$cards   = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$op   = (string) ( $change['op'] ?? '' );
			$lang = Cookie_Dialog_Config::lang_code( $change['lang'] ?? '' );
			switch ( $op ) {
				case 'set':
					$path = (string) ( $change['path'] ?? '' );
					if ( ! preg_match( self::PATHS, $path ) ) {
						$skipped[] = $path;
						continue 2;
					}
					$ref = &$config;
					foreach ( explode( '.', $path ) as $part ) {
						$ref = &$ref[ $part ];
					}
					$ref = $change['value'] ?? null;
					unset( $ref );
					++$applied;
					break;

				case 'text':
					$key = (string) ( $change['key'] ?? '' );
					if ( ! $lang || ! preg_match( '/^[a-zA-Z]{2,20}$/', $key ) || ! is_string( $change['value'] ?? null ) ) {
						$skipped[] = 'text';
						continue 2;
					}
					$config['texts'][ $lang ][ $key ] = $change['value'];
					++$applied;
					break;

				case 'texts':
					if ( ! $lang || ! is_array( $change['texts'] ?? null ) ) {
						$skipped[] = 'texts';
						continue 2;
					}
					foreach ( $change['texts'] as $key => $value ) {
						if ( is_string( $value ) && preg_match( '/^[a-zA-Z]{2,20}$/', (string) $key ) ) {
							$config['texts'][ $lang ][ $key ] = $value;
						}
					}
					if ( ! in_array( $lang, $config['languages'], true ) ) {
						$config['languages'][] = $lang;
					}
					++$applied;
					break;

				case 'remove_language':
					if ( $lang && count( $config['languages'] ) > 1 ) {
						$config['languages'] = array_values( array_diff( $config['languages'], array( $lang ) ) );
						++$applied;
					}
					break;

				case 'add_service':
					$preset = Cookie_Dialog_Config::preset( sanitize_key( (string) ( $change['preset'] ?? '' ) ) );
					if ( $preset ) {
						if ( Cookie_Dialog_Config::service( $preset['id'], $config ) ) {
							continue 2;
						}
						$config['services'][] = Cookie_Dialog_Config::service_from_preset( $preset, null, 'assistant' );
					} elseif ( ! empty( $change['name'] ) ) {
						$config['services'][] = Cookie_Dialog_Config::sanitize_service(
							array(
								'id'       => sanitize_key( (string) ( $change['id'] ?? $change['name'] ) ),
								'name'     => (string) $change['name'],
								'category' => (string) ( $change['category'] ?? 'marketing' ),
								'provider' => array(
									'name'    => (string) ( $change['provider'] ?? '' ),
									'address' => '',
								),
								'purpose'  => array( ( $lang ? $lang : 'de' ) => (string) ( $change['purpose'] ?? '' ) ),
								'hosts'    => (array) ( $change['hosts'] ?? array() ),
								'source'   => 'assistant',
							)
						);
					} else {
						$skipped[] = 'add_service';
						continue 2;
					}
					++$applied;
					break;

				case 'remove_service':
					$id                 = sanitize_key( (string) ( $change['id'] ?? '' ) );
					$before             = count( $config['services'] );
					$config['services'] = array_values( array_filter( $config['services'], static fn( $s ) => $s['id'] !== $id || 'cookie-dialog' === $s['id'] ) );
					$applied           += $before - count( $config['services'] );
					break;

				case 'service':
					$id = sanitize_key( (string) ( $change['id'] ?? '' ) );
					foreach ( $config['services'] as &$service ) {
						if ( $service['id'] !== $id ) {
							continue;
						}
						if ( ! empty( $change['category'] ) && 'cookie-dialog' !== $id ) {
							$service['category'] = sanitize_key( (string) $change['category'] );
						}
						if ( ! empty( $change['purpose'] ) ) {
							$service['purpose'][ $lang ? $lang : 'de' ] = (string) $change['purpose'];
						}
						if ( ! empty( $change['name'] ) ) {
							$service['name'] = (string) $change['name'];
						}
						++$applied;
					}
					unset( $service );
					break;

				case 'category':
					$id = sanitize_key( (string) ( $change['id'] ?? '' ) );
					foreach ( $config['categories'] as &$cat ) {
						if ( $cat['id'] === $id ) {
							if ( ! empty( $change['name'] ) ) {
								$cat['name'][ $lang ? $lang : 'de' ] = (string) $change['name'];
							}
							if ( ! empty( $change['description'] ) ) {
								$cat['description'][ $lang ? $lang : 'de' ] = (string) $change['description'];
							}
							++$applied;
						}
					}
					unset( $cat );
					break;

				case 'privacy_text':
					if ( $lang && is_string( $change['value'] ?? null ) ) {
						$config['privacyText'][ $lang ] = $change['value'];
						++$applied;
					}
					break;

				default:
					$skipped[] = $op;
			}
		}
		if ( $applied ) {
			/* translators: %s: what the person asked */
			Cookie_Dialog_Config::save( $config, $note ? sprintf( __( 'Assistent: %s', 'cookie-dialog' ), mb_substr( $note, 0, 80 ) ) : __( 'Assistent', 'cookie-dialog' ) );
			/* translators: %s: what the person asked */
			Cookie_Dialog_Config::remember( sprintf( __( 'Assistent: %s', 'cookie-dialog' ), mb_substr( $note, 0, 80 ) ) );
			$cards[] = array(
				'title' => __( 'Geändert', 'cookie-dialog' ),
				/* translators: %d: number of changes */
				'text'  => sprintf( _n( '%d Änderung', '%d Änderungen', $applied, 'cookie-dialog' ), $applied ),
			);
		}
		return array(
			'applied' => $applied,
			'skipped' => $skipped,
			'cards'   => $cards,
		);
	}

	/**
	 * Keeps the configuration as it is, for undo.
	 *
	 * @return string The token.
	 */
	public static function keep_undo() {
		$token = wp_generate_password( 16, false );
		set_transient( 'cookie_dialog_undo_' . get_current_user_id() . '_' . $token, get_option( Cookie_Dialog_Config::OPTION, array() ), DAY_IN_SECONDS );
		return $token;
	}

	/**
	 * Puts the configuration back as it was before an answer.
	 *
	 * @param string $token The token.
	 * @return array|WP_Error The configuration.
	 */
	public static function undo( $token ) {
		$key    = 'cookie_dialog_undo_' . get_current_user_id() . '_' . preg_replace( '/[^a-zA-Z0-9]/', '', (string) $token );
		$before = get_transient( $key );
		if ( ! is_array( $before ) ) {
			return new WP_Error( 'cookie_dialog_undo', __( 'Das lässt sich nicht mehr zurücknehmen.', 'cookie-dialog' ) );
		}
		delete_transient( $key );
		$current           = Cookie_Dialog_Config::get();
		$before['live']    = $current['live'];
		$before['version'] = $current['version'];
		$before['reask']   = $current['reask'];
		Cookie_Dialog_Config::remember( __( 'Assistent: Änderung zurückgenommen', 'cookie-dialog' ) );
		return Cookie_Dialog_Config::save( $before, __( 'Zurückgenommen', 'cookie-dialog' ) );
	}

	/**
	 * The prompt of a job, for the provider or the browser's model.
	 *
	 * @param string $job   translate, shorten, classify, privacy, setup.
	 * @param array  $input The job's input.
	 * @return array|WP_Error `system`, `prompt`, `schema`.
	 */
	public static function job_prompt( $job, array $input ) {
		$config = Cookie_Dialog_Config::get();
		$str    = array( 'type' => 'string' );
		switch ( $job ) {
			case 'translate':
				$lang   = Cookie_Dialog_Config::lang_code( $input['lang'] ?? '' );
				$source = in_array( 'de', $config['languages'], true ) && 'de' !== $lang ? 'de' : 'en';
				$texts  = Cookie_Dialog_Config::texts( $source, $config );
				unset( $texts['units'], $texts['types'] );
				$cats = array();
				foreach ( $config['categories'] as $cat ) {
					$cats[ $cat['id'] ] = array(
						'name'        => Cookie_Dialog_Config::pick( $cat['name'], $source ),
						'description' => Cookie_Dialog_Config::pick( $cat['description'], $source ),
					);
				}
				$site = get_bloginfo( 'name' );
				foreach ( $texts as $k => $v ) {
					$texts[ $k ] = str_replace( $site, '{site}', $v );
				}
				return array(
					'source'  => $source,
					'target'  => $lang,
					'strings' => array(
						'texts'      => $texts,
						'categories' => $cats,
					),
					'system'  => 'You translate the texts of a cookie consent dialog. Keep the meaning exact and the tone; keep placeholders like {site}, {service}, {provider} and %d unchanged; keep it short. Answer with JSON only.',
					'prompt'  => 'Translate from ' . $source . ' to the language with the code "' . $lang . '". Texts: ' . wp_json_encode( $texts, JSON_UNESCAPED_UNICODE ) . ' Categories: ' . wp_json_encode( $cats, JSON_UNESCAPED_UNICODE ),
					'schema'  => array(
						'type'       => 'object',
						'properties' => array(
							'texts'      => array(
								'type'                 => 'object',
								'additionalProperties' => $str,
							),
							'categories' => array(
								'type'                 => 'object',
								'additionalProperties' => array(
									'type'       => 'object',
									'properties' => array(
										'name'        => $str,
										'description' => $str,
									),
								),
							),
						),
						'required'   => array( 'texts' ),
					),
				);

			case 'shorten':
				$lang = Cookie_Dialog_Config::lang_code( $input['lang'] ?? '' );
				$lang = $lang ? $lang : $config['languages'][0];
				$text = Cookie_Dialog_Config::texts( $lang, $config )['text'];
				return array(
					'system' => 'You edit the text of a cookie consent dialog. Keep every fact (what is necessary, that the rest needs consent, that the choice can be changed), drop filler. Answer with JSON only.',
					'prompt' => 'Make this shorter, at most two sentences, same language: ' . $text,
					'schema' => array(
						'type'       => 'object',
						'properties' => array( 'text' => $str ),
						'required'   => array( 'text' ),
					),
				);

			case 'classify':
				$host  = sanitize_text_field( (string) ( $input['host'] ?? '' ) );
				$entry = Cookie_Dialog_Scanner::result()['hosts'][ $host ] ?? array();
				return array(
					'system' => 'You identify third-party services that websites load, for a cookie consent dialog in the EU. Answer with JSON only. If you do not know the service, say so in the name ("Unbekannt") and pick the category "marketing".',
					'prompt' => 'Host: ' . $host . '. URLs: ' . wp_json_encode( array_slice( array_column( (array) ( $entry['urls'] ?? array() ), 'url' ), 0, 5 ) ) . '. Found on pages: ' . implode( ', ', (array) ( $entry['pages'] ?? array() ) ) . '. Give: name of the service, provider (company), category (necessary, functional, statistics or marketing) and purpose (one short German sentence).',
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'name'     => $str,
							'provider' => $str,
							'category' => array(
								'type' => 'string',
								'enum' => Cookie_Dialog_Config::CATEGORIES,
							),
							'purpose'  => $str,
						),
						'required'   => array( 'name', 'category', 'purpose' ),
					),
				);

			case 'privacy':
				$lang     = Cookie_Dialog_Config::lang_code( $input['lang'] ?? '' );
				$lang     = $lang ? $lang : Cookie_Dialog_Config::site_language();
				$services = array_map(
					static fn( $s ) => $s['name'] . ' (' . $s['provider']['name'] . ', ' . $s['category'] . ')',
					Cookie_Dialog_Config::dialog_services( $config )
				);
				return array(
					'system' => 'You write the section about cookies of a website\'s privacy policy, for the EU (GDPR, ePrivacy; Austria: § 165 Abs. 3 TKG 2021; Germany: § 25 TDDDG). Plain and correct, no legal advice disclaimers, no invented facts. A table of all services and cookies follows your text automatically, so do not list cookies. Answer with JSON only.',
					'prompt' => 'Language: ' . $lang . '. Form of address: ' . $config['tone']['address'] . '. Site: ' . get_bloginfo( 'name' ) . '. Services: ' . implode( '; ', $services ) . '. Consent is stored for ' . $config['validity']['months'] . ' months; it can be changed or withdrawn any time via the cookie settings link. Google Consent Mode: ' . ( $config['google']['consentMode'] ? 'on' : 'off' ) . '. Write HTML with one <h2> and two to four <p>.',
					'schema' => array(
						'type'       => 'object',
						'properties' => array( 'html' => $str ),
						'required'   => array( 'html' ),
					),
				);

			case 'setup':
				$front = (int) get_option( 'page_on_front' );
				$text  = get_bloginfo( 'name' ) . '. ' . get_bloginfo( 'description' ) . '. ';
				if ( $front ) {
					$text .= wp_strip_all_tags( (string) get_post_field( 'post_content', $front ) );
				}
				foreach ( get_posts(
					array(
						'post_type'      => array( 'page', 'post' ),
						'posts_per_page' => 3,
					)
				) as $post ) {
					$text .= ' ' . wp_strip_all_tags( $post->post_title . '. ' . $post->post_content );
				}
				$text = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $text ) ), 0, 3000 );
				$keys = array( 'title', 'text', 'layerTitle', 'layerText' );
				return array(
					'system' => 'You write the texts of a cookie consent dialog in the tone of the website they appear on. EU rules: no pressure, no guilt, no claims that cookies are needed for things that are optional; mention that the choice can be changed. Title: a few words, may use the site name. Text: at most two sentences. Answer with JSON only.',
					'prompt' => 'The website\'s own words: "' . $text . '". Languages: ' . implode( ', ', $config['languages'] ) . '. Categories in use: ' . implode( ', ', wp_list_pluck( Cookie_Dialog_Config::dialog_categories( $config ), 'id' ) ) . '. Decide the form of address the site uses in German (du or Sie) and describe its tone in one or two German words. Write ' . implode( ', ', $keys ) . ' for every language.',
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'address' => array(
								'type' => 'string',
								'enum' => array( 'du', 'Sie' ),
							),
							'style'   => $str,
							'texts'   => array(
								'type'                 => 'object',
								'additionalProperties' => array(
									'type'       => 'object',
									'properties' => array_fill_keys( $keys, $str ),
								),
							),
						),
						'required'   => array( 'address', 'style', 'texts' ),
					),
				);
		}
		return new WP_Error( 'cookie_dialog_job', __( 'Diese Aufgabe gibt es nicht.', 'cookie-dialog' ) );
	}

	/**
	 * Runs a job on the provider, or applies the answer the browser's model gave.
	 *
	 * @param string     $job    The job.
	 * @param array      $input  Its input.
	 * @param array|null $result The browser's answer, if it ran there.
	 * @return array|WP_Error What was done, or for the browser `prompt` and `schema` to run.
	 */
	public static function job( $job, array $input, $result = null ) {
		$prompt = self::job_prompt( $job, $input );
		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}
		if ( null === $result ) {
			$status = self::status();
			if ( ! $status['ready'] ) {
				if ( in_array( $job, self::SIMPLE, true ) && $status['browser'] ) {
					// The browser's model gets the prompt; for translating also the texts themselves, which
					// Chrome's Translator takes one by one.
					return array(
						'browser' => true,
						'system'  => $prompt['system'],
						'prompt'  => $prompt['prompt'],
						'schema'  => $prompt['schema'],
						'source'  => $prompt['source'] ?? null,
						'target'  => $prompt['target'] ?? null,
						'strings' => $prompt['strings'] ?? null,
					);
				}
				return new WP_Error( 'cookie_dialog_no_provider', __( 'Dafür braucht WordPress einen KI-Anbieter (Einstellungen › Konnektoren).', 'cookie-dialog' ) );
			}
			$result = self::generate( $prompt['system'], $prompt['prompt'], $prompt['schema'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return self::apply_job( $job, $input, (array) $result );
	}

	/**
	 * Applies a job's answer.
	 *
	 * @param string $job    The job.
	 * @param array  $input  Its input.
	 * @param array  $result The answer.
	 * @return array|WP_Error `message`, `undo`.
	 */
	public static function apply_job( $job, array $input, array $result ) {
		$config = Cookie_Dialog_Config::get();
		$undo   = self::keep_undo();
		switch ( $job ) {
			case 'translate':
				$lang = Cookie_Dialog_Config::lang_code( $input['lang'] ?? '' );
				if ( ! $lang || ! is_array( $result['texts'] ?? null ) ) {
					return new WP_Error( 'cookie_dialog_job', __( 'Die Übersetzung kam unvollständig zurück.', 'cookie-dialog' ) );
				}
				$site = get_bloginfo( 'name' );
				foreach ( $result['texts'] as $key => $value ) {
					if ( is_string( $value ) && preg_match( '/^[a-zA-Z]{2,20}$/', (string) $key ) && ! in_array( $key, array( 'units', 'types' ), true ) ) {
						$config['texts'][ $lang ][ $key ] = str_replace( $site, '{site}', $value );
					}
				}
				foreach ( (array) ( $result['categories'] ?? array() ) as $id => $cat ) {
					foreach ( $config['categories'] as &$c ) {
						if ( $c['id'] === $id && is_array( $cat ) ) {
							$c['name'][ $lang ]        = (string) ( $cat['name'] ?? '' );
							$c['description'][ $lang ] = (string) ( $cat['description'] ?? '' );
						}
					}
					unset( $c );
				}
				if ( ! in_array( $lang, $config['languages'], true ) ) {
					$config['languages'][] = $lang;
				}
				/* translators: %s: language code */
				Cookie_Dialog_Config::save( $config, sprintf( __( 'Texte auf %s', 'cookie-dialog' ), strtoupper( $lang ) ) );
				/* translators: %s: language code */
				$message = sprintf( __( 'Die Texte gibt es jetzt auf %s.', 'cookie-dialog' ), strtoupper( $lang ) );
				break;

			case 'shorten':
				$lang = Cookie_Dialog_Config::lang_code( $input['lang'] ?? '' );
				$lang = $lang ? $lang : $config['languages'][0];
				if ( empty( $result['text'] ) || ! is_string( $result['text'] ) ) {
					return new WP_Error( 'cookie_dialog_job', __( 'Kein Text zurückgekommen.', 'cookie-dialog' ) );
				}
				$config['texts'][ $lang ]['text'] = $result['text'];
				Cookie_Dialog_Config::save( $config, __( 'Text gekürzt', 'cookie-dialog' ) );
				$message = __( 'Gekürzt.', 'cookie-dialog' );
				break;

			case 'classify':
				$host = sanitize_text_field( (string) ( $input['host'] ?? '' ) );
				$scan = Cookie_Dialog_Scanner::result();
				if ( ! isset( $scan['hosts'][ $host ] ) ) {
					return new WP_Error( 'cookie_dialog_host', __( 'Diesen Host kennt die letzte Prüfung nicht.', 'cookie-dialog' ) );
				}
				$scan['hosts'][ $host ]['suggest'] = array(
					'name'     => sanitize_text_field( (string) ( $result['name'] ?? $host ) ),
					'provider' => sanitize_text_field( (string) ( $result['provider'] ?? '' ) ),
					'category' => Cookie_Dialog_Config::one_of( $result['category'] ?? '', Cookie_Dialog_Config::CATEGORIES, 'marketing' ),
					'purpose'  => sanitize_text_field( (string) ( $result['purpose'] ?? '' ) ),
					'by'       => sanitize_key( (string) ( $input['by'] ?? 'provider' ) ),
				);
				update_option( Cookie_Dialog_Scanner::RESULT, $scan, false );
				/* translators: %s: service name */
				$message = sprintf( __( 'Vorschlag: %s', 'cookie-dialog' ), $scan['hosts'][ $host ]['suggest']['name'] );
				$undo    = null;
				break;

			case 'privacy':
				$lang = Cookie_Dialog_Config::lang_code( $input['lang'] ?? '' );
				$lang = $lang ? $lang : Cookie_Dialog_Config::site_language();
				if ( empty( $result['html'] ) || ! is_string( $result['html'] ) ) {
					return new WP_Error( 'cookie_dialog_job', __( 'Kein Absatz zurückgekommen.', 'cookie-dialog' ) );
				}
				$config['privacyText'][ $lang ] = $result['html'];
				Cookie_Dialog_Config::save( $config );
				$message = __( 'Der Absatz ist geschrieben.', 'cookie-dialog' );
				break;

			case 'setup':
				$config['tone']['address'] = Cookie_Dialog_Config::one_of( $result['address'] ?? '', array( 'du', 'Sie' ), $config['tone']['address'] );
				$config['tone']['style']   = sanitize_text_field( (string) ( $result['style'] ?? $config['tone']['style'] ) );
				$site                      = get_bloginfo( 'name' );
				foreach ( (array) ( $result['texts'] ?? array() ) as $lang => $set ) {
					$lang = Cookie_Dialog_Config::lang_code( $lang );
					if ( ! $lang || ! in_array( $lang, $config['languages'], true ) || ! is_array( $set ) ) {
						continue;
					}
					foreach ( $set as $key => $value ) {
						if ( is_string( $value ) && in_array( $key, array( 'title', 'text', 'layerTitle', 'layerText' ), true ) ) {
							$config['texts'][ $lang ][ $key ] = str_replace( $site, '{site}', $value );
						}
					}
				}
				Cookie_Dialog_Config::save( $config, __( 'Texte im Ton der Website', 'cookie-dialog' ) );
				update_option( 'cookie_dialog_drafted', time(), false );
				$message = __( 'Die Texte klingen jetzt wie deine Website.', 'cookie-dialog' );
				break;

			default:
				return new WP_Error( 'cookie_dialog_job', __( 'Diese Aufgabe gibt es nicht.', 'cookie-dialog' ) );
		}
		return array(
			'message' => $message,
			'undo'    => $undo,
			'version' => (int) Cookie_Dialog_Config::get()['version'],
		);
	}
}

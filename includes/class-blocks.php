<?php
/**
 * Blocks and shortcodes: Cookie-Einstellungen (reopen), Cookie-Tabelle (for the privacy policy),
 * Erst nach Einwilligung (a placeholder around any content).
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders on the server; the editor shows the same output.
 */
class Cookie_Dialog_Blocks {

	/** Hooks the blocks and the shortcodes. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/** Registers the editor script, the blocks and the shortcodes. */
	public static function register() {
		$asset = COOKIE_DIALOG_DIR . 'build/blocks.asset.php';
		$meta  = file_exists( $asset ) ? require $asset : array(
			'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			'version'      => COOKIE_DIALOG_VERSION,
		);
		wp_register_script( 'cookie-dialog-blocks', COOKIE_DIALOG_URL . 'build/blocks.js', $meta['dependencies'], $meta['version'], true );
		wp_set_script_translations( 'cookie-dialog-blocks', 'cookie-dialog', COOKIE_DIALOG_DIR . 'languages' );
		wp_add_inline_script(
			'cookie-dialog-blocks',
			'window.cookieDialogBlocks = ' . wp_json_encode(
				array(
					'services' => array_map(
						static fn( $s ) => array(
							'id'   => $s['id'],
							'name' => $s['name'],
						),
						array_values( array_filter( Cookie_Dialog_Config::get()['services'], array( 'Cookie_Dialog_Config', 'is_held' ) ) )
					),
				)
			) . ';',
			'before'
		);

		register_block_type( COOKIE_DIALOG_DIR . 'blocks/settings-button', array( 'render_callback' => array( __CLASS__, 'settings_button' ) ) );
		register_block_type( COOKIE_DIALOG_DIR . 'blocks/cookie-table', array( 'render_callback' => array( __CLASS__, 'cookie_table' ) ) );
		register_block_type( COOKIE_DIALOG_DIR . 'blocks/placeholder', array( 'render_callback' => array( __CLASS__, 'placeholder' ) ) );

		add_shortcode( 'cookie_settings', array( __CLASS__, 'settings_button' ) );
		add_shortcode( 'cookie_table', array( __CLASS__, 'cookie_table' ) );
		add_shortcode( 'cookie_placeholder', array( __CLASS__, 'placeholder' ) );
		add_shortcode( 'cookie_consent_id', array( __CLASS__, 'consent_id' ) );
	}

	/**
	 * A button or link that opens the choice.
	 *
	 * @param array|string $atts `label`, `style` (button, link).
	 * @return string
	 */
	public static function settings_button( $atts = array() ) {
		$atts  = shortcode_atts(
			array(
				'label' => '',
				'style' => 'button',
			),
			(array) $atts
		);
		$label = $atts['label'] ? $atts['label'] : Cookie_Dialog_Config::texts( Cookie_Dialog_Config::lang() )['reopen'];
		if ( 'link' === $atts['style'] ) {
			return sprintf( '<a href="#cookie-settings" class="cookie-dialog-settings" data-cookie-settings>%s</a>', esc_html( $label ) );
		}
		return sprintf( '<button type="button" class="cookie-dialog-settings wp-element-button" data-cookie-settings>%s</button>', esc_html( $label ) );
	}

	/**
	 * The visitor's consent ID, filled in by the dialog.
	 *
	 * @return string
	 */
	public static function consent_id() {
		$texts = Cookie_Dialog_Config::texts( Cookie_Dialog_Config::lang() );
		return sprintf( '<span class="cookie-dialog-id" data-cookie-dialog-id hidden>%s: <code></code></span>', esc_html( $texts['consentId'] ) );
	}

	/**
	 * Every service and cookie, by category, as visitors are told in the dialog.
	 *
	 * @param array|string $atts `showId`.
	 * @return string
	 */
	public static function cookie_table( $atts = array() ) {
		$config = Cookie_Dialog_Config::get();
		$lang   = Cookie_Dialog_Config::lang();
		$texts  = Cookie_Dialog_Config::texts( $lang, $config );
		$type   = array_combine( array( 'http', 'local', 'session' ), $texts['types'] );
		$out    = '<div class="cookie-dialog-table">';
		foreach ( Cookie_Dialog_Config::dialog_categories( $config ) as $cat ) {
			$services = array_filter( Cookie_Dialog_Config::dialog_services( $config ), static fn( $s ) => $s['category'] === $cat['id'] );
			if ( ! $services ) {
				continue;
			}
			$out .= '<h3>' . esc_html( Cookie_Dialog_Config::pick( $cat['name'], $lang ) ) . '</h3>';
			$out .= '<p>' . esc_html( Cookie_Dialog_Config::pick( $cat['description'], $lang ) ) . '</p>';
			foreach ( $services as $s ) {
				$out .= '<h4>' . esc_html( $s['name'] ) . '</h4><dl>';
				if ( $s['provider']['name'] ) {
					$out .= '<dt>' . esc_html( $texts['provider'] ) . '</dt><dd>' . esc_html( trim( $s['provider']['name'] . ', ' . $s['provider']['address'], ', ' ) ) . '</dd>';
				}
				$purpose = Cookie_Dialog_Config::pick( $s['purpose'], $lang );
				if ( $purpose ) {
					$out .= '<dt>' . esc_html( $texts['purpose'] ) . '</dt><dd>' . esc_html( $purpose ) . '</dd>';
				}
				if ( $s['privacy'] ) {
					$out .= sprintf( '<dt>%s</dt><dd><a href="%s" rel="noopener noreferrer" target="_blank">%s</a></dd>', esc_html( $texts['policy'] ), esc_url( $s['privacy'] ), esc_html( (string) preg_replace( '#^https?://(www\.)?#', '', $s['privacy'] ) ) );
				}
				$out .= '</dl>';
				if ( $s['cookies'] ) {
					$out .= sprintf( '<table><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>', esc_html( $texts['cookie'] ), esc_html( $texts['duration'] ), esc_html( $texts['type'] ) );
					foreach ( $s['cookies'] as $c ) {
						$out .= sprintf( '<tr><td><code>%s</code></td><td>%s</td><td>%s</td></tr>', esc_html( $c['name'] ), esc_html( Cookie_Dialog_Config::duration( $c['duration'], $texts ) ), esc_html( $type[ $c['type'] ] ?? 'HTTP' ) );
					}
					$out .= '</tbody></table>';
				}
			}
		}
		$show_id = is_array( $atts ) ? ( $atts['showId'] ?? ( $atts['show_id'] ?? true ) ) : true;
		if ( $show_id && 'false' !== $show_id ) {
			$out .= '<p>' . self::consent_id() . ' ' . self::settings_button( array( 'style' => 'link' ) ) . '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Content that waits for consent to a service.
	 *
	 * @param array|string $atts    `service`.
	 * @param string       $content The content.
	 * @return string
	 */
	public static function placeholder( $atts, $content = '' ) {
		$atts    = (array) $atts;
		$content = (string) $content;
		if ( isset( $atts['service'] ) && ! has_blocks( $content ) && doing_filter( 'the_content' ) ) {
			$content = do_shortcode( $content );
		}
		$service = Cookie_Dialog_Config::service( sanitize_key( (string) ( $atts['service'] ?? '' ) ) );
		if ( ! $service || ! Cookie_Dialog_Config::is_held( $service ) || ! Cookie_Dialog_Front::active() ) {
			return $content;
		}
		return Cookie_Dialog_Embeds::placeholder( $content, $service, '', array() );
	}
}

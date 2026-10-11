<?php
/**
 * `wp cookie-dialog`: export and import the configuration, read the site, run the Prüfung, prune.
 *
 * @package Cookie_Dialog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the cookie dialog from the command line.
 */
class Cookie_Dialog_Cli {

	/**
	 * Prints the configuration and the versions as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Write to this file instead.
	 *
	 * [--with-log]
	 * : Include the consent log.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function export( $args, $assoc ) {
		$data             = Cookie_Dialog_Config::export();
		$data['versions'] = array_map( static fn( $v ) => Cookie_Dialog_Consent::version( (int) $v['version'] ), Cookie_Dialog_Consent::versions() );
		if ( isset( $assoc['with-log'] ) ) {
			$data['log'] = Cookie_Dialog_Consent::rows(
				array(
					'range' => 'all',
					'per'   => 200,
				)
			)['rows'];
		}
		$json = (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! empty( $assoc['file'] ) ) {
			file_put_contents( $assoc['file'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::success( 'Exported to ' . $assoc['file'] );
			return;
		}
		WP_CLI::line( $json );
	}

	/**
	 * Imports a configuration exported before (not the log, not the versions). The site keeps whether the
	 * dialog is on; the privacy policy and the imprint are matched by their address.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The JSON file.
	 *
	 * @param array $args Positional.
	 */
	public function import( $args ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data  = json_decode( (string) file_get_contents( $args[0] ), true );
		$saved = Cookie_Dialog_Config::import( $data, 'Import' );
		if ( is_wp_error( $saved ) ) {
			WP_CLI::error( 'No configuration in this file.' );
		}
		WP_CLI::success( sprintf( 'Imported: %d services, privacy policy page %d, imprint page %d.', count( $saved['services'] ), $saved['pages']['privacy'], $saved['pages']['imprint'] ) );
	}

	/**
	 * Reads the site as a visitor without consent.
	 *
	 * ## OPTIONS
	 *
	 * [--pages=<n>]
	 * : Pages at most.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--propose]
	 * : Apply the proposal afterwards.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function scan( $args, $assoc ) {
		$result = Cookie_Dialog_Scanner::run( (int) ( $assoc['pages'] ?? 20 ) );
		if ( isset( $assoc['propose'] ) ) {
			Cookie_Dialog_Scanner::propose( $result );
		}
		$rows = array();
		foreach ( $result['hosts'] as $host ) {
			$rows[] = array(
				'host'    => $host['host'],
				'kinds'   => implode( ',', $host['kinds'] ),
				'service' => (string) $host['service'],
				'preset'  => (string) $host['preset'],
				'held'    => $host['held'] ? 'yes' : 'no',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'host', 'kinds', 'service', 'preset', 'held' ) );
		WP_CLI::success( sprintf( '%d pages, %d hosts.', count( $result['pages'] ), count( $result['hosts'] ) ) );
	}

	/**
	 * Runs the Prüfung.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function check( $args, $assoc ) {
		$rows = array_map(
			static fn( $c ) => array(
				'ok'    => $c['ok'] ? 'ok' : 'OPEN',
				'id'    => $c['id'],
				'title' => $c['title'],
			),
			Cookie_Dialog_Checks::run()
		);
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'ok', 'id', 'title' ) );
	}

	/**
	 * Switches the dialog on or off.
	 *
	 * ## OPTIONS
	 *
	 * <on|off>
	 * : On or off.
	 *
	 * @param array $args Positional.
	 */
	public function live( $args ) {
		$config         = Cookie_Dialog_Config::get();
		$config['live'] = 'on' === ( $args[0] ?? '' );
		$saved          = Cookie_Dialog_Config::save( $config, $config['live'] ? 'Dialog eingeschaltet' : '' );
		WP_CLI::success( $saved['live'] ? 'On, version ' . $saved['version'] . '.' : 'Off.' );
	}

	/**
	 * Deletes decisions older than the retention.
	 */
	public function prune() {
		WP_CLI::success( Cookie_Dialog_Consent::prune() . ' decisions deleted.' );
	}
}

WP_CLI::add_command( 'cookie-dialog', 'Cookie_Dialog_Cli' );

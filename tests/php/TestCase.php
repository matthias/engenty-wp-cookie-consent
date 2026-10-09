<?php
/**
 * Keeps the site as it was: the plugin's options and log rows are put back after each test.
 *
 * @package Cookie_Dialog
 */

use PHPUnit\Framework\TestCase as Base;

/**
 * A test with a configuration of its own.
 */
abstract class Cookie_Dialog_TestCase extends Base {

	/**
	 * Options as they were before the test.
	 *
	 * @var array
	 */
	private $kept = array();

	/**
	 * The highest log row before the test.
	 *
	 * @var int
	 */
	private $max_id = 0;

	protected function setUp(): void {
		global $wpdb;
		foreach ( array( 'cookie_dialog', 'cookie_dialog_scan', 'cookie_dialog_history', 'cookie_dialog_local' ) as $option ) {
			$this->kept[ $option ] = get_option( $option, null );
		}
		$this->max_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Cookie_Dialog_Consent::table() );
		$this->max_v  = (int) $wpdb->get_var( 'SELECT MAX(version) FROM ' . Cookie_Dialog_Consent::versions_table() );
		Cookie_Dialog_Config::flush();
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->kept as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Cookie_Dialog_Consent::table() . ' WHERE id > %d', $this->max_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Cookie_Dialog_Consent::versions_table() . ' WHERE version > %d', $this->max_v ) );
		Cookie_Dialog_Config::flush();
	}

	/**
	 * The highest version before the test.
	 *
	 * @var int
	 */
	protected $max_v = 0;

	/**
	 * A configuration with services from the library, saved.
	 *
	 * @param array $presets Preset IDs.
	 * @param array $patch   More settings.
	 * @return array
	 */
	protected function configure( array $presets, array $patch = array() ) {
		delete_option( 'cookie_dialog' );
		Cookie_Dialog_Config::flush();
		$config             = Cookie_Dialog_Config::defaults();
		$config['services'] = array();
		foreach ( $presets as $id ) {
			$config['services'][] = Cookie_Dialog_Config::service_from_preset( Cookie_Dialog_Config::preset( $id ) );
		}
		return Cookie_Dialog_Config::save( Cookie_Dialog_Config::merge( $config, $patch ) );
	}
}

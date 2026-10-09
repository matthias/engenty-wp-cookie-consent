<?php
/**
 * Google's Consent Mode: everything denied first, before any tag; a returning visitor's choice next.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Front::head().
 */
class ConsentModeTest extends Cookie_Dialog_TestCase {

	/**
	 * The head as a visitor's page prints it.
	 *
	 * @return string
	 */
	private function head() {
		ob_start();
		Cookie_Dialog_Front::head();
		return (string) ob_get_clean();
	}

	public function test_default_denied_before_the_config_and_tag_manager() {
		$this->configure( array( 'cookie-dialog', 'ga4' ), array( 'live' => true, 'google' => array( 'gtm' => 'GTM-ABC1234' ) ) );
		$head = $this->head();
		$this->assertMatchesRegularExpression( '/gtag\("consent","default",\{"ad_storage":"denied","ad_user_data":"denied","ad_personalization":"denied","analytics_storage":"denied"/', $head );
		$this->assertStringContainsString( '"region":["AT","BE"', $head );
		$this->assertStringContainsString( '"wait_for_update":500', $head );
		$this->assertStringContainsString( 'gtag("set","ads_data_redaction",true)', $head );
		$this->assertLessThan( strpos( $head, 'id="cookie-dialog-config"' ), strpos( $head, 'gtag("consent","default"' ) );
		// Basic mode: the container waits for statistics or marketing.
		$this->assertStringContainsString( '<script type="text/plain" data-cookie-category="statistics marketing"', $head );
		$this->assertStringContainsString( 'GTM-ABC1234', $head );
	}

	public function test_everywhere_has_one_default_and_advanced_loads_the_container() {
		$this->configure( array( 'ga4' ), array( 'live' => true, 'google' => array( 'everywhere' => true, 'mode' => 'advanced', 'gtm' => 'GTM-ABC1234' ) ) );
		$head = $this->head();
		$this->assertSame( 1, substr_count( $head, 'gtag("consent","default"' ) );
		$this->assertStringNotContainsString( '"region"', $head );
		$this->assertStringContainsString( 'data-cookie-dialog="gtm"', $head );
	}

	public function test_returning_visitor_rules_and_the_signal_map() {
		$this->configure( array( 'ga4', 'youtube' ), array( 'live' => true ) );
		$head = $this->head();
		$this->assertStringContainsString( 'gtag("consent","update",u)', $head );
		$this->assertStringContainsString( '"statistics":["analytics_storage"]', $head );
		$this->assertStringContainsString( '"ga4":["analytics_storage"]', $head );
	}

	public function test_nothing_before_the_dialog_is_on() {
		$this->configure( array( 'ga4' ), array( 'live' => false ) );
		$this->assertSame( '', $this->head() );
	}

	public function test_config_holds_no_visitor_data() {
		$config = $this->configure( array( 'ga4', 'youtube' ), array( 'live' => true ) );
		$_COOKIE[ COOKIE_DIALOG_COOKIE ] = wp_json_encode( array( 'id' => 'x', 'v' => 1, 't' => time(), 'c' => array( 'necessary' ), 's' => array() ) );
		$with                              = Cookie_Dialog_Front::data( $config, 'de' );
		unset( $_COOKIE[ COOKIE_DIALOG_COOKIE ] );
		$this->assertSame( wp_json_encode( Cookie_Dialog_Front::data( $config, 'de' ) ), wp_json_encode( $with ) );
	}
}

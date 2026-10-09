<?php
/**
 * The scan from fetched pages, the proposal and the checks.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Scanner and Cookie_Dialog_Checks.
 */
class ScannerTest extends Cookie_Dialog_TestCase {

	/**
	 * A page as the server would answer it.
	 *
	 * @param string $body    The HTML.
	 * @param array  $cookies Set-Cookie names.
	 * @return array
	 */
	private function page( $body, $cookies = array() ) {
		return array(
			'status'  => 200,
			'body'    => $body,
			'cookies' => $cookies,
			'error'   => '',
		);
	}

	public function test_hosts_by_kind_held_or_loaded_and_the_proposal() {
		$this->configure( array() );
		$home   = home_url( '/' );
		$result = Cookie_Dialog_Scanner::run(
			20,
			array(
				$home                     => $this->page( '<head><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter"><script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><script>gtag(\'config\', \'G-1\');</script><script src="https://cdn.unknown-widget.test/w.js"></script></head>', array( '_ga', 'PHPSESSID' ) ),
				home_url( '/leistungen/' ) => $this->page( '<iframe src="https://www.youtube.com/embed/abc" width="560" height="315"></iframe>' ),
			)
		);
		$this->assertSame( 'ga4', $result['hosts']['www.googletagmanager.com']['preset'] );
		$this->assertTrue( $result['hosts']['www.googletagmanager.com']['loaded'] );
		$this->assertContains( 'style', $result['hosts']['fonts.googleapis.com']['kinds'] );
		$this->assertContains( 'iframe', $result['hosts']['www.youtube.com']['kinds'] );
		$this->assertNull( $result['hosts']['cdn.unknown-widget.test']['preset'] );
		$this->assertSame( array( '_ga', 'PHPSESSID' ), array_column( $result['cookies'], 'name' ) );

		$config = Cookie_Dialog_Scanner::propose( $result );
		$ids    = array_column( $config['services'], 'id' );
		foreach ( array( 'wordpress', 'cookie-dialog', 'ga4', 'google-fonts', 'youtube' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertTrue( $config['setup'] );

		$checks  = Cookie_Dialog_Checks::run( $config, $result );
		$by_id   = array_column( $checks, null, 'id' );
		$this->assertFalse( $by_id['third_party']['ok'] );
		$this->assertContains( 'fonts.googleapis.com', $by_id['third_party']['fixes'][0]['args']['hosts'] );
		$this->assertArrayHasKey( 'unknown:cdn.unknown-widget.test', $by_id );
		$this->assertTrue( $by_id['fair']['ok'] );
	}

	public function test_consent_mode_must_come_first() {
		$this->assertTrue( Cookie_Dialog_Scanner::consent_mode_first( '<script>gtag("consent","default",{});</script><script src="https://www.googletagmanager.com/gtag/js"></script>' ) );
		$this->assertFalse( Cookie_Dialog_Scanner::consent_mode_first( '<script src="https://www.googletagmanager.com/gtag/js"></script><script>gtag("consent","default",{});</script>' ) );
		$this->assertNull( Cookie_Dialog_Scanner::consent_mode_first( '<p>no tags</p>' ) );
	}

	public function test_contrast() {
		$this->assertEqualsWithDelta( 21.0, Cookie_Dialog_Checks::contrast( '#000000', '#ffffff' ), 0.01 );
		$this->assertLessThan( 4.5, Cookie_Dialog_Checks::contrast( '#999999', '#ffffff' ) );
	}
}

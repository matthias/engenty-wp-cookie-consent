<?php
/**
 * The consent log: what the dialog may write, versions, numbers, pruning.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Consent.
 */
class LogTest extends Cookie_Dialog_TestCase {

	const ID = '7f3ac21e-1111-4222-8333-944455556666';

	public function test_turning_on_makes_version_one_and_a_text_change_a_new_version() {
		$config = $this->configure( array( 'ga4' ) );
		$this->assertSame( 0, $config['version'] );
		$config = Cookie_Dialog_Config::patch( array( 'live' => true ) );
		$v      = $config['version'];
		$this->assertGreaterThan( 0, $v );
		$this->assertSame( $v, $config['reask'] );
		// Nobody decided yet: a change within 15 minutes updates the same version.
		$config = Cookie_Dialog_Config::patch( array( 'texts' => array( 'de' => array( 'title' => 'Kekse' ) ) ) );
		$this->assertSame( $v, $config['version'] );
		$this->assertSame( 'Kekse', Cookie_Dialog_Consent::version( $v )['snapshot']['texts']['de']['title'] );
		// After a decision, a change is a new version and visitors are asked again.
		$this->record( 'all' );
		$config = Cookie_Dialog_Config::patch( array( 'texts' => array( 'de' => array( 'title' => 'Cookies' ) ) ) );
		$this->assertSame( $v + 1, $config['version'] );
		$this->assertSame( $v + 1, $config['reask'] );
		// Design is not what visitors are told: no new version.
		$config = Cookie_Dialog_Config::patch( array( 'design' => array( 'radius' => 4 ) ) );
		$this->assertSame( $v + 1, $config['version'] );
	}

	/**
	 * Records a decision through the public route.
	 *
	 * @param string $decision Decision.
	 * @param array  $extra    More fields.
	 * @return WP_REST_Response|WP_Error
	 */
	private function record( $decision, array $extra = array() ) {
		$request = new WP_REST_Request( 'POST', '/cookie-dialog/v1/consent' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'user_agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1' );
		$request->set_body(
			wp_json_encode(
				array_merge(
					array(
						'id'         => self::ID,
						'version'    => Cookie_Dialog_Config::get()['version'],
						'lang'       => 'de',
						'source'     => 'dialog',
						'decision'   => $decision,
						'categories' => array( 'statistics', 'nonsense' ),
						'services'   => array( 'ga4', 'evil' ),
					),
					$extra
				)
			)
		);
		return rest_get_server()->dispatch( $request );
	}

	public function test_route_keeps_what_the_config_knows_and_hashes_the_ip() {
		$this->configure( array( 'ga4' ), array( 'live' => true ) );
		$response = $this->record( 'custom' );
		$this->assertSame( 201, $response->get_status() );
		$rows = Cookie_Dialog_Consent::rows( array( 'q' => '7f3ac21e' ) )['rows'];
		$this->assertSame( array( 'necessary', 'statistics' ), $rows[0]['categories'] );
		$this->assertSame( array( 'ga4' ), $rows[0]['services'] );
		$this->assertSame( 'Safari · mobil', $rows[0]['ua'] );
		global $wpdb;
		$hash = $wpdb->get_var( $wpdb->prepare( 'SELECT ip_hash FROM ' . Cookie_Dialog_Consent::table() . ' WHERE consent_id = %s ORDER BY id DESC', self::ID ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
		$this->assertNotSame( hash( 'sha256', '203.0.113.7' ), $hash );
	}

	public function test_route_refuses_bad_input_and_an_off_dialog() {
		$this->configure( array( 'ga4' ), array( 'live' => true ) );
		$this->assertSame( 400, $this->record( 'all', array( 'id' => 'not-a-uuid' ) )->get_status() );
		$this->assertSame( 400, $this->record( 'all', array( 'version' => 9999 ) )->get_status() );
		Cookie_Dialog_Config::patch( array( 'live' => false ) );
		$this->assertSame( 409, $this->record( 'all' )->get_status() );
	}

	public function test_stats_and_pruning() {
		$this->configure( array( 'ga4' ), array( 'live' => true ) );
		$this->record( 'all', array( 'categories' => array( 'statistics' ) ) );
		$this->record( 'necessary', array( 'categories' => array(), 'services' => array(), 'source' => 'withdraw' ) );
		$stats = Cookie_Dialog_Consent::stats( array( 'range' => 'all' ) );
		$this->assertGreaterThanOrEqual( 2, $stats['total'] );
		$this->assertGreaterThanOrEqual( 1, $stats['withdrawals'] );

		Cookie_Dialog_Consent::insert(
			array(
				'consent_id' => self::ID,
				'created'    => gmdate( 'Y-m-d H:i:s', strtotime( '-14 months' ) ),
				'version'    => 1,
				'lang'       => 'de',
				'source'     => 'dialog',
				'decision'   => 'all',
				'categories' => ',necessary,',
				'services'   => '',
			)
		);
		$this->assertSame( 1, Cookie_Dialog_Consent::prune( 12 ) );
	}
}

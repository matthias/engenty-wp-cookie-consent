<?php
/**
 * Export and import: a configuration from another site, e.g. from staging to live.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Config::export(), ::import() and the admin's route.
 */
class ImportTest extends Cookie_Dialog_TestCase {

	/**
	 * Pages made by a test.
	 *
	 * @var int[]
	 */
	private $made = array();

	protected function tearDown(): void {
		foreach ( $this->made as $id ) {
			wp_delete_post( $id, true );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * A published page.
	 *
	 * @param string $slug Its address.
	 * @return int
	 */
	private function page( $slug ) {
		$id           = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $slug,
				'post_name'   => $slug,
			)
		);
		$this->made[] = $id;
		return $id;
	}

	public function test_export_names_the_pages_by_their_address() {
		$privacy = $this->page( 'cd-test-datenschutz' );
		$this->configure( array( 'ga4' ), array( 'pages' => array( 'privacy' => $privacy ) ) );
		$data = Cookie_Dialog_Config::export();
		$this->assertSame( 'cookie-dialog', $data['plugin'] );
		$this->assertSame( 'cd-test-datenschutz', $data['pages']['privacy'] );
		$this->assertSame( '', $data['pages']['imprint'] );
		$this->assertSame( 'ga4', $data['config']['services'][0]['id'] );
	}

	public function test_import_keeps_the_site_on_and_finds_its_pages() {
		$imprint = $this->page( 'cd-test-impressum' );
		$this->configure( array( 'ga4' ), array( 'pages' => array( 'imprint' => 4711 ) ) );
		$data                               = Cookie_Dialog_Config::export();
		$data['config']['design']['layout'] = 'bar';
		$data['config']['live']             = false;
		$data['config']['pages']['privacy'] = 99999;
		$data['pages']                      = array(
			'privacy' => 'not-here',
			'imprint' => 'cd-test-impressum',
		);
		$data['config']['services'][]       = Cookie_Dialog_Config::service_from_preset( Cookie_Dialog_Config::preset( 'youtube' ) );

		$this->configure(
			array( 'meta-pixel' ),
			array(
				'live'  => true,
				'pages' => array( 'privacy' => 12 ),
			)
		);
		$saved = Cookie_Dialog_Config::import( $data, 'Test' );

		$this->assertTrue( $saved['live'] );
		$this->assertSame( 'bar', $saved['design']['layout'] );
		$this->assertSame( array( 'ga4', 'youtube' ), array_column( $saved['services'], 'id' ) );
		$this->assertSame( 12, $saved['pages']['privacy'], 'no page with that address: the site keeps its own' );
		$this->assertSame( $imprint, $saved['pages']['imprint'] );
		$told = array_column( Cookie_Dialog_Consent::version( $saved['version'] )['snapshot']['services'], 'id' );
		$this->assertSame( array( 'ga4', 'youtube' ), $told, 'the version says what visitors are told now' );
	}

	public function test_import_refuses_other_files() {
		$this->assertWPError( Cookie_Dialog_Config::import( array( 'config' => array() ) ) );
		$this->assertWPError( Cookie_Dialog_Config::import( null ) );
	}

	public function test_route_keeps_snippets_from_someone_who_may_not_write_scripts() {
		$this->configure( array( 'ga4' ) );
		$data = Cookie_Dialog_Config::export();

		$data['config']['services'][0]['snippet'] = '<script>alert(1)</script>';
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/cookie-dialog/v1/import' );
		$request->set_param( 'data', $data );
		Cookie_Dialog_Rest::import( $request );
		$this->assertSame( '', Cookie_Dialog_Config::service( 'ga4' )['snippet'] );
	}

	/**
	 * Like WordPress' own assertion.
	 *
	 * @param mixed $actual The value.
	 */
	private function assertWPError( $actual ) {
		$this->assertInstanceOf( WP_Error::class, $actual );
	}
}

<?php
/**
 * Cookie Dialog in engenty.goose: the abilities change and take back, the skill is there with Goose.
 *
 * @package Cookie_Dialog
 */

/**
 * The bridge to the copilot.
 */
class GooseTest extends Cookie_Dialog_TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'cookie-dialog/change' ) ) {
			$this->markTestSkipped( 'Needs WordPress 7.0 with the Abilities API.' );
		}
		wp_set_current_user( 1 );
	}

	public function test_change_applies_and_says_how_to_take_it_back() {
		$this->configure( array( 'ga4' ), array( 'tone' => array( 'address' => 'du' ) ) );
		$out = wp_get_ability( 'cookie-dialog/change' )->execute(
			array(
				'changes' => array(
					array(
						'op'    => 'set',
						'path'  => 'tone.address',
						'value' => 'Sie',
					),
					array(
						'op'    => 'set',
						'path'  => 'live',
						'value' => true,
					),
				),
				'note'    => 'per Sie',
			)
		);
		$this->assertSame( 1, $out['applied'] );
		$this->assertSame( array( 'live' ), $out['skipped'] );
		$this->assertSame( 'Sie', Cookie_Dialog_Config::get()['tone']['address'] );
		$this->assertSame( 'cookie-dialog/undo', $out['undo']['ability'] );

		$back = wp_get_ability( 'cookie-dialog/undo' )->execute( $out['undo']['input'] );
		$this->assertTrue( $back['undone'] );
		$this->assertSame( 'du', Cookie_Dialog_Config::get()['tone']['address'] );
	}

	public function test_nothing_applied_leaves_nothing_to_take_back() {
		$out = wp_get_ability( 'cookie-dialog/change' )->execute(
			array(
				'changes' => array(
					array(
						'op'    => 'set',
						'path'  => 'live',
						'value' => true,
					),
				),
			)
		);
		$this->assertSame( 0, $out['applied'] );
		$this->assertArrayNotHasKey( 'undo', $out );
	}

	public function test_the_configuration_is_read_with_the_scan() {
		$out = wp_get_ability( 'cookie-dialog/get-config' )->execute();
		$this->assertArrayHasKey( 'scan', $out['config'] );
		$this->assertArrayHasKey( 'stats30d', $out );
	}

	public function test_an_unknown_operation_is_refused() {
		$this->assertInstanceOf( WP_Error::class, wp_get_ability( 'cookie-dialog/change' )->execute( array( 'changes' => array( array( 'op' => 'nonsense' ) ) ) ) );
	}

	public function test_only_admins_change_it() {
		wp_set_current_user( 0 );
		$this->assertInstanceOf( WP_Error::class, wp_get_ability( 'cookie-dialog/change' )->execute( array( 'changes' => array() ) ) );
	}

	public function test_goose_has_the_skill_and_loads_it_on_the_plugins_pages() {
		if ( ! function_exists( 'engenty_goose' ) ) {
			$this->markTestSkipped( 'engenty.goose is not active.' );
		}
		$skill = engenty_goose()->skills()->get( 'cookie-dialog' );
		$this->assertSame( array( 'cookie-dialog/get-config', 'cookie-dialog/change', 'cookie-dialog/undo' ), $skill['abilities'] );
		$this->assertTrue( Engenty_Goose_Skills::auto( $skill, array( 'screen' => 'toplevel_page_cookie-dialog' ) ) );
		$this->assertFalse( Engenty_Goose_Skills::auto( $skill, array( 'screen' => 'dashboard' ) ) );
		$this->assertStringContainsString( 'wpab__cookie-dialog__change', Engenty_Goose_Skills::instructions( $skill ) );
	}

	public function test_the_family_knows_goose() {
		$goose = Cookie_Dialog_Family::get( 'engenty-goose' );
		$this->assertSame( 'engenty.goose', $goose['name'] );
		$this->assertContains( $goose['state'], array( 'active', 'inactive', 'missing' ) );
	}
}

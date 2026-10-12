<?php
/**
 * Ignoring a check of the Prüfung: until it finds something else.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Checks::ignore().
 */
class IgnoreTest extends Cookie_Dialog_TestCase {

	/**
	 * The open checks.
	 *
	 * @return array ID => ignored.
	 */
	private function open() {
		$out = array();
		foreach ( Cookie_Dialog_Checks::run() as $check ) {
			if ( ! $check['ok'] ) {
				$out[ $check['id'] ] = $check['ignored'];
			}
		}
		return $out;
	}

	public function test_an_ignored_check_comes_back_when_it_says_something_else() {
		$this->configure( array(), array( 'reopen' => array( 'button' => false ) ) );
		update_option( Cookie_Dialog_Scanner::RESULT, array( 'at' => time() ) );
		$this->assertArrayHasKey( 'reopen', $this->open() );
		$before = Cookie_Dialog_Checks::open_count();

		$this->assertTrue( Cookie_Dialog_Checks::ignore( 'reopen', true ) );
		$this->assertTrue( $this->open()['reopen'] );
		$this->assertSame( $before - 1, Cookie_Dialog_Checks::open_count() );

		$config                      = Cookie_Dialog_Config::get();
		$config['ignored']['reopen'] = md5( 'something it said before' );
		Cookie_Dialog_Config::save( $config );
		$this->assertFalse( $this->open()['reopen'], 'it says something else now' );

		Cookie_Dialog_Checks::ignore( 'reopen', true );
		Cookie_Dialog_Checks::ignore( 'reopen', false );
		$this->assertFalse( $this->open()['reopen'] );
		$this->assertFalse( Cookie_Dialog_Checks::ignore( 'no-such-check', true ) );
	}
}

<?php
/**
 * What the assistant may change, and taking it back.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Assistant::apply() and undo().
 */
class AssistantTest extends Cookie_Dialog_TestCase {

	public function test_allowed_changes_apply_and_the_rest_is_skipped() {
		$this->configure( array( 'cookie-dialog', 'ga4' ) );
		$result = Cookie_Dialog_Assistant::apply(
			array(
				array( 'op' => 'set', 'path' => 'design.scheme', 'value' => 'dark' ),
				array( 'op' => 'set', 'path' => 'live', 'value' => true ),
				array( 'op' => 'set', 'path' => 'design.colors.button', 'value' => '#123456' ),
				array( 'op' => 'add_service', 'preset' => 'hotjar' ),
				array( 'op' => 'remove_service', 'id' => 'ga4' ),
				array( 'op' => 'remove_service', 'id' => 'cookie-dialog' ),
				array( 'op' => 'texts', 'lang' => 'it', 'texts' => array( 'accept' => 'Accetta tutti' ) ),
				array( 'op' => 'text', 'lang' => 'de', 'key' => 'title', 'value' => 'Kurz zu Cookies' ),
			),
			'test'
		);
		$this->assertSame( array( 'live' ), $result['skipped'] );
		$config = Cookie_Dialog_Config::get();
		$this->assertFalse( $config['live'] );
		$this->assertSame( 'dark', $config['design']['scheme'] );
		$this->assertSame( '#123456', $config['design']['colors']['button'] );
		$ids = array_column( $config['services'], 'id' );
		$this->assertContains( 'hotjar', $ids );
		$this->assertNotContains( 'ga4', $ids );
		$this->assertContains( 'cookie-dialog', $ids );
		$this->assertContains( 'it', $config['languages'] );
		$this->assertSame( 'Kurz zu Cookies', $config['texts']['de']['title'] );
	}

	public function test_undo_puts_the_configuration_back() {
		wp_set_current_user( 1 );
		$this->configure( array( 'ga4' ) );
		$token = Cookie_Dialog_Assistant::keep_undo();
		Cookie_Dialog_Assistant::apply( array( array( 'op' => 'set', 'path' => 'design.layout', 'value' => 'bar' ) ) );
		$this->assertSame( 'bar', Cookie_Dialog_Config::get()['design']['layout'] );
		Cookie_Dialog_Assistant::undo( $token );
		$this->assertSame( 'box', Cookie_Dialog_Config::get()['design']['layout'] );
		$this->assertInstanceOf( WP_Error::class, Cookie_Dialog_Assistant::undo( $token ) );
	}

	public function test_json_from_a_code_fence() {
		$this->assertSame( array( 'a' => 1 ), Cookie_Dialog_Assistant::decode( "Here:\n```json\n{\"a\":1}\n```" ) );
		$this->assertNull( Cookie_Dialog_Assistant::decode( 'no json' ) );
	}
}

<?php
/**
 * Embeds as placeholders: the Instagram feed of Smash Balloon.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Embeds.
 */
class EmbedsTest extends Cookie_Dialog_TestCase {

	public function test_the_instagram_feed_waits_as_one_placeholder_and_starts_again_when_loaded() {
		$this->configure( array( 'instagram' ), array( 'live' => true ) );
		$feed = '<div id="sb_instagram" class="sbi"><div id="sbi_images"></div></div>';
		$out  = Cookie_Dialog_Embeds::feed( $feed, 'instagram-feed' );
		$this->assertStringStartsWith( '<div class="cookie-dialog-ph', $out );
		$this->assertStringContainsString( 'data-cookie-service="instagram"', $out );
		$this->assertStringContainsString( '<template data-cookie-dialog="embed">' . $feed . '<script>window.sbi_init', $out );
		$this->assertStringContainsString( 'Beiträge laden', $out );
		$this->assertSame( '<p>x</p>', Cookie_Dialog_Embeds::feed( '<p>x</p>', 'gallery' ) );
	}

	public function test_the_feed_stays_where_instagram_is_necessary_or_the_dialog_is_off() {
		$feed = '<div id="sb_instagram" class="sbi"></div>';
		$this->configure( array( 'instagram' ), array( 'live' => false ) );
		$this->assertSame( $feed, Cookie_Dialog_Embeds::feed( $feed, 'instagram-feed' ) );
		$this->configure( array(), array( 'live' => true ) );
		$this->assertSame( $feed, Cookie_Dialog_Embeds::feed( $feed, 'instagram-feed' ) );
	}
}

<?php
/**
 * Scripts, images, iframes and snippets of services that wait are held back; the rest stays.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Blocker and the placeholders.
 */
class BlockerTest extends Cookie_Dialog_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->configure( array( 'wordpress', 'cookie-dialog', 'ga4', 'meta-pixel', 'youtube', 'instagram', 'stripe', 'google-fonts' ), array( 'live' => true ) );
	}

	public function test_gtag_script_and_its_inline_config_wait_for_statistics() {
		$html = Cookie_Dialog_Blocker::process( '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script><script>gtag(\'config\', \'G-ABC123\');</script>' );
		$this->assertSame( 2, substr_count( $html, 'type="text/plain"' ) );
		$this->assertStringContainsString( 'data-cookie-service="ga4"', $html );
		$this->assertStringContainsString( 'data-cookie-category="statistics"', $html );
		$this->assertStringContainsString( 'src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"', $html );
	}

	public function test_meta_pixel_inline_code_and_its_image_wait() {
		$html = Cookie_Dialog_Blocker::process( "<script>!function(f,b,e,v){}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','1');</script><img src=\"https://www.facebook.com/tr?id=1&ev=PageView\" width=\"1\">" );
		$this->assertStringContainsString( 'data-cookie-service="meta-pixel"', $html );
		$this->assertStringNotContainsString( ' src="https://www.facebook.com/tr', $html );
		$this->assertStringContainsString( 'data-cookie-src="https://www.facebook.com/tr?id=1&amp;ev=PageView"', $html );
	}

	public function test_necessary_and_own_scripts_stay() {
		$own    = '<script src="' . home_url( '/wp-includes/js/jquery/jquery.min.js' ) . '"></script>';
		$stripe = '<script src="https://js.stripe.com/v3/"></script>';
		$json   = '<script type="application/ld+json">{"@type":"Organization"}</script>';
		$this->assertSame( $own . $stripe . $json, Cookie_Dialog_Blocker::process( $own . $stripe . $json ) );
	}

	public function test_enqueued_script_is_held_by_handle_with_its_inline_parts() {
		$config                          = Cookie_Dialog_Config::get();
		$config['services'][2]['handles'] = array( 'google_gtagjs' );
		Cookie_Dialog_Config::save( $config );
		$tag = "<script id=\"google_gtagjs-js-before\">window.dataLayer=[];</script>\n<script src=\"https://www.googletagmanager.com/gtag/js?id=G-X\" id=\"google_gtagjs-js\"></script>\n<script id=\"google_gtagjs-js-after\">gtag('js', new Date());</script>";
		$out = Cookie_Dialog_Blocker::enqueued( $tag, 'google_gtagjs', 'https://www.googletagmanager.com/gtag/js?id=G-X' );
		$this->assertSame( 3, substr_count( $out, 'type="text/plain"' ) );
	}

	public function test_youtube_iframe_becomes_a_placeholder_with_nocookie_inside() {
		$html = Cookie_Dialog_Blocker::process( '<iframe width="560" height="315" src="https://www.youtube.com/embed/abcdefghijk"></iframe>' );
		$this->assertStringContainsString( 'class="cookie-dialog-ph cookie-dialog-ph--video', $html );
		$this->assertStringContainsString( 'data-cookie-service="youtube"', $html );
		$this->assertStringContainsString( 'aspect-ratio:560/315', $html );
		$this->assertStringContainsString( '<template data-cookie-dialog="embed"><iframe width="560" height="315" src="https://www.youtube-nocookie.com/embed/abcdefghijk"', $html );
		$this->assertStringContainsString( 'data-cookie-always', $html );
		// A second pass leaves the placeholder alone.
		$this->assertSame( $html, Cookie_Dialog_Blocker::process( $html ) );
	}

	public function test_unknown_iframes_wait_too_unless_switched_off() {
		$iframe = '<iframe src="https://widgets.example-booking.test/frame"></iframe>';
		$this->assertStringContainsString( 'data-cookie-host="widgets.example-booking.test"', Cookie_Dialog_Blocker::process( $iframe ) );
		Cookie_Dialog_Config::patch( array( 'thirdParty' => array( 'unknownIframes' => false ) ) );
		$this->assertSame( $iframe, Cookie_Dialog_Blocker::process( $iframe ) );
	}

	public function test_pasted_instagram_post_becomes_one_placeholder() {
		$html = Cookie_Dialog_Blocker::process( '<blockquote class="instagram-media"><a href="https://www.instagram.com/p/X/">Post</a></blockquote><script async src="//www.instagram.com/embed.js"></script>' );
		$this->assertSame( 1, substr_count( $html, 'class="cookie-dialog-ph ' ) );
		$this->assertStringContainsString( 'cookie-dialog-ph--social', $html );
	}

	public function test_snippet_is_held_and_loses_noscript() {
		$service = Cookie_Dialog_Config::service( 'meta-pixel' );
		$out     = Cookie_Dialog_Blocker::hold_html( "<script>fbq('init','1');</script><noscript><img src=\"https://www.facebook.com/tr?id=1\"></noscript>", $service );
		$this->assertStringContainsString( 'type="text/plain" data-cookie-service="meta-pixel"', $out );
		$this->assertStringNotContainsString( 'noscript', $out );
	}

	public function test_hints_to_third_parties_go_and_local_copies_replace_stylesheets() {
		$config = Cookie_Dialog_Config::get();
		$this->assertSame( '', Cookie_Dialog_Resources::link( array( 'rel' => 'preconnect', 'href' => 'https://fonts.gstatic.com' ), '', $config ) );
		update_option(
			Cookie_Dialog_Resources::MAP,
			array(
				'https://fonts.googleapis.com/css2?family=Inter' => array(
					'local' => home_url( '/wp-content/uploads/cookie-dialog/css/x.css' ),
					'kind'  => 'style',
					'host'  => 'fonts.googleapis.com',
					'at'    => time(),
				),
			)
		);
		$reflection = new ReflectionProperty( 'Cookie_Dialog_Resources', 'map' );
		$reflection->setValue( null, null );
		$out = Cookie_Dialog_Blocker::process( "<link rel='stylesheet' href='https://fonts.googleapis.com/css2?family=Inter&#038;ver=6.6' media='all' />" );
		$this->assertStringContainsString( 'cookie-dialog/css/x.css', $out );
		$this->assertStringNotContainsString( 'fonts.googleapis.com', $out );
		$reflection->setValue( null, null );
	}
}

<?php
/**
 * The configuration: sanitizing, matching a URL to a service, texts.
 *
 * @package Cookie_Dialog
 */

/**
 * Cookie_Dialog_Config.
 */
class ConfigTest extends Cookie_Dialog_TestCase {

	public function test_sanitize_keeps_types_and_refuses_unknown_values() {
		$config = Cookie_Dialog_Config::sanitize(
			Cookie_Dialog_Config::merge(
				Cookie_Dialog_Config::defaults(),
				array(
					'design'   => array(
						'layout' => 'popup',
						'radius' => 99,
						'colors' => array( 'button' => 'red;}' ),
					),
					'google'   => array( 'gtm' => 'gtm-abc123x' ),
					'validity' => array( 'months' => 40 ),
					'evil'     => 1,
				)
			)
		);
		$this->assertSame( 'box', $config['design']['layout'] );
		$this->assertSame( 32, $config['design']['radius'] );
		$this->assertSame( '#1f3d2b', $config['design']['colors']['button'] );
		$this->assertSame( 'GTM-ABC123X', $config['google']['gtm'] );
		$this->assertSame( 12, $config['validity']['months'] );
		$this->assertArrayNotHasKey( 'evil', $config );
		$this->assertSame( 'necessary', $config['categories'][0]['id'] );
		$this->assertTrue( $config['categories'][0]['locked'] );
	}

	public function test_shared_google_hosts_need_the_precise_pattern() {
		$this->configure( array( 'ga4', 'google-maps', 'recaptcha', 'youtube' ) );
		$this->assertSame( 'google-maps', Cookie_Dialog_Config::match( 'https://www.google.com/maps/embed?pb=1' )['id'] );
		$this->assertSame( 'recaptcha', Cookie_Dialog_Config::match( 'https://www.google.com/recaptcha/api.js' )['id'] );
		$this->assertSame( 'ga4', Cookie_Dialog_Config::match( 'https://www.googletagmanager.com/gtag/js?id=G-1' )['id'] );
		$this->assertNull( Cookie_Dialog_Config::match( 'https://www.google.com/search?q=x' ) );
		$this->assertSame( 'youtube', Cookie_Dialog_Config::match( '//www.youtube-nocookie.com/embed/x' )['id'] );
		$this->assertNull( Cookie_Dialog_Config::match( home_url( '/wp-content/themes/x/app.js' ) ) );
	}

	public function test_texts_fill_site_and_purposes_and_follow_the_address() {
		$config = $this->configure( array( 'ga4', 'meta-pixel' ) );
		$texts  = Cookie_Dialog_Config::texts( 'de', $config );
		$this->assertStringContainsString( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $texts['title'] );
		$this->assertStringContainsString( 'Statistik und Marketing', $texts['text'] );
		$config['tone']['address'] = 'Sie';
		$this->assertStringContainsString( 'Ihrer Zustimmung', Cookie_Dialog_Config::texts( 'de', $config )['text'] );
		$this->assertSame( '13 Monate', Cookie_Dialog_Config::duration( '13mo', $texts ) );
		$this->assertSame( '1 Tag', Cookie_Dialog_Config::duration( '1d', $texts ) );
	}

	public function test_media_is_a_category_of_its_own_and_always_there() {
		$this->assertSame( 'media', Cookie_Dialog_Config::preset( 'youtube' )['category'] );
		$this->assertSame( 'media', Cookie_Dialog_Config::preset( 'instagram' )['category'] );
		$this->assertContains( 'instagram-feed/instagram-feed.php', Cookie_Dialog_Config::preset( 'instagram' )['plugins'] );
		$old               = Cookie_Dialog_Config::defaults();
		$old['categories'] = array_values( array_filter( $old['categories'], static fn( $c ) => 'media' !== $c['id'] ) );
		$old['services']   = array( Cookie_Dialog_Config::service_from_preset( Cookie_Dialog_Config::preset( 'vimeo' ) ) );
		$config            = Cookie_Dialog_Config::sanitize( $old );
		$this->assertContains( 'media', array_column( $config['categories'], 'id' ) );
		$this->assertSame( 'media', $config['services'][0]['category'] );
	}

	public function test_library_presets_are_complete() {
		$library = Cookie_Dialog_Config::library();
		$this->assertGreaterThanOrEqual( 50, count( $library ) );
		foreach ( $library as $id => $preset ) {
			$this->assertSame( $id, $preset['id'] );
			$this->assertContains( $preset['category'], Cookie_Dialog_Config::CATEGORIES, $id );
			$this->assertNotEmpty( $preset['purpose']['de'] ?? '', $id );
			$this->assertNotEmpty( $preset['purpose']['en'] ?? '', $id );
		}
	}
}

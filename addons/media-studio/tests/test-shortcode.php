<?php
/**
 * Shortcode tests.
 *
 * @package NV_oOS_Media_Studio
 */
class Test_Media_Studio_Shortcode extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_VERSION', '0.1.0' );
		}
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_PATH' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_URL' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_URL', 'http://example.com/wp-content/plugins/nvoos-media-studio/' );
		}
		require_once NVOOS_MEDIA_STUDIO_PATH . 'includes/rest/class-nvoos-media-studio-rest.php';
		require_once NVOOS_MEDIA_STUDIO_PATH . 'includes/shortcode/class-nvoos-media-studio-shortcode.php';
	}

	/**
	 * Test that shortcode returns root container div.
	 */
	public function test_shortcode_returns_root_container() {
		$out = NV_oOS_Media_Studio_Shortcode::render( array() );
		$this->assertStringContainsString( 'nvoos-media-studio-root', $out );
		$this->assertStringContainsString( 'data-config', $out );
	}

	/** Default mode is image-editor. */
	public function test_default_mode_is_image_editor() {
		$out = NV_oOS_Media_Studio_Shortcode::render( array() );
		$this->assertStringContainsString( '&quot;mode&quot;:&quot;image-editor&quot;', $out );
	}

	/** Valid mode values are passed through. */
	public function test_valid_modes() {
		foreach ( array( 'image-editor', 'media-player', 'audio-waveform' ) as $mode ) {
			$out = NV_oOS_Media_Studio_Shortcode::render( array( 'mode' => $mode ) );
			$this->assertStringContainsString( '&quot;mode&quot;:&quot;' . $mode . '&quot;', $out );
		}
	}

	/** Unknown mode falls back to image-editor. */
	public function test_unknown_mode_fallback() {
		$out = NV_oOS_Media_Studio_Shortcode::render( array( 'mode' => 'unknown-thing' ) );
		$this->assertStringContainsString( '&quot;mode&quot;:&quot;image-editor&quot;', $out );
	}

	/** Src attribute is included in config. */
	public function test_src_attribute_included() {
		$out = NV_oOS_Media_Studio_Shortcode::render( array( 'src' => 'https://example.com/audio.mp3' ) );
		$this->assertStringContainsString( '&quot;src&quot;:', $out );
		$this->assertStringContainsString( 'audio.mp3', $out );
	}

	/**
	 * Test that shortcode respects the can_render filter.
	 */
	public function test_shortcode_respects_can_render_filter() {
		add_filter( 'nvoos_media_studio_can_render', '__return_false' );
		$out = NV_oOS_Media_Studio_Shortcode::render( array() );
		$this->assertSame( '', $out );
		remove_filter( 'nvoos_media_studio_can_render', '__return_false' );
	}
}

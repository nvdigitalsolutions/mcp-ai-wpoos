<?php
/**
 * Shortcode tests.
 *
 * @package NV_oOS_Chat_Spa
 */

class Test_Chat_Spa_Shortcode extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_CHAT_SPA_VERSION' ) ) {
			define( 'NVOOS_CHAT_SPA_VERSION', '0.4.0' );
		}
		if ( ! defined( 'NVOOS_CHAT_SPA_PATH' ) ) {
			define( 'NVOOS_CHAT_SPA_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_CHAT_SPA_URL' ) ) {
			define( 'NVOOS_CHAT_SPA_URL', 'http://example.com/wp-content/plugins/nvoos-chat-spa/' );
		}
		require_once NVOOS_CHAT_SPA_PATH . 'includes/rest/class-nvoos-chat-spa-rest.php';
		require_once NVOOS_CHAT_SPA_PATH . 'includes/shortcode/class-nvoos-chat-spa-shortcode.php';
	}

	public function test_shortcode_returns_root_container() {
		$out = NV_oOS_Chat_Spa_Shortcode::render( array() );
		$this->assertStringContainsString( 'nvoos-chat-spa-root', $out );
		$this->assertStringContainsString( 'data-config', $out );
	}

	public function test_shortcode_sanitizes_assistant_id() {
		$out = NV_oOS_Chat_Spa_Shortcode::render( array( 'assistant_id' => '42abc' ) );
		// absint() strips non-digits; 42 should appear in the JSON config.
		$this->assertStringContainsString( '&quot;assistantId&quot;:42', $out );
	}

	public function test_shortcode_clamps_unknown_theme_to_auto() {
		$out = NV_oOS_Chat_Spa_Shortcode::render( array( 'theme' => 'rainbow' ) );
		$this->assertStringContainsString( '&quot;theme&quot;:&quot;auto&quot;', $out );
	}

	public function test_shortcode_respects_can_render_filter() {
		add_filter( 'nvoos_chat_spa_can_render', '__return_false' );
		$out = NV_oOS_Chat_Spa_Shortcode::render( array() );
		$this->assertSame( '', $out );
		remove_filter( 'nvoos_chat_spa_can_render', '__return_false' );
	}
}

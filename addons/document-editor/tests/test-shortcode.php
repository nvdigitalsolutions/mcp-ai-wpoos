<?php
/**
 * Shortcode tests.
 *
 * @package NV_oOS_Document_Editor
 */
class Test_Document_Editor_Shortcode extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_DOCUMENT_EDITOR_VERSION' ) ) {
			define( 'NVOOS_DOCUMENT_EDITOR_VERSION', '0.1.0' );
		}
		if ( ! defined( 'NVOOS_DOCUMENT_EDITOR_PATH' ) ) {
			define( 'NVOOS_DOCUMENT_EDITOR_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_DOCUMENT_EDITOR_URL' ) ) {
			define( 'NVOOS_DOCUMENT_EDITOR_URL', 'http://example.com/wp-content/plugins/nvoos-document-editor/' );
		}
		require_once NVOOS_DOCUMENT_EDITOR_PATH . 'includes/rest/class-nvoos-document-editor-rest.php';
		require_once NVOOS_DOCUMENT_EDITOR_PATH . 'includes/shortcode/class-nvoos-document-editor-shortcode.php';
	}

	/**
	 * Test that shortcode returns root container div.
	 */
	public function test_shortcode_returns_root_container() {
		$out = NV_oOS_Document_Editor_Shortcode::render( array() );
		$this->assertStringContainsString( 'nvoos-document-editor-root', $out );
		$this->assertStringContainsString( 'data-config', $out );
		// Default mode is "editor".
		$this->assertStringContainsString( '&quot;mode&quot;:&quot;editor&quot;', $out );
	}

	/**
	 * Test that shortcode accepts a known mode.
	 */
	public function test_shortcode_accepts_known_mode() {
		$out = NV_oOS_Document_Editor_Shortcode::render( array( 'mode' => 'site-creator' ) );
		// sanitize_key converts hyphens; "site-creator" → "site-creator" is preserved.
		$this->assertStringContainsString( 'site-creator', $out );
	}

	/**
	 * Test that shortcode falls back to default mode for unknown mode.
	 */
	public function test_shortcode_falls_back_for_unknown_mode() {
		$out = NV_oOS_Document_Editor_Shortcode::render( array( 'mode' => 'evil-mode' ) );
		$this->assertStringContainsString( '&quot;mode&quot;:&quot;editor&quot;', $out );
	}

	/**
	 * Test that shortcode accepts a document_id attribute.
	 */
	public function test_shortcode_accepts_document_id() {
		$out = NV_oOS_Document_Editor_Shortcode::render( array( 'document_id' => '42' ) );
		$this->assertStringContainsString( '&quot;document_id&quot;:42', $out );
	}

	/**
	 * Test that shortcode respects the can_render filter.
	 */
	public function test_shortcode_respects_can_render_filter() {
		add_filter( 'nvoos_document_editor_can_render', '__return_false' );
		$out = NV_oOS_Document_Editor_Shortcode::render( array() );
		$this->assertSame( '', $out );
		remove_filter( 'nvoos_document_editor_can_render', '__return_false' );
	}
}

<?php
/**
 * Test the #/media and #/documents endpoints emitted by the Pro SPA runtime
 * config.
 *
 * Covers the native island contract (Proposal 087 Phase 3): the
 * `mediaStudio` / `documentEditor` endpoints carry the local REST bases and
 * the `*Asset` / `*Css` endpoints carry the lazily-loaded island bundle URLs,
 * only when the addons are active and the session is admin + non-guest, and
 * honour the override filters. When inactive the endpoints are empty and the
 * SPA pages render their unavailable states.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Pro SPA config — island endpoint tests.
 */
class Test_Pro_Spa_Config_Island_Endpoints extends WP_UnitTestCase {

	/**
	 * Admin user id used by the admin-gated assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up an administrator session.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Load the addon stubs (feature flags resolve through class_exists).
	 *
	 * @return void
	 */
	private function load_stubs() {
		require_once __DIR__ . '/class-media-studio-stub.php';
		require_once __DIR__ . '/class-document-editor-stub.php';
	}

	/**
	 * Both island endpoint groups are empty while the addons are inactive.
	 */
	public function test_island_endpoints_empty_when_addons_inactive() {
		if ( class_exists( 'NV_oOS_Media_Studio_Shortcode' ) || class_exists( 'NV_oOS_Document_Editor_Shortcode' ) ) {
			$this->markTestSkipped( 'Island addon stubs already loaded by another test in this run.' );
		}

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();
		$this->assertSame( '', $runtime['endpoints']['mediaStudio'] );
		$this->assertSame( '', $runtime['endpoints']['mediaStudioAsset'] );
		$this->assertSame( '', $runtime['endpoints']['mediaStudioCss'] );
		$this->assertSame( '', $runtime['endpoints']['documentEditor'] );
		$this->assertSame( '', $runtime['endpoints']['documentEditorAsset'] );
		$this->assertSame( '', $runtime['endpoints']['documentEditorCss'] );
	}

	/**
	 * Active addons yield local REST bases and lazy-load asset URLs.
	 */
	public function test_island_endpoints_when_addons_active() {
		$this->load_stubs();

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();

		$this->assertSame( rest_url( 'nvoos-media-studio/v1' ), $runtime['endpoints']['mediaStudio'] );
		$this->assertStringContainsString( 'nvoos-media-studio/assets/dist/media-studio.js', $runtime['endpoints']['mediaStudioAsset'] );
		$this->assertStringContainsString( 'nvoos-media-studio/assets/dist/media-studio.css', $runtime['endpoints']['mediaStudioCss'] );

		$this->assertSame( rest_url( 'nvoos-document-editor/v1' ), $runtime['endpoints']['documentEditor'] );
		$this->assertStringContainsString( 'nvoos-document-editor/assets/dist/document-editor.js', $runtime['endpoints']['documentEditorAsset'] );
		$this->assertStringContainsString( 'nvoos-document-editor/assets/dist/document-editor.css', $runtime['endpoints']['documentEditorCss'] );
	}

	/**
	 * The override filters replace the REST bases.
	 */
	public function test_island_endpoints_honor_override_filters() {
		$this->load_stubs();

		add_filter(
			'wp_mcp_ai_pro_spa_media_studio_url',
			static function () {
				return 'https://example.com/wp-json/custom-media/v1';
			}
		);
		add_filter(
			'wp_mcp_ai_pro_spa_document_editor_url',
			static function () {
				return 'https://example.com/wp-json/custom-docs/v1';
			}
		);

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();
		$this->assertSame( 'https://example.com/wp-json/custom-media/v1', $runtime['endpoints']['mediaStudio'] );
		$this->assertSame( 'https://example.com/wp-json/custom-docs/v1', $runtime['endpoints']['documentEditor'] );
	}

	/**
	 * Non-admin sessions never receive the island endpoints.
	 */
	public function test_island_endpoints_empty_for_non_admin() {
		$this->load_stubs();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();
		$this->assertSame( '', $runtime['endpoints']['mediaStudio'] );
		$this->assertSame( '', $runtime['endpoints']['mediaStudioAsset'] );
		$this->assertSame( '', $runtime['endpoints']['documentEditor'] );
		$this->assertSame( '', $runtime['endpoints']['documentEditorAsset'] );

		wp_set_current_user( $this->admin_id );
	}
}

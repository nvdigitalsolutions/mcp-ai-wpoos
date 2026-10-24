<?php
/**
 * Test the #/docs endpoint emitted by the Pro SPA runtime config.
 *
 * Covers the native Docs Hub island contract (Proposal 087): the `docsHub`
 * endpoint carries the local `nvoos-docs/v1` REST base only when the Docs Hub
 * plugin is active and the session is admin + non-guest, and honours the
 * `wp_mcp_ai_pro_spa_docs_url` override filter. When the plugin is inactive
 * the endpoint is empty and the SPA page renders its unavailable state (same
 * contract as `contentGraph`).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Pro SPA config — docs endpoint tests.
 */
class Test_Pro_Spa_Config_Docs_Hub extends WP_UnitTestCase {

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
	 * The docs endpoint is empty while the Docs Hub plugin is inactive.
	 */
	public function test_docs_endpoint_empty_when_plugin_inactive() {
		if ( class_exists( 'NV_oOS_Docs_Hub_Plugin' ) ) {
			$this->markTestSkipped( 'Docs Hub stub already loaded by another test in this run.' );
		}

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();
		$this->assertSame( '', $runtime['endpoints']['docsHub'] );
	}

	/**
	 * The docs endpoint carries the local REST base when the plugin is active.
	 */
	public function test_docs_endpoint_local_rest_base_when_plugin_active() {
		require_once __DIR__ . '/class-docs-hub-stubs.php';

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();
		$this->assertSame(
			rest_url( 'nvoos-docs/v1' ),
			$runtime['endpoints']['docsHub']
		);
	}

	/**
	 * The wp_mcp_ai_pro_spa_docs_url filter overrides the REST base.
	 */
	public function test_docs_endpoint_honors_override_filter() {
		require_once __DIR__ . '/class-docs-hub-stubs.php';

		add_filter(
			'wp_mcp_ai_pro_spa_docs_url',
			static function () {
				return 'https://example.com/wp-json/custom-docs/v1';
			}
		);

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();
		$this->assertSame(
			'https://example.com/wp-json/custom-docs/v1',
			$runtime['endpoints']['docsHub']
		);
	}

	/**
	 * Non-admin sessions never receive the docs endpoint.
	 */
	public function test_docs_endpoint_empty_for_non_admin() {
		require_once __DIR__ . '/class-docs-hub-stubs.php';

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$runtime = WP_MCP_AI_Pro_SPA_Config::build();
		$this->assertSame( '', $runtime['endpoints']['docsHub'] );

		wp_set_current_user( $this->admin_id );
	}
}

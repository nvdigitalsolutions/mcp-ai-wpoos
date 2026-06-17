<?php
/**
 * Integration tests for JetEngine MCP functionality.
 *
 * Tests the end-to-end flow: discover → call → validate, as well as
 * backward compatibility with JetEngine < 3.8 when MCP is unavailable.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */
class Test_JetEngine_MCP_Integration extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Load all MCP classes.
		$pro_path = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : dirname( __DIR__ ) . '/';

		$classes = array(
			'includes/class-wp-mcp-ai-jetengine-compat.php',
			'includes/class-wp-mcp-ai-jetengine-mcp-client.php',
			'includes/class-wp-mcp-ai-jetengine-mcp-resources.php',
			'includes/class-wp-mcp-ai-jetengine-mcp-prompts.php',
		);

		foreach ( $classes as $class_file ) {
			$file = $pro_path . $class_file;
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_compat_class_has_mcp_methods() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_JetEngine_Compat' ) );
		$this->assertTrue( method_exists( 'WP_MCP_AI_JetEngine_Compat', 'has_mcp_server' ) );
		$this->assertTrue( method_exists( 'WP_MCP_AI_JetEngine_Compat', 'get_mcp_endpoint' ) );
		$this->assertTrue( method_exists( 'WP_MCP_AI_JetEngine_Compat', 'get_mcp_capabilities' ) );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_mcp_endpoint_returns_url() {
		$endpoint = WP_MCP_AI_JetEngine_Compat::get_mcp_endpoint();
		$this->assertStringContainsString( 'jet-engine/v1/mcp', $endpoint );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_has_mcp_server_returns_false_without_jetengine() {
		// Without JetEngine active, has_mcp_server should be false.
		// since is_jetengine_38_plus requires JET_ENGINE_VERSION constant.
		if ( defined( 'JET_ENGINE_VERSION' ) ) {
			$this->markTestSkipped( 'JetEngine is active; cannot test absence.' );
		}

		$this->assertFalse( WP_MCP_AI_JetEngine_Compat::has_mcp_server() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_get_mcp_capabilities_without_mcp() {
		if ( defined( 'JET_ENGINE_VERSION' ) && version_compare( JET_ENGINE_VERSION, '3.8.0', '>=' ) ) {
			$this->markTestSkipped( 'JetEngine 3.8+ is active.' );
		}

		$result = WP_MCP_AI_JetEngine_Compat::get_mcp_capabilities();
		$this->assertWPError( $result );
		$this->assertEquals( 'mcp_not_available', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_tool_handlers_has_mcp_namespace() {
		$file = defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-jetengine-tool-handlers.php'
			: '';

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Tool_Handlers' ) && ! empty( $file ) && file_exists( $file ) ) {
			require_once $file;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Tool_Handlers' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_JetEngine_Tool_Handlers not available.' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_JetEngine_Tool_Handlers' );
		$constants  = $reflection->getConstants();

		$this->assertArrayHasKey( 'REST_NAMESPACE_MCP', $constants );
		$this->assertEquals( 'jet-engine/v1/mcp', $constants['REST_NAMESPACE_MCP'] );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_backward_compatibility_without_mcp() {
		// Verify the existing compat methods still work without MCP.
		$this->assertIsBool( WP_MCP_AI_JetEngine_Compat::is_compatible() );
		$this->assertIsBool( WP_MCP_AI_JetEngine_Compat::is_jetengine_38_plus() );

		// These should return empty arrays when JetEngine not active.
		if ( ! function_exists( 'jet_engine' ) ) {
			$this->assertEmpty( WP_MCP_AI_JetEngine_Compat::get_jetengine_cpts() );
			$this->assertEmpty( WP_MCP_AI_JetEngine_Compat::get_jetengine_taxonomies() );
		}
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_mcp_client_constants() {
		$this->assertEquals( 'jet-engine/v1/mcp', WP_MCP_AI_JetEngine_MCP_Client::REST_NAMESPACE );
		$this->assertEquals( '2.0', WP_MCP_AI_JetEngine_MCP_Client::JSONRPC_VERSION );
		$this->assertEquals( 300, WP_MCP_AI_JetEngine_MCP_Client::DEFAULT_CACHE_TTL );
		$this->assertEquals( 'wp_mcp_ai_je_mcp_', WP_MCP_AI_JetEngine_MCP_Client::CACHE_PREFIX );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_settings_schema_includes_mcp_fields() {
		// Verify that settings class includes MCP-related settings.
		if ( ! class_exists( 'WP_MCP_AI_Section_JetEngine_Integration' ) ) {
			$file = defined( 'WP_MCP_AI_PATH' )
				? WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-jetengine.php'
				: '';

			if ( ! empty( $file ) && file_exists( $file ) ) {
				// Need the parent class first.
				if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
					$this->markTestSkipped( 'Settings section base class not available.' );
				}
				require_once $file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Section_JetEngine_Integration' ) ) {
			$this->markTestSkipped( 'Settings section class not available.' );
		}

		$section = new WP_MCP_AI_Section_JetEngine_Integration();
		$fields  = $section->get_fields();

		// MCP fields should be present when JetEngine is active.
		if ( class_exists( 'Jet_Engine' ) ) {
			$this->assertArrayHasKey( 'jetengine_mcp_enabled', $fields );
			$this->assertArrayHasKey( 'jetengine_mcp_cache_ttl', $fields );
			$this->assertArrayHasKey( 'jetengine_mcp_context_injection', $fields );
		}
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_resources_and_prompts_classes_available() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_JetEngine_MCP_Resources' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_JetEngine_MCP_Prompts' ) );
	}
}

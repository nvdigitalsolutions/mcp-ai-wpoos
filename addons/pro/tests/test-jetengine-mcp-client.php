<?php
/**
 * Tests for the JetEngine MCP Client.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */
class Test_JetEngine_MCP_Client extends WP_UnitTestCase {

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

		// Load the MCP client class.
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ) ) {
			$file = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-client.php'
				: dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-jetengine-mcp-client.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		// Clear any cached MCP responses.
		delete_transient( 'wp_mcp_ai_je_mcp_init' );
		delete_transient( 'wp_mcp_ai_je_mcp_tools_list' );
		delete_transient( 'wp_mcp_ai_je_mcp_resources_list' );
		delete_transient( 'wp_mcp_ai_je_mcp_prompts_list' );

		parent::tearDown();
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_client_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ),
			'WP_MCP_AI_JetEngine_MCP_Client class should exist'
		);
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_client_can_be_instantiated() {
		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$this->assertInstanceOf( 'WP_MCP_AI_JetEngine_MCP_Client', $client );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_client_with_remote_url() {
		$client = new WP_MCP_AI_JetEngine_MCP_Client( 'https://example.com' );
		$this->assertInstanceOf( 'WP_MCP_AI_JetEngine_MCP_Client', $client );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_client_with_auth_credentials() {
		$client = new WP_MCP_AI_JetEngine_MCP_Client(
			'https://example.com',
			array(
				'username' => 'admin',
				'password' => 'pass123',
			)
		);
		$this->assertInstanceOf( 'WP_MCP_AI_JetEngine_MCP_Client', $client );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_tools_call_requires_name() {
		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->tools_call( '' );

		$this->assertWPError( $result );
		$this->assertEquals( 'mcp_invalid_tool', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_prompts_get_requires_name() {
		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->prompts_get( '' );

		$this->assertWPError( $result );
		$this->assertEquals( 'mcp_invalid_prompt', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_clear_cache_deletes_transients() {
		set_transient( 'wp_mcp_ai_je_mcp_init', array( 'test' => true ), 300 );
		set_transient( 'wp_mcp_ai_je_mcp_tools_list', array( 'test' => true ), 300 );
		set_transient( 'wp_mcp_ai_je_mcp_resources_list', array( 'test' => true ), 300 );
		set_transient( 'wp_mcp_ai_je_mcp_prompts_list', array( 'test' => true ), 300 );

		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$client->clear_cache();

		$this->assertFalse( get_transient( 'wp_mcp_ai_je_mcp_init' ) );
		$this->assertFalse( get_transient( 'wp_mcp_ai_je_mcp_tools_list' ) );
		$this->assertFalse( get_transient( 'wp_mcp_ai_je_mcp_resources_list' ) );
		$this->assertFalse( get_transient( 'wp_mcp_ai_je_mcp_prompts_list' ) );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_initialize_uses_cache() {
		$cached_data = array(
			'protocolVersion' => '2024-11-05',
			'capabilities'    => array( 'tools' => true ),
		);
		set_transient( 'wp_mcp_ai_je_mcp_init', $cached_data, 300 );

		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->initialize( true );

		$this->assertEquals( $cached_data, $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_tools_list_uses_cache() {
		$cached_data = array(
			'tools' => array(
				array(
					'name'        => 'create_post_type',
					'description' => 'Create a post type',
				),
			),
		);
		set_transient( 'wp_mcp_ai_je_mcp_tools_list', $cached_data, 300 );

		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->tools_list( true );

		$this->assertEquals( $cached_data, $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_resources_list_uses_cache() {
		$cached_data = array(
			'resources' => array(
				array(
					'type' => 'post_types',
					'data' => array(),
				),
			),
		);
		set_transient( 'wp_mcp_ai_je_mcp_resources_list', $cached_data, 300 );

		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->resources_list( true );

		$this->assertEquals( $cached_data, $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_prompts_list_uses_cache() {
		$cached_data = array(
			'prompts' => array(
				array(
					'name'        => 'code_review',
					'description' => 'Review code',
				),
			),
		);
		set_transient( 'wp_mcp_ai_je_mcp_prompts_list', $cached_data, 300 );

		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->prompts_list( true );

		$this->assertEquals( $cached_data, $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_cache_ttl_respects_settings() {
		update_option( 'wp_mcp_ai_settings', array( 'jetengine_mcp_cache_ttl' => 600 ) );

		$client     = new WP_MCP_AI_JetEngine_MCP_Client();
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'get_cache_ttl' );
		$method->setAccessible( true );

		$ttl = $method->invoke( $client );
		$this->assertEquals( 600, $ttl );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_cache_ttl_minimum_is_60() {
		update_option( 'wp_mcp_ai_settings', array( 'jetengine_mcp_cache_ttl' => 10 ) );

		$client     = new WP_MCP_AI_JetEngine_MCP_Client();
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'get_cache_ttl' );
		$method->setAccessible( true );

		$ttl = $method->invoke( $client );
		$this->assertEquals( 60, $ttl );

		delete_option( 'wp_mcp_ai_settings' );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_jsonrpc_error_response_parsing() {
		$client     = new WP_MCP_AI_JetEngine_MCP_Client();
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'parse_jsonrpc_response' );
		$method->setAccessible( true );

		$error_response = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'error'   => array(
				'code'    => -32601,
				'message' => 'Method not found',
			),
		);

		$result = $method->invoke( $client, $error_response );

		$this->assertWPError( $result );
		$this->assertEquals( 'mcp_jsonrpc_error', $result->get_error_code() );
		$this->assertStringContainsString( '-32601', $result->get_error_message() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_jsonrpc_success_response_parsing() {
		$client     = new WP_MCP_AI_JetEngine_MCP_Client();
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'parse_jsonrpc_response' );
		$method->setAccessible( true );

		$success_response = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array(
				'tools' => array( 'create_post_type', 'get_post_types' ),
			),
		);

		$result = $method->invoke( $client, $success_response );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_jsonrpc_invalid_response_parsing() {
		$client     = new WP_MCP_AI_JetEngine_MCP_Client();
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'parse_jsonrpc_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $client, 'not an array' );

		$this->assertWPError( $result );
		$this->assertEquals( 'mcp_invalid_response', $result->get_error_code() );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_rest_namespace_constant() {
		$this->assertEquals( 'jet-engine/v1/mcp', WP_MCP_AI_JetEngine_MCP_Client::REST_NAMESPACE );
	}

	/** Summary.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_default_cache_ttl_constant() {
		$this->assertEquals( 300, WP_MCP_AI_JetEngine_MCP_Client::DEFAULT_CACHE_TTL );
	}
}

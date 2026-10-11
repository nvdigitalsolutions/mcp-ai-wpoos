<?php
/**
 * Tests for the JetEngine MCP client ttlMs cache freshness (proposal 066).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */
class Test_JetEngine_MCP_Client_TTL extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$client_path = defined( 'WP_MCP_AI_PRO_PATH' )
			? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-client.php'
			: dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-jetengine-mcp-client.php';

		if ( file_exists( $client_path ) ) {
			require_once $client_path;
		}
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		delete_transient( WP_MCP_AI_JetEngine_MCP_Client::CACHE_PREFIX . 'tools_list' );
		parent::tearDown();
	}

	/**
	 * Zero/absent ttlMs keeps the default TTL.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_resolve_cache_ttl_zero_keeps_default() {
		$this->assertSame( 300, WP_MCP_AI_JetEngine_MCP_Client::resolve_cache_ttl( 0, 300 ) );
		$this->assertSame( 300, WP_MCP_AI_JetEngine_MCP_Client::resolve_cache_ttl( 'not-a-number', 300 ) );
	}

	/**
	 * A positive ttlMs caps the default TTL at the server's bound.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_resolve_cache_ttl_positive_caps_default() {
		$this->assertSame( 60, WP_MCP_AI_JetEngine_MCP_Client::resolve_cache_ttl( 60000, 300 ) );
		$this->assertSame( 1, WP_MCP_AI_JetEngine_MCP_Client::resolve_cache_ttl( 500, 300 ) );
		$this->assertSame( 300, WP_MCP_AI_JetEngine_MCP_Client::resolve_cache_ttl( 999999999, 300 ) );
	}

	/**
	 * Tools_list caches its result (bounded by the server ttlMs).
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_tools_list_caches_with_server_ttl() {
		$payload = array(
			'tools' => array( array( 'name' => 'je_tool' ) ),
			'ttlMs' => 1000,
		);

		$this->intercept_jetengine_rpc( $payload );

		$cache_key = WP_MCP_AI_JetEngine_MCP_Client::CACHE_PREFIX . 'tools_list';
		delete_transient( $cache_key );

		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->tools_list( false );

		$this->assertSame( $payload, $result );
		$this->assertSame( $payload, get_transient( $cache_key ) );
	}

	/**
	 * Tools_list still caches when the server declares no ttlMs.
	 *
	 * @group jetengine
	 * @group pro
	 * @group mcp
	 */
	public function test_tools_list_caches_without_server_ttl() {
		$payload = array( 'tools' => array( array( 'name' => 'je_tool' ) ) );

		$this->intercept_jetengine_rpc( $payload );

		$cache_key = WP_MCP_AI_JetEngine_MCP_Client::CACHE_PREFIX . 'tools_list';
		delete_transient( $cache_key );

		$client = new WP_MCP_AI_JetEngine_MCP_Client();
		$result = $client->tools_list( false );

		$this->assertSame( $payload, $result );
		$this->assertSame( $payload, get_transient( $cache_key ) );
	}

	/**
	 * Intercept the internal JetEngine MCP dispatch with a canned result.
	 *
	 * @param array $result JSON-RPC result payload.
	 * @return void
	 */
	private function intercept_jetengine_rpc( array $result ) {
		add_filter(
			'rest_pre_dispatch',
			static function ( $response, $server, $request ) use ( $result ) {
				if ( 0 === strpos( $request->get_route(), '/' . WP_MCP_AI_JetEngine_MCP_Client::REST_NAMESPACE ) ) {
					return new WP_REST_Response(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => $result,
						),
						200
					);
				}

				return $response;
			},
			10,
			3
		);
	}
}

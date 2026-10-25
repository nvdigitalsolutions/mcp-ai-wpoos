<?php
/**
 * Shopify REST sunset regression tests (Proposal 089 follow-up).
 *
 * Shopify marked its REST Admin API legacy on 2024-10-01; sunset waves
 * started 2025-10 (products/count.json et al. went dark) and continue
 * through 2026. These tests pin the contract that Shopify connections
 * never resolve to a REST URL:
 *
 *  - legacy REST endpoints are rejected with a structured WP_Error,
 *  - GraphQL endpoints still resolve to {store}/admin/api/{v}/graphql.json,
 *  - UCP catalog modes resolve to their UCP origins (no REST anywhere).
 *
 * Zero real HTTP: every outbound request is intercepted by pre_http_request.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Shopify_REST_Sunset_Test extends WP_UnitTestCase {

	/**
	 * Saved connection id (cleaned up per test).
	 *
	 * @var string
	 */
	private $connection_id = '';

	/**
	 * Intercept every outbound request and capture the URL.
	 *
	 * @var string
	 */
	private $captured_url = '';

	/**
	 * Set up the HTTP interceptor.
	 */
	public function set_up() {
		parent::set_up();

		$this->captured_url = '';
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'ok' => true ) ),
				);
			},
			10,
			3
		);
	}

	/**
	 * Drop the fixture connection + interceptor.
	 */
	public function tear_down() {
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		}
		$this->connection_id = '';
		parent::tear_down();
	}

	/**
	 * Skip when the Pro classes are absent.
	 */
	private function require_pro() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) || ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * Save a Shopify connection fixture (skips when the manager rejects it).
	 *
	 * @param array $overrides Connection field overrides.
	 */
	private function save_shopify_connection( $overrides = array() ) {
		$data = array_merge(
			array(
				'name'            => 'Shopify fixture store',
				'url'             => 'https://fixture.myshopify.com',
				'connection_type' => 'shopify',
				'api_key'         => 'fixture-key',
				'enabled'         => 1,
			),
			$overrides
		);

		$save = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $data );
		if ( is_wp_error( $save ) ) {
			$this->markTestSkipped( 'Shopify fixture rejected: ' . $save->get_error_message() );
		}
		$this->connection_id = $save;
	}

	/**
	 * Legacy REST endpoints are rejected with a structured error.
	 */
	public function test_shopify_rest_endpoint_is_rejected() {
		$this->require_pro();
		$this->save_shopify_connection();

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, 'products/count.json' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_pro_shopify_rest_retired', $result->get_error_code() );
		$this->assertSame( '', $this->captured_url, 'No HTTP request may leave for a retired REST endpoint.' );
	}

	/**
	 * GraphQL endpoints still resolve to the versioned graphql.json URL.
	 */
	public function test_shopify_graphql_endpoint_resolves() {
		$this->require_pro();
		$this->save_shopify_connection();

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, 'graphql.json' );

		$this->assertNotWPError( $result );
		$this->assertStringEndsWith( '/graphql.json', $this->captured_url );
		$this->assertStringNotContainsString( '/admin/api/2025-01/products', $this->captured_url );
	}

	/**
	 * UCP catalog modes resolve to their UCP origins — never a REST path.
	 */
	public function test_shopify_ucp_modes_resolve_to_ucp() {
		$this->require_pro();
		$this->save_shopify_connection( array( 'shopify_api_mode' => 'global_catalog' ) );

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, 'search' );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'catalog.shopify.com/api/ucp/mcp', $this->captured_url );

		// Storefront Catalog MCP lives on the store's own origin.
		$this->save_shopify_connection( array( 'shopify_api_mode' => 'storefront_catalog' ) );
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
		$result     = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, 'search' );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'fixture.myshopify.com/api/ucp/mcp', $this->captured_url );
	}
}

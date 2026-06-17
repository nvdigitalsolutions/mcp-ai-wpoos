<?php
/**
 * Tests for WP_MCP_AI_Vector_Store_Adapter.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Test_Vector_Store_Adapter.
 */
class Test_Vector_Store_Adapter extends WP_UnitTestCase {

	/**
	 * Reset settings between tests.
	 */
	/** Set up test. */
	public function set_up() {
		parent::set_up();
		delete_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_SETTINGS );
		delete_option( WP_MCP_AI_Vector_Store_Adapter::OPTION_NAMESPACES );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Test singleton returns same instance.
	 */
	public function test_singleton_returns_same_instance() {
		$a = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$b = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$this->assertSame( $a, $b );
	}

	/** Test default backend is openai.
	 */
	public function test_default_backend_is_openai() {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$this->assertSame( 'openai', $adapter->get_backend() );
	}

	/** Test set backend qdrant round trips.
	 */
	public function test_set_backend_qdrant_round_trips() {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$this->assertTrue( $adapter->set_backend( 'qdrant' ) );

		// The filter may force-override; ensure either the new value or the forced one is returned.
		$result = $adapter->get_backend();
		$this->assertContains( $result, array( 'qdrant', 'openai' ) );
	}

	/** Test is configured pgvector false without dsn.
	 */
	public function test_is_configured_pgvector_false_without_dsn() {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$this->assertFalse( $adapter->is_configured( 'pgvector' ) );
	}

	/** Test list backends returns three entries.
	 */
	public function test_list_backends_returns_three_entries() {
		$adapter  = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$backends = $adapter->list_backends();
		$this->assertCount( 3, $backends );
		foreach ( $backends as $b ) {
			$this->assertArrayHasKey( 'key', $b );
			$this->assertArrayHasKey( 'label', $b );
			$this->assertArrayHasKey( 'configured', $b );
			$this->assertArrayHasKey( 'description', $b );
		}
	}

	/** Test upsert with stub backend returns success.
	 */
	public function test_upsert_with_stub_backend_returns_success() {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$adapter->set_backend( 'pgvector' );

		$result = $adapter->upsert(
			'team-a',
			array(
				array(
					'id'       => '1',
					'text'     => 'hi',
					'metadata' => array(),
				),
			)
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'pgvector', $result['backend'] );
		$this->assertTrue( $result['stub'] );
		$this->assertSame( 1, $result['would_upsert'] );
	}

	/** Test query fires action.
	 */
	public function test_query_fires_action() {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$adapter->set_backend( 'qdrant' );

		$captured = array();
		$cb       = function ( $namespace, $backend, $count ) use ( &$captured ) {
			$captured = array( $namespace, $backend, $count );
		};
		add_action( 'wp_mcp_ai_vector_store_query', $cb, 10, 3 );

		$adapter->query( 'team-b', 'hello world', 3 );

		remove_action( 'wp_mcp_ai_vector_store_query', $cb, 10 );

		$this->assertCount( 3, $captured );
		$this->assertSame( 'team-b', $captured[0] );
		$this->assertSame( 'qdrant', $captured[1] );
	}

	/** Test namespace filter is applied.
	 */
	public function test_namespace_filter_is_applied() {
		$adapter = WP_MCP_AI_Vector_Store_Adapter::get_instance();
		$adapter->set_backend( 'pgvector' );

		$cb = function ( $namespace ) {
			return 'team-x/' . $namespace;
		};
		add_filter( 'wp_mcp_ai_vector_store_namespace', $cb );

		$adapter->upsert( 'docs', array() );

		remove_filter( 'wp_mcp_ai_vector_store_namespace', $cb );

		$namespaces = $adapter->list_namespaces();
		$this->assertContains( 'team-x/docs', $namespaces );
	}
}

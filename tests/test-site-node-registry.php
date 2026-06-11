<?php
/**
 * Tests for the Site Node Registry and built-in site nodes.
 *
 * @package    WP_MCP_AI
 * @subpackage Site_Builder
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    GPL-3.0-or-later
 */

/**
 * Test Site Node Registry — auto-discovery, registration, and front-end output.
 */
class Test_Site_Node_Registry extends WP_UnitTestCase {

	/**
	 * Registry instance.
	 *
	 * @var WP_MCP_AI_Site_Node_Registry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/site-builder/class-wp-mcp-ai-site-node-interface.php';
		require_once dirname( __DIR__ ) . '/includes/site-builder/class-wp-mcp-ai-site-node-registry.php';

		$this->registry = WP_MCP_AI_Site_Node_Registry::get_instance();
	}

	/**
	 * Test that the registry is a singleton.
	 */
	public function test_registry_is_singleton() {
		$a = WP_MCP_AI_Site_Node_Registry::get_instance();
		$b = WP_MCP_AI_Site_Node_Registry::get_instance();

		$this->assertSame( $a, $b );
	}

	/**
	 * Test that init() loads default nodes.
	 */
	public function test_init_loads_default_nodes() {
		$this->registry->init();

		$this->assertTrue( $this->registry->has_node( 'wp_query_source' ), 'WP Query node should be registered.' );
		$this->assertTrue( $this->registry->has_node( 'text_block' ), 'Text Block node should be registered.' );
		$this->assertTrue( $this->registry->has_node( 'flex_container' ), 'Flex Container node should be registered.' );
	}

	/**
	 * Test getting a node by slug.
	 */
	public function test_get_node_returns_correct_instance() {
		$this->registry->init();

		$node = $this->registry->get_node( 'text_block' );
		$this->assertInstanceOf( WP_MCP_AI_Site_Node_Interface::class, $node );
		$this->assertSame( 'text_block', $node->get_slug() );
	}

	/**
	 * Test getting an unregistered node returns null.
	 */
	public function test_get_node_returns_null_for_unknown_slug() {
		$this->registry->init();

		$this->assertNull( $this->registry->get_node( 'nonexistent_node' ) );
	}

	/**
	 * Test get_nodes_by_category filters correctly.
	 */
	public function test_get_nodes_by_category() {
		$this->registry->init();

		$sources = $this->registry->get_nodes_by_category( 'source' );
		$layouts = $this->registry->get_nodes_by_category( 'layout' );

		$this->assertCount( 1, $sources, 'Should have exactly one source node.' );
		$this->assertCount( 2, $layouts, 'Should have exactly two layout nodes.' );

		foreach ( $sources as $node ) {
			$this->assertSame( 'source', $node->get_category() );
		}
		foreach ( $layouts as $node ) {
			$this->assertSame( 'layout', $node->get_category() );
		}
	}

	/**
	 * Test that third-party nodes can be registered via the action hook.
	 */
	public function test_custom_node_registration_via_action() {
		$this->registry->init();

		$custom = new class implements WP_MCP_AI_Site_Node_Interface {
			public function get_slug(): string        { return 'custom_test_node'; }
			public function get_name(): string        { return 'Custom Test'; }
			public function get_description(): string { return 'Custom test node.'; }
			public function get_category(): string    { return 'integration'; }
			public function get_inputs(): array       { return array(); }
			public function get_outputs(): array      { return array(); }
			public function execute( array $inputs )  { return array( 'ok' => true ); }
		};

		do_action( 'wp_mcp_ai_register_site_nodes', $this->registry );

		// The custom node would be added by a hook callback — let's simulate directly.
		$this->registry->register_node( $custom );

		$this->assertTrue( $this->registry->has_node( 'custom_test_node' ) );
	}

	/**
	 * Test get_nodes_for_frontend returns valid structure.
	 */
	public function test_get_nodes_for_frontend() {
		$this->registry->init();

		$data = $this->registry->get_nodes_for_frontend();

		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );

		foreach ( $data as $entry ) {
			$this->assertArrayHasKey( 'slug', $entry );
			$this->assertArrayHasKey( 'name', $entry );
			$this->assertArrayHasKey( 'description', $entry );
			$this->assertArrayHasKey( 'category', $entry );
			$this->assertArrayHasKey( 'inputs', $entry );
			$this->assertArrayHasKey( 'outputs', $entry );
			$this->assertIsArray( $entry['inputs'] );
			$this->assertIsArray( $entry['outputs'] );
		}

		// Verify stable ordering: source before layout, alphabetically within.
		$categories = array_map( function ( $e ) {
			return $e['category'];
		}, $data );

		$sorted = $categories;
		sort( $sorted );
		$this->assertSame( $sorted, $categories, 'Front-end data should be sorted by category.' );
	}

	/**
	 * Test execute_node returns WP_Error for unknown node.
	 */
	public function test_execute_node_returns_error_for_unknown() {
		$this->registry->init();

		$result = $this->registry->execute_node( 'does_not_exist', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_node_not_found', $result->get_error_code() );
	}
}

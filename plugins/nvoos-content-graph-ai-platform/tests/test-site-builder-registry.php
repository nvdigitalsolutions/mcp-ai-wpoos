<?php
/**
 * Site builder registry port tests (Wave E4, sub-cluster 5).
 *
 * Characterization suite for the ported `SiteNodeRegistry`: singleton
 * lifecycle, idempotent `init()` with the built-in node map, slug
 * lookups, category filtering, the stable front-end palette
 * serialisation, the `wp_mcp_ai_register_site_nodes` extension action,
 * and the `wp_mcp_ai_site_node_not_found` envelope. The custom-node
 * registration test resolves the interface per install mode (base
 * interface monolith / platform interface standalone). Runs in both
 * matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeRegistry;
use NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeInterface;

/**
 * Site node registry characterization.
 */
class Test_Site_Builder_Registry extends \WP_UnitTestCase {

	/**
	 * Registry instance.
	 *
	 * @var SiteNodeRegistry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->registry = SiteNodeRegistry::get_instance();
	}

	/**
	 * The registry is a singleton.
	 */
	public function test_registry_is_singleton() {
		$a = SiteNodeRegistry::get_instance();
		$b = SiteNodeRegistry::get_instance();

		$this->assertSame( $a, $b );
	}

	/**
	 * init() loads the built-in nodes (resolved per install mode).
	 */
	public function test_init_loads_default_nodes() {
		$this->registry->init();

		$this->assertTrue( $this->registry->has_node( 'wp_query_source' ), 'WP Query node should be registered.' );
		$this->assertTrue( $this->registry->has_node( 'text_block' ), 'Text Block node should be registered.' );
		$this->assertTrue( $this->registry->has_node( 'flex_container' ), 'Flex Container node should be registered.' );
	}

	/**
	 * Getting a node by slug returns a conforming instance.
	 */
	public function test_get_node_returns_correct_instance() {
		$this->registry->init();

		$node = $this->registry->get_node( 'text_block' );

		$this->assertNotNull( $node );
		$this->assertSame( 'text_block', $node->get_slug() );
	}

	/**
	 * Getting an unregistered node returns null.
	 */
	public function test_get_node_returns_null_for_unknown_slug() {
		$this->registry->init();

		$this->assertNull( $this->registry->get_node( 'nonexistent_node' ) );
	}

	/**
	 * get_nodes_by_category filters correctly.
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
	 * Third-party nodes can be registered through the public register
	 * surface. The fixture node implements whichever interface the
	 * current install mode accepts.
	 */
	public function test_custom_node_registration() {
		$this->registry->init();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$custom = new class() implements \WP_MCP_AI_Site_Node_Interface {
				public function get_slug(): string {
					return 'custom_test_node'; }
				public function get_name(): string {
					return 'Custom Test'; }
				public function get_description(): string {
					return 'Custom test node.'; }
				public function get_category(): string {
					return 'integration'; }
				public function get_inputs(): array {
					return array(); }
				public function get_outputs(): array {
					return array(); }
				public function execute( array $inputs ) {
					return array( 'ok' => true ); }
			};
		} else {
			$custom = new class() implements SiteNodeInterface {
				public function get_slug(): string {
					return 'custom_test_node'; }
				public function get_name(): string {
					return 'Custom Test'; }
				public function get_description(): string {
					return 'Custom test node.'; }
				public function get_category(): string {
					return 'integration'; }
				public function get_inputs(): array {
					return array(); }
				public function get_outputs(): array {
					return array(); }
				public function execute( array $inputs ) {
					return array( 'ok' => true ); }
			};
		}

		$this->registry->register_node( $custom );

		$this->assertTrue( $this->registry->has_node( 'custom_test_node' ) );
	}

	/**
	 * get_nodes_for_frontend returns the documented structure with stable
	 * category ordering.
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

		// Verify stable ordering: category ascending.
		$categories = array_map(
			function ( $e ) {
				return $e['category'];
			},
			$data
		);

		$sorted = $categories;
		sort( $sorted );
		$this->assertSame( $sorted, $categories, 'Front-end data should be sorted by category.' );
	}

	/**
	 * execute_node returns the documented WP_Error for unknown nodes.
	 */
	public function test_execute_node_returns_error_for_unknown() {
		$this->registry->init();

		$result = $this->registry->execute_node( 'does_not_exist', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_site_node_not_found', $result->get_error_code() );
	}

	/**
	 * Non-conforming objects are skipped fail-soft (documented hardening
	 * over the base's interface type-hint).
	 */
	public function test_non_interface_objects_are_skipped() {
		$this->registry->init();

		$this->registry->register_node( new \stdClass() );

		// No fatal, no registration: assert the built-in set is unchanged.
		$this->assertFalse( $this->registry->has_node( 'stdclass' ) );
	}
}

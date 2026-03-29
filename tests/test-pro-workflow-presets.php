<?php
/**
 * Tests for WP_MCP_AI_Pro_Workflow_Presets.
 *
 * Covers:
 * - Retrieving all presets and verifying count
 * - Required keys on every preset definition
 * - Node and edge structure validation
 * - Edge-to-node referential integrity
 * - Single-preset retrieval (valid and invalid IDs)
 * - Filtering by category
 * - Category list completeness
 * - Installing a preset returns expected workflow data
 * - Node position numeric validation
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @covers  WP_MCP_AI_Pro_Workflow_Presets
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-workflow-presets.php';

/**
 * Test suite for Pro Workflow Presets.
 *
 * @since 1.0.0
 */
class Test_Pro_Workflow_Presets extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Preset retrieval
	// -------------------------------------------------------------------------

	/**
	 * Test that get_presets() returns a non-empty array.
	 */
	public function test_get_presets_returns_array() {
		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();

		$this->assertIsArray( $presets );
		$this->assertNotEmpty( $presets );
	}

	/**
	 * Test that there are at least 20 workflow presets.
	 */
	public function test_get_presets_returns_at_least_20_presets() {
		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();

		$this->assertGreaterThanOrEqual( 20, count( $presets ) );
	}

	/**
	 * Test that every preset contains all required keys.
	 */
	public function test_each_preset_has_required_keys() {
		$required_keys = array(
			'name',
			'description',
			'category',
			'icon',
			'tags',
			'nodes',
			'edges',
		);

		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			foreach ( $required_keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$preset,
					"Preset '{$id}' is missing required key '{$key}'."
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Node validation
	// -------------------------------------------------------------------------

	/**
	 * Test that every node has required keys: id, type, position (x, y), data (label).
	 */
	public function test_preset_nodes_are_valid() {
		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			$this->assertIsArray( $preset['nodes'], "Preset '{$id}' nodes must be an array." );

			foreach ( $preset['nodes'] as $index => $node ) {
				$this->assertArrayHasKey(
					'id',
					$node,
					"Preset '{$id}' node [{$index}] is missing 'id'."
				);
				$this->assertArrayHasKey(
					'type',
					$node,
					"Preset '{$id}' node [{$index}] is missing 'type'."
				);
				$this->assertArrayHasKey(
					'position',
					$node,
					"Preset '{$id}' node [{$index}] is missing 'position'."
				);
				$this->assertArrayHasKey(
					'x',
					$node['position'],
					"Preset '{$id}' node [{$index}] position is missing 'x'."
				);
				$this->assertArrayHasKey(
					'y',
					$node['position'],
					"Preset '{$id}' node [{$index}] position is missing 'y'."
				);
				$this->assertArrayHasKey(
					'data',
					$node,
					"Preset '{$id}' node [{$index}] is missing 'data'."
				);
				$this->assertArrayHasKey(
					'label',
					$node['data'],
					"Preset '{$id}' node [{$index}] data is missing 'label'."
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Edge validation
	// -------------------------------------------------------------------------

	/**
	 * Test that every edge has required keys: id, source, target.
	 */
	public function test_preset_edges_are_valid() {
		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			$this->assertIsArray( $preset['edges'], "Preset '{$id}' edges must be an array." );

			foreach ( $preset['edges'] as $index => $edge ) {
				$this->assertArrayHasKey(
					'id',
					$edge,
					"Preset '{$id}' edge [{$index}] is missing 'id'."
				);
				$this->assertArrayHasKey(
					'source',
					$edge,
					"Preset '{$id}' edge [{$index}] is missing 'source'."
				);
				$this->assertArrayHasKey(
					'target',
					$edge,
					"Preset '{$id}' edge [{$index}] is missing 'target'."
				);
			}
		}
	}

	/**
	 * Test that all edge source/target IDs reference existing node IDs.
	 */
	public function test_edges_reference_valid_nodes() {
		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			$node_ids = wp_list_pluck( $preset['nodes'], 'id' );

			foreach ( $preset['edges'] as $index => $edge ) {
				$this->assertContains(
					$edge['source'],
					$node_ids,
					"Preset '{$id}' edge [{$index}] source '{$edge['source']}' does not reference a valid node."
				);
				$this->assertContains(
					$edge['target'],
					$node_ids,
					"Preset '{$id}' edge [{$index}] target '{$edge['target']}' does not reference a valid node."
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Single-preset retrieval
	// -------------------------------------------------------------------------

	/**
	 * Test that get_preset() returns the correct preset for a known ID.
	 */
	public function test_get_preset_returns_single_preset() {
		$preset = WP_MCP_AI_Pro_Workflow_Presets::get_preset( 'content_pipeline' );

		$this->assertIsArray( $preset );
		$this->assertArrayHasKey( 'name', $preset );
		$this->assertArrayHasKey( 'nodes', $preset );
		$this->assertArrayHasKey( 'edges', $preset );
	}

	/**
	 * Test that get_preset() returns null for a non-existent ID.
	 */
	public function test_get_preset_returns_null_for_invalid_id() {
		$preset = WP_MCP_AI_Pro_Workflow_Presets::get_preset( 'nonexistent' );

		$this->assertNull( $preset );
	}

	// -------------------------------------------------------------------------
	// Filtering by category
	// -------------------------------------------------------------------------

	/**
	 * Test that get_presets_by_category() returns only matching presets.
	 */
	public function test_get_presets_by_category_filters_correctly() {
		$categories = WP_MCP_AI_Pro_Workflow_Presets::get_categories();
		$first_cat  = array_key_first( $categories );
		$filtered   = WP_MCP_AI_Pro_Workflow_Presets::get_presets_by_category( $first_cat );

		$this->assertIsArray( $filtered );
		$this->assertNotEmpty( $filtered );

		foreach ( $filtered as $id => $preset ) {
			$this->assertSame(
				$first_cat,
				$preset['category'],
				"Preset '{$id}' has category '{$preset['category']}' instead of '{$first_cat}'."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Category list
	// -------------------------------------------------------------------------

	/**
	 * Test that get_categories() includes all eight expected categories.
	 */
	public function test_get_categories_returns_all_categories() {
		$categories = WP_MCP_AI_Pro_Workflow_Presets::get_categories();

		$expected = array(
			'content',
			'seo',
			'ecommerce',
			'marketing',
			'data',
			'communication',
			'maintenance',
			'onboarding',
		);

		foreach ( $expected as $slug ) {
			$this->assertArrayHasKey(
				$slug,
				$categories,
				"Category '{$slug}' is missing from get_categories()."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Preset installation
	// -------------------------------------------------------------------------

	/**
	 * Test that install_preset() returns workflow data for a valid preset.
	 */
	public function test_install_preset_returns_workflow_data() {
		$result = WP_MCP_AI_Pro_Workflow_Presets::install_preset( 'content_pipeline' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'nodes', $result );
		$this->assertArrayHasKey( 'edges', $result );
	}

	/**
	 * Test that install_preset() returns WP_Error for a non-existent ID.
	 */
	public function test_install_preset_returns_null_for_invalid_id() {
		$result = WP_MCP_AI_Pro_Workflow_Presets::install_preset( 'bad_id' );

		$this->assertWPError( $result );
	}

	// -------------------------------------------------------------------------
	// Category coverage
	// -------------------------------------------------------------------------

	/**
	 * Test that every category has at least one preset.
	 */
	public function test_all_categories_have_presets() {
		$categories = WP_MCP_AI_Pro_Workflow_Presets::get_categories();

		foreach ( array_keys( $categories ) as $slug ) {
			$filtered = WP_MCP_AI_Pro_Workflow_Presets::get_presets_by_category( $slug );

			$this->assertNotEmpty(
				$filtered,
				"Category '{$slug}' has no presets."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Node position validation
	// -------------------------------------------------------------------------

	/**
	 * Test that all node x and y positions are numeric values.
	 */
	public function test_node_positions_are_numeric() {
		$presets = WP_MCP_AI_Pro_Workflow_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			foreach ( $preset['nodes'] as $index => $node ) {
				$this->assertIsNumeric(
					$node['position']['x'],
					"Preset '{$id}' node [{$index}] position x is not numeric."
				);
				$this->assertIsNumeric(
					$node['position']['y'],
					"Preset '{$id}' node [{$index}] position y is not numeric."
				);
			}
		}
	}
}

<?php
/**
 * Tests for WP_MCP_AI_Pro_Schedule_Presets.
 *
 * Covers:
 * - Retrieving all presets and verifying count
 * - Required keys on every preset definition
 * - Valid schedule types
 * - Single-preset retrieval (valid and invalid IDs)
 * - Filtering by toolkit and category
 * - Category list completeness
 * - Per-toolkit preset minimum count
 * - Installing a preset as a live schedule
 * - Type-specific schedule_data validation
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @covers  WP_MCP_AI_Pro_Schedule_Presets
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-presets.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';

/**
 * Test suite for Pro Schedule Presets.
 *
 * @since 1.0.0
 */
class Test_Pro_Schedule_Presets extends WP_UnitTestCase {

	/**
	 * Admin user ID used for install_preset tests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Preset retrieval
	// -------------------------------------------------------------------------

	/**
	 * Test that get_presets() returns a non-empty array.
	 */
	public function test_get_presets_returns_array() {
		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		$this->assertIsArray( $presets );
		$this->assertNotEmpty( $presets );
	}

	/**
	 * Test that there are at least 100 presets (5 per 20 toolkits).
	 */
	public function test_get_presets_returns_at_least_100_presets() {
		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		$this->assertGreaterThanOrEqual( 100, count( $presets ) );
	}

	/**
	 * Test that every preset contains all required keys.
	 */
	public function test_each_preset_has_required_keys() {
		$required_keys = array(
			'name',
			'description',
			'toolkit',
			'category',
			'icon',
			'schedule_type',
			'schedule',
			'tags',
			'schedule_data',
		);

		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

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

	/**
	 * Test that every preset uses a valid schedule_type value.
	 */
	public function test_preset_schedule_types_are_valid() {
		$valid_types = array(
			'task',
			'workflow',
			'assistant_run',
			'channel_broadcast',
			'workflow_builder',
		);

		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			$this->assertContains(
				$preset['schedule_type'],
				$valid_types,
				"Preset '{$id}' has invalid schedule_type '{$preset['schedule_type']}'."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Single-preset retrieval
	// -------------------------------------------------------------------------

	/**
	 * Test that get_preset() returns the correct preset for a known ID.
	 */
	public function test_get_preset_returns_single_preset() {
		$preset = WP_MCP_AI_Pro_Schedule_Presets::get_preset( 'inventory_check' );

		$this->assertIsArray( $preset );
		$this->assertArrayHasKey( 'name', $preset );
		$this->assertArrayHasKey( 'schedule_type', $preset );
	}

	/**
	 * Test that get_preset() returns null for a non-existent ID.
	 */
	public function test_get_preset_returns_null_for_invalid_id() {
		$preset = WP_MCP_AI_Pro_Schedule_Presets::get_preset( 'nonexistent' );

		$this->assertNull( $preset );
	}

	// -------------------------------------------------------------------------
	// Filtering by toolkit
	// -------------------------------------------------------------------------

	/**
	 * Test that get_presets_by_toolkit() returns only presets from the given toolkit.
	 */
	public function test_get_presets_by_toolkit_filters_correctly() {
		$ecommerce = WP_MCP_AI_Pro_Schedule_Presets::get_presets_by_toolkit( 'ecommerce' );

		$this->assertIsArray( $ecommerce );
		$this->assertNotEmpty( $ecommerce );

		foreach ( $ecommerce as $id => $preset ) {
			$this->assertSame(
				'ecommerce',
				$preset['toolkit'],
				"Preset '{$id}' has toolkit '{$preset['toolkit']}' instead of 'ecommerce'."
			);
		}
	}

	/**
	 * Test that get_presets_by_toolkit() returns an empty array for an unknown toolkit.
	 */
	public function test_get_presets_by_toolkit_returns_empty_for_invalid_toolkit() {
		$result = WP_MCP_AI_Pro_Schedule_Presets::get_presets_by_toolkit( 'does_not_exist' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// -------------------------------------------------------------------------
	// Filtering by category
	// -------------------------------------------------------------------------

	/**
	 * Test that get_presets_by_category() returns only matching presets.
	 */
	public function test_get_presets_by_category_filters_correctly() {
		$monitoring = WP_MCP_AI_Pro_Schedule_Presets::get_presets_by_category( 'monitoring' );

		$this->assertIsArray( $monitoring );
		$this->assertNotEmpty( $monitoring );

		foreach ( $monitoring as $id => $preset ) {
			$this->assertSame(
				'monitoring',
				$preset['category'],
				"Preset '{$id}' has category '{$preset['category']}' instead of 'monitoring'."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Category list
	// -------------------------------------------------------------------------

	/**
	 * Test that get_categories() includes the expected categories.
	 */
	public function test_get_categories_returns_all_categories() {
		$categories = WP_MCP_AI_Pro_Schedule_Presets::get_categories();

		$expected = array(
			'content',
			'monitoring',
			'reporting',
			'communication',
			'maintenance',
			'marketing',
			'business',
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
	// Per-toolkit minimum count
	// -------------------------------------------------------------------------

	/**
	 * Test that every toolkit has at least five presets.
	 */
	public function test_all_toolkits_have_at_least_five_presets() {
		$presets  = WP_MCP_AI_Pro_Schedule_Presets::get_presets();
		$toolkits = array();

		foreach ( $presets as $preset ) {
			if ( isset( $preset['toolkit'] ) ) {
				$tk = $preset['toolkit'];
				if ( ! isset( $toolkits[ $tk ] ) ) {
					$toolkits[ $tk ] = 0;
				}
				++$toolkits[ $tk ];
			}
		}

		foreach ( $toolkits as $toolkit => $count ) {
			$this->assertGreaterThanOrEqual(
				5,
				$count,
				"Toolkit '{$toolkit}' has only {$count} preset(s); expected at least 5."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Preset installation
	// -------------------------------------------------------------------------

	/**
	 * Test that install_preset() creates a schedule for a valid preset.
	 */
	public function test_install_preset_creates_schedule() {
		$result = WP_MCP_AI_Pro_Schedule_Presets::install_preset( 'inventory_check', $this->admin_id );

		$this->assertIsString( $result, 'install_preset should return a schedule ID string.' );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test that install_preset() returns WP_Error for a non-existent preset.
	 */
	public function test_install_preset_returns_error_for_invalid_preset() {
		$result = WP_MCP_AI_Pro_Schedule_Presets::install_preset( 'nonexistent', $this->admin_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_preset', $result->get_error_code() );
	}

	/**
	 * Test that install_preset() returns WP_Error when user_id is 0.
	 */
	public function test_install_preset_returns_error_for_invalid_user() {
		$result = WP_MCP_AI_Pro_Schedule_Presets::install_preset( 'inventory_check', 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// Type-specific schedule_data validation
	// -------------------------------------------------------------------------

	/**
	 * Test that all task-type presets include a 'hook' in schedule_data.
	 */
	public function test_task_presets_have_hook_in_schedule_data() {
		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			if ( 'task' !== $preset['schedule_type'] ) {
				continue;
			}
			$this->assertArrayHasKey(
				'hook',
				$preset['schedule_data'],
				"Task preset '{$id}' is missing 'hook' in schedule_data."
			);
		}
	}

	/**
	 * Test that all workflow-type presets include 'workflow_steps' in schedule_data.
	 */
	public function test_workflow_presets_have_steps_in_schedule_data() {
		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			if ( 'workflow' !== $preset['schedule_type'] ) {
				continue;
			}
			$this->assertArrayHasKey(
				'workflow_steps',
				$preset['schedule_data'],
				"Workflow preset '{$id}' is missing 'workflow_steps' in schedule_data."
			);
		}
	}

	/**
	 * Test that all assistant_run presets include 'assistant_config' with 'message'.
	 */
	public function test_assistant_run_presets_have_config_in_schedule_data() {
		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			if ( 'assistant_run' !== $preset['schedule_type'] ) {
				continue;
			}
			$this->assertArrayHasKey(
				'assistant_config',
				$preset['schedule_data'],
				"Assistant-run preset '{$id}' is missing 'assistant_config' in schedule_data."
			);
			$this->assertArrayHasKey(
				'message',
				$preset['schedule_data']['assistant_config'],
				"Assistant-run preset '{$id}' is missing 'message' inside assistant_config."
			);
		}
	}

	/**
	 * Test that all channel_broadcast presets include 'broadcast_config' with 'message' and 'channels'.
	 */
	public function test_channel_broadcast_presets_have_config() {
		$presets = WP_MCP_AI_Pro_Schedule_Presets::get_presets();

		foreach ( $presets as $id => $preset ) {
			if ( 'channel_broadcast' !== $preset['schedule_type'] ) {
				continue;
			}
			$this->assertArrayHasKey(
				'broadcast_config',
				$preset['schedule_data'],
				"Broadcast preset '{$id}' is missing 'broadcast_config' in schedule_data."
			);
			$this->assertArrayHasKey(
				'message',
				$preset['schedule_data']['broadcast_config'],
				"Broadcast preset '{$id}' is missing 'message' inside broadcast_config."
			);
			$this->assertArrayHasKey(
				'channels',
				$preset['schedule_data']['broadcast_config'],
				"Broadcast preset '{$id}' is missing 'channels' inside broadcast_config."
			);
		}
	}
}

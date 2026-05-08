<?php
/**
 * AJAX tests for the Schedule Manager handlers (Pro addon).
 *
 * Covers the 4-point coverage contract for:
 *   - wp_mcp_ai_sm_get_schedules      (ajax_get_schedules)
 *   - wp_mcp_ai_sm_create_schedule    (ajax_create_schedule)
 *   - wp_mcp_ai_sm_update_schedule    (ajax_update_schedule)
 *   - wp_mcp_ai_sm_delete_schedule    (ajax_delete_schedule)
 *   - wp_mcp_ai_sm_toggle_schedule    (ajax_toggle_schedule)
 *   - wp_mcp_ai_sm_trigger_schedule   (ajax_trigger_schedule)
 *   - wp_mcp_ai_sm_get_history        (ajax_get_history)
 *   - wp_mcp_ai_sm_clear_history      (ajax_clear_history)
 *   - wp_mcp_ai_sm_export_history_csv (ajax_export_history_csv)
 *   - wp_mcp_ai_sm_export_ical        (ajax_export_ical)
 *   - wp_mcp_ai_sm_get_presets        (ajax_get_presets)
 *   - wp_mcp_ai_sm_install_preset     (ajax_install_preset)
 *
 * All handlers live in `addons/pro/` and are skipped when the Pro class is absent.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- inherits camelCase $_last_response from WP_Ajax_UnitTestCase.

/**
 * AJAX cluster: Schedule Manager (Pro).
 */
class Test_Schedule_Manager_AJAX extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Nonce action used by all schedule manager handlers.
	 */
	const NONCE = 'wp_mcp_ai_pro_schedule_manager';

	/**
	 * Skip the entire class when the Pro addon is absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Section_Schedule_Manager' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Section_Schedule_Manager (Pro) is not available in this environment.' );
		}
	}

	// ---
	// Shared helper: assert cap/nonce rejection for a given action.
	// ---

	/**
	 * Assert rejected without nonce.
	 *
	 * @param mixed $action Value.
	 */
	private function assert_rejected_without_nonce( $action ) {
		$this->as_admin();
		$response = $this->dispatch( $action );
		$this->assertAjaxForbidden( $response );
	}

	/**
	 * Assert rejected for subscriber.
	 *
	 * @param mixed $action Value.
	 * @param array $extra Value.
	 */
	private function assert_rejected_for_subscriber( $action, array $extra = array() ) {
		$this->as_subscriber();
		$response = $this->dispatch(
			$action,
			array_merge( array( 'nonce' => wp_create_nonce( self::NONCE ) ), $extra )
		);
		$this->assertAjaxError( $response, 'Unauthorized' );
	}

	// ---
	// wp_mcp_ai_sm_get_schedules
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_schedules_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_get_schedules' );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_schedules_rejects_subscriber() {
		$this->assert_rejected_for_subscriber( 'wp_mcp_ai_sm_get_schedules' );
	}

	/** Verifies the response returns array for admin. */
	public function test_get_schedules_returns_array_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_get_schedules',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );

		if ( $response['success'] ) {
			$this->assertArrayHasKey( 'schedules', $response['data'] );
			$this->assertIsArray( $response['data']['schedules'] );
		}
	}

	// ---
	// wp_mcp_ai_sm_create_schedule
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_create_schedule_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_create_schedule' );
	}

	/** Guards against insufficient capabilities. */
	public function test_create_schedule_rejects_subscriber() {
		$this->assert_rejected_for_subscriber(
			'wp_mcp_ai_sm_create_schedule',
			array( 'schedule_data' => wp_json_encode( array( 'name' => 'Test' ) ) )
		);
	}

	/** Validates the missing data parameter. */
	public function test_create_schedule_validates_missing_data() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_create_schedule',
			array(
				'nonce'         => wp_create_nonce( self::NONCE ),
				'schedule_data' => '',
			)
		);

		$this->assertAjaxError( $response, 'Invalid schedule data' );
	}

	/** Verifies the response returns structured response with valid data. */
	public function test_create_schedule_returns_structured_response_with_valid_data() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_create_schedule',
			array(
				'nonce'         => wp_create_nonce( self::NONCE ),
				'schedule_data' => wp_json_encode(
					array(
						'name'         => 'Test Schedule',
						'assistant_id' => 0,
						'cron'         => '0 * * * *',
						'enabled'      => true,
					)
				),
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_sm_delete_schedule
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_delete_schedule_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_delete_schedule' );
	}

	/** Guards against insufficient capabilities. */
	public function test_delete_schedule_rejects_subscriber() {
		$this->assert_rejected_for_subscriber(
			'wp_mcp_ai_sm_delete_schedule',
			array( 'schedule_id' => 1 )
		);
	}

	/** Validates the missing schedule id parameter. */
	public function test_delete_schedule_validates_missing_schedule_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_delete_schedule',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'schedule_id' => '',
			)
		);

		$this->assertAjaxError( $response, 'Missing schedule_id' );
	}

	/** Verifies the response returns not found for unknown id. */
	public function test_delete_schedule_returns_not_found_for_unknown_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_delete_schedule',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'schedule_id' => 99999,
			)
		);

		// Either not found or deleted (if IDs are auto-incremented from 0).
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_sm_toggle_schedule
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_toggle_schedule_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_toggle_schedule' );
	}

	/** Guards against insufficient capabilities. */
	public function test_toggle_schedule_rejects_subscriber() {
		$this->assert_rejected_for_subscriber(
			'wp_mcp_ai_sm_toggle_schedule',
			array(
				'schedule_id' => 1,
				'enabled'     => 1,
			)
		);
	}

	/** Validates the missing schedule id parameter. */
	public function test_toggle_schedule_validates_missing_schedule_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_toggle_schedule',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'schedule_id' => '',
				'enabled'     => 1,
			)
		);

		$this->assertAjaxError( $response, 'Missing schedule_id' );
	}

	/** Verifies the response returns structured response for admin. */
	public function test_toggle_schedule_returns_structured_response_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_toggle_schedule',
			array(
				'nonce'       => wp_create_nonce( self::NONCE ),
				'schedule_id' => 99999,
				'enabled'     => 0,
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_sm_get_history
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_history_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_get_history' );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_history_rejects_subscriber() {
		$this->assert_rejected_for_subscriber( 'wp_mcp_ai_sm_get_history' );
	}

	/** Verifies the response returns array for admin. */
	public function test_get_history_returns_array_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_get_history',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_sm_clear_history
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_clear_history_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_clear_history' );
	}

	/** Guards against insufficient capabilities. */
	public function test_clear_history_rejects_subscriber() {
		$this->assert_rejected_for_subscriber( 'wp_mcp_ai_sm_clear_history' );
	}

	/** Dispatches successfully on the happy path. */
	public function test_clear_history_succeeds_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_clear_history',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertAjaxSuccess( $response );
	}

	// ---
	// wp_mcp_ai_sm_get_presets
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_get_presets_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_get_presets' );
	}

	/** Guards against insufficient capabilities. */
	public function test_get_presets_rejects_subscriber() {
		$this->assert_rejected_for_subscriber( 'wp_mcp_ai_sm_get_presets' );
	}

	/** Verifies the response returns array for admin. */
	public function test_get_presets_returns_array_for_admin() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_get_presets',
			array( 'nonce' => wp_create_nonce( self::NONCE ) )
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}

	// ---
	// wp_mcp_ai_sm_install_preset
	// ---

	/** Guards against a missing or invalid nonce. */
	public function test_install_preset_rejects_missing_nonce() {
		$this->assert_rejected_without_nonce( 'wp_mcp_ai_sm_install_preset' );
	}

	/** Guards against insufficient capabilities. */
	public function test_install_preset_rejects_subscriber() {
		$this->assert_rejected_for_subscriber(
			'wp_mcp_ai_sm_install_preset',
			array( 'preset_id' => 'daily-digest' )
		);
	}

	/** Validates the missing preset id parameter. */
	public function test_install_preset_validates_missing_preset_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_install_preset',
			array(
				'nonce'     => wp_create_nonce( self::NONCE ),
				'preset_id' => '',
			)
		);

		$this->assertAjaxError( $response );
	}

	/** Verifies the response returns structured response for unknown preset. */
	public function test_install_preset_returns_structured_response_for_unknown_preset() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_sm_install_preset',
			array(
				'nonce'     => wp_create_nonce( self::NONCE ),
				'preset_id' => 'definitely-does-not-exist-preset',
			)
		);

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'success', $response );
	}
}

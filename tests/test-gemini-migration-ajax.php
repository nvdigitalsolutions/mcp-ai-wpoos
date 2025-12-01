<?php
/**
 * Test Gemini Cost Migration AJAX Handler
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Gemini_Migration_AJAX
 */
class Test_Gemini_Migration_AJAX extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize the database table.
		WP_MCP_AI_Token_Tracking_Database::maybe_create_or_update_table();

		// Load the AJAX handlers class.
		if ( ! class_exists( 'WP_MCP_AI_Admin_AJAX_Handlers' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php';
		}
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test data.
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );

		parent::tearDown();
	}

	/**
	 * Test that the AJAX handler is registered in the action map.
	 */
	public function test_ajax_handler_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs' ),
			'AJAX handler should be registered'
		);
	}

	/**
	 * Test migration preview with no records.
	 */
	public function test_migration_preview_no_records() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up POST data.
		$_POST['action_type'] = 'preview';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_migrate_gemini_costs' );

		// Capture output.
		ob_start();
		try {
			do_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_* calls wp_die().
		}
		$output = ob_get_clean();

		// Verify response.
		$response = json_decode( $output, true );
		$this->assertNotNull( $response, 'Response should be valid JSON' );
		$this->assertTrue( $response['success'], 'Response should be successful' );
		$this->assertEquals( 0, $response['data']['total_checked'], 'Should check 0 records' );
		$this->assertEquals( 0, $response['data']['records_updated'], 'Should update 0 records' );
		$this->assertTrue( $response['data']['dry_run'], 'Should be a dry run' );
	}

	/**
	 * Test migration preview with misattributed records.
	 */
	public function test_migration_preview_with_records() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create a misattributed Gemini tool record.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$admin_id,
			'generate_gemini_image',
			'openai', // WRONG - should be gemini.
			'gpt-4o-mini',
			1000,
			500,
			null,
			true
		);

		// Set up POST data.
		$_POST['action_type'] = 'preview';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_migrate_gemini_costs' );

		// Capture output.
		ob_start();
		try {
			do_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_* calls wp_die().
		}
		$output = ob_get_clean();

		// Verify response.
		$response = json_decode( $output, true );
		$this->assertNotNull( $response, 'Response should be valid JSON' );
		$this->assertTrue( $response['success'], 'Response should be successful' );
		$this->assertEquals( 1, $response['data']['total_checked'], 'Should check 1 record' );
		$this->assertEquals( 1, $response['data']['records_updated'], 'Should plan to update 1 record' );
		$this->assertTrue( $response['data']['dry_run'], 'Should be a dry run' );

		// Verify record was NOT actually updated (dry run).
		global $wpdb;
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT provider FROM {$table_name} WHERE tool = %s",
				'generate_gemini_image'
			)
		);

		$this->assertEquals( 'openai', $record->provider, 'Provider should still be openai (dry run)' );
	}

	/**
	 * Test actual migration execution.
	 */
	public function test_migration_execution() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create a misattributed Gemini tool record.
		WP_MCP_AI_Token_Tracking_Database::record_usage(
			$admin_id,
			'edit_gemini_image',
			'openai', // WRONG - should be gemini.
			'gpt-4o',
			2000,
			1000,
			null,
			true
		);

		// Set up POST data.
		$_POST['action_type'] = 'migrate';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_migrate_gemini_costs' );

		// Capture output.
		ob_start();
		try {
			do_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_* calls wp_die().
		}
		$output = ob_get_clean();

		// Verify response.
		$response = json_decode( $output, true );
		$this->assertNotNull( $response, 'Response should be valid JSON' );
		$this->assertTrue( $response['success'], 'Response should be successful' );
		$this->assertEquals( 1, $response['data']['records_updated'], 'Should update 1 record' );
		$this->assertFalse( $response['data']['dry_run'], 'Should not be a dry run' );

		// Verify record WAS actually updated.
		global $wpdb;
		$table_name = WP_MCP_AI_Token_Tracking_Database::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT provider, model FROM {$table_name} WHERE tool = %s",
				'edit_gemini_image'
			),
			ARRAY_A
		);

		$this->assertEquals( 'gemini', $record['provider'], 'Provider should be updated to gemini' );
		$this->assertStringContainsString( 'gemini', strtolower( $record['model'] ), 'Model should be a Gemini model' );
	}

	/**
	 * Test permission check - non-admin cannot migrate.
	 */
	public function test_migration_permission_check() {
		// Create subscriber user (non-admin).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Set up POST data.
		$_POST['action_type'] = 'migrate';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_migrate_gemini_costs' );

		// Capture output.
		ob_start();
		try {
			do_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_* calls wp_die().
		}
		$output = ob_get_clean();

		// Verify response.
		$response = json_decode( $output, true );
		$this->assertNotNull( $response, 'Response should be valid JSON' );
		$this->assertFalse( $response['success'], 'Response should fail for non-admin' );
		$this->assertStringContainsString( 'permission', strtolower( $response['data']['message'] ), 'Error should mention permission' );
	}

	/**
	 * Test invalid action type.
	 */
	public function test_migration_invalid_action_type() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up POST data with invalid action.
		$_POST['action_type'] = 'invalid_action';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_migrate_gemini_costs' );

		// Capture output.
		ob_start();
		try {
			do_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - wp_send_json_* calls wp_die().
		}
		$output = ob_get_clean();

		// Verify response.
		$response = json_decode( $output, true );
		$this->assertNotNull( $response, 'Response should be valid JSON' );
		$this->assertFalse( $response['success'], 'Response should fail for invalid action' );
		$this->assertStringContainsString( 'invalid', strtolower( $response['data']['message'] ), 'Error should mention invalid action' );
	}
}

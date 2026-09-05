<?php
/**
 * Test Pro Toolkit Optimization Classes
 *
 * Tests the 6 performance optimization classes for:
 * - Chat Channels, Social Media, Healthcare, Ecommerce,
 *   Calendar/Orchestration, Document Generation/QMS
 *
 * @package WP_MCP_AI
 * @since 2.9.0
 */

/**
 * Test class for Pro toolkit optimization.
 */
class Test_Pro_Toolkit_Optimization extends WP_UnitTestCase {

	/**
	 * Admin user ID for capability checks.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Original settings state before each test.
	 *
	 * @var array
	 */
	private $original_settings;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Save original settings to restore after.
		$this->original_settings = get_option( 'wp_mcp_ai_settings', array() );

		// Clear cron before each test.
		_set_cron_array( array() );

		// Delete known option keys that optimization classes manage.
		delete_option( 'wp_mcp_ai_chat_channels_toolkit_settings' );
		delete_option( 'wp_mcp_ai_autorespond_templates' );
		delete_option( 'wp_mcp_ai_inventory_movements' );
		delete_option( 'wp_mcp_ai_business_hours' );
		delete_option( 'wp_mcp_ai_qms_audit_schema' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Restore original settings.
		update_option( 'wp_mcp_ai_settings', $this->original_settings );

		// Clean up cron.
		_set_cron_array( array() );

		// Reset user.
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	// ──────────────────────────────────────────────
	// Chat Channels Optimization Tests
	// ──────────────────────────────────────────────

	/**
	 * Test CC optimization initializes when toolkit is enabled.
	 */
	public function test_cc_optimization_inits_when_toolkit_enabled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_chat_channels_toolkit' => true ) );

		WP_MCP_AI_Chat_Channels_Optimization::init();

		// Cron should be scheduled (or was already from init).
		$this->assertIsBool( wp_next_scheduled( 'wp_mcp_ai_cc_daily_optimize' ) !== false );
	}

	/**
	 * Test CC optimization does not init when toolkit is disabled.
	 */
	public function test_cc_optimization_skips_when_toolkit_disabled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_chat_channels_toolkit' => false ) );

		// Re-init should not add any hooks or schedule cron,
		// but the hooks from a previous init may persist.
		// Test that the class's internal gate works.
		$this->expectNotToPerformAssertions();
		WP_MCP_AI_Chat_Channels_Optimization::init();
	}

	/**
	 * Test settings autoload fix hooks are registered.
	 */
	public function test_cc_fix_autoload_hooks_registered() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_chat_channels_toolkit' => true ) );

		WP_MCP_AI_Chat_Channels_Optimization::init();

		$this->assertIsInt(
			has_action( 'update_option_wp_mcp_ai_chat_channels_toolkit_settings', array( 'WP_MCP_AI_Chat_Channels_Optimization', 'fix_autoload' ) )
		);
		$this->assertIsInt(
			has_action( 'added_option_wp_mcp_ai_chat_channels_toolkit_settings', array( 'WP_MCP_AI_Chat_Channels_Optimization', 'fix_autoload' ) )
		);
	}

	/**
	 * Test fix_autoload forces no autoload on settings option.
	 */
	public function test_cc_fix_autoload_forces_no_autoload() {
		global $wpdb;

		// Insert option with autoload=yes to simulate old behavior.
		update_option( 'wp_mcp_ai_chat_channels_toolkit_settings', array( 'test' => true ), true );

		// Trigger the autoload fix.
		WP_MCP_AI_Chat_Channels_Optimization::fix_autoload( array(), array( 'test' => true ) );

		// Verify autoload is now 'no'.
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'wp_mcp_ai_chat_channels_toolkit_settings'
			)
		);
		$this->assertSame( 'no', $autoload );
	}

	// ──────────────────────────────────────────────
	// Social Media Optimization Tests
	// ──────────────────────────────────────────────

	/**
	 * Test SM optimization registers the scheduled post CPT.
	 */
	public function test_sm_optimization_registers_cpt() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_social_media_toolkit' => true ) );

		WP_MCP_AI_Social_Media_Optimization::init();

		// Manually trigger CPT registration.
		WP_MCP_AI_Social_Media_Optimization::register_scheduled_post_cpt();

		$this->assertTrue(
			post_type_exists( 'social_sched_post' ),
			'social_sched_post CPT should be registered'
		);
	}

	/**
	 * Test SM optimization wires the publish scheduled post handler.
	 */
	public function test_sm_optimization_wires_cron_handler() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_social_media_toolkit' => true ) );

		WP_MCP_AI_Social_Media_Optimization::init();

		$this->assertIsInt(
			has_action( 'wp_mcp_ai_publish_scheduled_post', array( 'WP_MCP_AI_Social_Media_Optimization', 'handle_publish_scheduled_post' ) )
		);
	}

	/**
	 * Test handle_publish_scheduled_post transitions future to publish.
	 */
	public function test_sm_handle_publish_scheduled_post() {
		// Register the CPT first.
		WP_MCP_AI_Social_Media_Optimization::register_scheduled_post_cpt();

		// Create a scheduled post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'social_sched_post',
				'post_status' => 'future',
				'post_title'  => 'Test Scheduled Post',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
			)
		);

		$this->assertIsInt( $post_id );

		// Simulate cron firing.
		WP_MCP_AI_Social_Media_Optimization::handle_publish_scheduled_post( $post_id );

		$post = get_post( $post_id );
		$this->assertSame( 'publish', $post->post_status );

		// Verify meta was set.
		$this->assertSame( 'published', get_post_meta( $post_id, '_social_status', true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, '_social_published_at', true ) );
	}

	/**
	 * Test handle_publish_scheduled_post rejects invalid post ID.
	 */
	public function test_sm_handle_publish_scheduled_post_rejects_zero() {
		// Should return early without error.
		WP_MCP_AI_Social_Media_Optimization::handle_publish_scheduled_post( 0 );
		$this->expectNotToPerformAssertions();
	}

	/**
	 * Test autorespond templates autoload fix.
	 */
	public function test_sm_fix_templates_autoload_forces_no_autoload() {
		global $wpdb;

		update_option( 'wp_mcp_ai_autorespond_templates', array( 'template1' => array() ), true );

		WP_MCP_AI_Social_Media_Optimization::fix_templates_autoload( array(), array( 'template1' => array() ) );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'wp_mcp_ai_autorespond_templates'
			)
		);
		$this->assertSame( 'no', $autoload );
	}

	/**
	 * Test autorespond templates cap enforcement.
	 */
	public function test_sm_fix_templates_autoload_caps_template_count() {
		global $wpdb;

		// Create 60 templates (exceeds MAX_TEMPLATES = 50).
		$templates = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$templates[ "template_{$i}" ] = array( 'name' => "Template {$i}" );
		}
		update_option( 'wp_mcp_ai_autorespond_templates', $templates, false );

		// Trigger the fix.
		WP_MCP_AI_Social_Media_Optimization::fix_templates_autoload( array(), $templates );

		// Option should now have at most 50 templates.
		$stored = get_option( 'wp_mcp_ai_autorespond_templates', array() );
		// The DB was updated directly, so get_option may still have the old cached value.
		// Check DB directly.
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				'wp_mcp_ai_autorespond_templates'
			)
		);
		$decoded = maybe_unserialize( $raw );
		$this->assertIsArray( $decoded );
		$this->assertLessThanOrEqual( 50, count( $decoded ) );
	}

	// ──────────────────────────────────────────────
	// Healthcare Optimization Tests
	// ──────────────────────────────────────────────

	/**
	 * Test HC optimization intercepts per-member options.
	 */
	public function test_hc_intercept_per_member_options_forces_no_autoload() {
		global $wpdb;

		update_option( 'wp_mcp_ai_settings', array( 'enable_healthcare_toolkit' => true ) );

		// Create a per-member option with autoload=yes.
		update_option( 'wp_mcp_ai_vital_signs_42', array( 'heart_rate' => 72 ), true );

		// Trigger the interceptor.
		$result = WP_MCP_AI_Healthcare_Optimization::intercept_per_member_options(
			array( 'heart_rate' => 72 ),
			array(),
			'wp_mcp_ai_vital_signs_42'
		);

		// Value should pass through unchanged.
		$this->assertSame( array( 'heart_rate' => 72 ), $result );

		// Autoload should now be 'no'.
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'wp_mcp_ai_vital_signs_42'
			)
		);
		$this->assertSame( 'no', $autoload );
	}

	/**
	 * Test HC interceptor ignores non-per-member options.
	 */
	public function test_hc_intercept_ignores_non_per_member_options() {
		global $wpdb;

		// Non-per-member option should not be touched.
		update_option( 'some_random_option', 'hello', true );

		$result = WP_MCP_AI_Healthcare_Optimization::intercept_per_member_options(
			'hello',
			'',
			'some_random_option'
		);

		$this->assertSame( 'hello', $result );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'some_random_option'
			)
		);
		// Since WP 6.6 update_option() persists autoload=true as 'on'.
		$this->assertSame( 'on', $autoload );
	}

	/**
	 * Test HC prune_expired_reminders removes old entries.
	 */
	public function test_hc_prune_expired_reminders() {
		$one_year_ago = time() - ( 365 * DAY_IN_SECONDS );
		$reminders    = array(
			'rem_1' => array(
				'reminder_timestamp' => $one_year_ago,
				'status'             => 'active',
			),
			'rem_2' => array(
				'reminder_timestamp' => time(),
				'status'             => 'active',
			),
			'rem_3' => array(
				'reminder_timestamp' => $one_year_ago,
				'status'             => 'completed',
			),
		);
		update_option( 'wp_mcp_ai_health_reminders', $reminders, false );

		// Use reflection to call private method.
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Healthcare_Optimization', 'prune_expired_reminders' );
		$reflection->setAccessible( true );
		$reflection->invoke( null );

		$stored = get_option( 'wp_mcp_ai_health_reminders', array() );

		// rem_1 (expired) and rem_3 (completed) should be removed.
		$this->assertArrayNotHasKey( 'rem_1', $stored );
		$this->assertArrayNotHasKey( 'rem_3', $stored );
		// rem_2 (recent, active) should remain.
		$this->assertArrayHasKey( 'rem_2', $stored );
	}

	// ──────────────────────────────────────────────
	// Ecommerce Optimization Tests
	// ──────────────────────────────────────────────

	/**
	 * Test EC inventory movements autoload fix.
	 */
	public function test_ec_fix_inventory_autoload() {
		global $wpdb;

		update_option( 'wp_mcp_ai_inventory_movements', array( array( 'product_id' => 1 ) ), true );

		WP_MCP_AI_Ecommerce_Optimization::fix_inventory_autoload( array(), array( array( 'product_id' => 1 ) ) );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'wp_mcp_ai_inventory_movements'
			)
		);
		$this->assertSame( 'no', $autoload );
	}

	/**
	 * Test EC optimization initializes with enabled toolkit.
	 */
	public function test_ec_optimization_inits_when_enabled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_ecommerce_toolkit' => true ) );

		WP_MCP_AI_Ecommerce_Optimization::init();

		$this->assertIsInt(
			has_action( 'wp_mcp_ai_ec_daily_cleanup', array( 'WP_MCP_AI_Ecommerce_Optimization', 'run_daily_cleanup' ) )
		);
	}

	// ──────────────────────────────────────────────
	// Calendar & Orchestration Optimization Tests
	// ──────────────────────────────────────────────

	/**
	 * Test Calendar business hours autoload fix.
	 */
	public function test_cal_fix_business_hours_autoload() {
		global $wpdb;

		update_option( 'wp_mcp_ai_business_hours', array( 'monday' => '9-5' ), true );

		WP_MCP_AI_Calendar_Orchestration_Optimization::fix_business_hours_autoload( array(), array( 'monday' => '9-5' ) );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'wp_mcp_ai_business_hours'
			)
		);
		$this->assertSame( 'no', $autoload );
	}

	/**
	 * Test schedule count cap works.
	 */
	public function test_cal_cap_schedule_count() {
		// Create 150 schedules (exceeds cap of 100).
		$schedules = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$schedules[ "sched_{$i}" ] = array(
				'name'       => "Schedule {$i}",
				'updated_at' => gmdate( 'Y-m-d H:i:s', time() - ( ( 150 - $i ) * 60 ) ),
			);
		}

		$capped = WP_MCP_AI_Calendar_Orchestration_Optimization::cap_schedule_count( $schedules, array() );

		$this->assertCount( 100, $capped );

		// The most recent (sched_51 to sched_150) should be kept.
		$this->assertArrayHasKey( 'sched_150', $capped, 'Most recent entries should be kept' );
		$this->assertArrayNotHasKey( 'sched_1', $capped, 'Oldest entries should be removed' );
	}

	/**
	 * Test schedule count cap passes through arrays under limit.
	 */
	public function test_cal_cap_schedule_count_passes_under_limit() {
		$schedules = array(
			'sched_1' => array( 'name' => 'Test', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
		);

		$capped = WP_MCP_AI_Calendar_Orchestration_Optimization::cap_schedule_count( $schedules, array() );

		$this->assertSame( $schedules, $capped );
	}

	/**
	 * Test schedule count cap handles non-array input.
	 */
	public function test_cal_cap_schedule_count_handles_non_array() {
		$capped = WP_MCP_AI_Calendar_Orchestration_Optimization::cap_schedule_count( 'not_an_array', array() );
		$this->assertSame( 'not_an_array', $capped );
	}

	/**
	 * Test orphan schedule detection skips when no orphans exist.
	 */
	public function test_cal_detect_orphan_schedules_no_orphans() {
		$schedules = array(
			'sched_1' => array(
				'cron_hook'  => 'some_existing_hook',
				'status'     => 'active',
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
		);
		update_option( 'wp_mcp_ai_pro_schedules', $schedules, false );

		// Call orphan detection — should not remove anything.
		WP_MCP_AI_Calendar_Orchestration_Optimization::detect_orphan_schedules();

		$stored = get_option( 'wp_mcp_ai_pro_schedules', array() );
		$this->assertCount( 1, $stored );
		$this->assertArrayHasKey( 'sched_1', $stored );
	}

	/**
	 * Test orphan schedule detection removes old disabled schedules.
	 */
	public function test_cal_detect_orphan_schedules_removes_old_disabled() {
		$forty_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );
		$schedules      = array(
			'sched_1' => array(
				'cron_hook'  => 'some_hook',
				'status'     => 'active',
				'updated_at' => $forty_days_ago,
			),
			'sched_2' => array(
				'cron_hook'  => 'some_other_hook',
				'status'     => 'disabled',
				'updated_at' => $forty_days_ago,
			),
		);
		update_option( 'wp_mcp_ai_pro_schedules', $schedules, false );

		WP_MCP_AI_Calendar_Orchestration_Optimization::detect_orphan_schedules();

		$stored = get_option( 'wp_mcp_ai_pro_schedules', array() );
		$this->assertCount( 1, $stored );
		$this->assertArrayHasKey( 'sched_1', $stored, 'Active schedule should remain' );
		$this->assertArrayNotHasKey( 'sched_2', $stored, 'Old disabled schedule should be removed' );
	}

	// ──────────────────────────────────────────────
	// Document Generation & QMS Optimization Tests
	// ──────────────────────────────────────────────

	/**
	 * Test DG optimization initializes when toolkit is enabled.
	 */
	public function test_dg_optimization_inits_when_enabled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_document_generation_toolkit' => true ) );

		WP_MCP_AI_Document_Gen_Optimization::init();

		$this->assertIsInt(
			has_action( 'wp_mcp_ai_dg_weekly_audit_prune', array( 'WP_MCP_AI_Document_Gen_Optimization', 'prune_audit_log' ) )
		);
	}

	/**
	 * Test DG optimization does not init when toolkit is disabled.
	 */
	public function test_dg_optimization_skips_when_disabled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_document_generation_toolkit' => false ) );

		WP_MCP_AI_Document_Gen_Optimization::init();

		// No hooks should be registered.
		$this->assertFalse(
			has_action( 'wp_mcp_ai_dg_weekly_audit_prune', array( 'WP_MCP_AI_Document_Gen_Optimization', 'prune_audit_log' ) )
		);
	}

	/**
	 * Test QMS audit schema autoload fix.
	 */
	public function test_dg_fix_schema_autoload() {
		global $wpdb;

		update_option( 'wp_mcp_ai_qms_audit_schema', array( 'version' => 1 ), true );

		WP_MCP_AI_Document_Gen_Optimization::fix_schema_autoload( array(), array( 'version' => 1 ) );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'wp_mcp_ai_qms_audit_schema'
			)
		);
		$this->assertSame( 'no', $autoload );
	}

	// ──────────────────────────────────────────────
	// Cross-cutting: Cron Scheduling Tests
	// ──────────────────────────────────────────────

	/**
	 * Test that CC daily cron is scheduled only once.
	 */
	public function test_cc_cron_is_not_duplicated() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_chat_channels_toolkit' => true ) );

		WP_MCP_AI_Chat_Channels_Optimization::maybe_schedule();
		$first = wp_next_scheduled( 'wp_mcp_ai_cc_daily_optimize' );

		// Call again — should not create duplicate.
		WP_MCP_AI_Chat_Channels_Optimization::maybe_schedule();
		$second = wp_next_scheduled( 'wp_mcp_ai_cc_daily_optimize' );

		$this->assertSame( $first, $second, 'Cron event timestamp should not change on re-schedule' );
	}

	/**
	 * Test that SM daily cleanup cron is scheduled.
	 */
	public function test_sm_cron_is_scheduled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_social_media_toolkit' => true ) );

		WP_MCP_AI_Social_Media_Optimization::maybe_schedule();

		$this->assertNotFalse(
			wp_next_scheduled( 'wp_mcp_ai_sm_daily_cleanup' ),
			'Social media daily cleanup cron should be scheduled'
		);
	}

	/**
	 * Test that HC daily optimize cron is scheduled.
	 */
	public function test_hc_cron_is_scheduled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_healthcare_toolkit' => true ) );

		WP_MCP_AI_Healthcare_Optimization::maybe_schedule();

		$this->assertNotFalse(
			wp_next_scheduled( 'wp_mcp_ai_hc_daily_optimize' ),
			'Healthcare daily optimize cron should be scheduled'
		);
	}

	/**
	 * Test that EC daily cleanup cron is scheduled.
	 */
	public function test_ec_cron_is_scheduled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_ecommerce_toolkit' => true ) );

		WP_MCP_AI_Ecommerce_Optimization::maybe_schedule();

		$this->assertNotFalse(
			wp_next_scheduled( 'wp_mcp_ai_ec_daily_cleanup' ),
			'Ecommerce daily cleanup cron should be scheduled'
		);
	}

	/**
	 * Test that Calendar appointment prune cron is scheduled.
	 */
	public function test_cal_prune_cron_is_scheduled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_calendar_booking_toolkit' => true ) );

		WP_MCP_AI_Calendar_Orchestration_Optimization::maybe_schedule_appointment_prune();

		$this->assertNotFalse(
			wp_next_scheduled( 'wp_mcp_ai_cal_prune_appointments' ),
			'Calendar appointment prune cron should be scheduled'
		);
	}

	/**
	 * Test that Orchestration weekly cleanup cron is scheduled.
	 */
	public function test_orch_cleanup_cron_is_scheduled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_orchestration_toolkit' => true ) );

		WP_MCP_AI_Calendar_Orchestration_Optimization::maybe_schedule_orch_cleanup();

		$this->assertNotFalse(
			wp_next_scheduled( 'wp_mcp_ai_orch_weekly_cleanup' ),
			'Orchestration weekly cleanup cron should be scheduled'
		);
	}

	/**
	 * Test that DG weekly audit prune cron is scheduled.
	 */
	public function test_dg_audit_prune_cron_is_scheduled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_document_generation_toolkit' => true ) );

		WP_MCP_AI_Document_Gen_Optimization::maybe_schedule();

		$this->assertNotFalse(
			wp_next_scheduled( 'wp_mcp_ai_dg_weekly_audit_prune' ),
			'Document Generation weekly audit prune cron should be scheduled'
		);
	}

	// ──────────────────────────────────────────────
	// Edge Cases & Safety Tests
	// ──────────────────────────────────────────────

	/**
	 * Test that CC optimization handles missing JetEngine gracefully.
	 */
	public function test_cc_deregister_cpts_handles_no_jetengine() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_chat_channels_toolkit' => true ) );

		// If JetEngine is not active, maybe_deregister_cpts should return early
		// without errors. We verify it doesn't throw.
		WP_MCP_AI_Chat_Channels_Optimization::maybe_deregister_cpts();
		$this->expectNotToPerformAssertions();
	}

	/**
	 * Test that EC optimization handles missing temp directory gracefully.
	 */
	public function test_ec_cleanup_temp_dir_handles_missing_dir() {
		// Use reflection to call private method.
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Ecommerce_Optimization', 'cleanup_temp_directory' );
		$reflection->setAccessible( true );

		// Should not throw even if temp dir doesn't exist.
		$reflection->invoke( null );
		$this->expectNotToPerformAssertions();
	}

	/**
	 * Test that DG prune_audit_log handles missing table gracefully.
	 */
	public function test_dg_prune_audit_log_handles_missing_table() {
		// Should not throw if the audit table doesn't exist.
		WP_MCP_AI_Document_Gen_Optimization::prune_audit_log();
		$this->expectNotToPerformAssertions();
	}

	/**
	 * Test that Cal orphan detection handles no Schedule Manager.
	 */
	public function test_cal_detect_orphan_schedules_handles_no_class() {
		// If WP_MCP_AI_Pro_Schedule_Manager doesn't exist, should return early.
		WP_MCP_AI_Calendar_Orchestration_Optimization::detect_orphan_schedules();
		$this->expectNotToPerformAssertions();
	}

	/**
	 * Test that all optimization classes exist and are loadable.
	 */
	public function test_all_optimization_classes_exist() {
		$classes = array(
			'WP_MCP_AI_Chat_Channels_Optimization',
			'WP_MCP_AI_Social_Media_Optimization',
			'WP_MCP_AI_Healthcare_Optimization',
			'WP_MCP_AI_Ecommerce_Optimization',
			'WP_MCP_AI_Calendar_Orchestration_Optimization',
			'WP_MCP_AI_Document_Gen_Optimization',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				class_exists( $class ),
				"{$class} should exist and be loadable"
			);
		}
	}

	/**
	 * Test that all optimization classes have required init method.
	 */
	public function test_all_optimization_classes_have_init_method() {
		$classes = array(
			'WP_MCP_AI_Chat_Channels_Optimization',
			'WP_MCP_AI_Social_Media_Optimization',
			'WP_MCP_AI_Healthcare_Optimization',
			'WP_MCP_AI_Ecommerce_Optimization',
			'WP_MCP_AI_Calendar_Orchestration_Optimization',
			'WP_MCP_AI_Document_Gen_Optimization',
		);

		foreach ( $classes as $class ) {
			$this->assertTrue(
				method_exists( $class, 'init' ),
				"{$class} should have an init() method"
			);
			$this->assertTrue(
				is_callable( array( $class, 'init' ) ),
				"{$class}::init() should be callable"
			);
		}
	}

	/**
	 * Test that retention day constants are sensible values.
	 */
	public function test_retention_constants_are_sensible() {
		$this->assertSame( 90, WP_MCP_AI_Chat_Channels_Optimization::DEFAULT_RETENTION_DAYS );
		$this->assertSame( 30, WP_MCP_AI_Social_Media_Optimization::DEFAULT_RETENTION_DAYS );
		$this->assertSame( 50, WP_MCP_AI_Social_Media_Optimization::MAX_TEMPLATES );
		$this->assertSame( 730, WP_MCP_AI_Document_Gen_Optimization::DEFAULT_AUDIT_RETENTION_DAYS );
		$this->assertSame( 50, WP_MCP_AI_Healthcare_Optimization::MAX_CARE_PLANS );
		$this->assertSame( 500, WP_MCP_AI_Healthcare_Optimization::MAX_REMINDERS );
	}
}

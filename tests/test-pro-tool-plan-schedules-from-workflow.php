<?php
/**
 * Tests for WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow.
 *
 * Covers:
 * - Parsing of multi-line workflow_text
 * - Cadence inference (weekly, monthly, daily fallback)
 * - dry_run does not persist schedules
 * - Permission denial for non-admin users
 * - Error aggregation when items are invalid
 * - Category propagation as a tag
 *
 * @package WP_MCP_AI_Pro
 */

// Guard: only run if Pro addon is present.
if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-schedule-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-pro-tool-plan-schedules-from-workflow.php';

/**
 * Test suite for plan_schedules_from_workflow.
 */
class Test_Pro_Tool_Plan_Schedules_From_Workflow extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * The tool under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow
	 */
	private $tool;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->tool          = new WP_MCP_AI_Pro_Tool_Plan_Schedules_From_Workflow();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::HISTORY_OPTION );
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Tool metadata
	// ---------------------------------------------------------------------

	public function test_tool_slug_and_capability_flags() {
		$this->assertSame( 'plan_schedules_from_workflow', $this->tool->get_slug() );
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'write', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'bulk', $flags );
	}

	public function test_parameters_schema_is_valid_object() {
		$schema = $this->tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'workflow_items', $schema['properties'] );
		$this->assertArrayHasKey( 'workflow_text', $schema['properties'] );
		// Array properties must include items.
		$this->assertArrayHasKey( 'items', $schema['properties']['workflow_items'] );
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public function test_non_admin_is_denied() {
		$result = $this->tool->execute(
			array( 'workflow_text' => 'Respond to emails' ),
			array( 'user_id' => $this->subscriber_id )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'insufficient_permissions', $result->get_error_code() );
	}

	// ---------------------------------------------------------------------
	// Parsing
	// ---------------------------------------------------------------------

	public function test_workflow_text_parses_lines_into_items() {
		$text = "Respond to emails\nCheck WhatsApp messages on internal groups\nWeekly sales updates";

		$result = $this->tool->execute(
			array(
				'workflow_text' => $text,
				'category'      => 'admin',
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result['plan'] );
		$this->assertSame( 3, $result['summary']['total'] );
		$this->assertSame( 3, $result['summary']['planned'] );
		$this->assertTrue( $result['summary']['dry_run'] );

		// Category should appear in tags.
		foreach ( $result['plan'] as $entry ) {
			$this->assertContains( 'admin', $entry['tags'] );
			$this->assertContains( 'planned-from-workflow', $entry['tags'] );
		}
	}

	public function test_empty_input_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $result );
		$this->assertSame( 'no_workflow_items', $result->get_error_code() );
	}

	public function test_heading_lines_set_category_for_following_items() {
		$text = "## Marketing\nReview social posts\nPlan campaigns\n## Sales\nWeekly sales updates";

		$result = $this->tool->execute(
			array(
				'workflow_text' => $text,
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertCount( 3, $result['plan'] );
		$this->assertContains( 'Marketing', $result['plan'][0]['tags'] );
		$this->assertContains( 'Marketing', $result['plan'][1]['tags'] );
		$this->assertContains( 'Sales', $result['plan'][2]['tags'] );
	}

	public function test_list_bullets_are_stripped() {
		$text = "- Respond to emails\n* Check WhatsApp\n1. Follow up tasks";

		$result = $this->tool->execute(
			array(
				'workflow_text' => $text,
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertCount( 3, $result['plan'] );
		$this->assertSame( 'Respond to emails', $result['plan'][0]['name'] );
		$this->assertSame( 'Check WhatsApp', $result['plan'][1]['name'] );
		$this->assertSame( 'Follow up tasks', $result['plan'][2]['name'] );
	}

	// ---------------------------------------------------------------------
	// Cadence inference
	// ---------------------------------------------------------------------

	public function test_weekly_keyword_infers_weekly_cadence() {
		$result = $this->tool->execute(
			array(
				'workflow_text' => 'Weekly sales updates',
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 'weekly', $result['plan'][0]['schedule'] );
	}

	public function test_monthly_keyword_infers_monthly_cadence_when_registered() {
		// monthly may not be registered by core; only assert when it is.
		if ( ! array_key_exists( 'monthly', wp_get_schedules() ) ) {
			$this->markTestSkipped( 'monthly cadence not registered in this environment' );
		}
		$result = $this->tool->execute(
			array(
				'workflow_text' => 'Monthly budget review',
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 'monthly', $result['plan'][0]['schedule'] );
	}

	public function test_default_cadence_is_used_when_no_keyword() {
		$result = $this->tool->execute(
			array(
				'workflow_text'   => 'Send invoices',
				'default_cadence' => 'daily',
				'dry_run'         => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 'daily', $result['plan'][0]['schedule'] );
	}

	// ---------------------------------------------------------------------
	// Persistence
	// ---------------------------------------------------------------------

	public function test_dry_run_does_not_persist_schedules() {
		$before = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();

		$result = $this->tool->execute(
			array(
				'workflow_text' => "Respond to emails\nWeekly sales updates",
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$after = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$this->assertCount( count( $before ), $after );
		$this->assertCount( 0, $result['created'] );
		$this->assertCount( 2, $result['plan'] );
	}

	public function test_non_dry_run_persists_schedules() {
		$result = $this->tool->execute(
			array(
				'workflow_text' => "Respond to emails\nWeekly sales updates",
				'category'      => 'admin',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['created'] );

		$persisted = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$this->assertGreaterThanOrEqual( 2, count( $persisted ) );
	}

	public function test_priority_inferred_from_urgent_keyword() {
		$result = $this->tool->execute(
			array(
				'workflow_text' => 'Urgent: respond to client escalations',
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 1, $result['plan'][0]['priority'] );
	}

	public function test_explicit_priority_overrides_inference() {
		$result = $this->tool->execute(
			array(
				'workflow_items' => array(
					array(
						'title'    => 'Urgent: respond to escalations',
						'priority' => 7,
					),
				),
				'dry_run'        => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 7, $result['plan'][0]['priority'] );
	}

	public function test_default_assistant_uses_assistant_run_when_published_assistant_exists() {
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		$result = $this->tool->execute(
			array(
				'workflow_text'        => 'Respond to emails',
				'default_assistant_id' => $assistant_id,
				'dry_run'              => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 'assistant_run', $result['plan'][0]['schedule_type'] );
		$this->assertSame( $assistant_id, $result['plan'][0]['assistant_config']['assistant_id'] );
	}

	public function test_no_assistant_falls_back_to_task_hook() {
		$result = $this->tool->execute(
			array(
				'workflow_text' => 'Respond to emails',
				'dry_run'       => true,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 'task', $result['plan'][0]['schedule_type'] );
		$this->assertSame( 'wp_mcp_ai_workflow_reminder', $result['plan'][0]['hook'] );
	}
}

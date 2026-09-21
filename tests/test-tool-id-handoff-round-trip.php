<?php
/**
 * L2 deterministic round-trip suite for ID-handoff contracts.
 *
 * Drives each manifest family flagged `round_trip => true` through the
 * exact multi-step flow that fails in real agentic workflows: create the
 * record, take the identifier from the success envelope, feed it to the
 * consuming tools, and assert the identifier survives the handoff.
 *
 * No LLM is involved — this suite is the deterministic regression guard
 * for the failure mode where the model loses the record ID between calls.
 *
 * Part of the P3 data-contract rollout
 * (docs/project/proposals/P3-data-contract-rollout-plan-2026-09.md).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @group tools
 * @group unix-theory
 */
class Test_Tool_Id_Handoff_Round_Trip extends WP_UnitTestCase {

	use WP_MCP_AI_Tool_Id_Handoff_Test_Helper;

	/**
	 * Administrator context for tool execution.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Reset shared cron state and act as an administrator.
	 */
	public function setUp(): void {
		parent::setUp();

		// Cron state is shared; start every test from a clean slate.
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Clean up cron state and reset the current user.
	 */
	public function tearDown(): void {
		_set_cron_array( array() );
		delete_option( WP_MCP_AI_Cron_Manager::OPTION_NAME );
		delete_option( 'wp_mcp_ai_pro_schedules' );
		delete_option( 'wp_mcp_ai_pro_schedule_history' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Data provider: every manifest family flagged for round-trip testing.
	 *
	 * @return array[] List of ( family_key ) cases.
	 */
	public static function manifest_round_trip_families() {
		$manifest = require __DIR__ . '/fixtures/tool-contract-manifest.php';

		$cases = array();
		foreach ( $manifest['families'] as $key => $family ) {
			if ( ! empty( $family['round_trip'] ) ) {
				$cases[ $key ] = array( $key );
			}
		}

		return $cases;
	}

	/**
	 * Drive a full create → fetch → update → delete round trip per family.
	 *
	 * @dataProvider manifest_round_trip_families
	 *
	 * @param string $family_key The identifier key under test.
	 */
	public function test_id_handoff_round_trip( $family_key ) {
		$context = array( 'user_id' => $this->admin_id );

		// Pro-scoped families run whenever the Pro source tree is present
		// (drivers require their own tool files); registry registration of
		// Pro tools is settings-gated and varies per environment.
		$manifest = require __DIR__ . '/fixtures/tool-contract-manifest.php';
		$family   = $manifest['families'][ $family_key ];
		if ( ! empty( $family['scope'] ) && 'pro' === $family['scope'] ) {
			$probe_files = array(
				'schedule_id' => 'orchestration/class-wp-mcp-ai-pro-tool-create-pro-schedule.php',
				'item_id'     => 'infrastructure/class-wp-mcp-ai-pro-tool-cpt.php',
				'record_id'   => 'healthcare/wellness/medical-records/class-wp-mcp-ai-tool-create-medical-record.php',
			);
			$probe_path  = isset( $probe_files[ $family_key ] )
				? dirname( __DIR__ ) . '/addons/pro/includes/tools/' . $probe_files[ $family_key ]
				: '';
			if ( '' === $probe_path || ! file_exists( $probe_path ) ) {
				$this->markTestSkipped( 'Family ' . $family_key . ' requires the Pro source tree, which is unavailable in this environment.' );
			}
		}

		switch ( $family_key ) {
			case 'job_id':
				$this->run_cron_round_trip( $context );
				break;
			case 'post_id':
				$this->run_post_round_trip( $context );
				break;
			case 'term_id':
				$this->run_term_round_trip( $context );
				break;
			case 'assistant_id':
				$this->run_assistant_round_trip( $context );
				break;
			case 'schedule_id':
				$this->run_pro_schedule_round_trip( $context );
				break;
			case 'item_id':
				$this->run_toolkit_cpt_round_trip( $context );
				break;
			case 'record_id':
				$this->run_medical_record_round_trip( $context );
				break;
			default:
				$this->fail( 'No round-trip driver for family: ' . $family_key );
		}
	}

	/**
	 * Cron family: create_cron_job → get_cron_job → delete_cron_job.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_cron_round_trip( $context ) {
		$create_tool = new WP_MCP_AI_Tool_Create_Cron_Job();
		$created     = $create_tool->execute(
			array(
				'hook'      => 'wp_mcp_ai_handoff_test',
				'timestamp' => time() + HOUR_IN_SECONDS,
			),
			$context
		);
		$job_id      = $this->assert_produces_key( $created, 'job_id', 'create_cron_job' );

		$get_tool = new WP_MCP_AI_Tool_Get_Cron_Job();
		$fetched  = $get_tool->execute( array( 'job_id' => $job_id ), $context );
		$this->assert_id_round_trip( $fetched, 'job_id', $job_id, 'get_cron_job' );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Cron_Job();
		$deleted     = $delete_tool->execute( array( 'job_id' => $job_id ), $context );
		$this->assert_id_round_trip( $deleted, 'job_id', $job_id, 'delete_cron_job' );

		// Negative path: a fabricated ID must fail cleanly, not silently.
		$missing = $get_tool->execute( array( 'job_id' => 'does-not-exist' ), $context );
		$this->assert_id_not_found( $missing, 'get_cron_job', 'job_id' );
	}

	/**
	 * Post family: create_post → get_post → save_post (update) → delete_post.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_post_round_trip( $context ) {
		$create_tool = new WP_MCP_AI_Tool_Create_Post();
		$created     = $create_tool->execute(
			array(
				'title'   => 'ID handoff round-trip post',
				'content' => 'Round-trip content.',
				'status'  => 'draft',
			),
			$context
		);
		$post_id     = $this->assert_produces_key( $created, 'post_id', 'create_post' );

		$get_tool = new WP_MCP_AI_Tool_Get_Post();
		$fetched  = $get_tool->execute( array( 'post_id' => $post_id ), $context );
		$this->assert_id_round_trip( $fetched, 'post_id', $post_id, 'get_post' );
		$this->assertSame( 'ID handoff round-trip post', $fetched['title'] );

		$save_tool = new WP_MCP_AI_Tool_Save_Post();
		$saved     = $save_tool->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'ID handoff round-trip post (updated)',
				'content' => 'Updated round-trip content.',
			),
			$context
		);
		$this->assert_id_round_trip( $saved, 'post_id', $post_id, 'save_post' );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Post();
		$deleted     = $delete_tool->execute(
			array(
				'post_id'      => $post_id,
				'force_delete' => true,
			),
			$context
		);
		$this->assertNotWPError( $deleted, 'delete_post should succeed for the produced post_id.' );

		// Negative path: a fabricated ID must fail cleanly, not silently.
		$missing = $get_tool->execute( array( 'post_id' => PHP_INT_MAX - 1 ), $context );
		$this->assert_id_not_found( $missing, 'get_post', 'post_id' );
	}

	/**
	 * Term family: create_term → update_term, plus a clean negative path.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_term_round_trip( $context ) {
		$create_tool = new WP_MCP_AI_Tool_Create_Term();
		$created     = $create_tool->execute(
			array(
				'name'     => 'ID handoff category',
				'taxonomy' => 'category',
			),
			$context
		);
		$term_id     = $this->assert_produces_key( $created, 'term_id', 'create_term' );

		$update_tool = new WP_MCP_AI_Tool_Update_Term();
		$updated     = $update_tool->execute(
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'category',
				'name'     => 'ID handoff category (updated)',
			),
			$context
		);
		$this->assert_id_round_trip( $updated, 'term_id', $term_id, 'update_term' );

		// Negative path: a fabricated ID must fail cleanly.
		$missing = $update_tool->execute(
			array(
				'term_id'  => PHP_INT_MAX - 1,
				'taxonomy' => 'category',
				'name'     => 'nope',
			),
			$context
		);
		$this->assert_id_not_found( $missing, 'update_term', 'term_id' );

		wp_delete_term( $term_id, 'category' );
	}

	/**
	 * Assistant family: create_assistant → duplicate_assistant.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_assistant_round_trip( $context ) {
		$create_tool  = new WP_MCP_AI_Tool_Create_Assistant();
		$created      = $create_tool->execute(
			array( 'title' => 'ID handoff assistant' ),
			$context
		);
		$assistant_id = $this->assert_produces_key( $created, 'assistant_id', 'create_assistant' );

		$duplicate_tool = new WP_MCP_AI_Tool_Duplicate_Assistant();
		$duplicated     = $duplicate_tool->execute(
			array( 'assistant_id' => $assistant_id ),
			$context
		);
		$this->assertNotWPError( $duplicated, 'duplicate_assistant should succeed for the produced assistant_id.' );
		$this->assertIsArray( $duplicated );
		$this->assertArrayHasKey( 'assistant_id', $duplicated, 'duplicate_assistant must return the new assistant_id.' );
		$this->assertNotSame(
			$assistant_id,
			$duplicated['assistant_id'],
			'duplicate_assistant must produce a NEW assistant_id.'
		);

		// Negative path: a fabricated ID must fail cleanly.
		$missing = $duplicate_tool->execute(
			array( 'assistant_id' => PHP_INT_MAX - 1 ),
			$context
		);
		$this->assert_id_not_found( $missing, 'duplicate_assistant', 'assistant_id' );

		wp_delete_post( $assistant_id, true );
		wp_delete_post( $duplicated['assistant_id'], true );
	}

	/**
	 * Pro Schedule Manager family: create → update → latest result → delete.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_pro_schedule_round_trip( $context ) {
		$orchestration_dir = dirname( __DIR__ ) . '/addons/pro/includes/tools/orchestration/';
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Create_Pro_Schedule' ) ) {
			require_once $orchestration_dir . 'class-wp-mcp-ai-pro-tool-create-pro-schedule.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Update_Pro_Schedule' ) ) {
			require_once $orchestration_dir . 'class-wp-mcp-ai-pro-tool-update-pro-schedule.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Get_Schedule_Latest_Result' ) ) {
			require_once $orchestration_dir . 'class-wp-mcp-ai-pro-tool-get-schedule-latest-result.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule' ) ) {
			require_once $orchestration_dir . 'class-wp-mcp-ai-pro-tool-delete-pro-schedule.php';
		}

		$create_tool = new WP_MCP_AI_Pro_Tool_Create_Pro_Schedule();
		$created     = $create_tool->execute(
			array(
				'name'          => 'ID handoff schedule',
				'schedule_type' => 'task',
				'hook'          => 'wp_mcp_ai_handoff_test_hook',
				'schedule'      => 'daily',
			),
			$context
		);
		$schedule_id = $this->assert_produces_key( $created, 'schedule_id', 'create_pro_schedule' );

		$update_tool = new WP_MCP_AI_Pro_Tool_Update_Pro_Schedule();
		$updated     = $update_tool->execute(
			array(
				'schedule_id' => $schedule_id,
				'name'        => 'ID handoff schedule (updated)',
			),
			$context
		);
		$this->assert_id_round_trip( $updated, 'schedule_id', $schedule_id, 'update_pro_schedule' );

		$result_tool = new WP_MCP_AI_Pro_Tool_Get_Schedule_Latest_Result();
		$latest      = $result_tool->execute( array( 'schedule_id' => $schedule_id ), $context );
		$this->assert_id_round_trip( $latest, 'schedule_id', $schedule_id, 'get_schedule_latest_result' );

		$delete_tool = new WP_MCP_AI_Pro_Tool_Delete_Pro_Schedule();
		$deleted     = $delete_tool->execute( array( 'schedule_id' => $schedule_id ), $context );
		$this->assertNotWPError( $deleted, 'delete_pro_schedule should succeed for the produced schedule_id.' );

		// Negative path: a fabricated ID must fail cleanly.
		$missing = $update_tool->execute(
			array(
				'schedule_id' => 'does-not-exist',
				'name'        => 'nope',
			),
			$context
		);
		$this->assert_id_not_found( $missing, 'update_pro_schedule', 'schedule_id' );
	}

	/**
	 * Toolkit CPT family: create_item → get_item → update_item → delete_item
	 * on a locally registered toolkit post type.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_toolkit_cpt_round_trip( $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/tools/infrastructure/class-wp-mcp-ai-pro-tool-cpt.php';
		}

		if ( ! post_type_exists( 'mcp_ai_project' ) ) {
			register_post_type(
				'mcp_ai_project',
				array(
					'public'   => false,
					'supports' => array( 'title', 'editor', 'custom-fields' ),
				)
			);
		}

		$tool = new WP_MCP_AI_Pro_Tool_CPT();

		$created = $tool->execute(
			array(
				'action'    => 'create_item',
				'post_type' => 'mcp_ai_project',
				'fields'    => array( 'title' => 'ID handoff project' ),
			),
			$context
		);
		$item_id = $this->assert_produces_key( $created, 'item_id', 'toolkit_cpt' );

		$fetched = $tool->execute(
			array(
				'action'    => 'get_item',
				'post_type' => 'mcp_ai_project',
				'item_id'   => $item_id,
			),
			$context
		);
		$this->assertNotWPError( $fetched, 'toolkit_cpt get_item should succeed for the produced item_id.' );
		$this->assertSame( $item_id, $fetched['id'], 'toolkit_cpt get_item should echo the item id it was given.' );

		$updated = $tool->execute(
			array(
				'action'    => 'update_item',
				'post_type' => 'mcp_ai_project',
				'item_id'   => $item_id,
				'fields'    => array( 'title' => 'ID handoff project (updated)' ),
			),
			$context
		);
		$this->assert_id_round_trip( $updated, 'item_id', $item_id, 'toolkit_cpt update_item' );

		$deleted = $tool->execute(
			array(
				'action'    => 'delete_item',
				'post_type' => 'mcp_ai_project',
				'item_id'   => $item_id,
			),
			$context
		);
		$this->assertNotWPError( $deleted, 'toolkit_cpt delete_item should succeed for the produced item_id.' );

		// Negative path: a fabricated ID must fail cleanly.
		$missing = $tool->execute(
			array(
				'action'    => 'get_item',
				'post_type' => 'mcp_ai_project',
				'item_id'   => PHP_INT_MAX - 1,
			),
			$context
		);
		$this->assert_id_not_found( $missing, 'toolkit_cpt', 'item_id' );
	}

	/**
	 * Medical record family: create_medical_record → get_medical_record →
	 * update_medical_record → delete_medical_record.
	 *
	 * @param array $context Tool execution context.
	 */
	private function run_medical_record_round_trip( $context ) {
		update_option( 'wp_mcp_ai_settings', array( 'enable_health_wellness_management' => true ) );

		if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/class-wp-mcp-ai-health-wellness-cpt.php';
		}
		WP_MCP_AI_Health_Wellness_CPT::register_post_types();

		$tool_dir = dirname( __DIR__ ) . '/addons/pro/includes/tools/healthcare/wellness/medical-records/';
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Medical_Record' ) ) {
			require_once $tool_dir . 'class-wp-mcp-ai-tool-create-medical-record.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Get_Medical_Record' ) ) {
			require_once $tool_dir . 'class-wp-mcp-ai-tool-get-medical-record.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Update_Medical_Record' ) ) {
			require_once $tool_dir . 'class-wp-mcp-ai-tool-update-medical-record.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delete_Medical_Record' ) ) {
			require_once $tool_dir . 'class-wp-mcp-ai-tool-delete-medical-record.php';
		}

		$member_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_member' ) );

		$create_tool = new WP_MCP_AI_Tool_Create_Medical_Record();
		$created     = $create_tool->execute(
			array(
				'member_id'   => $member_id,
				'record_type' => 'diagnosis',
				'title'       => 'ID handoff record',
			),
			$context
		);
		$record_id   = $this->assert_produces_key( $created, 'record_id', 'create_medical_record' );

		$get_tool = new WP_MCP_AI_Tool_Get_Medical_Record();
		$fetched  = $get_tool->execute( array( 'record_id' => $record_id ), $context );
		$this->assertNotWPError( $fetched, 'get_medical_record should succeed for the produced record_id.' );
		$this->assertSame( $record_id, $fetched['record']['id'], 'get_medical_record should echo the record id it was given.' );

		$update_tool = new WP_MCP_AI_Tool_Update_Medical_Record();
		$updated     = $update_tool->execute(
			array(
				'record_id' => $record_id,
				'title'     => 'ID handoff record (updated)',
			),
			$context
		);
		$this->assertNotWPError( $updated, 'update_medical_record should succeed for the produced record_id.' );
		$this->assertSame( $record_id, $updated['record']['id'], 'update_medical_record should echo the record id it was given.' );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Medical_Record();
		$deleted     = $delete_tool->execute( array( 'record_id' => $record_id ), $context );
		$this->assertNotWPError( $deleted, 'delete_medical_record should succeed for the produced record_id.' );

		// Negative path: a fabricated ID must fail cleanly.
		$missing = $update_tool->execute( array( 'record_id' => PHP_INT_MAX - 1 ), $context );
		$this->assert_id_not_found( $missing, 'update_medical_record', 'record_id' );

		wp_delete_post( $member_id, true );
	}
}

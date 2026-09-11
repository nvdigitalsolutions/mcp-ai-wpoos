<?php
/**
 * Characterization tests for the Wave F2 CRM activities tool batch — the
 * ported activities CRUD tools (`create/list/get/complete/snooze` CRM
 * activity).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surface and the
 *   CPT-direct execute() flows are asserted against the base Activity CPT.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/activities/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM activities tool batch tests.
 */
class Test_Crm_Tools_Activities extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		parent::tearDown();
	}

	/**
	 * Enable the CRM toolkit flag in the shared settings option.
	 *
	 * @return void
	 */
	private function enable_crm_toolkit(): void {
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * Register the CRM Activity CPT for the active matrix.
	 *
	 * @return void
	 */
	private function register_activity_cpt(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-crm-activity-cpt.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-crm-activity-cpt.php';
		}
		WP_MCP_AI_CRM_Activity_CPT::register_post_type();
	}

	/**
	 * The five tool surfaces must be byte-identical.
	 */
	public function test_activities_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_CRM_Activity'   => 'create_crm_activity',
			'WP_MCP_AI_Tool_List_CRM_Activities'   => 'list_crm_activities',
			'WP_MCP_AI_Tool_Get_CRM_Activity'      => 'get_crm_activity',
			'WP_MCP_AI_Tool_Complete_CRM_Activity' => 'complete_crm_activity',
			'WP_MCP_AI_Tool_Snooze_CRM_Activity'   => 'snooze_crm_activity',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$this->assertSame(
			array( 'title' ),
			( new WP_MCP_AI_Tool_Create_CRM_Activity() )->get_parameters_schema()['required']
		);
	}

	/**
	 * Create CRM Activity must persist an activity post and return the
	 * canonical envelope.
	 */
	public function test_create_activity_execute(): void {
		$this->enable_crm_toolkit();
		$this->register_activity_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Create_CRM_Activity() )->execute(
			array(
				'title'         => 'Call the prospect',
				'activity_type' => 'call',
				'related_type'  => 'lead',
				'related_id'    => 1,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['activity_id'] );
		$this->assertGreaterThan( 0, $result['activity_id'] );
		$this->assertSame( 'mcp_ai_crm_activity', get_post_type( $result['activity_id'] ) );

		// Byte-identical leniency: the title requirement is schema-level only;
		// execute() does not enforce it and falls back to an empty title.
		$untitled = ( new WP_MCP_AI_Tool_Create_CRM_Activity() )->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertIsArray( $untitled );
		$this->assertTrue( $untitled['success'] );
		$this->assertSame( '', get_the_title( $untitled['activity_id'] ) );
	}

	/**
	 * The complete/snooze flows must stamp the byte-identical meta fields.
	 */
	public function test_complete_and_snooze_activity(): void {
		$this->enable_crm_toolkit();
		$this->register_activity_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$created = ( new WP_MCP_AI_Tool_Create_CRM_Activity() )->execute(
			array(
				'title'         => 'Follow up email',
				'activity_type' => 'email',
			),
			$context
		);
		$this->assertTrue( $created['success'] );
		$activity_id = $created['activity_id'];

		// Complete.
		$completed = ( new WP_MCP_AI_Tool_Complete_CRM_Activity() )->execute(
			array(
				'activity_id' => $activity_id,
				'outcome'     => 'Replied positively',
			),
			$context
		);
		$this->assertTrue( $completed['success'] );
		$this->assertSame( '1', get_post_meta( $activity_id, 'completed', true ) );
		$this->assertSame( 'Replied positively', get_post_meta( $activity_id, 'outcome', true ) );
		$this->assertNotEmpty( get_post_meta( $activity_id, 'completed_at', true ) );

		// Snooze.
		$snoozed = ( new WP_MCP_AI_Tool_Snooze_CRM_Activity() )->execute(
			array(
				'activity_id'  => $activity_id,
				'new_due_date' => '2026-10-01',
			),
			$context
		);
		$this->assertTrue( $snoozed['success'] );
		$this->assertSame( '2026-10-01', $snoozed['new_due_date'] );
		$this->assertSame( 1, $snoozed['snooze_count'] );
		$this->assertSame( '2026-10-01', get_post_meta( $activity_id, 'due_date', true ) );

		// Unknown activity IDs must be rejected.
		$ghost = ( new WP_MCP_AI_Tool_Complete_CRM_Activity() )->execute(
			array( 'activity_id' => 999999 ),
			$context
		);
		$this->assertInstanceOf( 'WP_Error', $ghost );
	}

	/**
	 * The list/get flows must read activities back from the CPT.
	 */
	public function test_list_and_get_activities(): void {
		$this->enable_crm_toolkit();
		$this->register_activity_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$created = ( new WP_MCP_AI_Tool_Create_CRM_Activity() )->execute(
			array(
				'title'         => 'Discovery call',
				'activity_type' => 'meeting',
			),
			$context
		);
		$this->assertTrue( $created['success'] );

		$listed = ( new WP_MCP_AI_Tool_List_CRM_Activities() )->execute( array(), $context );
		$this->assertTrue( $listed['success'] );

		$fetched = ( new WP_MCP_AI_Tool_Get_CRM_Activity() )->execute(
			array( 'activity_id' => $created['activity_id'] ),
			$context
		);
		$this->assertTrue( $fetched['success'] );
	}

	/**
	 * Standalone only: the init's tool filter must carry the activities
	 * batch.
	 */
	public function test_activities_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_CRM_Activity', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_List_CRM_Activities', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_CRM_Activity', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Complete_CRM_Activity', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Snooze_CRM_Activity', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/activities/class-wp-mcp-ai-tool-create-crm-activity.php',
			$tools['WP_MCP_AI_Tool_Create_CRM_Activity']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the
	 * activities batch into both registries.
	 */
	public function test_activities_ecosystem_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['create_crm_activity'] ?? null );
		$this->assertNotNull( $parent->all()['complete_crm_activity'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_crm_activity' ) );
		$this->assertTrue( $core_tools->has( 'list_crm_activities' ) );
		$this->assertTrue( $core_tools->has( 'complete_crm_activity' ) );
		$this->assertTrue( $core_tools->has( 'snooze_crm_activity' ) );
	}
}

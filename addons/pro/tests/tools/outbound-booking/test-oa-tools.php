<?php
/**
 * Test Outbound Booking MCP tools.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Tool registration and envelope tests.
 */
class Test_OA_Tools extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_outbound_booking_toolkit' => 1,
				'enable_crm_toolkit'              => 1,
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/init.php';

		foreach ( array( 'mcp_ai_oa_angle', 'mcp_ai_lead' ) as $pt ) {
			if ( ! post_type_exists( $pt ) ) {
				register_post_type( $pt, array( 'public' => false, 'label' => $pt ) );
			}
		}

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * The registration filter adds the three outbound tools.
	 */
	public function test_tools_are_registered_via_filter() {
		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Outbound_Pipeline', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Outbound_Import_Leads', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Outbound_Manage_Angle', $tools );
	}

	/**
	 * The pipeline tool returns the canonical envelope with stats.
	 */
	public function test_pipeline_tool_executes() {
		$tool = new WP_MCP_AI_Tool_Outbound_Pipeline();
		$this->assertSame( 'outbound_get_pipeline_stats', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
		$this->assertTrue( $tool->requires_base_pro() );

		$result = $tool->execute( array(), array( 'user_id' => $this->admin_id ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'booked', $result['stats'] );
	}

	/**
	 * Tools deny when the feature flags are off.
	 */
	public function test_tools_unavailable_when_flags_off() {
		update_option( 'wp_mcp_ai_settings', array() );
		$tool = new WP_MCP_AI_Tool_Outbound_Pipeline();
		$this->assertFalse( WP_MCP_AI_Tool_Outbound_Pipeline::is_available() );
		$result = $tool->execute( array(), array( 'user_id' => $this->admin_id ) );
		$this->assertWPError( $result );
		$this->assertSame( 'unavailable', $result->get_error_code() );
	}

	/**
	 * Tools deny execution for users without the required capability.
	 */
	public function test_tools_deny_underprivileged_users() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$tool       = new WP_MCP_AI_Tool_Outbound_Pipeline();
		$result     = $tool->execute( array(), array( 'user_id' => $subscriber ) );
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * The manage-angle tool creates and updates entries.
	 */
	public function test_manage_angle_tool_crud() {
		$tool = new WP_MCP_AI_Tool_Outbound_Manage_Angle();
		$create = $tool->execute(
			array(
				'name'        => 'The Teardown Angle',
				'kind'        => 'angle',
				'channel'     => 'email',
				'subject'     => 'Quick question',
				'first_line'  => 'Hi {{first_name}}',
				'body'        => 'Body copy.',
				'test_status' => 'challenger',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertNotWPError( $create );
		$this->assertTrue( $create['success'] );
		$this->assertNotEmpty( $create['angle_id'] );

		$update = $tool->execute(
			array(
				'angle_id' => $create['angle_id'],
				'name'     => 'Updated Angle',
				'body'     => 'Updated copy.',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertNotWPError( $update );
		$this->assertSame( $create['angle_id'], $update['angle_id'] );
		$this->assertSame( 'Updated Angle', get_the_title( $update['angle_id'] ) );
		$this->assertSame( 'Updated copy.', get_post_meta( $update['angle_id'], '_oa_angle_body', true ) );
	}

	/**
	 * The import tool creates deduped leads.
	 */
	public function test_import_tool_creates_leads() {
		$tool = new WP_MCP_AI_Tool_Outbound_Import_Leads();
		$result = $tool->execute(
			array(
				'rows' => array(
					array(
						'email'      => 'jane@example.test',
						'first_name' => 'Jane',
						'last_name'  => 'Smith',
						'company'    => 'Acme',
						'job_title'  => 'CEO',
					),
					array(
						'email'      => 'jane@example.test',
						'first_name' => 'Jane',
						'last_name'  => 'Smith',
						'company'    => 'Acme',
						'job_title'  => 'CEO',
					),
					array(
						'email'      => 'not-an-email',
						'first_name' => 'Bad',
					),
				),
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['skipped_duplicate'] );
		$this->assertSame( 1, $result['skipped_invalid'] );
	}
}

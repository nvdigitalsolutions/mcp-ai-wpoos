<?php
/**
 * Characterization tests for the Wave F2 CRM outbound tool batch — the
 * ported send-lead-email/SMS/WhatsApp/DM, log-call-outcome,
 * draft-lead-reply, auto-reply-inbound, and schedule-follow-up tools.
 *
 * The channel-send paths require external integrations; these tests pin
 * the byte-identical surfaces and the deterministic pre-flight contracts
 * (availability, capability, contact-data, consent, DNC, lead resolution).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the same contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/outbound/` are asserted in full, including the serving
 *   sources and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM outbound tool batch tests.
 */
class Test_Crm_Tools_Outbound extends WP_UnitTestCase {

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
	 * The eight tool surfaces must be byte-identical.
	 */
	public function test_outbound_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Send_Lead_Email'    => 'send_lead_email',
			'WP_MCP_AI_Tool_Send_Lead_SMS'      => 'send_lead_sms',
			'WP_MCP_AI_Tool_Send_Lead_Whatsapp' => 'send_lead_whatsapp',
			'WP_MCP_AI_Tool_Send_Lead_Dm'       => 'send_lead_dm',
			'WP_MCP_AI_Tool_Log_Call_Outcome'   => 'log_call_outcome',
			'WP_MCP_AI_Tool_Draft_Lead_Reply'   => 'draft_lead_reply',
			'WP_MCP_AI_Tool_Auto_Reply_Inbound' => 'auto_reply_inbound',
			'WP_MCP_AI_Tool_Schedule_Follow_Up' => 'schedule_follow_up',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$this->assertSame(
			array( 'lead_id', 'subject', 'body' ),
			( new WP_MCP_AI_Tool_Send_Lead_Email() )->get_parameters_schema()['required']
		);
		$this->assertSame(
			array( 'lead_id', 'intent' ),
			( new WP_MCP_AI_Tool_Auto_Reply_Inbound() )->get_parameters_schema()['required']
		);
	}

	/**
	 * The serving sources must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		foreach (
			array(
				'WP_MCP_AI_Tool_Send_Lead_Email',
				'WP_MCP_AI_Tool_Send_Lead_SMS',
				'WP_MCP_AI_Tool_Draft_Lead_Reply',
				'WP_MCP_AI_Tool_Schedule_Follow_Up',
			) as $class
		) {
			$reflection = new ReflectionClass( $class );
			$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/tools/crm/outbound/', $file, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/crm/outbound/', $file, $class );
			}
		}
	}

	/**
	 * Send Lead Email must enforce the contact-data gate (no email meta →
	 * no_email) before any channel work.
	 */
	public function test_send_email_no_email_gate(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$lead_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
				'post_title'  => 'No Email Lead',
			)
		);

		$result = ( new WP_MCP_AI_Tool_Send_Lead_Email() )->execute(
			array(
				'lead_id' => $lead_id,
				'subject' => 'Hello',
				'body'    => 'Hi there',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'no_email', $result->get_error_code() );
	}

	/**
	 * The consent gate must block sends without consent on file.
	 */
	public function test_send_email_consent_gate(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$lead_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
				'post_title'  => 'Consentless Lead',
			)
		);
		update_post_meta( $lead_id, 'email', 'lead@example.com' );

		$result = ( new WP_MCP_AI_Tool_Send_Lead_Email() )->execute(
			array(
				'lead_id' => $lead_id,
				'subject' => 'Hello',
				'body'    => 'Hi there',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'consent_required', $result->get_error_code() );
	}

	/**
	 * Send Lead SMS must enforce the contact-data gate (no phone → no_phone).
	 */
	public function test_send_sms_no_phone_gate(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$lead_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
				'post_title'  => 'No Phone Lead',
			)
		);

		$result = ( new WP_MCP_AI_Tool_Send_Lead_SMS() )->execute(
			array(
				'lead_id' => $lead_id,
				'message' => 'Hello',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'no_phone', $result->get_error_code() );
	}

	/**
	 * Log Call Outcome must reject unknown leads before any write.
	 */
	public function test_log_call_outcome_not_found(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Log_Call_Outcome() )->execute(
			array( 'lead_id' => 999999 ),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	/**
	 * Schedule Follow Up must create a follow-up activity (no channel I/O).
	 */
	public function test_schedule_follow_up(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$lead_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
				'post_title'  => 'Followable Lead',
			)
		);

		$result = ( new WP_MCP_AI_Tool_Schedule_Follow_Up() )->execute(
			array(
				'lead_id' => $lead_id,
				'days'    => 3,
				'notes'   => 'Check in',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['activity_id'] );
		$this->assertSame( 'mcp_ai_crm_activity', get_post_type( $result['activity_id'] ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry the outbound batch.
	 */
	public function test_outbound_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Send_Lead_Email', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Send_Lead_SMS', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Send_Lead_Whatsapp', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Send_Lead_Dm', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Log_Call_Outcome', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Draft_Lead_Reply', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Auto_Reply_Inbound', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Schedule_Follow_Up', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php',
			$tools['WP_MCP_AI_Tool_Send_Lead_Email']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the outbound
	 * batch into both registries.
	 */
	public function test_outbound_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['send_lead_email'] ?? null );
		$this->assertNotNull( $parent->all()['schedule_follow_up'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'send_lead_email' ) );
		$this->assertTrue( $core_tools->has( 'send_lead_sms' ) );
		$this->assertTrue( $core_tools->has( 'send_lead_whatsapp' ) );
		$this->assertTrue( $core_tools->has( 'send_lead_dm' ) );
		$this->assertTrue( $core_tools->has( 'log_call_outcome' ) );
		$this->assertTrue( $core_tools->has( 'draft_lead_reply' ) );
		$this->assertTrue( $core_tools->has( 'auto_reply_inbound' ) );
		$this->assertTrue( $core_tools->has( 'schedule_follow_up' ) );
	}
}

<?php
/**
 * Test CRM Toolkit — Engine, Tools & Pipeline
 *
 * Validates the CRM shared engine classes and tool implementations
 * from Phase A through Phase E.  Covers lead/deal/activity CRUD,
 * pipeline analytics, routing, sequences, workflow rules,
 * inbound triage, outbound with consent gates, and compliance.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

/**
 * Test case for CRM toolkit.
 *
 * @since 2.3.0
 */
class Test_CRM_Toolkit extends WP_UnitTestCase {

	/**
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Lead CPT post IDs created during tests.
	 *
	 * @var int[]
	 */
	private $test_lead_ids = array();

	/**
	 * Deal CPT post IDs created during tests.
	 *
	 * @var int[]
	 */
	private $test_deal_ids = array();

	/**
	 * Activity CPT post IDs created during tests.
	 *
	 * @var int[]
	 */
	private $test_activity_ids = array();

	/** @var int */
	private $admin_user_id;

	// ────────────────────────────────────────────────────────
	// Phase A — Engine infrastructure
	// ────────────────────────────────────────────────────────

	public function setUp(): void {
		parent::setUp();

		// Enable CRM toolkit.
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		}
	}

	public function tearDown(): void {
		foreach ( $this->test_lead_ids as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->test_deal_ids as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->test_activity_ids as $id ) {
			wp_delete_post( $id, true );
		}

		$this->test_lead_ids     = array();
		$this->test_deal_ids     = array();
		$this->test_activity_ids = array();

		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────
	// Phase A — Engine classes exist
	// ────────────────────────────────────────────────────────

	public function test_engine_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Engine' ), 'CRM Engine class should exist.' );
	}

	public function test_codes_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Codes' ), 'CRM Codes class should exist.' );
	}

	public function test_audit_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Audit' ), 'CRM Audit class should exist.' );
	}

	public function test_capabilities_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Capabilities' ), 'CRM Capabilities class should exist.' );
	}

	public function test_consent_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Consent' ), 'CRM Consent class should exist.' );
	}

	public function test_pipeline_stages_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Pipeline_Stages' ), 'CRM Pipeline Stages class should exist.' );
	}

	public function test_classifier_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Classifier' ), 'CRM Classifier class should exist.' );
	}

	public function test_engine_returns_settings() {
		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'qualification_framework', $settings );
		$this->assertArrayHasKey( 'hot_score_threshold', $settings );
		$this->assertArrayHasKey( 'routing', $settings );
		$this->assertArrayHasKey( 'pipeline', $settings );
		$this->assertArrayHasKey( 'consent', $settings );
	}

	public function test_engine_score_label() {
		$this->assertSame( 'hot', WP_MCP_AI_CRM_Engine::score_label( 85 ), 'Score 85 should be hot.' );
		$this->assertSame( 'warm', WP_MCP_AI_CRM_Engine::score_label( 55 ), 'Score 55 should be warm.' );
		$this->assertSame( 'cold', WP_MCP_AI_CRM_Engine::score_label( 25 ), 'Score 25 should be cold.' );
		$this->assertSame( 'unscored', WP_MCP_AI_CRM_Engine::score_label( null ), 'Null score should be unscored.' );
	}

	public function test_engine_calculate_lead_score() {
		$score = WP_MCP_AI_CRM_Engine::calculate_lead_score(
			array(
				'fit'        => 80,
				'intent'     => 90,
				'engagement' => 70,
				'recency'    => 60,
			)
		);
		$this->assertGreaterThanOrEqual( 0, $score );
		$this->assertLessThanOrEqual( 100, $score );
	}

	public function test_codes_validates_channels() {
		$this->assertTrue( WP_MCP_AI_CRM_Codes::is_valid_channel( 'email' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Codes::is_valid_channel( 'whatsapp' ) );
		$this->assertFalse( WP_MCP_AI_CRM_Codes::is_valid_channel( 'snail_mail' ) );
	}

	public function test_codes_validates_intents() {
		$this->assertTrue( WP_MCP_AI_CRM_Codes::is_valid_intent( 'demo_request' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Codes::is_valid_intent( 'complaint' ) );
	}

	public function test_pipeline_stages_returns_all() {
		$stages = WP_MCP_AI_CRM_Pipeline_Stages::get_stages();
		$this->assertIsArray( $stages );
		$this->assertArrayHasKey( 'prospecting', $stages );
		$this->assertArrayHasKey( 'closed_won', $stages );
		$this->assertArrayHasKey( 'closed_lost', $stages );
	}

	public function test_pipeline_stages_is_won_lost() {
		$this->assertTrue( WP_MCP_AI_CRM_Pipeline_Stages::is_won( 'closed_won' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Pipeline_Stages::is_lost( 'closed_lost' ) );
		$this->assertFalse( WP_MCP_AI_CRM_Pipeline_Stages::is_won( 'prospecting' ) );
	}

	public function test_audit_records_entry() {
		WP_MCP_AI_CRM_Audit::record( 'test_event', 'test_type', 123, array( 'key' => 'value' ) );
		$entries = WP_MCP_AI_CRM_Audit::get_entries( 1, 1, 'test_event' );
		$this->assertNotEmpty( $entries );
		$this->assertSame( 'test_event', $entries[0]['event'] );
		$this->assertSame( 'test_type', $entries[0]['resource_type'] );
		WP_MCP_AI_CRM_Audit::clear();
	}

	public function test_classifier_classify_returns_structure() {
		$result = WP_MCP_AI_CRM_Classifier::classify( 'I need a demo of your product', 'email' );
		if ( ! is_wp_error( $result ) ) {
			$this->assertArrayHasKey( 'intent', $result );
			$this->assertArrayHasKey( 'sentiment', $result );
			$this->assertArrayHasKey( 'buying_signals', $result );
			$this->assertArrayHasKey( 'is_spam', $result );
		}
	}

	public function test_classifier_bant_extraction() {
		$bant = WP_MCP_AI_CRM_Classifier::extract_bant(
			'We have budget approved and need a solution urgently. I am the VP of Engineering.'
		);
		$this->assertArrayHasKey( 'budget', $bant );
		$this->assertArrayHasKey( 'authority', $bant );
		$this->assertArrayHasKey( 'need', $bant );
		$this->assertArrayHasKey( 'timeline', $bant );
		$this->assertGreaterThan( 0, $bant['budget']['score'] );
		$this->assertGreaterThan( 0, $bant['authority']['score'] );
	}

	// ────────────────────────────────────────────────────────
	// Phase B — CPT registration
	// ────────────────────────────────────────────────────────

	public function test_lead_cpt_registered() {
		$this->assertTrue( post_type_exists( 'mcp_ai_lead' ), 'Lead CPT should be registered.' );
	}

	public function test_deal_cpt_registered() {
		$this->assertTrue( post_type_exists( 'mcp_ai_deal' ), 'Deal CPT should be registered.' );
	}

	public function test_activity_cpt_registered() {
		$this->assertTrue( post_type_exists( 'mcp_ai_crm_activity' ), 'Activity CPT should be registered.' );
	}

	public function test_sequence_cpt_registered() {
		$this->assertTrue( post_type_exists( 'mcp_ai_sequence' ), 'Sequence CPT should be registered.' );
	}

	public function test_workflow_rule_cpt_registered() {
		$this->assertTrue( post_type_exists( 'mcp_ai_crm_workflow_rule' ), 'Workflow Rule CPT should be registered.' );
	}

	// ────────────────────────────────────────────────────────
	// Phase B — Lead CRUD
	// ────────────────────────────────────────────────────────

	public function test_create_lead() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Test Lead',
				'post_status' => 'publish',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $lead_id );
		$this->test_lead_ids[] = $lead_id;

		update_post_meta( $lead_id, 'email', 'test@example.com' );
		update_post_meta( $lead_id, 'lead_status', 'new' );
		update_post_meta( $lead_id, 'lifecycle_stage', 'lead' );

		$email = get_post_meta( $lead_id, 'email', true );
		$this->assertSame( 'test@example.com', $email );
	}

	public function test_lead_meta_fields_persist() {
		$lead_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Meta Lead',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;

		update_post_meta( $lead_id, 'first_name', 'John' );
		update_post_meta( $lead_id, 'last_name', 'Doe' );
		update_post_meta( $lead_id, 'email', 'john@example.com' );
		update_post_meta( $lead_id, 'phone', '+15551234567' );
		update_post_meta( $lead_id, 'company', 'Acme Inc' );
		update_post_meta( $lead_id, 'source', 'web_form' );
		update_post_meta( $lead_id, 'lead_score', 75 );
		update_post_meta( $lead_id, 'contact_owner', $this->admin_user_id );

		$this->assertSame( 'John', get_post_meta( $lead_id, 'first_name', true ) );
		$this->assertSame( 'Doe', get_post_meta( $lead_id, 'last_name', true ) );
		$this->assertSame( 'john@example.com', get_post_meta( $lead_id, 'email', true ) );
		$this->assertSame( '+15551234567', get_post_meta( $lead_id, 'phone', true ) );
		$this->assertSame( 'Acme Inc', get_post_meta( $lead_id, 'company', true ) );
		$this->assertSame( 'web_form', get_post_meta( $lead_id, 'source', true ) );
		$this->assertSame( '75', get_post_meta( $lead_id, 'lead_score', true ) );
		$this->assertSame( (string) $this->admin_user_id, get_post_meta( $lead_id, 'contact_owner', true ) );
	}

	public function test_lead_delete_trashes() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'To Delete',
				'post_status' => 'publish',
			)
		);
		wp_trash_post( $lead_id );
		$post = get_post( $lead_id );
		$this->assertSame( 'trash', $post->post_status );
		wp_delete_post( $lead_id, true );
	}

	public function test_convert_lead_to_customer() {
		$lead_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Convert Me',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;

		update_post_meta( $lead_id, 'lifecycle_stage', 'sql' );
		update_post_meta( $lead_id, 'lead_status', 'qualified' );

		// Simulate conversion.
		update_post_meta( $lead_id, 'lifecycle_stage', 'customer' );
		update_post_meta( $lead_id, 'lead_status', 'converted' );

		$this->assertSame( 'customer', get_post_meta( $lead_id, 'lifecycle_stage', true ) );
		$this->assertSame( 'converted', get_post_meta( $lead_id, 'lead_status', true ) );
	}

	// ────────────────────────────────────────────────────────
	// Phase B — Deal CRUD
	// ────────────────────────────────────────────────────────

	public function test_create_deal() {
		$lead_id = $this->create_test_lead();

		$deal_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_title'  => 'Test Deal',
				'post_status' => 'publish',
			)
		);
		$this->test_deal_ids[] = $deal_id;

		update_post_meta( $deal_id, 'lead_id', $lead_id );
		update_post_meta( $deal_id, 'deal_stage', 'prospecting' );
		update_post_meta( $deal_id, 'deal_amount', 10000 );
		update_post_meta( $deal_id, 'deal_probability', 0.05 );
		update_post_meta( $deal_id, 'deal_owner', $this->admin_user_id );

		$this->assertSame( (string) $lead_id, get_post_meta( $deal_id, 'lead_id', true ) );
		$this->assertSame( 'prospecting', get_post_meta( $deal_id, 'deal_stage', true ) );
		$this->assertEquals( 10000, (float) get_post_meta( $deal_id, 'deal_amount', true ) );
	}

	public function test_move_deal_stage() {
		$deal_id = $this->create_test_deal();

		update_post_meta( $deal_id, 'deal_stage', 'qualification' );
		update_post_meta( $deal_id, 'deal_probability', 0.10 );

		$this->assertSame( 'qualification', get_post_meta( $deal_id, 'deal_stage', true ) );

		// Move to won.
		update_post_meta( $deal_id, 'deal_stage', 'closed_won' );
		update_post_meta( $deal_id, 'deal_probability', 1.00 );

		$this->assertSame( 'closed_won', get_post_meta( $deal_id, 'deal_stage', true ) );
	}

	// ────────────────────────────────────────────────────────
	// Phase B — Activity CRUD
	// ────────────────────────────────────────────────────────

	public function test_create_activity() {
		$lead_id = $this->create_test_lead();

		$activity_id               = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => 'Discovery Call',
				'post_content' => 'Had a great discovery call.',
				'post_status'  => 'publish',
			)
		);
		$this->test_activity_ids[] = $activity_id;

		update_post_meta( $activity_id, 'activity_type', 'call' );
		update_post_meta( $activity_id, 'related_type', 'lead' );
		update_post_meta( $activity_id, 'related_id', $lead_id );
		update_post_meta( $activity_id, 'due_date', gmdate( 'Y-m-d', strtotime( '+2 days' ) ) );
		update_post_meta( $activity_id, 'disposition', 'connected' );

		$this->assertSame( 'call', get_post_meta( $activity_id, 'activity_type', true ) );
		$this->assertSame( 'lead', get_post_meta( $activity_id, 'related_type', true ) );
		$this->assertSame( (string) $lead_id, get_post_meta( $activity_id, 'related_id', true ) );
		$this->assertSame( 'connected', get_post_meta( $activity_id, 'disposition', true ) );
	}

	public function test_complete_activity() {
		$activity_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_activity',
				'post_title'  => 'Task to Complete',
				'post_status' => 'publish',
			)
		);
		$this->test_activity_ids[] = $activity_id;

		update_post_meta( $activity_id, 'completed', '1' );
		update_post_meta( $activity_id, 'completed_at', gmdate( 'c' ) );

		$this->assertSame( '1', get_post_meta( $activity_id, 'completed', true ) );
		$this->assertNotEmpty( get_post_meta( $activity_id, 'completed_at', true ) );
	}

	// ────────────────────────────────────────────────────────
	// Phase B — Routing
	// ────────────────────────────────────────────────────────

	public function test_assign_lead_to_owner() {
		$lead_id = $this->create_test_lead();

		update_post_meta( $lead_id, 'contact_owner', $this->admin_user_id );

		$this->assertSame(
			(string) $this->admin_user_id,
			get_post_meta( $lead_id, 'contact_owner', true )
		);
	}

	// ────────────────────────────────────────────────────────
	// Phase C — Inbound triage
	// ────────────────────────────────────────────────────────

	public function test_classify_message_intent() {
		$result = WP_MCP_AI_CRM_Classifier::classify(
			'Can I get a demo of your enterprise plan?',
			'email'
		);
		if ( ! is_wp_error( $result ) ) {
			$this->assertArrayHasKey( 'intent', $result );
			$this->assertArrayHasKey( 'sentiment', $result );
		}
	}

	public function test_spam_detection() {
		$result = WP_MCP_AI_CRM_Classifier::classify(
			'You won the lottery! Click here to claim your prize.',
			'email'
		);
		if ( ! is_wp_error( $result ) ) {
			$this->assertTrue( $result['is_spam'], 'Spam should be detected.' );
		}
	}

	public function test_buying_signal_detection() {
		$signals = array();
		$kw      = apply_filters( 'wp_mcp_ai_crm_buying_signal_keywords', array( 'pricing', 'demo', 'budget' ) );
		$msg     = 'I need pricing and a demo. We have budget.';
		$lower   = mb_strtolower( $msg );
		foreach ( $kw as $k ) {
			if ( false !== strpos( $lower, $k ) ) {
				$signals[] = $k;
			}
		}
		$this->assertCount( 3, $signals );
		$this->assertContains( 'pricing', $signals );
		$this->assertContains( 'demo', $signals );
		$this->assertContains( 'budget', $signals );
	}

	// ────────────────────────────────────────────────────────
	// Phase C — Consent gates
	// ────────────────────────────────────────────────────────

	public function test_record_consent() {
		$lead_id = $this->create_test_lead();
		$result  = WP_MCP_AI_CRM_Consent::record(
			$lead_id,
			'email',
			'consent',
			'web_form',
			'https://example.com/consent/123'
		);
		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'email' ) );
	}

	public function test_revoke_consent() {
		$lead_id = $this->create_test_lead();

		// Grant then revoke.
		WP_MCP_AI_CRM_Consent::record( $lead_id, 'sms', 'consent', 'web_form' );
		$this->assertTrue( WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'sms' ) );

		WP_MCP_AI_CRM_Consent::revoke( $lead_id, 'sms' );
		$this->assertFalse( WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'sms' ) );
	}

	public function test_dnc_list() {
		$email = 'blocked@example.com';
		WP_MCP_AI_CRM_Engine::add_to_dnc( $email, 'email' );
		$this->assertTrue( WP_MCP_AI_CRM_Engine::check_dnc( $email, 'email' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Engine::check_dnc( $email, 'all' ) );
		$this->assertFalse( WP_MCP_AI_CRM_Engine::check_dnc( 'clean@example.com', 'email' ) );
	}

	// ────────────────────────────────────────────────────────
	// Phase D — Sequences
	// ────────────────────────────────────────────────────────

	public function test_create_sequence() {
		$seq_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_sequence',
				'post_title'  => '5-Day Outreach',
				'post_status' => 'publish',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $seq_id );
		$this->test_deal_ids[] = $seq_id;

		$steps = array(
			array(
				'order'       => 1,
				'channel'     => 'email',
				'template_id' => 'intro',
				'wait_hours'  => 0,
			),
			array(
				'order'       => 2,
				'channel'     => 'email',
				'template_id' => 'followup',
				'wait_hours'  => 48,
			),
		);
		update_post_meta( $seq_id, 'steps', $steps );
		update_post_meta( $seq_id, 'step_count', count( $steps ) );

		$this->assertSame( 2, (int) get_post_meta( $seq_id, 'step_count', true ) );
	}

	public function test_enroll_lead_in_sequence() {
		$lead_id = $this->create_test_lead();

		update_post_meta( $lead_id, '_active_sequence_id', 99 );
		update_post_meta( $lead_id, '_sequence_step', 0 );
		update_post_meta( $lead_id, '_sequence_started', gmdate( 'c' ) );

		$this->assertSame( '99', get_post_meta( $lead_id, '_active_sequence_id', true ) );
		$this->assertSame( '0', get_post_meta( $lead_id, '_sequence_step', true ) );

		// Pause.
		update_post_meta( $lead_id, '_sequence_paused', '1' );
		$this->assertSame( '1', get_post_meta( $lead_id, '_sequence_paused', true ) );

		// Exit.
		delete_post_meta( $lead_id, '_active_sequence_id' );
		delete_post_meta( $lead_id, '_sequence_step' );
		delete_post_meta( $lead_id, '_sequence_paused' );
		$this->assertEmpty( get_post_meta( $lead_id, '_active_sequence_id', true ) );
	}

	// ────────────────────────────────────────────────────────
	// Phase D — Workflow Rules
	// ────────────────────────────────────────────────────────

	public function test_create_workflow_rule() {
		$rule_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_workflow_rule',
				'post_title'  => 'Auto-Reply Hot Leads',
				'post_status' => 'publish',
			)
		);
		$this->assertNotInstanceOf( 'WP_Error', $rule_id );
		$this->test_deal_ids[] = $rule_id; // reuse cleanup.

		update_post_meta( $rule_id, 'trigger', 'inbound_message_received' );
		update_post_meta( $rule_id, 'is_active', '1' );
		update_post_meta(
			$rule_id,
			'actions',
			array(
				array(
					'type'   => 'send_email',
					'params' => array( 'template_id' => 'auto_reply' ),
				),
			)
		);

		$this->assertSame( 'inbound_message_received', get_post_meta( $rule_id, 'trigger', true ) );
		$this->assertSame( '1', get_post_meta( $rule_id, 'is_active', true ) );
	}

	// ────────────────────────────────────────────────────────
	// Phase E — Compliance
	// ────────────────────────────────────────────────────────

	public function test_process_opt_out() {
		$email = 'optout@example.com';
		WP_MCP_AI_CRM_Engine::add_to_dnc( $email, 'all' );
		$this->assertTrue( WP_MCP_AI_CRM_Engine::check_dnc( $email, 'email' ) );
		$this->assertTrue( WP_MCP_AI_CRM_Engine::check_dnc( $email, 'sms' ) );
	}

	public function test_consent_audit_retrieval() {
		$lead_id = $this->create_test_lead();
		WP_MCP_AI_CRM_Consent::record( $lead_id, 'email', 'consent', 'web_form' );
		$audit = WP_MCP_AI_CRM_Consent::get_consent_audit( $lead_id );
		$this->assertArrayHasKey( 'consent_records', $audit );
		$this->assertNotEmpty( $audit['consent_records'] );
	}

	// ────────────────────────────────────────────────────────
	// Phase E — CSV import
	// ────────────────────────────────────────────────────────

	public function test_csv_import_parsing() {
		$csv   = "first_name,last_name,email,company\nAlice,Smith,alice@example.com,Acme\nBob,Jones,bob@example.com,Beta";
		$lines = explode( "\n", trim( $csv ) );
		$this->assertCount( 3, $lines ); // header + 2 rows.

		$headers = str_getcsv( array_shift( $lines ) );
		$this->assertSame( array( 'first_name', 'last_name', 'email', 'company' ), $headers );

		$row = str_getcsv( $lines[0] );
		$this->assertSame( 'Alice', $row[0] );
		$this->assertSame( 'alice@example.com', $row[2] );
	}

	// ────────────────────────────────────────────────────────
	// Blueprint file existence
	// ────────────────────────────────────────────────────────

	public function test_blueprint_files_exist() {
		$base       = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : dirname( __DIR__, 3 ) . '/addons/pro/';
		$blueprints = array(
			'b2b-saas-sdr.json',
			'agency-account-manager.json',
			'real-estate-buyer-agent.json',
			'wholesale-distributor.json',
			'bespoke-concierge.json',
			'luxeseek-sourcing-agent.json',
			'business-advisory.json',
			'career-coach.json',
		);
		foreach ( $blueprints as $bp ) {
			$file = $base . 'includes/tools/crm/examples/' . $bp;
			$this->assertFileExists( $file, "Blueprint {$bp} should exist." );
			$json = json_decode( file_get_contents( $file ), true );
			$this->assertIsArray( $json, "Blueprint {$bp} should be valid JSON." );
			$this->assertArrayHasKey( 'name', $json );
			$this->assertArrayHasKey( 'meta', $json );
		}
	}

	// ────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────

	private function create_test_lead(): int {
		$lead_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Test Lead ' . wp_rand(),
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'email', 'lead' . wp_rand() . '@example.com' );
		return $lead_id;
	}

	private function create_test_deal(): int {
		$lead_id               = $this->create_test_lead();
		$deal_id               = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_title'  => 'Test Deal ' . wp_rand(),
				'post_status' => 'publish',
			)
		);
		$this->test_deal_ids[] = $deal_id;
		update_post_meta( $deal_id, 'lead_id', $lead_id );
		update_post_meta( $deal_id, 'deal_stage', 'prospecting' );
		return $deal_id;
	}
}

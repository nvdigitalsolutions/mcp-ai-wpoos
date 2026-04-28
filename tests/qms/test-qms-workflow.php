<?php
/**
 * PHPUnit tests for the QMS state-machine workflow.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * @group qms
 */
class Test_QMS_Workflow extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_document_generation_toolkit' => true,
				'enable_qms_compliance'              => true,
			)
		);
		$base = dirname( __DIR__, 2 ) . '/addons/pro/includes/qms';
		require_once $base . '/class-wp-mcp-ai-qms-capabilities.php';
		require_once $base . '/class-wp-mcp-ai-qms-audit-log.php';
		require_once $base . '/class-wp-mcp-ai-qms-doc-record-cpt.php';
		require_once $base . '/class-wp-mcp-ai-qms-workflow.php';

		WP_MCP_AI_QMS_Doc_Record_CPT::register();
		WP_MCP_AI_QMS_Audit_Log::install();
	}

	protected function make_record( $extra_meta = array() ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE,
				'post_title'   => 'Test Doc',
				'post_content' => 'Body',
			)
		);
		update_post_meta( $post_id, '_qms_document_id', 'SOP-001' );
		update_post_meta( $post_id, '_qms_revision', '1.0' );
		update_post_meta( $post_id, '_qms_status', WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT );
		foreach ( $extra_meta as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}
		WP_MCP_AI_QMS_Doc_Record_CPT::recompute_hash( $post_id );
		return $post_id;
	}

	public function test_draft_to_in_review_requires_reviewer() {
		$post_id = $this->make_record();
		$result  = WP_MCP_AI_QMS_Workflow::transition( $post_id, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_qms_no_reviewers', $result->get_error_code() );
	}

	public function test_draft_to_in_review_succeeds_with_reviewer() {
		$user_id = self::factory()->user->create();
		$post_id = $this->make_record( array( '_qms_reviewer_ids' => array( $user_id ) ) );
		$result  = WP_MCP_AI_QMS_Workflow::transition( $post_id, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW );
		$this->assertTrue( $result );
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW, get_post_meta( $post_id, '_qms_status', true ) );
	}

	public function test_invalid_transition_is_rejected() {
		$post_id = $this->make_record();
		$result  = WP_MCP_AI_QMS_Workflow::transition( $post_id, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_qms_invalid_transition', $result->get_error_code() );
	}

	public function test_release_requires_approval_signature() {
		$reviewer = self::factory()->user->create();
		$approver = self::factory()->user->create();
		$post_id  = $this->make_record(
			array(
				'_qms_reviewer_ids' => array( $reviewer ),
				'_qms_approver_ids' => array( $approver ),
			)
		);

		$this->assertTrue( WP_MCP_AI_QMS_Workflow::transition( $post_id, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW ) );
		$this->assertTrue( WP_MCP_AI_QMS_Workflow::transition( $post_id, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED ) );

		$result = WP_MCP_AI_QMS_Workflow::transition( $post_id, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_qms_no_approval_signature', $result->get_error_code() );
	}

	public function test_audit_log_records_state_transitions() {
		$reviewer = self::factory()->user->create();
		$post_id  = $this->make_record( array( '_qms_reviewer_ids' => array( $reviewer ) ) );

		WP_MCP_AI_QMS_Workflow::transition( $post_id, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW, array( 'reason' => 'go' ) );
		$rows = WP_MCP_AI_QMS_Audit_Log::query( array( 'post_id' => $post_id, 'event' => 'state_transition' ) );
		$this->assertNotEmpty( $rows );
		$this->assertSame( 'state_transition', $rows[0]['event'] );
		$this->assertSame( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW, $rows[0]['to_state'] );
	}

	public function test_can_transition_rules() {
		$this->assertTrue( WP_MCP_AI_QMS_Workflow::can_transition( '', WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT ) );
		$this->assertWPError( WP_MCP_AI_QMS_Workflow::can_transition( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE, WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT ) );
	}

	public function test_sign_with_wrong_password_fails() {
		$user_id = self::factory()->user->create( array( 'user_pass' => 'correct-horse' ) );
		$post_id = $this->make_record( array( '_qms_approver_ids' => array( $user_id ) ) );
		$result  = WP_MCP_AI_QMS_Workflow::sign( $post_id, 'approved', $user_id, 'wrong-password' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_qms_auth_failed', $result->get_error_code() );
	}

	public function test_sign_with_correct_password_records_signature() {
		$user_id = self::factory()->user->create( array( 'user_pass' => 'right-pass' ) );
		$post_id = $this->make_record( array( '_qms_approver_ids' => array( $user_id ) ) );
		$result  = WP_MCP_AI_QMS_Workflow::sign( $post_id, 'approved', $user_id, 'right-pass' );
		$this->assertTrue( $result );
		$signatures = (array) get_post_meta( $post_id, '_qms_signatures', true );
		$this->assertCount( 1, $signatures );
		$this->assertSame( 'approved', $signatures[0]['intent'] );
		$this->assertNotEmpty( $signatures[0]['signature_hash'] );
		$this->assertSame( 64, strlen( $signatures[0]['signature_hash'] ) );
	}
}

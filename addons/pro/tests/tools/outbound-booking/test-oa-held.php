<?php
/**
 * Held outbox tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group outbound-booking
 * @group pro
 */

/**
 * Held mail lifecycle.
 */
class Test_OA_Held extends WP_UnitTestCase {

	/**
	 * Lead ID.
	 *
	 * @var int
	 */
	private $lead_id;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-codes.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-engine.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-audit.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-pre-send-gate.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-settings.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-outbox.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-templates.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-channels.php';

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_outbound_booking_toolkit' => 1,
				'enable_crm_toolkit'              => 1,
			)
		);
		WP_MCP_AI_OA_Settings::update( array( 'email_mode' => 'auto' ) );
		WP_MCP_AI_OA_Outbox::register_post_type();

		$this->lead_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_lead' ) );
		update_post_meta( $this->lead_id, 'email', 'jane@acme.test' );

		add_filter( 'pre_wp_mail', '__return_true' );
		delete_option( WP_MCP_AI_CRM_Pre_Send_Gate::OPTION );
		WP_MCP_AI_CRM_Pre_Send_Gate::flush_cache();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', '__return_true' );
		delete_option( WP_MCP_AI_CRM_Pre_Send_Gate::OPTION );
		WP_MCP_AI_CRM_Pre_Send_Gate::flush_cache();
		parent::tearDown();
	}

	/**
	 * Create an outbox row.
	 *
	 * @param string $body Body.
	 * @return int
	 */
	private function outbox( $body ) {
		return WP_MCP_AI_OA_Outbox::create(
			array(
				'lead_id' => $this->lead_id,
				'channel' => 'email',
				'subject' => 'Quick question',
				'body'    => $body,
				'status'  => 'pending',
				'kind'    => 'sequence',
				'step'    => 0,
			)
		);
	}

	/**
	 * A gate failure holds, does not fail.
	 */
	public function test_gate_failure_holds() {
		$outbox_id = $this->outbox( 'Hi {{first_name}}, is this on your radar?' );
		$this->assertIsInt( $outbox_id );

		$result = WP_MCP_AI_OA_Channels::dispatch( $outbox_id );
		$this->assertWPError( $result );
		$this->assertSame( 'held', get_post_meta( $outbox_id, '_oa_ob_status', true ) );
		$this->assertNotEmpty( get_post_meta( $outbox_id, '_oa_ob_hold_reason', true ) );
	}

	/**
	 * Approve-all skips held mail.
	 */
	public function test_bulk_approve_skips_held() {
		$outbox_id = $this->outbox( 'Hi {{first_name}}, quick question.' );
		WP_MCP_AI_OA_Channels::dispatch( $outbox_id );
		$this->assertSame( 'held', get_post_meta( $outbox_id, '_oa_ob_status', true ) );

		WP_MCP_AI_OA_Outbox::handle_bulk_actions( '', 'oa_approve', array( $outbox_id ) );
		$this->assertSame( 'held', get_post_meta( $outbox_id, '_oa_ob_status', true ) );
	}

	/**
	 * Approving held mail re-runs the gate.
	 */
	public function test_approve_held_rechecks_and_sends() {
		$outbox_id = $this->outbox( 'Hi {{first_name}}, quick question.' );
		WP_MCP_AI_OA_Channels::dispatch( $outbox_id );
		$this->assertSame( 'held', get_post_meta( $outbox_id, '_oa_ob_status', true ) );

		$still_blocked = WP_MCP_AI_OA_Outbox::approve_held( $outbox_id );
		$this->assertWPError( $still_blocked );
		$this->assertSame( 'held', get_post_meta( $outbox_id, '_oa_ob_status', true ) );

		update_post_meta( $outbox_id, '_oa_ob_body', 'Jane, is this on your radar?' );
		$approved = WP_MCP_AI_OA_Outbox::approve_held( $outbox_id );
		$this->assertTrue( $approved );
		$this->assertSame( 'sent', get_post_meta( $outbox_id, '_oa_ob_status', true ) );
	}
}

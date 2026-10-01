<?php
/**
 * Test Outbound Booking outbox / approval board.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Outbox tests.
 */
class Test_OA_Outbox extends WP_UnitTestCase {

	/**
	 * Lead ID used in tests.
	 *
	 * @var int
	 */
	private $lead_id;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-settings.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-outbox.php';
		if ( ! post_type_exists( WP_MCP_AI_OA_Outbox::POST_TYPE ) ) {
			WP_MCP_AI_OA_Outbox::register_post_type();
		}
		$this->lead_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_lead',
				'post_title' => 'Jane Smith',
			)
		);
	}

	/**
	 * Create stores meta and returns a post ID.
	 */
	public function test_create_persists_message() {
		$id = WP_MCP_AI_OA_Outbox::create(
			array(
				'lead_id'     => $this->lead_id,
				'sequence_id' => 7,
				'step'        => 0,
				'channel'     => 'email',
				'subject'     => 'Quick question',
				'body'        => 'Hi Jane',
			)
		);
		$this->assertIsInt( $id );
		$this->assertSame( $this->lead_id, (int) get_post_meta( $id, '_oa_ob_lead_id', true ) );
		$this->assertSame( 'pending', get_post_meta( $id, '_oa_ob_status', true ) );
		$this->assertSame( 'email', get_post_meta( $id, '_oa_ob_channel', true ) );
	}

	/**
	 * Creating the same lead/sequence/step twice returns the first message.
	 */
	public function test_create_dedupes_identical_pending_messages() {
		$args = array(
			'lead_id'     => $this->lead_id,
			'sequence_id' => 9,
			'step'        => 1,
			'channel'     => 'email',
			'subject'     => 'Follow-up',
			'body'        => 'Hey again',
		);
		$first  = WP_MCP_AI_OA_Outbox::create( $args );
		$second = WP_MCP_AI_OA_Outbox::create( $args );
		$this->assertSame( $first, $second );
	}

	/**
	 * Status transitions validate against the allowed set.
	 */
	public function test_set_status_transitions() {
		$id = WP_MCP_AI_OA_Outbox::create(
			array(
				'lead_id' => $this->lead_id,
				'channel' => 'linkedin_dm',
				'body'    => 'DM body',
			)
		);
		WP_MCP_AI_OA_Outbox::set_status( $id, 'sent' );
		$this->assertSame( 'sent', get_post_meta( $id, '_oa_ob_status', true ) );
		$this->assertNotEmpty( get_post_meta( $id, '_oa_ob_sent_at', true ) );

		WP_MCP_AI_OA_Outbox::set_status( $id, 'not-a-status' );
		$this->assertSame( 'sent', get_post_meta( $id, '_oa_ob_status', true ) );

		WP_MCP_AI_OA_Outbox::set_status( $id, 'failed', 'Webhook returned 500' );
		$this->assertSame( 'failed', get_post_meta( $id, '_oa_ob_status', true ) );
		$this->assertSame( 'Webhook returned 500', get_post_meta( $id, '_oa_ob_error', true ) );
	}

	/**
	 * Status counts aggregate across the board.
	 */
	public function test_get_status_counts() {
		$pending = WP_MCP_AI_OA_Outbox::create( array( 'lead_id' => $this->lead_id, 'channel' => 'email', 'body' => 'A' ) );
		$sent    = WP_MCP_AI_OA_Outbox::create( array( 'lead_id' => $this->lead_id, 'channel' => 'email', 'body' => 'B', 'sequence_id' => 2, 'step' => 5 ) );
		WP_MCP_AI_OA_Outbox::set_status( $sent, 'sent' );

		$counts = WP_MCP_AI_OA_Outbox::get_status_counts();
		$this->assertGreaterThanOrEqual( 1, $counts['pending'] );
		$this->assertGreaterThanOrEqual( 1, $counts['sent'] );
		$this->assertSame( 'pending', get_post_meta( $pending, '_oa_ob_status', true ) );
		$this->assertSame( 'sent', get_post_meta( $sent, '_oa_ob_status', true ) );
	}
}

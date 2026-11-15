<?php
/**
 * Tests for the CRM pre-send gate.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * Pre-send gate tests.
 */
class Test_CRM_Pre_Send_Gate extends WP_UnitTestCase {

	/**
	 * Lead post ID.
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

		$this->lead_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Jane Smith',
			)
		);
		update_post_meta( $this->lead_id, 'email', 'jane@acme.test' );

		delete_option( WP_MCP_AI_CRM_Pre_Send_Gate::OPTION );
		delete_option( 'wp_mcp_ai_crm_dnc_list' );
		WP_MCP_AI_CRM_Pre_Send_Gate::flush_cache();
		WP_MCP_AI_CRM_Engine::flush_settings_cache();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_CRM_Pre_Send_Gate::OPTION );
		delete_option( 'wp_mcp_ai_crm_dnc_list' );
		delete_option( WP_MCP_AI_CRM_Engine::SETTINGS_OPTION );
		delete_post_meta( $this->lead_id, '_crm_personalization_evidence' );
		WP_MCP_AI_CRM_Pre_Send_Gate::flush_cache();
		WP_MCP_AI_CRM_Engine::flush_settings_cache();
		parent::tearDown();
	}

	/**
	 * Build a valid payload.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function payload( array $overrides = array() ) {
		return array_merge(
			array(
				'lead_id' => $this->lead_id,
				'channel' => 'email',
				'kind'    => 'one_off',
				'to'      => 'jane@acme.test',
				'subject' => 'Your booking page',
				'body'    => 'Jane, I noticed the contact page still asks people to call. Is that intentional?',
				'step'    => 0,
			),
			$overrides
		);
	}

	/**
	 * Assert the payload is blocked with a reason code.
	 *
	 * @param array  $payload Payload.
	 * @param string $code    Expected reason code.
	 */
	private function assertBlockedWith( array $payload, $code ) {
		$result = WP_MCP_AI_CRM_Pre_Send_Gate::check( $payload );
		$this->assertWPError( $result );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'codes', $data );
		$this->assertContains( $code, $data['codes'] );
	}

	/**
	 * A clean payload passes.
	 */
	public function test_clean_payload_passes() {
		$this->assertTrue( WP_MCP_AI_CRM_Pre_Send_Gate::check( $this->payload() ) );
	}

	/**
	 * Unrendered double-brace tags block.
	 */
	public function test_merge_tag_blocks() {
		$this->assertBlockedWith(
			$this->payload( array( 'body' => 'Hi {{first_name}}, quick question.' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_MERGE_TAG
		);
	}

	/**
	 * Known single-brace tokens block; prose braces do not.
	 */
	public function test_single_brace_token_blocks_but_prose_passes() {
		$this->assertBlockedWith(
			$this->payload( array( 'body' => 'Hi {first_name}, quick question.' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_MERGE_TAG
		);

		$this->assertTrue(
			WP_MCP_AI_CRM_Pre_Send_Gate::check(
				$this->payload( array( 'body' => 'The config uses {not_a_token} in this example.' ) )
			)
		);
	}

	/**
	 * Banned phrases block case-insensitively.
	 */
	public function test_banned_phrase_blocks() {
		$this->assertBlockedWith(
			$this->payload( array( 'body' => 'Act Now and double your pipeline.' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_BANNED_PHRASE
		);
	}

	/**
	 * Subject length blocks.
	 */
	public function test_subject_too_long_blocks() {
		$this->assertBlockedWith(
			$this->payload( array( 'subject' => str_repeat( 'a', 120 ) ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_SUBJECT_LENGTH
		);
	}

	/**
	 * Hard body ceiling blocks.
	 */
	public function test_body_too_long_blocks() {
		$body = implode( ' ', array_fill( 0, 260, 'word' ) );
		$this->assertBlockedWith(
			$this->payload( array( 'body' => $body ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_BODY_LENGTH
		);
	}

	/**
	 * First touch allows zero links.
	 */
	public function test_first_touch_link_budget_blocks_one_link() {
		$this->assertBlockedWith(
			$this->payload(
				array(
					'kind' => 'sequence',
					'step' => 0,
					'body' => 'Jane, is this on your radar? https://acme.test/audit',
				)
			),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_LINK_BUDGET
		);
	}

	/**
	 * Later touches allow one link.
	 */
	public function test_later_touch_allows_one_link() {
		$this->assertTrue(
			WP_MCP_AI_CRM_Pre_Send_Gate::check(
				$this->payload(
					array(
						'kind' => 'sequence',
						'step' => 1,
						'body' => 'Jane, the teardown is here: https://acme.test/audit',
					)
				)
			)
		);
	}

	/**
	 * Recipient mismatch blocks.
	 */
	public function test_recipient_mismatch_blocks() {
		$this->assertBlockedWith(
			$this->payload( array( 'to' => 'john@acme.test' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_RECIPIENT_MATCH
		);
	}

	/**
	 * DNC blocks.
	 */
	public function test_dnc_blocks() {
		update_option( 'wp_mcp_ai_crm_dnc_list', array( 'jane@acme.test' => array( 'all' ) ) );
		$this->assertBlockedWith( $this->payload(), WP_MCP_AI_CRM_Pre_Send_Gate::REASON_DNC );
	}

	/**
	 * Tracking pixels block even when HTML is allowed.
	 */
	public function test_tracking_pixel_blocks() {
		$this->assertBlockedWith(
			$this->payload( array( 'body' => 'Hello<img src="https://acme.test/p.gif" width="1" height="1">' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_TRACKING_PIXEL
		);
	}

	/**
	 * Plain-text requirement blocks HTML.
	 */
	public function test_plain_text_requirement_blocks_html() {
		update_option(
			WP_MCP_AI_CRM_Pre_Send_Gate::OPTION,
			array( 'require_plain_text' => true )
		);
		WP_MCP_AI_CRM_Pre_Send_Gate::flush_cache();

		$this->assertBlockedWith(
			$this->payload( array( 'body' => '<p>Jane, is this on your radar?</p>' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_HTML_BODY
		);
	}

	/**
	 * Postal address requirement blocks when missing.
	 */
	public function test_postal_address_requirement_blocks() {
		update_option(
			WP_MCP_AI_CRM_Pre_Send_Gate::OPTION,
			array( 'require_postal_address' => true )
		);
		update_option(
			WP_MCP_AI_CRM_Engine::SETTINGS_OPTION,
			array( 'consent' => array( 'physical_address' => '1 Main St, Springfield' ) )
		);
		WP_MCP_AI_CRM_Engine::flush_settings_cache();
		WP_MCP_AI_CRM_Pre_Send_Gate::flush_cache();

		$this->assertBlockedWith(
			$this->payload( array( 'kind' => 'sequence' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_POSTAL
		);
	}

	/**
	 * Evidence requirement blocks when missing and passes when present.
	 */
	public function test_evidence_requirement() {
		update_option(
			WP_MCP_AI_CRM_Pre_Send_Gate::OPTION,
			array( 'require_evidence' => true )
		);
		WP_MCP_AI_CRM_Pre_Send_Gate::flush_cache();

		$this->assertBlockedWith(
			$this->payload( array( 'kind' => 'sequence' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_EVIDENCE
		);

		update_post_meta( $this->lead_id, '_crm_personalization_evidence', array( array( 'signal' => 'no_online_booking' ) ) );
		$this->assertTrue( WP_MCP_AI_CRM_Pre_Send_Gate::check( $this->payload( array( 'kind' => 'sequence' ) ) ) );
	}

	/**
	 * DM channels may omit a recipient.
	 */
	public function test_dm_channel_allows_empty_recipient() {
		$this->assertTrue(
			WP_MCP_AI_CRM_Pre_Send_Gate::check(
				$this->payload(
					array(
						'channel' => 'linkedin_dm',
						'to'      => '',
						'subject' => '',
						'body'    => 'Jane, is this on your radar?',
					)
				)
			)
		);
	}

	/**
	 * Unknown channels block.
	 */
	public function test_unknown_channel_blocks() {
		$this->assertBlockedWith(
			$this->payload( array( 'channel' => 'carrier_pigeon' ) ),
			WP_MCP_AI_CRM_Pre_Send_Gate::REASON_UNKNOWN_CHANNEL
		);
	}

	/**
	 * The kill switch is honoured.
	 */
	public function test_filter_can_disable_gate() {
		add_filter( 'wp_mcp_ai_crm_gate_enabled', '__return_false' );
		$this->assertTrue(
			WP_MCP_AI_CRM_Pre_Send_Gate::check(
				$this->payload( array( 'body' => 'Act now {{first_name}}' ) )
			)
		);
		remove_filter( 'wp_mcp_ai_crm_gate_enabled', '__return_false' );
	}

	/**
	 * Preview returns reasons and warnings without side effects.
	 */
	public function test_preview_reports_without_side_effects() {
		$preview = WP_MCP_AI_CRM_Pre_Send_Gate::preview(
			$this->payload( array( 'body' => 'Act now' ) )
		);
		$this->assertFalse( $preview['ok'] );
		$this->assertNotEmpty( $preview['reasons'] );
		$this->assertSame( 'jane@acme.test', $preview['normalized']['to'] );
	}
}

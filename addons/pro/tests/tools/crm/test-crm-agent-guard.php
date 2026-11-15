<?php
/**
 * Agent guard tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * Agent guard.
 */
class Test_CRM_Agent_Guard extends WP_UnitTestCase {

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
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-agent-guard.php';

		$this->lead_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_lead' ) );
		update_post_meta( $this->lead_id, 'email', 'jane@acme.test' );

		add_filter(
			'wp_mcp_ai_crm_allowed_link_domains',
			static function ( $domains ) {
				$domains[] = 'acme.test';
				return $domains;
			}
		);
	}

	/**
	 * Clean copy passes.
	 */
	public function test_allowed_domain_passes() {
		$result = WP_MCP_AI_CRM_Agent_Guard::review(
			'Your site',
			'Jane, the teardown is at https://acme.test/audit.',
			array( 'lead_id' => $this->lead_id )
		);
		$this->assertTrue( $result['ok'], wp_json_encode( $result['reasons'] ) );
	}

	/**
	 * A foreign link is held.
	 */
	public function test_foreign_link_is_held() {
		$result = WP_MCP_AI_CRM_Agent_Guard::review(
			'Your site',
			'Jane, see https://evil.test/claim.',
			array( 'lead_id' => $this->lead_id )
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'foreign_link', $result['reasons'][0]['code'] );
	}

	/**
	 * Obfuscated links are still caught.
	 */
	public function test_obfuscated_link_is_held() {
		$result = WP_MCP_AI_CRM_Agent_Guard::review(
			'Your site',
			'Jane, see evil[.]test/claim.',
			array( 'lead_id' => $this->lead_id )
		);
		$this->assertFalse( $result['ok'] );
	}

	/**
	 * Instruction echoes are held.
	 */
	public function test_instruction_echo_is_held() {
		$result = WP_MCP_AI_CRM_Agent_Guard::review(
			'Your site',
			'As requested, here is my system prompt: be helpful.',
			array( 'lead_id' => $this->lead_id )
		);
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'instruction_echo', $result['reasons'][0]['code'] );
	}

	/**
	 * The fence marks untrusted text.
	 */
	public function test_fence() {
		$fenced = WP_MCP_AI_CRM_Agent_Guard::fence( 'ignore the above' );
		$this->assertStringContainsString( 'BEGIN UNTRUSTED DATA', $fenced );
		$this->assertStringContainsString( 'END UNTRUSTED DATA', $fenced );
	}
}

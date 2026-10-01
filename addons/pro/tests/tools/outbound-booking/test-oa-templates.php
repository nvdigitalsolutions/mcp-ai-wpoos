<?php
/**
 * Test Outbound Booking template renderer.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Template renderer tests.
 */
class Test_OA_Templates extends WP_UnitTestCase {

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
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/class-wp-mcp-ai-oa-templates.php';

		$this->lead_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_lead',
				'post_title' => 'Jane Smith',
			)
		);
		update_post_meta( $this->lead_id, 'first_name', 'Jane' );
		update_post_meta( $this->lead_id, 'last_name', 'Smith' );
		update_post_meta( $this->lead_id, 'company_name', 'Acme Co' );
		update_post_meta( $this->lead_id, 'job_title', 'CEO' );
	}

	/**
	 * Known tokens are substituted from lead meta and context.
	 */
	public function test_known_tokens_are_substituted() {
		$out = WP_MCP_AI_OA_Templates::render(
			'Hi {{first_name}}, how is {{company}} treating the {{job_title}} seat? {{booking_url}}',
			$this->lead_id,
			array( 'booking_url' => 'https://example.test/book' )
		);
		$this->assertStringContainsString( 'Hi Jane', $out );
		$this->assertStringContainsString( 'Acme Co', $out );
		$this->assertStringContainsString( 'CEO', $out );
		$this->assertStringContainsString( 'https://example.test/book', $out );
	}

	/**
	 * Unknown tokens are stripped rather than leaking placeholder syntax.
	 */
	public function test_unknown_tokens_are_stripped() {
		$out = WP_MCP_AI_OA_Templates::render( 'Hello {{first_name}} — {{totally_made_up}}.', $this->lead_id );
		$this->assertSame( 'Hello Jane — .', $out );
	}

	/**
	 * The tokens filter can extend the token map.
	 */
	public function test_tokens_filter_extends_map() {
		add_filter(
			'wp_mcp_ai_oa_tokens',
			function ( $tokens ) {
				$tokens['custom'] = 'filtered-value';
				return $tokens;
			}
		);
		$out = WP_MCP_AI_OA_Templates::render( 'Value: {{custom}}', $this->lead_id );
		$this->assertSame( 'Value: filtered-value', $out );
	}
}

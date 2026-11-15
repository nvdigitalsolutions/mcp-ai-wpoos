<?php
/**
 * Unsubscribe tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * One-click unsubscribe.
 */
class Test_CRM_Unsubscribe extends WP_UnitTestCase {

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
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-consent.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-unsubscribe.php';

		$this->lead_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_lead' ) );
		update_post_meta( $this->lead_id, 'email', 'jane@acme.test' );

		delete_option( WP_MCP_AI_CRM_Unsubscribe::SECRET_OPTION );
		delete_option( 'wp_mcp_ai_crm_dnc_list' );
	}

	/**
	 * Token round-trip.
	 */
	public function test_token_round_trip() {
		$token  = WP_MCP_AI_CRM_Unsubscribe::generate_token( $this->lead_id, 'jane@acme.test', 'email' );
		$result = WP_MCP_AI_CRM_Unsubscribe::verify_token( $token );
		$this->assertIsArray( $result );
		$this->assertSame( $this->lead_id, $result['lead_id'] );
		$this->assertSame( 'jane@acme.test', $result['email'] );
	}

	/**
	 * Tampering is rejected.
	 */
	public function test_tampered_token_rejected() {
		$token  = WP_MCP_AI_CRM_Unsubscribe::generate_token( $this->lead_id, 'jane@acme.test', 'email' );
		$result = WP_MCP_AI_CRM_Unsubscribe::verify_token( $token . 'x' );
		$this->assertWPError( $result );
	}

	/**
	 * Revoking a token adds DNC and pauses the sequence.
	 */
	public function test_revoke_token() {
		$token  = WP_MCP_AI_CRM_Unsubscribe::generate_token( $this->lead_id, 'jane@acme.test', 'email' );
		$result = WP_MCP_AI_CRM_Unsubscribe::revoke_token( $token );
		$this->assertIsArray( $result );
		$this->assertSame( '1', get_post_meta( $this->lead_id, '_sequence_paused', true ) );
		$this->assertTrue( WP_MCP_AI_CRM_Engine::check_dnc( 'jane@acme.test', 'email' ) );
	}

	/**
	 * Headers are added for sequence email only.
	 */
	public function test_headers() {
		$headers = WP_MCP_AI_CRM_Unsubscribe::headers_for_lead( $this->lead_id, 'email', 'sequence' );
		$this->assertNotEmpty( $headers );
		$this->assertStringContainsString( 'List-Unsubscribe:', $headers[0] );
		$this->assertSame( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers[1] );

		$this->assertSame( array(), WP_MCP_AI_CRM_Unsubscribe::headers_for_lead( $this->lead_id, 'email', 'transactional' ) );
	}

	/**
	 * The RFC 8058 POST handler revokes.
	 */
	public function test_post_handler() {
		$token   = WP_MCP_AI_CRM_Unsubscribe::generate_token( $this->lead_id, 'jane@acme.test', 'email' );
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/crm/unsubscribe' );
		$request->set_param( 'token', $token );

		$response = WP_MCP_AI_CRM_Unsubscribe::handle_post( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( WP_MCP_AI_CRM_Engine::check_dnc( 'jane@acme.test', 'email' ) );
	}
}

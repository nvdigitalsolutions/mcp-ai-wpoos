<?php
/**
 * Opt-out detector tests.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group crm
 * @group pro
 */

/**
 * Opt-out detector.
 */
class Test_CRM_Opt_Out_Detector extends WP_UnitTestCase {

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
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/crm/class-wp-mcp-ai-crm-opt-out-detector.php';
	}

	/**
	 * English opt-out.
	 */
	public function test_english_opt_out() {
		$result = WP_MCP_AI_CRM_Opt_Out_Detector::detect( 'No thanks, please remove me from this list.' );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'en', $result['lang'] );
	}

	/**
	 * Spanish opt-out.
	 */
	public function test_spanish_opt_out() {
		$result = WP_MCP_AI_CRM_Opt_Out_Detector::detect( 'BAJA' );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'es', $result['lang'] );
	}

	/**
	 * Hebrew opt-out.
	 */
	public function test_hebrew_opt_out() {
		$result = WP_MCP_AI_CRM_Opt_Out_Detector::detect( 'הסר' );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'he', $result['lang'] );
	}

	/**
	 * Quoted history is ignored.
	 */
	public function test_quoted_text_is_ignored() {
		$result = WP_MCP_AI_CRM_Opt_Out_Detector::detect( "Sounds good.\n> STOP\n> remove me" );
		$this->assertFalse( $result['matched'] );
	}

	/**
	 * A Spanish courtesy phrase is not an opt-out.
	 */
	public function test_spanish_exclusion() {
		$result = WP_MCP_AI_CRM_Opt_Out_Detector::detect( '¡Cómo no, gracias!' );
		$this->assertFalse( $result['matched'] );
	}

	/**
	 * Legal threat is flagged alongside the opt-out.
	 */
	public function test_legal_threat() {
		$result = WP_MCP_AI_CRM_Opt_Out_Detector::detect( 'STOP. My attorney will be in touch.' );
		$this->assertTrue( $result['matched'] );
		$this->assertTrue( $result['legal_threat'] );
	}

	/**
	 * Handling revokes consent and pauses the sequence.
	 */
	public function test_handle_revokes_and_pauses() {
		$lead_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_lead' ) );
		update_post_meta( $lead_id, 'email', 'jane@acme.test' );

		$result = WP_MCP_AI_CRM_Opt_Out_Detector::handle( $lead_id, 'No thanks.' );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( '1', get_post_meta( $lead_id, '_sequence_paused', true ) );
		$this->assertNotEmpty( get_post_meta( $lead_id, '_crm_opt_out_at', true ) );
	}
}

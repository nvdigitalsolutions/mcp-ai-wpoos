<?php
/**
 * Tests for the assistant fallback-chain meta wiring (Proposal 091, Track D).
 *
 * @package WP_MCP_AI
 */

/**
 * Fallback chain meta test case.
 */
class Test_Fallback_Chain_Meta extends WP_UnitTestCase {

	/**
	 * The fallback-chain meta round-trips into the assistant configuration,
	 * sanitized and de-duplicated.
	 *
	 * @return void
	 */
	public function test_fallback_chain_meta_round_trips_into_configuration() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Chain Assistant',
			)
		);

		update_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_FALLBACK_CHAIN, array( 'gemini', 'anthropic', 'gemini', 'Not A Slug!' ) );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );

		$this->assertSame( array( 'gemini', 'anthropic', 'notaslug' ), $config['fallback_chain'] );
	}

	/**
	 * Empty and malformed values sanitize to an empty chain.
	 *
	 * @return void
	 */
	public function test_fallback_chain_meta_sanitizes_garbage() {
		$this->assertSame( array(), WP_MCP_AI_Assistant_CPT::sanitize_fallback_chain_meta( 'not json' ) );
		$this->assertSame( array(), WP_MCP_AI_Assistant_CPT::sanitize_fallback_chain_meta( null ) );
		$this->assertSame( array( 'openai' ), WP_MCP_AI_Assistant_CPT::sanitize_fallback_chain_meta( array( 'openai' ) ) );
		$this->assertSame( array( 'gemini' ), WP_MCP_AI_Assistant_CPT::sanitize_fallback_chain_meta( wp_json_encode( array( 'gemini' ) ) ) );
	}
}

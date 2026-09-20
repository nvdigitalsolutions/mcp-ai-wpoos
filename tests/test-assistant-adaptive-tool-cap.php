<?php
/**
 * Tests for the per-assistant adaptive tool cap metadata.
 *
 * @package MCP_AI_WPooS
 */

/**
 * Test class for the per-assistant adaptive tool cap.
 */
class Test_Assistant_Adaptive_Tool_Cap extends WP_UnitTestCase {

	/**
	 * CPT instance under test.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	private $cpt;

	/**
	 * Set up: admin user + CPT instance.
	 */
	public function setUp(): void {
		parent::setUp();
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->cpt = new WP_MCP_AI_Assistant_CPT( WP_MCP_AI_Tool_Registry::get_instance() );
	}

	/**
	 * The meta sanitizer accepts only the three canonical states.
	 */
	public function test_sanitize_adaptive_tool_cap_meta_three_states() {
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_adaptive_tool_cap_meta( '' ) );
		$this->assertSame( 'on', WP_MCP_AI_Assistant_CPT::sanitize_adaptive_tool_cap_meta( 'on' ) );
		$this->assertSame( 'off', WP_MCP_AI_Assistant_CPT::sanitize_adaptive_tool_cap_meta( 'off' ) );
	}

	/**
	 * The sanitizer normalises casing and rejects unknown values.
	 */
	public function test_sanitize_adaptive_tool_cap_meta_rejects_unknown() {
		$this->assertSame( 'on', WP_MCP_AI_Assistant_CPT::sanitize_adaptive_tool_cap_meta( 'ON' ) );
		$this->assertSame( 'off', WP_MCP_AI_Assistant_CPT::sanitize_adaptive_tool_cap_meta( ' Off ' ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_adaptive_tool_cap_meta( 'sometimes' ) );
		$this->assertSame( '', WP_MCP_AI_Assistant_CPT::sanitize_adaptive_tool_cap_meta( 1 ) );
	}

	/**
	 * The meta key is registered with the sanitizer as its callback.
	 *
	 * The init hook already fired before this suite, so the registration
	 * function is re-invoked directly (the established suite pattern).
	 */
	public function test_meta_registered_with_sanitize_callback() {
		WP_MCP_AI_Assistant_CPT::register_meta();
		$keys = get_registered_meta_keys( 'post', 'mcp_ai_assistant' );

		$this->assertArrayHasKey( WP_MCP_AI_Assistant_CPT::META_ADAPTIVE_TOOL_CAP, $keys );
		$this->assertNotEmpty( $keys[ WP_MCP_AI_Assistant_CPT::META_ADAPTIVE_TOOL_CAP ]['sanitize_callback'] );
	}

	/**
	 * Saving with the defaults-metabox nonce persists an explicit "on" state
	 * and surfaces it in get_assistant_configuration().
	 */
	public function test_save_post_persists_on_state_and_config() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_assistant',
				'post_title' => 'Adaptive Cap Assistant',
			)
		);
		$post    = get_post( $post_id );

		$_POST['wp_mcp_ai_defaults_meta_nonce'] = wp_create_nonce( 'wp_mcp_ai_defaults_meta' );
		$_POST['wp_mcp_ai_adaptive_tool_cap']   = 'on';

		$this->cpt->save_post( $post_id, $post );

		$this->assertSame( 'on', get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_ADAPTIVE_TOOL_CAP, true ) );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post_id );
		$this->assertSame( 'on', $config['adaptive_tool_cap'] );
	}

	/**
	 * Saving the "Default" state removes the meta so the site setting applies.
	 */
	public function test_save_post_default_state_deletes_meta() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'mcp_ai_assistant',
				'post_title' => 'Default Cap Assistant',
			)
		);
		$post    = get_post( $post_id );

		update_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_ADAPTIVE_TOOL_CAP, 'on' );

		$_POST['wp_mcp_ai_defaults_meta_nonce'] = wp_create_nonce( 'wp_mcp_ai_defaults_meta' );
		$_POST['wp_mcp_ai_adaptive_tool_cap']   = '';

		$this->cpt->save_post( $post_id, $post );

		$this->assertSame( '', get_post_meta( $post_id, WP_MCP_AI_Assistant_CPT::META_ADAPTIVE_TOOL_CAP, true ) );
	}
}

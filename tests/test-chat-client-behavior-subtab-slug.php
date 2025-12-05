<?php
/**
 * Tests for Chat Client behavior subtab slug uniqueness.
 *
 * Verifies that the Chat Client section's behavior subtab has a unique slug
 * that doesn't conflict with the General section's behavior subtab.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that Chat Client and General sections have unique behavior subtab slugs.
 */
class WP_MCP_AI_Chat_Client_Behavior_Subtab_Slug_Test extends WP_UnitTestCase {

	/**
	 * Test that Chat Client section has behavior-chat-client subtab.
	 */
	public function test_chat_client_has_unique_behavior_subtab_slug() {
		$section       = new WP_MCP_AI_Section_Chat_Client();
		$subtab_groups = $this->get_subtab_groups_via_reflection( $section );

		// Verify the Chat Client section has 'behavior-chat-client' subtab.
		$this->assertArrayHasKey( 'behavior-chat-client', $subtab_groups, 'Chat Client section should have behavior-chat-client subtab' );
		$this->assertEquals( 'behavior-chat-client', $subtab_groups['behavior-chat-client']['id'], 'Subtab ID should be behavior-chat-client' );
		$this->assertEquals( 'Behavior', $subtab_groups['behavior-chat-client']['label'], 'Subtab label should be Behavior' );

		// Verify the Chat Client section does NOT have 'behavior' subtab.
		$this->assertArrayNotHasKey( 'behavior', $subtab_groups, 'Chat Client section should NOT have behavior subtab (should be behavior-chat-client)' );
	}

	/**
	 * Test that General section still has behavior subtab.
	 */
	public function test_general_section_still_has_behavior_subtab() {
		$section       = new WP_MCP_AI_Section_General();
		$subtab_groups = $this->get_subtab_groups_via_reflection( $section );

		// Verify the General section has 'behavior' subtab.
		$this->assertArrayHasKey( 'behavior', $subtab_groups, 'General section should have behavior subtab' );
		$this->assertEquals( 'behavior', $subtab_groups['behavior']['id'], 'Subtab ID should be behavior' );
		$this->assertEquals( 'Behavior & Limits', $subtab_groups['behavior']['label'], 'Subtab label should be Behavior & Limits' );
	}

	/**
	 * Test that Chat Client and General sections don't have conflicting subtab slugs.
	 */
	public function test_no_subtab_slug_conflicts_between_sections() {
		$chat_client_section = new WP_MCP_AI_Section_Chat_Client();
		$general_section     = new WP_MCP_AI_Section_General();

		$chat_client_subtabs = $this->get_subtab_groups_via_reflection( $chat_client_section );
		$general_subtabs     = $this->get_subtab_groups_via_reflection( $general_section );

		// Get all subtab IDs from both sections.
		$chat_client_ids = array_keys( $chat_client_subtabs );
		$general_ids     = array_keys( $general_subtabs );

		// Check for conflicts.
		$conflicts = array_intersect( $chat_client_ids, $general_ids );

		$this->assertEmpty( $conflicts, 'Chat Client and General sections should not have conflicting subtab slugs. Found conflicts: ' . implode( ', ', $conflicts ) );
	}

	/**
	 * Test that behavior-chat-client subtab contains expected fields.
	 */
	public function test_behavior_chat_client_subtab_has_expected_fields() {
		$section       = new WP_MCP_AI_Section_Chat_Client();
		$subtab_groups = $this->get_subtab_groups_via_reflection( $section );

		$this->assertArrayHasKey( 'behavior-chat-client', $subtab_groups );
		$this->assertArrayHasKey( 'fields', $subtab_groups['behavior-chat-client'] );

		$expected_fields = array(
			'chat_max_history_display',
			'chat_message_delay',
			'chat_enable_typing_indicator',
			'chat_auto_scroll',
			'chat_enable_markdown',
			'chat_enable_code_highlighting',
			'chat_persist_history',
			'chat_welcome_message',
			'chat_placeholder_text',
			'chat_send_button_text',
		);

		$actual_fields = $subtab_groups['behavior-chat-client']['fields'];

		$this->assertEquals( $expected_fields, $actual_fields, 'behavior-chat-client subtab should contain expected fields' );
	}

	/**
	 * Helper method to access protected get_subtab_groups method via reflection.
	 *
	 * @param object $section Section instance.
	 * @return array Subtab groups.
	 */
	private function get_subtab_groups_via_reflection( $section ) {
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );
		return $method->invoke( $section );
	}
}

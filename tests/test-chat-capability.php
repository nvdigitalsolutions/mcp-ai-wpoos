<?php
/**
 * Tests for wp_mcp_ai_get_required_chat_capability().
 */
class WP_MCP_AI_Chat_Capability_Test extends WP_UnitTestCase {

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_chat_capability' );
		parent::tearDown();
	}

	public function test_default_capability_is_edit_posts() {
		$this->assertSame( 'edit_posts', wp_mcp_ai_get_required_chat_capability() );
	}

	public function test_filter_allows_public_access() {
		add_filter(
			'wp_mcp_ai_chat_capability',
			static function () {
				return 'public';
			}
		);

		$this->assertSame( 'public', wp_mcp_ai_get_required_chat_capability( 123, 'rest' ) );
	}

	public function test_filter_value_is_sanitized() {
		add_filter(
			'wp_mcp_ai_chat_capability',
			static function () {
				return 'manage_options ';
			}
		);

		$this->assertSame( 'manage_options', wp_mcp_ai_get_required_chat_capability( 0, 'ShortCode' ) );
	}

	public function test_filter_can_disable_capability_check() {
		add_filter( 'wp_mcp_ai_chat_capability', '__return_false' );

		$this->assertFalse( wp_mcp_ai_get_required_chat_capability( 0, 'rest' ) );
	}
}

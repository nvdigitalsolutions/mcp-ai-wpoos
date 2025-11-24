<?php
/**
 * Tests covering assistant tool registrations and sanitization.
 */
class WP_MCP_AI_Assistant_Tools_Test extends WP_UnitTestCase {

	/**
	 * Ensure tool slugs are restricted to the registered tool list.
	 */
	public function test_sanitize_tools_meta_discards_unregistered_slugs() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$sanitized = WP_MCP_AI_Assistant_CPT::sanitize_tools_meta(
			array(
				'get_recent_posts',
				'invalid-tool',
				'GET_SITE_SUMMARY',
				'',
			)
		);

		$this->assertSame(
			array(
				'get_recent_posts',
				'get_site_summary',
			),
			$sanitized
		);
	}

	/**
	 * Ensure invalid input types do not trigger notices and return an empty array.
	 */
	public function test_sanitize_tools_meta_handles_non_array_values() {
		$this->assertSame(
			array(),
			WP_MCP_AI_Assistant_CPT::sanitize_tools_meta( null )
		);

		$this->assertSame(
			array(),
			WP_MCP_AI_Assistant_CPT::sanitize_tools_meta( 'get_recent_posts' )
		);
	}
}

<?php
/**
 * Test to verify that null subtabs don't create gaps in provider navigation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that embedded provider subtab doesn't create a gap when disabled.
 */
class WP_MCP_AI_Provider_Subtab_Null_Gap_Test extends WP_UnitTestCase {

	/**
	 * Test that get_subtab_groups filters out null values.
	 */
	public function test_subtab_groups_filters_out_null_values() {
		// Mock the base version scenario where embedded is null.
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			define( 'WP_MCP_AI_BASE_VERSION', true );
		}

		$section = new WP_MCP_AI_Section_Providers();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		// Verify no null values in the array.
		foreach ( $subtabs as $key => $subtab ) {
			$this->assertNotNull( $subtab, "Subtab '$key' should not be null" );
			$this->assertIsArray( $subtab, "Subtab '$key' should be an array" );
		}

		// Verify embedded is not in the list when base version is enabled.
		if ( WP_MCP_AI_BASE_VERSION ) {
			$this->assertArrayNotHasKey( 'embedded', $subtabs, 'Embedded subtab should not be present in base version' );
		}

		// Verify expected subtabs are present.
		$expected_subtabs = array( 'priority', 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio', 'huggingface', 'cloudflare', 'google_maps' );
		foreach ( $expected_subtabs as $expected ) {
			$this->assertArrayHasKey( $expected, $subtabs, "Expected subtab '$expected' should be present" );
		}
	}

	/**
	 * Test that lm_studio and huggingface are adjacent when embedded is null.
	 */
	public function test_lm_studio_and_huggingface_are_adjacent() {
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			define( 'WP_MCP_AI_BASE_VERSION', true );
		}

		$section = new WP_MCP_AI_Section_Providers();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );
		$keys    = array_keys( $subtabs );

		// Find positions of lm_studio and huggingface.
		$lm_studio_pos   = array_search( 'lm_studio', $keys, true );
		$huggingface_pos = array_search( 'huggingface', $keys, true );

		$this->assertNotFalse( $lm_studio_pos, 'lm_studio should be in subtabs' );
		$this->assertNotFalse( $huggingface_pos, 'huggingface should be in subtabs' );

		// In base version, they should be adjacent (no gap from null embedded).
		if ( WP_MCP_AI_BASE_VERSION ) {
			$this->assertEquals(
				$huggingface_pos,
				$lm_studio_pos + 1,
				'Hugging Face should immediately follow LM Studio (no gap)'
			);
		}
	}

	/**
	 * Test that array has no gaps (sequential array keys).
	 */
	public function test_subtab_array_has_no_gaps() {
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
			define( 'WP_MCP_AI_BASE_VERSION', true );
		}

		$section = new WP_MCP_AI_Section_Providers();

		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		$subtabs = $method->invoke( $section );

		// array_filter preserves keys, so we should have non-sequential numeric keys.
		// But for subtabs (string keys), this should be fine.
		// What matters is that there are no null values.
		$null_count = 0;
		foreach ( $subtabs as $subtab ) {
			if ( is_null( $subtab ) ) {
				++$null_count;
			}
		}

		$this->assertEquals( 0, $null_count, 'There should be no null values in subtabs array' );
	}
}

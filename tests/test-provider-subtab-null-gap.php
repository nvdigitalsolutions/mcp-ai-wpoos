<?php
/**
 * Test to verify that null subtabs don't create gaps in provider navigation.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test that embedded provider subtab doesn't create a gap when disabled.
 */
class WP_MCP_AI_Provider_Subtab_Null_Gap_Test extends WP_UnitTestCase {

	/**
	 * Test that get_subtab_groups filters out null values.
	 *
	 * NOTE: this suite must NOT define the WP_MCP_AI_BASE_VERSION constant —
	 * constants cannot be undefined and would flip every later suite into
	 * base-version mode for the rest of the process.
	 */
	public function test_subtab_groups_filters_out_null_values() {
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

		// The embedded subtab is contributed by the Pro Providers section when
		// that section has been loaded; the base section must never emit a
		// null entry in its place (covered by the loop above).

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

		// When the Pro providers section is not loaded the embedded subtab is
		// absent, so lm_studio and huggingface must sit next to each other.
		if ( ! isset( $subtabs['embedded'] ) ) {
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

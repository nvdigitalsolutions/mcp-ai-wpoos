<?php
/**
 * Tests for the General section's SSE background hand-off setting.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Guards the General section's sub-tab registration contract.
 *
 * A field is only rendered — and only saved — when it is listed in one of the
 * section's sub-tab groups: `WP_MCP_AI_Settings_Section::render()` iterates the
 * active group's `fields`, and `sanitize_with_subtabs()` whitelists input to
 * that same list. A field defined in `get_fields()` but missing from every
 * group is therefore invisible in the admin and silently dropped on save.
 */
class Test_General_Section_SSE_Offload_Setting extends WP_UnitTestCase {

	/**
	 * Read the section's sub-tab groups through reflection.
	 *
	 * @return array
	 */
	private function get_subtab_groups() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Section_General' );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		return $method->invoke( new WP_MCP_AI_Section_General() );
	}

	/**
	 * The field is defined with the documented default.
	 */
	public function test_field_is_defined_with_expected_default() {
		$section = new WP_MCP_AI_Section_General();
		$fields  = $section->get_fields();

		$this->assertArrayHasKey( 'sse_offload_elapsed_seconds', $fields );
		$this->assertSame( 'number', $fields['sse_offload_elapsed_seconds']['type'] );
		$this->assertSame( 110, $fields['sse_offload_elapsed_seconds']['default'] );
	}

	/**
	 * The field is registered in the Behavior & Limits sub-tab, next to the
	 * provider Request Timeout it is deliberately independent of.
	 */
	public function test_field_is_registered_in_the_behavior_subtab() {
		$groups = $this->get_subtab_groups();

		$this->assertArrayHasKey( 'behavior', $groups );
		$this->assertContains( 'sse_offload_elapsed_seconds', $groups['behavior']['fields'] );
	}

	/**
	 * Every field the section defines is reachable in the admin UI.
	 *
	 * Regression guard: `sse_offload_elapsed_seconds` once shipped defined but
	 * unregistered, so it rendered nowhere and could not be saved.
	 */
	public function test_every_field_is_registered_in_exactly_one_subtab() {
		$section = new WP_MCP_AI_Section_General();
		$fields  = array_keys( $section->get_fields() );
		$groups  = $this->get_subtab_groups();

		$registered = array();
		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field_key ) {
				$this->assertContains(
					$field_key,
					$fields,
					sprintf( 'Sub-tab "%s" lists "%s", which the section does not define.', $group['id'], $field_key )
				);
				$registered[] = $field_key;
			}
		}

		$orphans    = array_values( array_diff( $fields, $registered ) );
		$counts     = array_count_values( $registered );
		$duplicates = array();
		foreach ( $counts as $field_key => $count ) {
			if ( $count > 1 ) {
				$duplicates[] = $field_key;
			}
		}

		$this->assertSame( array(), $orphans, 'Every setting must appear in a sub-tab, or it renders nowhere.' );
		$this->assertSame( array(), $duplicates, 'A setting must appear in exactly one sub-tab.' );
	}

	/**
	 * Zero stays valid because it disables hand-off; an out-of-range value is
	 * rejected rather than stored.
	 */
	public function test_validation_accepts_zero_and_rejects_out_of_range_values() {
		$section = new WP_MCP_AI_Section_General();

		$valid = $section->validate( array( 'sse_offload_elapsed_seconds' => 0 ) );
		$this->assertNotWPError( $valid );

		$invalid = $section->validate( array( 'sse_offload_elapsed_seconds' => 601 ) );
		$this->assertWPError( $invalid );
	}
}

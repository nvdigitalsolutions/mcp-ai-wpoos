<?php
/**
 * Tests for the Security section's sub-tab headings.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Pins heading registration and markup for the Security section.
 *
 * The 22 `_heading_*` separators were defined but listed in no sub-tab, and the
 * shared renderer had no `heading` case, so every Security sub-tab rendered as
 * one undifferentiated list of fields. Both halves are asserted here.
 */
class Test_Security_Section_Headings extends WP_UnitTestCase {

	/**
	 * Read the section's sub-tab groups through reflection.
	 *
	 * @return array
	 */
	private function get_subtab_groups() {
		$section    = new WP_MCP_AI_Section_Security();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'get_subtab_groups' );
		$method->setAccessible( true );

		return $method->invoke( $section );
	}

	/**
	 * Every heading the section defines is registered in exactly one sub-tab.
	 */
	public function test_every_heading_is_registered_in_one_subtab() {
		$section  = new WP_MCP_AI_Section_Security();
		$fields   = $section->get_fields();
		$headings = array();

		foreach ( $fields as $key => $field ) {
			if ( isset( $field['type'] ) && 'heading' === $field['type'] ) {
				$headings[] = $key;
			}
		}

		$this->assertNotEmpty( $headings, 'The Security section defines heading fields.' );

		$registered = array();
		foreach ( $this->get_subtab_groups() as $group ) {
			foreach ( $group['fields'] as $key ) {
				$registered[] = $key;
			}
		}

		foreach ( $headings as $key ) {
			$this->assertContains(
				$key,
				$registered,
				sprintf( 'Heading "%s" is in no sub-tab, so it renders nowhere.', $key )
			);
			$this->assertSame(
				1,
				count( array_keys( $registered, $key, true ) ),
				sprintf( 'Heading "%s" must appear in exactly one sub-tab.', $key )
			);
		}
	}

	/**
	 * A heading renders an <h3> between two form tables, and no field row.
	 */
	public function test_heading_renders_an_h3_between_tables() {
		$section    = new WP_MCP_AI_Section_Security();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'render_heading_field' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			$section,
			array(
				'type'  => 'heading',
				'label' => 'Global Access Control',
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( '</table>', $html );
		$this->assertStringContainsString( '<h3 class="wp-mcp-ai-section-subheading">Global Access Control</h3>', $html );
		$this->assertStringContainsString( '<table class="form-table" role="presentation">', $html );
		$this->assertStringNotContainsString( '<tr>', $html, 'A heading must not emit a field row.' );
	}

	/**
	 * The type check used by render() matches headings only.
	 */
	public function test_is_heading_field_matches_only_heading_type() {
		$section    = new WP_MCP_AI_Section_Security();
		$reflection = new ReflectionClass( $section );
		$method     = $reflection->getMethod( 'is_heading_field' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $section, array( 'type' => 'heading' ) ) );
		$this->assertFalse( $method->invoke( $section, array( 'type' => 'text' ) ) );
		$this->assertFalse( $method->invoke( $section, array() ) );
	}
}

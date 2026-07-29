<?php
/**
 * Tests for WP_MCP_AI_Trait_Validated_Upload — SVG sanitization and MIME validation.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Validated upload test suite.
 *
 * @group security
 * @group upload
 * @group svg
 */
class WP_MCP_AI_Validated_Upload_Tests extends WP_UnitTestCase {

	/**
	 * Get a test instance that uses the trait.
	 *
	 * @return object
	 */
	private function get_test_instance() {
		return new class {
			use WP_MCP_AI_Trait_Validated_Upload;

			// Expose protected methods for testing.
			public function test_svg_sanitize( $svg ) {
				return $this->sanitize_svg_content( $svg );
			}

			public function test_upload( $name, $mime, $bits ) {
				return $this->validated_upload_bits( $name, $mime, $bits );
			}
		};
	}

	/**
	 * Test that script tags are stripped from SVG.
	 */
	public function test_svg_strips_script_tags() {
		$instance = $this->get_test_instance();

		$svg  = '<svg xmlns="http://www.w3.org/2000/svg">';
		$svg .= '<script>alert("xss")</script>';
		$svg .= '<circle cx="50" cy="50" r="40" />';
		$svg .= '</svg>';

		$sanitized = $instance->test_svg_sanitize( $svg );

		$this->assertStringNotContainsString( '<script>', $sanitized, 'Script tags should be stripped.' );
		$this->assertStringNotContainsString( 'alert', $sanitized, 'Script content should be removed.' );
		$this->assertStringContainsString( '<circle', $sanitized, 'Safe SVG elements should remain.' );
	}

	/**
	 * Test that onclick event handlers are stripped.
	 */
	public function test_svg_strips_event_handlers() {
		$instance = $this->get_test_instance();

		$svg = '<svg xmlns="http://www.w3.org/2000/svg">'
			. '<circle cx="50" cy="50" r="40" onclick="alert(1)" onload="evil()" />'
			. '</svg>';

		$sanitized = $instance->test_svg_sanitize( $svg );

		$this->assertStringNotContainsString( 'onclick', $sanitized, 'onclick should be stripped.' );
		$this->assertStringNotContainsString( 'onload', $sanitized, 'onload should be stripped.' );
		$this->assertStringContainsString( '<circle', $sanitized, 'Safe elements should remain.' );
	}

	/**
	 * Test that foreignObject is stripped.
	 */
	public function test_svg_strips_foreign_object() {
		$instance = $this->get_test_instance();

		$svg = '<svg xmlns="http://www.w3.org/2000/svg">'
			. '<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject>'
			. '<rect width="100" height="100" />'
			. '</svg>';

		$sanitized = $instance->test_svg_sanitize( $svg );

		$this->assertStringNotContainsString( 'foreignObject', $sanitized, 'foreignObject should be stripped.' );
		$this->assertStringContainsString( '<rect', $sanitized, 'Safe elements should remain.' );
	}

	/**
	 * Test that javascript: URIs in href are stripped.
	 */
	public function test_svg_strips_javascript_uris() {
		$instance = $this->get_test_instance();

		$svg = '<svg xmlns="http://www.w3.org/2000/svg">'
			. '<a href="javascript:alert(1)"><text>Click</text></a>'
			. '</svg>';

		$sanitized = $instance->test_svg_sanitize( $svg );

		$this->assertStringNotContainsString( 'javascript:', $sanitized, 'javascript: URIs should be stripped.' );
	}

	/**
	 * Test that safe SVG passes through.
	 */
	public function test_svg_passes_safe_content() {
		$instance = $this->get_test_instance();

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
			. '<circle cx="50" cy="50" r="40" fill="blue" />'
			. '<text x="50" y="55" text-anchor="middle" fill="white">OK</text>'
			. '</svg>';

		$sanitized = $instance->test_svg_sanitize( $svg );

		$this->assertStringContainsString( '<circle', $sanitized );
		$this->assertStringContainsString( '<text', $sanitized );
		$this->assertStringContainsString( 'fill="blue"', $sanitized );
	}

	/**
	 * Test that empty SVG returns error.
	 */
	public function test_svg_rejects_empty() {
		$instance = $this->get_test_instance();
		$result   = $instance->test_svg_sanitize( '' );

		$this->assertWPError( $result, 'Empty SVG should return error.' );
	}

	/**
	 * Test that non-SVG content returns error.
	 */
	public function test_svg_rejects_non_svg() {
		$instance = $this->get_test_instance();
		$result   = $instance->test_svg_sanitize( '<div>not svg</div>' );

		$this->assertWPError( $result, 'Non-SVG content should return error.' );
	}

	/**
	 * Test that CDATA sections containing script are removed.
	 */
	public function test_svg_strips_cdata_script() {
		$instance = $this->get_test_instance();

		$svg = '<svg xmlns="http://www.w3.org/2000/svg">'
			. '<![CDATA[<script>alert(1)</script>]]>'
			. '<rect width="100" height="100" />'
			. '</svg>';

		$sanitized = $instance->test_svg_sanitize( $svg );

		$this->assertStringNotContainsString( 'CDATA', $sanitized, 'CDATA should be stripped.' );
		$this->assertStringNotContainsString( 'alert', $sanitized, 'Script in CDATA should be removed.' );
	}
}

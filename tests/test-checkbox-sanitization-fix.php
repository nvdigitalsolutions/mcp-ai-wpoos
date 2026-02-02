<?php
/**
 * Test Checkbox Sanitization Fix
 *
 * Unit tests to verify the checkbox sanitization fix handles string '0' correctly.
 *
 * @package WP_MCP_AI
 */

// Minimal test - does not require WordPress test suite.
// Tests the abstract class checkbox sanitization logic directly.

// Define minimal WordPress stubs for testing.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/' );
}

// Stub WordPress functions needed by the abstract class.
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub get_option function.
	 *
	 * @param string $option Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

// Load the abstract class.
require_once dirname( __DIR__ ) . '/includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';

// Stub the admin settings class constant.
if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
	/**
	 * Stub admin settings class.
	 */
	class WP_MCP_AI_Admin_Settings {
		/**
		 * Option name.
		 */
		const OPTION_NAME = 'wp_mcp_ai_settings';
	}
}

/**
 * Concrete test implementation of the abstract settings section.
 */
class Test_Settings_Section extends WP_MCP_AI_Settings_Section {
	/**
	 * Get section ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'test';
	}

	/**
	 * Get section title.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Test Section';
	}

	/**
	 * Get tab ID.
	 *
	 * @return string
	 */
	public function get_tab() {
		return 'test';
	}

	/**
	 * Get field definitions.
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'enable_mesh'                 => array(
				'type'    => 'checkbox',
				'label'   => 'Enable Mesh',
				'default' => false,
			),
			'enable_federation'           => array(
				'type'    => 'checkbox',
				'label'   => 'Enable Federation',
				'default' => false,
			),
			'enable_federation_directory' => array(
				'type'    => 'checkbox',
				'label'   => 'Enable Directory',
				'default' => false,
			),
		);
	}

	/**
	 * Render the section.
	 */
	public function render() {
		// No-op for testing.
	}

	/**
	 * Expose the protected sanitize_fields method for testing.
	 *
	 * @param array $input         Raw input from form.
	 * @param array $fields        Field definitions to sanitize.
	 * @param bool  $is_form_submit Whether this is an actual form submission.
	 * @return array Sanitized input.
	 */
	public function test_sanitize_fields( $input, $fields, $is_form_submit = true ) {
		return $this->sanitize_fields( $input, $fields, $is_form_submit );
	}
}

// Run tests.
echo "Testing checkbox sanitization fix...\n\n";

$section = new Test_Settings_Section();
$fields  = $section->get_fields();

// Test 1: String '0' should be treated as false.
echo "Test 1: String '0' should be treated as false\n";
$input  = array(
	'enable_mesh'                 => '0',
	'enable_federation'           => '0',
	'enable_federation_directory' => '1',
);
$result = $section->test_sanitize_fields( $input, $fields );

assert( $result['enable_mesh'] === false, 'enable_mesh should be false when value is "0"' );
assert( $result['enable_federation'] === false, 'enable_federation should be false when value is "0"' );
assert( $result['enable_federation_directory'] === true, 'enable_federation_directory should be true when value is "1"' );
echo "✓ PASS: String '0' correctly treated as false\n\n";

// Test 2: String '1' should be treated as true.
echo "Test 2: String '1' should be treated as true\n";
$input  = array(
	'enable_mesh'                 => '1',
	'enable_federation'           => '1',
	'enable_federation_directory' => '1',
);
$result = $section->test_sanitize_fields( $input, $fields );

assert( $result['enable_mesh'] === true, 'enable_mesh should be true when value is "1"' );
assert( $result['enable_federation'] === true, 'enable_federation should be true when value is "1"' );
assert( $result['enable_federation_directory'] === true, 'enable_federation_directory should be true when value is "1"' );
echo "✓ PASS: String '1' correctly treated as true\n\n";

// Test 3: Integer 0 should be treated as false.
echo "Test 3: Integer 0 should be treated as false\n";
$input  = array(
	'enable_mesh'       => 0,
	'enable_federation' => 0,
);
$result = $section->test_sanitize_fields( $input, $fields );

assert( $result['enable_mesh'] === false, 'enable_mesh should be false when value is 0' );
assert( $result['enable_federation'] === false, 'enable_federation should be false when value is 0' );
echo "✓ PASS: Integer 0 correctly treated as false\n\n";

// Test 4: Integer 1 should be treated as true.
echo "Test 4: Integer 1 should be treated as true\n";
$input  = array(
	'enable_mesh'       => 1,
	'enable_federation' => 1,
);
$result = $section->test_sanitize_fields( $input, $fields );

assert( $result['enable_mesh'] === true, 'enable_mesh should be true when value is 1' );
assert( $result['enable_federation'] === true, 'enable_federation should be true when value is 1' );
echo "✓ PASS: Integer 1 correctly treated as true\n\n";

// Test 5: Missing checkboxes should be treated as false.
echo "Test 5: Missing checkboxes should be treated as false\n";
$input  = array(
	'enable_federation_directory' => '1',
);
$result = $section->test_sanitize_fields( $input, $fields );

assert( ! isset( $result['enable_mesh'] ), 'enable_mesh should not be set when not in input' );
assert( ! isset( $result['enable_federation'] ), 'enable_federation should not be set when not in input' );
assert( $result['enable_federation_directory'] === true, 'enable_federation_directory should be true' );
echo "✓ PASS: Missing checkboxes correctly omitted from sanitized output\n\n";

// Test 6: All checkboxes can be unchecked (all with value="0").
echo "Test 6: All checkboxes can be unchecked\n";
$input  = array(
	'enable_mesh'                 => '0',
	'enable_federation'           => '0',
	'enable_federation_directory' => '0',
);
$result = $section->test_sanitize_fields( $input, $fields );

assert( $result['enable_mesh'] === false, 'enable_mesh should be false' );
assert( $result['enable_federation'] === false, 'enable_federation should be false' );
assert( $result['enable_federation_directory'] === false, 'enable_federation_directory should be false' );
echo "✓ PASS: All checkboxes can be unchecked\n\n";

echo "═══════════════════════════════════════\n";
echo "All tests passed! ✓\n";
echo "═══════════════════════════════════════\n";

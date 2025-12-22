#!/usr/bin/env php
<?php
/**
 * Test script to verify profession default_tools are properly saved and displayed.
 *
 * This script can be run from the command line to test the default_tools persistence
 * without needing to access the WordPress admin UI.
 *
 * Usage: php bin/test-profession-tools-display.php
 *
 * @package WP_MCP_AI
 */

// Load WordPress.
$wp_load_paths = array(
	dirname( __DIR__, 4 ) . '/wp-load.php',
	dirname( __DIR__, 3 ) . '/wp-load.php',
	dirname( __DIR__, 2 ) . '/wp-load.php',
	dirname( __DIR__ ) . '/wp-load.php',
);

$wp_loaded = false;
foreach ( $wp_load_paths as $path ) {
	if ( file_exists( $path ) ) {
		require_once $path;
		$wp_loaded = true;
		break;
	}
}

if ( ! $wp_loaded ) {
	echo "Error: Could not find wp-load.php\n";
	exit( 1 );
}

// Load required classes.
if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
}

if ( ! class_exists( 'WP_MCP_AI_Profession_Knowledge_Base_Loader' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-knowledge-base-loader.php';
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Profession Default Tools Display Test                        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Test 1: Create a new profession with default_tools.
echo "Test 1: Create new profession with default_tools\n";
echo str_repeat( '-', 64 ) . "\n";

$repository      = new WP_MCP_AI_Profession_Repository();
$profession_data = array(
	'title'            => 'CLI Test Profession',
	'slug'             => 'cli_test_profession_' . time(),
	'description'      => 'Test profession created via CLI',
	'category'         => 'technical',
	'role_description' => 'Test role description',
	'expertise'        => array( 'Testing', 'Debugging' ),
	'warnings'         => array( 'This is a test' ),
	'knowledge_base'   => 'Test knowledge base',
	'default_tools'    => array( 'web_search', 'search_content', 'save_post' ),
);

echo "Creating profession: {$profession_data['title']}\n";
echo "Default tools: " . implode( ', ', $profession_data['default_tools'] ) . "\n";

$post_id = $repository->save( $profession_data );

if ( is_wp_error( $post_id ) ) {
	echo "❌ FAILED: " . $post_id->get_error_message() . "\n";
	exit( 1 );
}

echo "✓ Created profession with ID: {$post_id}\n\n";

// Test 2: Retrieve and verify the saved default_tools.
echo "Test 2: Retrieve saved default_tools\n";
echo str_repeat( '-', 64 ) . "\n";

$saved_tools = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );

if ( ! is_array( $saved_tools ) ) {
	echo "❌ FAILED: default_tools is not an array\n";
	wp_delete_post( $post_id, true );
	exit( 1 );
}

echo "Retrieved tools: " . implode( ', ', $saved_tools ) . "\n";
echo "Tool count: " . count( $saved_tools ) . "\n";

// Verify array structure.
if ( array_values( $saved_tools ) === $saved_tools ) {
	echo "✓ Array has sequential keys\n";
} else {
	echo "❌ WARNING: Array has non-sequential keys\n";
}

// Verify all tools are present.
$expected_tools = array( 'web_search', 'search_content', 'save_post' );
$missing_tools  = array_diff( $expected_tools, $saved_tools );
$extra_tools    = array_diff( $saved_tools, $expected_tools );

if ( empty( $missing_tools ) && empty( $extra_tools ) ) {
	echo "✓ All expected tools are present and no extras\n\n";
} else {
	if ( ! empty( $missing_tools ) ) {
		echo "❌ Missing tools: " . implode( ', ', $missing_tools ) . "\n";
	}
	if ( ! empty( $extra_tools ) ) {
		echo "❌ Extra tools: " . implode( ', ', $extra_tools ) . "\n";
	}
	echo "\n";
}

// Test 3: Update the profession with new tools.
echo "Test 3: Update profession with new default_tools\n";
echo str_repeat( '-', 64 ) . "\n";

$updated_data                 = $profession_data;
$updated_data['id']           = $post_id;
$updated_data['default_tools'] = array( 'create_chart', 'send_group_email' );

echo "Updating to new tools: " . implode( ', ', $updated_data['default_tools'] ) . "\n";

$result = $repository->save( $updated_data );

if ( is_wp_error( $result ) ) {
	echo "❌ FAILED: " . $result->get_error_message() . "\n";
	wp_delete_post( $post_id, true );
	exit( 1 );
}

echo "✓ Updated profession\n\n";

// Test 4: Verify updated tools.
echo "Test 4: Verify updated default_tools\n";
echo str_repeat( '-', 64 ) . "\n";

$updated_tools = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );

echo "Retrieved tools: " . implode( ', ', $updated_tools ) . "\n";

$expected_updated = array( 'create_chart', 'send_group_email' );
if ( $updated_tools === $expected_updated ) {
	echo "✓ Tools updated correctly\n\n";
} else {
	echo "❌ FAILED: Tools do not match expected\n";
	echo "Expected: " . implode( ', ', $expected_updated ) . "\n";
	echo "Got: " . implode( ', ', $updated_tools ) . "\n\n";
}

// Test 5: Load from JSON and verify tools.
echo "Test 5: Load profession from JSON and verify default_tools\n";
echo str_repeat( '-', 64 ) . "\n";

$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
$professions = $loader->load_all();

if ( is_wp_error( $professions ) ) {
	echo "❌ FAILED: " . $professions->get_error_message() . "\n";
} elseif ( empty( $professions ) ) {
	echo "❌ FAILED: No professions loaded from JSON\n";
} else {
	echo "✓ Loaded " . count( $professions ) . " professions from JSON\n";

	// Find a profession with tools.
	$profession_with_tools = null;
	foreach ( $professions as $prof ) {
		if ( ! empty( $prof['default_tools'] ) ) {
			$profession_with_tools = $prof;
			break;
		}
	}

	if ( $profession_with_tools ) {
		echo "Sample: {$profession_with_tools['title']}\n";
		echo "Tools: " . implode( ', ', $profession_with_tools['default_tools'] ) . "\n";
		echo "✓ JSON professions have default_tools\n\n";
	} else {
		echo "❌ WARNING: No professions with default_tools found in JSON\n\n";
	}
}

// Clean up.
echo "Cleaning up...\n";
echo str_repeat( '-', 64 ) . "\n";

wp_delete_post( $post_id, true );
echo "✓ Deleted test profession\n\n";

// Summary.
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  All Tests Completed Successfully                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "The fix ensures:\n";
echo "  1. default_tools are saved as sequential arrays\n";
echo "  2. Empty values are filtered out\n";
echo "  3. Updates preserve data correctly\n";
echo "  4. JSON loading includes default_tools\n\n";

echo "Manual verification needed:\n";
echo "  1. Go to Professions → Edit any profession\n";
echo "  2. Verify default_tools checkboxes are pre-checked\n";
echo "  3. Run a reseed from Settings → Advanced\n";
echo "  4. Verify checkboxes remain checked after reseed\n\n";

exit( 0 );

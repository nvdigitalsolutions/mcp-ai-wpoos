<?php
/**
 * Test script for Toolkit Registry.
 *
 * Run with: wp eval-file test-toolkit-registry.php
 *
 * @package NV_oOS
 */

// Load WordPress.
require_once __DIR__ . '/../../../wp-load.php';

// Load toolkit registry.
require_once __DIR__ . '/includes/class-wp-mcp-ai-toolkit-registry.php';

// Get registry instance.
$toolkit_registry = WP_MCP_AI_Toolkit_Registry::get_instance();

echo "=== Toolkit Registry Test ===\n\n";

// Test 1: List all toolkits.
echo "1. All Toolkits:\n";
$toolkits = $toolkit_registry->get_toolkits();
foreach ( $toolkits as $slug => $toolkit ) {
echo "   - {$slug}: {$toolkit['name']} ({$toolkit['primary_pattern']})\n";
}
echo "\n";

// Test 2: Get toolkit stats.
echo "2. Toolkit Statistics:\n";
$stats = $toolkit_registry->get_toolkit_stats();
foreach ( $stats as $slug => $stat ) {
echo "   - {$stat['name']}: {$stat['tool_count']} tools\n";
}
echo "\n";

// Test 3: Get coverage report.
echo "3. Coverage Report:\n";
$coverage = $toolkit_registry->get_coverage_report();
echo "   Total Tools: {$coverage['total_tools']}\n";
echo "   Mapped Tools: {$coverage['mapped_tools']}\n";
echo "   Unmapped Tools: {$coverage['unmapped_tools']}\n";
echo "   Coverage: {$coverage['coverage_percent']}%\n";
echo "\n";

// Test 4: Get tools for specific toolkit.
echo "4. Tools in 'content_publishing' toolkit:\n";
$content_tools = $toolkit_registry->get_toolkit_tools( 'content_publishing' );
foreach ( $content_tools as $tool_slug ) {
echo "   - {$tool_slug}\n";
}
echo "\n";

// Test 5: Get tools by profession.
echo "5. Tools for 'writer' profession:\n";
$writer_tools = $toolkit_registry->get_tools_by_profession( 'writer' );
foreach ( $writer_tools as $tool_slug ) {
echo "   - {$tool_slug}\n";
}
echo "\n";

// Test 6: Get unmapped tools.
echo "6. Unmapped Tools (first 10):\n";
$unmapped        = $toolkit_registry->get_unmapped_tools();
$unmapped_sample = array_slice( $unmapped, 0, 10 );
foreach ( $unmapped_sample as $tool_slug ) {
echo "   - {$tool_slug}\n";
}
echo '   ... and ' . ( count( $unmapped ) - 10 ) . " more\n";
echo "\n";

echo "=== Test Complete ===\n";

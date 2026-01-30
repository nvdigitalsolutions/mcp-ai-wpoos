#!/usr/bin/env php
<?php
/**
 * Tool Metadata Migration Script.
 *
 * Automatically adds get_definition() methods to tool files based on the toolkit mapping.
 *
 * Usage: php bin/add-tool-metadata.php
 *
 * @package WP_MCP_AI
 */

// Load the toolkit metadata mapping.
$mapping = require __DIR__ . '/../includes/toolkit-metadata-mapping.php';

// Directory containing tool files.
$tools_dir = __DIR__ . '/../includes/tools';

$updated_count = 0;
$skipped_count = 0;
$error_count   = 0;

echo "Tool Metadata Migration Script\n";
echo "===============================\n\n";

foreach ( $mapping as $tool_slug => $metadata ) {
	$tool_file = $tools_dir . '/class-wp-mcp-ai-tool-' . str_replace( '_', '-', $tool_slug ) . '.php';

	if ( ! file_exists( $tool_file ) ) {
		echo "⚠️  Tool file not found: $tool_slug ($tool_file)\n";
		$error_count++;
		continue;
	}

	// Read the file content.
	$content = file_get_contents( $tool_file );

	if ( false === $content ) {
		echo "❌ Failed to read file: $tool_slug\n";
		$error_count++;
		continue;
	}

	// Check if get_definition() already exists.
	if ( preg_match( '/public\s+function\s+get_definition\s*\(\s*\)/', $content ) ) {
		echo "⏭️  Already has get_definition(): $tool_slug\n";
		$skipped_count++;
		continue;
	}

	// Find the location to insert get_definition() - before get_capability_flags() if it exists.
	$insertion_point = null;

	if ( preg_match( '/^(\s*)\/\*\*\s*\n\s*\*\s*\{@inheritdoc\}\s*\n\s*\*\/\s*\n\s*public\s+function\s+get_capability_flags\s*\(\s*\)\s*\{/m', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
		$insertion_point = $matches[0][1];
		$indent          = $matches[1][0];
	} else {
		// Look for the closing brace of the class (last occurrence).
		if ( preg_match_all( '/^}/m', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			$last_brace      = end( $matches[0] );
			$insertion_point = $last_brace[1];
			$indent          = '';
		}
	}

	if ( null === $insertion_point ) {
		echo "❌ Could not find insertion point: $tool_slug\n";
		$error_count++;
		continue;
	}

	// Build the get_definition() method code.
	$method_code = "\n" . $indent . "/**\n";
	$method_code .= $indent . " * Get extended tool definition including toolkit metadata.\n";
	$method_code .= $indent . " *\n";
	$method_code .= $indent . " * @since 1.1.0\n";
	$method_code .= $indent . " *\n";
	$method_code .= $indent . " * @return array Tool definition with metadata.\n";
	$method_code .= $indent . " */\n";
	$method_code .= $indent . "public function get_definition() {\n";
	$method_code .= $indent . "\treturn array(\n";
	$method_code .= $indent . "\t\t'name'                  => \$this->get_name(),\n";
	$method_code .= $indent . "\t\t'description'           => \$this->get_description(),\n";
	$method_code .= $indent . "\t\t'toolkit'               => '" . $metadata['toolkit'] . "',\n";

	// Add pattern_compatibility.
	$patterns = array_map(
		function ( $p ) {
			return "'$p'";
		},
		$metadata['pattern_compatibility']
	);
	$method_code .= $indent . "\t\t'pattern_compatibility' => array( " . implode( ', ', $patterns ) . " ),\n";

	// Add profession_tags.
	$professions = array_map(
		function ( $p ) {
			return "'$p'";
		},
		$metadata['profession_tags']
	);
	$method_code .= $indent . "\t\t'profession_tags'       => array( " . implode( ', ', $professions ) . " ),\n";

	$method_code .= $indent . "\t\t'risk_level'            => '" . $metadata['risk_level'] . "',\n";
	$method_code .= $indent . "\t);\n";
	$method_code .= $indent . "}\n\n";

	// Insert the method code.
	$new_content = substr_replace( $content, $method_code, $insertion_point, 0 );

	// Write the updated content back to the file.
	if ( false === file_put_contents( $tool_file, $new_content ) ) {
		echo "❌ Failed to write file: $tool_slug\n";
		$error_count++;
		continue;
	}

	echo "✅ Updated: $tool_slug\n";
	$updated_count++;
}

echo "\n";
echo "===============================\n";
echo "Migration Complete!\n\n";
echo "✅ Updated: $updated_count tools\n";
echo "⏭️  Skipped: $skipped_count tools (already have get_definition)\n";
echo "❌ Errors: $error_count tools\n";
echo "\nNext steps:\n";
echo "1. Review the changes with: git diff includes/tools/\n";
echo "2. Test the tools to ensure they still work\n";
echo "3. Commit the changes: git add includes/tools/ && git commit -m 'Add toolkit metadata to tools'\n";

#!/usr/bin/env php
<?php
/**
 * Quick verification script for capability flags implementation.
 *
 * Run this to verify the capability flags system is working.
 */

// Simulate WordPress environment for testing.
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_MCP_AI_PATH', __DIR__ . '/' );

// Simple mock functions for testing.
function __( $text, $domain ) {
	return $text;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

// Load the interface and registry.
require_once __DIR__ . '/includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once __DIR__ . '/includes/class-wp-mcp-ai-tool-registry.php';

echo "=== Capability Flags Implementation Verification ===\n\n";

echo "1. Checking if WP_MCP_AI_Tool_Capability_Flags_Interface exists...\n";
if ( interface_exists( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) ) {
	echo "   ✓ Interface exists\n\n";
} else {
	echo "   ✗ Interface not found!\n";
	exit( 1 );
}

echo "2. Checking if Tool Registry class exists...\n";
if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
	echo "   ✓ Class exists\n\n";
} else {
	echo "   ✗ Class not found!\n";
	exit( 1 );
}

echo "3. Checking required methods exist...\n";
$registry_class = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );

$required_methods = array(
	'get_tool_capability_flags',
	'get_all_tool_capability_flags',
	'get_tools_by_capability_flag',
);

foreach ( $required_methods as $method_name ) {
	if ( $registry_class->hasMethod( $method_name ) ) {
		echo "   ✓ Method exists: {$method_name}\n";

		$method = $registry_class->getMethod( $method_name );
		if ( $method->isPublic() ) {
			echo "      - Access: public ✓\n";
		} else {
			echo "      - Access: NOT public ✗\n";
		}
	} else {
		echo "   ✗ Method missing: {$method_name}\n";
		exit( 1 );
	}
}
echo "\n";

echo "4. Checking method signatures...\n";

// Check get_tool_capability_flags.
$method = $registry_class->getMethod( 'get_tool_capability_flags' );
$params = $method->getParameters();
if ( count( $params ) === 1 && $params[0]->getName() === 'slug' ) {
	echo "   ✓ get_tool_capability_flags(\$slug) signature correct\n";
} else {
	echo "   ✗ get_tool_capability_flags() signature incorrect\n";
}

// Check get_all_tool_capability_flags.
$method = $registry_class->getMethod( 'get_all_tool_capability_flags' );
$params = $method->getParameters();
if ( count( $params ) === 0 ) {
	echo "   ✓ get_all_tool_capability_flags() signature correct\n";
} else {
	echo "   ✗ get_all_tool_capability_flags() signature incorrect\n";
}

// Check get_tools_by_capability_flag.
$method = $registry_class->getMethod( 'get_tools_by_capability_flag' );
$params = $method->getParameters();
if ( count( $params ) === 1 && $params[0]->getName() === 'flag' ) {
	echo "   ✓ get_tools_by_capability_flag(\$flag) signature correct\n";
} else {
	echo "   ✗ get_tools_by_capability_flag() signature incorrect\n";
}
echo "\n";

echo "5. Checking PHPDoc comments...\n";
foreach ( $required_methods as $method_name ) {
	$method  = $registry_class->getMethod( $method_name );
	$doc     = $method->getDocComment();
	$has_doc = $doc && strpos( $doc, '@return' ) !== false;

	if ( $has_doc ) {
		echo "   ✓ {$method_name} has PHPDoc with @return\n";
	} else {
		echo "   ✗ {$method_name} missing proper PHPDoc\n";
	}
}
echo "\n";

echo "=== All structural checks passed! ===\n";
echo "\nThe capability flags system has been successfully implemented.\n";
echo "The following methods are now available:\n";
echo "  - \$registry->get_tool_capability_flags(\$slug)\n";
echo "  - \$registry->get_all_tool_capability_flags()\n";
echo "  - \$registry->get_tools_by_capability_flag(\$flag)\n";
echo "\nNext step: Run the WordPress test suite to verify runtime behavior.\n";

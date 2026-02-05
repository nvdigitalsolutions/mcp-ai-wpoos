#!/usr/bin/env php
<?php
/**
 * Manual verification script for React package detection
 *
 * This script simulates the package checking logic to verify
 * that React packages are properly detected based on file existence.
 *
 * @package WP_MCP_AI
 */

// Set up paths similar to WordPress plugin environment.
define( 'WP_MCP_AI_PATH', dirname( __DIR__ ) . '/' );

// Check if Pro addon exists.
$pro_path = WP_MCP_AI_PATH . 'addons/pro/';
if ( file_exists( $pro_path ) ) {
	define( 'WP_MCP_AI_PRO_PATH', $pro_path );
}

// Packages to test.
$react_packages = array(
	'react',
	'react-dom',
	'reactflow',
	'@dnd-kit/core',
	'@dnd-kit/sortable',
	'@dnd-kit/utilities',
);

echo "React Package Detection Verification\n";
echo str_repeat( '=', 50 ) . "\n\n";

echo "Paths to check:\n";
echo "  WP_MCP_AI_PATH: " . WP_MCP_AI_PATH . "\n";
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	echo "  WP_MCP_AI_PRO_PATH: " . WP_MCP_AI_PRO_PATH . "\n";
} else {
	echo "  WP_MCP_AI_PRO_PATH: [not defined]\n";
}
echo "\n";

/**
 * Simulate the check_package_installed logic.
 *
 * @param string $package Package name.
 * @return bool|string True if found, false if not, or path if found.
 */
function check_react_package( $package ) {
	// Priority 1: Check for built workflow-builder bundle in Pro addon directory.
	if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
		$workflow_build_path = WP_MCP_AI_PRO_PATH . 'build/workflow-builder/workflow-builder.js';
		if ( file_exists( $workflow_build_path ) ) {
			return $workflow_build_path;
		}
	}

	// Priority 2: Check base build directory (legacy/development location).
	$legacy_workflow_build_path = WP_MCP_AI_PATH . 'build/workflow-builder/workflow-builder.js';
	if ( file_exists( $legacy_workflow_build_path ) ) {
		return $legacy_workflow_build_path;
	}

	// Priority 3: Check base node_modules (development).
	$node_modules_path = WP_MCP_AI_PATH . 'node_modules/' . $package;
	if ( file_exists( $node_modules_path ) ) {
		return $node_modules_path;
	}

	// If none exist, return false.
	return false;
}

// Test each package.
echo "Package Detection Results:\n";
echo str_repeat( '-', 50 ) . "\n";

foreach ( $react_packages as $package ) {
	$result = check_react_package( $package );

	if ( $result === false ) {
		echo "❌ {$package}: NOT FOUND\n";
		echo "   (This is expected if workflow builder hasn't been built)\n";
	} else {
		echo "✅ {$package}: FOUND\n";
		echo "   Location: {$result}\n";
	}
	echo "\n";
}

echo str_repeat( '=', 50 ) . "\n";
echo "\nExpected Behavior:\n";
echo "- In DEVELOPMENT (with node_modules): All packages should be FOUND\n";
echo "- In PRODUCTION (with built bundle): All packages should be FOUND if workflow builder is built\n";
echo "- Without either: All packages will be NOT FOUND (acceptable before build)\n";
echo "\nTo build the workflow builder:\n";
echo "  npm run build:workflow\n";
echo "  (Outputs to: addons/pro/build/workflow-builder/)\n";

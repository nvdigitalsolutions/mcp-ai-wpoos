#!/usr/bin/env php
<?php
/**
 * Plugin Integrity Verification Script
 *
 * This script verifies the plugin hasn't been breached by recent changes.
 * It performs checks that don't require a full WordPress installation.
 *
 * @package WP_MCP_AI
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

echo "=== WP oOS Plugin Integrity Verification ===\n\n";

$root_dir  = __DIR__;
$errors    = array();
$warnings  = array();
$successes = array();

/**
 * Check PHP syntax for all PHP files in the plugin.
 */
function check_php_syntax( $root_dir, &$errors, &$successes ) {
	echo "1. Checking PHP Syntax...\n";
	
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root_dir )
	);
	
	$php_files       = 0;
	$syntax_errors   = 0;
	$exclude_dirs    = array( 'vendor', 'node_modules', '.git', '.codex-wordpress' );
	
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
			continue;
		}
		
		$path = $file->getPathname();
		
		// Skip excluded directories.
		$skip = false;
		foreach ( $exclude_dirs as $exclude ) {
			if ( false !== strpos( $path, DIRECTORY_SEPARATOR . $exclude . DIRECTORY_SEPARATOR ) ) {
				$skip = true;
				break;
			}
		}
		
		if ( $skip ) {
			continue;
		}
		
		$php_files++;
		
		// Check syntax.
		$output     = array();
		$return_var = 0;
		exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $output, $return_var );
		
		if ( 0 !== $return_var ) {
			$syntax_errors++;
			$errors[] = "Syntax error in {$path}: " . implode( "\n", $output );
		}
	}
	
	if ( 0 === $syntax_errors ) {
		$successes[] = "✓ All {$php_files} PHP files have valid syntax";
	} else {
		$errors[] = "✗ Found {$syntax_errors} files with syntax errors out of {$php_files} files";
	}
	
	echo "\n";
}

/**
 * Verify Composer dependencies are installed.
 */
function check_composer_dependencies( $root_dir, &$errors, &$successes ) {
	echo "2. Checking Composer Dependencies...\n";
	
	$composer_json = $root_dir . '/composer.json';
	$vendor_dir    = $root_dir . '/vendor';
	
	if ( ! file_exists( $composer_json ) ) {
		$errors[] = '✗ composer.json not found';
		echo "\n";
		return;
	}
	
	if ( ! file_exists( $vendor_dir ) ) {
		$errors[] = '✗ vendor directory not found - run composer install';
		echo "\n";
		return;
	}
	
	$composer_data = json_decode( file_get_contents( $composer_json ), true );
	
	// Check required packages (from both require and require-dev).
	$required_packages = array_merge(
		isset( $composer_data['require'] ) ? array_keys( $composer_data['require'] ) : array(),
		isset( $composer_data['require-dev'] ) ? array_keys( $composer_data['require-dev'] ) : array()
	);
	
	$missing = array();
	foreach ( $required_packages as $package ) {
		if ( 'php' === $package ) {
			continue; // Skip PHP version requirement.
		}
		
		// Convert package name to directory path.
		$package_dir = $vendor_dir . '/' . $package;
		
		if ( ! is_dir( $package_dir ) ) {
			$missing[] = $package;
		}
	}
	
	if ( empty( $missing ) ) {
		$successes[] = '✓ All ' . count( $required_packages ) . ' Composer dependencies are installed';
	} else {
		$errors[] = '✗ Missing Composer packages: ' . implode( ', ', $missing );
	}
	
	// Specifically check for PHPUnit (the recent change).
	if ( file_exists( $vendor_dir . '/phpunit/phpunit' ) ) {
		$successes[] = '✓ PHPUnit is installed (main purpose of recent PR #989)';
	} else {
		$errors[] = '✗ PHPUnit is NOT installed - recent change may have failed';
	}
	
	// Check if composer autoloader exists.
	if ( file_exists( $vendor_dir . '/autoload.php' ) ) {
		$successes[] = '✓ Composer autoloader is present';
	} else {
		$errors[] = '✗ Composer autoloader is missing';
	}
	
	echo "\n";
}

/**
 * Verify main plugin file structure.
 */
function check_plugin_structure( $root_dir, &$errors, &$successes, &$warnings ) {
	echo "3. Checking Plugin Structure...\n";
	
	$main_file = $root_dir . '/wp-mcp-ai.php';
	
	if ( ! file_exists( $main_file ) ) {
		$errors[] = '✗ Main plugin file (wp-mcp-ai.php) not found';
		echo "\n";
		return;
	}
	
	$content = file_get_contents( $main_file );
	
	// Check for required plugin headers.
	$required_headers = array(
		'Plugin Name',
		'Plugin URI',
		'Description',
		'Version',
		'Author',
	);
	
	foreach ( $required_headers as $header ) {
		if ( false === strpos( $content, $header . ':' ) ) {
			$warnings[] = "⚠ Main plugin file missing header: {$header}";
		}
	}
	
	// Check for main plugin class or bootstrap function.
	if ( false !== strpos( $content, 'class WP_MCP_AI' ) || false !== strpos( $content, 'function wp_mcp_ai' ) ) {
		$successes[] = '✓ Main plugin file contains expected code structure';
	} else {
		$errors[] = '✗ Main plugin file missing expected class or function definitions';
	}
	
	// Check critical directories exist.
	$critical_dirs = array( 'includes', 'assets', 'tests' );
	foreach ( $critical_dirs as $dir ) {
		if ( is_dir( $root_dir . '/' . $dir ) ) {
			$successes[] = "✓ Directory '{$dir}' exists";
		} else {
			$errors[] = "✗ Critical directory '{$dir}' is missing";
		}
	}
	
	echo "\n";
}

/**
 * Check critical include files.
 */
function check_include_files( $root_dir, &$errors, &$successes ) {
	echo "4. Checking Critical Include Files...\n";
	
	$includes_dir = $root_dir . '/includes';
	
	if ( ! is_dir( $includes_dir ) ) {
		$errors[] = '✗ Includes directory not found';
		echo "\n";
		return;
	}
	
	// Find all class files.
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $includes_dir )
	);
	
	$class_files = 0;
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$class_files++;
		}
	}
	
	if ( $class_files > 0 ) {
		$successes[] = "✓ Found {$class_files} class files in includes directory";
	} else {
		$errors[] = '✗ No class files found in includes directory';
	}
	
	echo "\n";
}

/**
 * Verify test infrastructure.
 */
function check_test_infrastructure( $root_dir, &$errors, &$successes ) {
	echo "5. Checking Test Infrastructure...\n";
	
	$phpunit_xml  = $root_dir . '/phpunit.xml.dist';
	$tests_dir    = $root_dir . '/tests';
	$bootstrap    = $tests_dir . '/bootstrap.php';
	$phpunit_bin  = $root_dir . '/vendor/bin/phpunit';
	
	if ( file_exists( $phpunit_xml ) ) {
		$successes[] = '✓ PHPUnit configuration file exists';
	} else {
		$errors[] = '✗ PHPUnit configuration file (phpunit.xml.dist) not found';
	}
	
	if ( is_dir( $tests_dir ) ) {
		// Count test files.
		$test_files = glob( $tests_dir . '/test-*.php' );
		if ( ! empty( $test_files ) ) {
			$successes[] = '✓ Found ' . count( $test_files ) . ' test files';
		} else {
			$warnings[] = '⚠ No test files found in tests directory';
		}
	} else {
		$errors[] = '✗ Tests directory not found';
	}
	
	if ( file_exists( $bootstrap ) ) {
		$successes[] = '✓ Test bootstrap file exists';
	} else {
		$errors[] = '✗ Test bootstrap file not found';
	}
	
	if ( file_exists( $phpunit_bin ) ) {
		// Check PHPUnit version.
		exec( escapeshellarg( $phpunit_bin ) . ' --version 2>&1', $version_output );
		if ( ! empty( $version_output ) ) {
			$version = trim( $version_output[0] );
			$successes[] = "✓ PHPUnit executable is available: {$version}";
		}
	} else {
		$errors[] = '✗ PHPUnit executable not found in vendor/bin';
	}
	
	echo "\n";
}

// Run all checks.
check_php_syntax( $root_dir, $errors, $successes );
check_composer_dependencies( $root_dir, $errors, $successes );
check_plugin_structure( $root_dir, $errors, $successes, $warnings );
check_include_files( $root_dir, $errors, $successes );
check_test_infrastructure( $root_dir, $errors, $successes );

// Print summary.
echo "=== SUMMARY ===\n\n";

if ( ! empty( $successes ) ) {
	echo "SUCCESSES (" . count( $successes ) . "):\n";
	foreach ( $successes as $success ) {
		echo "  {$success}\n";
	}
	echo "\n";
}

if ( ! empty( $warnings ) ) {
	echo "WARNINGS (" . count( $warnings ) . "):\n";
	foreach ( $warnings as $warning ) {
		echo "  {$warning}\n";
	}
	echo "\n";
}

if ( ! empty( $errors ) ) {
	echo "ERRORS (" . count( $errors ) . "):\n";
	foreach ( $errors as $error ) {
		echo "  {$error}\n";
	}
	echo "\n";
}

// Final verdict.
echo "=== VERDICT ===\n";
if ( empty( $errors ) ) {
	echo "✓ Plugin integrity check PASSED\n";
	echo "✓ Recent changes (PR #989) have not breached the plugin\n";
	exit( 0 );
} else {
	echo "✗ Plugin integrity check FAILED\n";
	echo "✗ Found " . count( $errors ) . " critical issue(s)\n";
	exit( 1 );
}

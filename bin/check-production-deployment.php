#!/usr/bin/env php
<?php
/**
 * Production Deployment Verification Script
 *
 * This script verifies that the plugin is properly set up for production deployment.
 * Run this after cloning the repository or before deploying to production.
 *
 * Usage:
 *   php bin/verify-production-deployment.php
 *   OR
 *   ./bin/verify-production-deployment.php
 *
 * Exit codes:
 *   0 = All checks passed
 *   1 = One or more checks failed
 *
 * @package MCP_AI_WP_OOS
 */

// Color output support
$supports_color = (
	( function_exists( 'posix_isatty' ) && @posix_isatty( STDOUT ) ) ||
	getenv( 'TERM' ) !== false
);

/**
 * Output colored text
 *
 * @param string $text  Text to output.
 * @param string $color Color code (green, red, yellow, blue).
 */
function output( $text, $color = '' ) {
	global $supports_color;
	$colors = array(
		'green'  => "\033[0;32m",
		'red'    => "\033[0;31m",
		'yellow' => "\033[0;33m",
		'blue'   => "\033[0;34m",
		'reset'  => "\033[0m",
	);

	if ( $supports_color && isset( $colors[ $color ] ) ) {
		echo $colors[ $color ] . $text . $colors['reset'] . PHP_EOL;
	} else {
		echo $text . PHP_EOL;
	}
}

/**
 * Check result output
 *
 * @param bool   $passed Check result.
 * @param string $message Success message.
 * @param string $error   Error message.
 */
function check( $passed, $message, $error = '' ) {
	if ( $passed ) {
		output( '✓ ' . $message, 'green' );
		return true;
	} else {
		output( '✗ ' . $message, 'red' );
		if ( $error ) {
			output( '  ' . $error, 'yellow' );
		}
		return false;
	}
}

// Change to plugin root directory
$plugin_dir = dirname( __DIR__ );
chdir( $plugin_dir );

output( '', '' );
output( '==============================================', 'blue' );
output( 'Production Deployment Verification', 'blue' );
output( '==============================================', 'blue' );
output( '', '' );

$all_passed = true;

// Check 1: Vendor directory exists
output( 'Checking vendor directory...', '' );
$vendor_exists = is_dir( 'vendor' );
$all_passed   &= check(
	$vendor_exists,
	'Vendor directory exists',
	'Run: composer install --no-dev --prefer-dist --classmap-authoritative'
);

if ( ! $vendor_exists ) {
	output( '', '' );
	output( 'CRITICAL: Cannot continue without vendor directory.', 'red' );
	exit( 1 );
}

// Check 2: Composer autoloader exists
output( '', '' );
output( 'Checking Composer autoloader...', '' );
$autoloader_exists = file_exists( 'vendor/autoload.php' );
$all_passed       &= check(
	$autoloader_exists,
	'Composer autoloader exists',
	'Run: composer install --no-dev --prefer-dist --classmap-authoritative'
);

// Check 3: Load autoloader
if ( $autoloader_exists ) {
	try {
		require_once 'vendor/autoload.php';
		$all_passed &= check( true, 'Composer autoloader loads successfully' );
	} catch ( Exception $e ) {
		$all_passed &= check( false, 'Composer autoloader loads successfully', $e->getMessage() );
	}
}

// Check 4: Critical vendor packages
output( '', '' );
output( 'Checking critical vendor packages...', '' );

$required_packages = array(
	'ralouphie/getallheaders' => 'vendor/ralouphie/getallheaders/src/getallheaders.php',
	'symfony/http-client'     => 'vendor/symfony/http-client/HttpClient.php',
	'symfony/validator'       => 'vendor/symfony/validator/Validation.php',
	'symfony/cache'           => 'vendor/symfony/cache/Adapter/ArrayAdapter.php',
	'symfony/filesystem'      => 'vendor/symfony/filesystem/Filesystem.php',
	'symfony/process'         => 'vendor/symfony/process/Process.php',
	'rahul900day/tiktoken-php' => 'vendor/rahul900day/tiktoken-php/src/Tiktoken.php',
	'nyholm/psr7'             => 'vendor/nyholm/psr7/src/Factory/Psr17Factory.php',
	'league/oauth2-client'    => 'vendor/league/oauth2-client/src/Provider/AbstractProvider.php',
);

foreach ( $required_packages as $package => $file ) {
	$all_passed &= check(
		file_exists( $file ),
		sprintf( 'Package %s is present', $package ),
		sprintf( 'Missing file: %s', $file )
	);
}

// Check 5: Autoloader optimization
output( '', '' );
output( 'Checking autoloader optimization...', '' );

$autoload_real  = file_get_contents( 'vendor/composer/autoload_real.php' );
$is_optimized   = strpos( $autoload_real, 'setClassMapAuthoritative(true)' ) !== false;
$all_passed    &= check(
	$is_optimized,
	'Autoloader is optimized for production (classmap-authoritative)',
	'Run: composer install --no-dev --prefer-dist --classmap-authoritative'
);

// Check 6: No dev dependencies
output( '', '' );
output( 'Checking for dev dependencies...', '' );

$dev_packages = array(
	'vendor/phpunit',
	'vendor/squizlabs',
	'vendor/wp-coding-standards',
	'vendor/dealerdirect',
	'vendor/phpcompatibility',
);

$dev_found = false;
foreach ( $dev_packages as $dev_package ) {
	if ( is_dir( $dev_package ) ) {
		$dev_found = true;
		output( sprintf( '  Found dev package: %s', $dev_package ), 'yellow' );
	}
}

$all_passed &= check(
	! $dev_found,
	'No dev dependencies present',
	'Run: composer install --no-dev --prefer-dist --classmap-authoritative'
);

// Check 7: File permissions
output( '', '' );
output( 'Checking file permissions...', '' );

$vendor_writable = is_writable( 'vendor' );
$all_passed     &= check(
	$vendor_writable,
	'Vendor directory is writable (for potential updates)',
	'Fix with: chmod -R 755 vendor/'
);

// Check 8: Git attributes
output( '', '' );
output( 'Checking Git configuration...', '' );

$gitattributes_exists = file_exists( '.gitattributes' );
$all_passed          &= check(
	$gitattributes_exists,
	'.gitattributes file exists',
	'This file should be committed to the repository'
);

// Check 9: Main plugin file
output( '', '' );
output( 'Checking plugin files...', '' );

$main_file_exists = file_exists( 'mcp-ai-wpoos.php' );
$all_passed      &= check(
	$main_file_exists,
	'Main plugin file exists (mcp-ai-wpoos.php)',
	'Repository structure may be corrupt'
);

// Check 10: Includes directory
$includes_exists = is_dir( 'includes' ) && is_dir( 'includes/tools' );
$all_passed     &= check(
	$includes_exists,
	'Plugin includes directory structure is valid',
	'Repository structure may be corrupt'
);

// Final summary
output( '', '' );
output( '==============================================', 'blue' );
if ( $all_passed ) {
	output( 'RESULT: All checks passed! ✓', 'green' );
	output( '==============================================', 'blue' );
	output( '', '' );
	output( 'Your installation is ready for production deployment.', 'green' );
	output( '', '' );
	output( 'Next steps:', '' );
	output( '1. Deploy to your WordPress installation', '' );
	output( '2. Activate the plugin in WordPress admin', '' );
	output( '3. Configure your AI provider settings', '' );
	output( '', '' );
	exit( 0 );
} else {
	output( 'RESULT: Some checks failed! ✗', 'red' );
	output( '==============================================', 'blue' );
	output( '', '' );
	output( 'Please fix the issues above before deploying to production.', 'red' );
	output( '', '' );
	output( 'Common fixes:', '' );
	output( '1. Regenerate vendor with: composer install --no-dev --prefer-dist --classmap-authoritative', 'yellow' );
	output( '2. Check file permissions: chmod -R 755 vendor/', 'yellow' );
	output( '3. If cloning from GitHub, ensure .gitignore allows vendor/ files', 'yellow' );
	output( '', '' );
	exit( 1 );
}

#!/usr/bin/env php
<?php
/**
 * Verification script for pro toolkit slash commands
 *
 * This script verifies that all pro toolkit slash commands are properly defined
 * and accessible in the system.
 *
 * @package WP_MCP_AI
 * @since 1.3.0
 */

// Load WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	// Try common WordPress paths.
	$wp_load_paths = array(
		dirname( dirname( __FILE__ ) ) . '/../../../wp-load.php',
		dirname( dirname( __FILE__ ) ) . '/../../../../wp-load.php',
		dirname( dirname( __FILE__ ) ) . '/../../../../../wp-load.php',
	);

	foreach ( $wp_load_paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			break;
		}
	}
}

// Manually load plugin for standalone testing.
if ( ! defined( 'ABSPATH' ) ) {
	// Fallback: Load minimal environment for testing.
	define( 'ABSPATH', dirname( dirname( __FILE__ ) ) . '/' );
	define( 'WP_MCP_AI_PATH', dirname( dirname( __FILE__ ) ) . '/' );
	define( 'WP_MCP_AI_BASE_VERSION', false ); // Enable pro mode for testing.

	// Mock WordPress functions needed for verification.
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}

	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook_name, $value ) {
			return $value;
		}
	}

	// Load required classes.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php';

	// Create instances using reflection.
	$handler = new WP_MCP_AI_Slash_Command_Handler();

	// Use reflection to access protected methods.
	$manager_class = new ReflectionClass( 'WP_MCP_AI_Slash_Command_Toolkit_Manager' );
	$instance_property = $manager_class->getProperty( 'instance' );
	$instance_property->setAccessible( true );

	$manager_constructor = $manager_class->getConstructor();
	$manager_constructor->setAccessible( true );

	$manager = $manager_class->newInstanceWithoutConstructor();
	$instance_property->setValue( null, $manager );

	// Manually initialize.
	$handler_property = $manager_class->getProperty( 'handler' );
	$handler_property->setAccessible( true );
	$handler_property->setValue( $manager, $handler );

	$commands_property = $manager_class->getProperty( 'toolkit_commands' );
	$commands_property->setAccessible( true );

	// Call protected define method.
	$define_method = $manager_class->getMethod( 'define_toolkit_commands' );
	$define_method->setAccessible( true );
	$define_method->invoke( $manager );

	$toolkit_commands = $commands_property->getValue( $manager );
}

// Define expected pro toolkits.
$pro_toolkits = array(
	'ai_tool_builder'         => 10,
	'analytics_pro'           => 12,
	'architect_agent'         => 11,
	'architectural_design'    => 16,
	'calendar_booking'        => 12,
	'chat_channels'           => 10,
	'crm'                     => 14,
	'dj_management'           => 11,
	'document_generation'     => 13,
	'ecommerce_pro'           => 15,
	'fantasy_football'        => 12,
	'financial_planner'       => 14,
	'image_production'        => 13,
	'media_pro'               => 11,
	'multilingual'            => 12,
	'regulatory_registration' => 15,
	'site_creator'            => 14,
	'social_media'            => 13,
	'video_production'        => 14,
);

echo "\n=== Pro Toolkit Slash Commands Verification ===\n\n";

$total_commands = 0;
$verified_toolkits = 0;
$errors = array();

foreach ( $pro_toolkits as $toolkit_slug => $expected_count ) {
	$commands = isset( $toolkit_commands[ $toolkit_slug ] ) ? $toolkit_commands[ $toolkit_slug ] : array();
	$actual_count = count( $commands );

	if ( $actual_count === $expected_count ) {
		echo "✓ {$toolkit_slug}: {$actual_count} commands\n";
		$verified_toolkits++;
		$total_commands += $actual_count;
	} else {
		$error_msg = "✗ {$toolkit_slug}: Expected {$expected_count} commands, found {$actual_count}\n";
		echo $error_msg;
		$errors[] = $error_msg;
	}
}

echo "\n=== Summary ===\n";
echo "Total Pro Toolkits: " . count( $pro_toolkits ) . "\n";
echo "Verified Toolkits: {$verified_toolkits}\n";
echo "Total Commands: {$total_commands}\n";

if ( empty( $errors ) ) {
	echo "\n✓ All pro toolkit commands verified successfully!\n\n";
	exit( 0 );
} else {
	echo "\n✗ Verification failed with " . count( $errors ) . " errors:\n";
	foreach ( $errors as $error ) {
		echo "  - {$error}";
	}
	echo "\n";
	exit( 1 );
}

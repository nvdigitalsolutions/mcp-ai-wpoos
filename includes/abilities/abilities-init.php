<?php
/**
 * Abilities API — init script.
 *
 * Boots the Abilities API integration: category registrar and ability
 * registrar. Both are no-ops on WordPress < 6.9 due to function_exists()
 * guards in their respective registration methods.
 *
 * This file is loaded by includes/bootstrap/loader.php during plugin
 * bootstrap (after the tool registry is available).
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure dependent classes are loaded (Composer classmap autoloads these,
// but we include them explicitly as a safety net for environments where
// dump-autoload has not been re-run after adding new files).
if ( ! class_exists( 'WP_MCP_AI_Ability_Category_Registrar' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/abilities/class-wp-mcp-ai-ability-category-registrar.php';
}
if ( ! class_exists( 'WP_MCP_AI_Ability_Bridge' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/abilities/class-wp-mcp-ai-ability-bridge.php';
}
if ( ! class_exists( 'WP_MCP_AI_Ability_Registrar' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/abilities/class-wp-mcp-ai-ability-registrar.php';
}
if ( ! class_exists( 'WP_MCP_AI_Ability_Security_Bridge' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/abilities/class-wp-mcp-ai-ability-security-bridge.php';
}

// Ensure the optional ability interface is available for tools that implement it.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Ability_Interface' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-ability.php';
}

// Register ability categories on wp_abilities_api_categories_init.
WP_MCP_AI_Ability_Category_Registrar::init();

// Register eligible tools as Abilities on wp_abilities_api_init.
WP_MCP_AI_Ability_Registrar::init();

// Wire ability execution hooks into the security infrastructure
// (destructive ops gate, audit logger, cost tracker, concurrency guard).
WP_MCP_AI_Ability_Security_Bridge::register();

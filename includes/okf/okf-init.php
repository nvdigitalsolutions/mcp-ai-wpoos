<?php
/**
 * OKF — Bootstrap initialization.
 *
 * Loads all OKF core classes and registers MCP tools.
 * Hooked into wp_mcp_ai_bootstrapped at priority 32 (after Paper Store at 30).
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @since   2.5.0 — OKF v0.2 support (trust signals, nested YAML, provenance).
 * @since   1.1.62 — Bundle Manager loaded before the skill-knowledge generator.
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 *
 * @link https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load core classes in dependency order.
require_once __DIR__ . '/class-wp-mcp-ai-okf-parser.php';
require_once __DIR__ . '/class-wp-mcp-ai-okf-reader.php';
require_once __DIR__ . '/class-wp-mcp-ai-okf-writer.php';
require_once __DIR__ . '/class-wp-mcp-ai-okf-bundle-manager.php';
require_once __DIR__ . '/class-wp-mcp-ai-okf-skill-knowledge-generator.php';

// Load tool classes.
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-read-concept.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-browse.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-traverse.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-search.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-list-bundles.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-write-concept.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-delete-concept.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-validate-attestation.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-validate-bundle.php';
require_once WP_MCP_AI_PATH . 'includes/tools/okf/class-wp-mcp-ai-tool-okf-import-bundle.php';

/**
 * Register OKF tools with the tool registry.
 *
 * Hooked at priority 32 — after Paper Store at priority 30.
 */
// Auto-generate the skill-knowledge bundle from bundled skills so the OKF
// tools work out of the box. Hooks the same action at priority 32.
WP_MCP_AI_OKF_Skill_Knowledge_Generator::init();

add_action(
	'wp_mcp_ai_bootstrapped',
	function () {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Read tools.
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Read_Concept' );
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Browse' );
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Traverse' );
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Search' );
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_List_Bundles' );

		// Write tools.
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Write_Concept' );
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Delete_Concept' );

		// Validate tools (OKF v0.2).
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Validate_Attestation' );
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Validate_Bundle' );

		// Bundle lifecycle tools (1.1.62+).
		$registry->register_tool( 'WP_MCP_AI_Tool_OKF_Import_Bundle' );
	},
	32
);

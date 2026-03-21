<?php
/**
 * Toolkit Constants — backward-compatibility shim.
 *
 * The canonical class now lives in includes/domain/.
 * This file loads that class so any code that require_once's this path
 * continues to work unchanged.
 *
 * @package WP_MCP_AI
 * @since   1.1.0
 * @deprecated 1.2.0 Use includes/domain/class-wp-mcp-ai-toolkit-constants.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/domain/class-wp-mcp-ai-toolkit-constants.php';

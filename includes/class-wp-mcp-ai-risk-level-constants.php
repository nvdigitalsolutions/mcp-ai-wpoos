<?php
/**
 * Risk Level Constants — backward-compatibility shim.
 *
 * The canonical class now lives in includes/domain/.
 * This file loads that class so any code that require_once's this path
 * continues to work unchanged.
 *
 * @package WP_MCP_AI
 * @since   1.1.0
 * @deprecated 1.2.0 Use includes/domain/class-wp-mcp-ai-risk-level-constants.php
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/domain/class-wp-mcp-ai-risk-level-constants.php';

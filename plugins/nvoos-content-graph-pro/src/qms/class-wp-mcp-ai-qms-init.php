<?php
/**
 * class-wp-mcp-ai-qms-init (ecosystem port - Wave F2, document-generation QMS/admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/qms/class-wp-mcp-ai-qms-init.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is
 * defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants, so no path swaps.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-qms-capabilities.php';
require_once __DIR__ . '/class-wp-mcp-ai-qms-audit-log.php';
require_once __DIR__ . '/class-wp-mcp-ai-qms-taxonomy.php';
require_once __DIR__ . '/class-wp-mcp-ai-qms-doc-record-cpt.php';
require_once __DIR__ . '/class-wp-mcp-ai-qms-workflow.php';
require_once __DIR__ . '/class-wp-mcp-ai-qms-retention.php';
require_once __DIR__ . '/class-wp-mcp-ai-qms-para-bridge.php';

WP_MCP_AI_QMS_Capabilities::init();
WP_MCP_AI_QMS_Audit_Log::init();
WP_MCP_AI_QMS_Taxonomy::init();
WP_MCP_AI_QMS_Doc_Record_CPT::init();
WP_MCP_AI_QMS_Retention::init();
WP_MCP_AI_QMS_PARA_Bridge::init();

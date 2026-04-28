<?php
/**
 * QMS Initialization.
 *
 * Bootstraps the QMS subsystem when the Document Generation toolkit is
 * enabled and the `enable_qms_compliance` feature flag is on.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

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

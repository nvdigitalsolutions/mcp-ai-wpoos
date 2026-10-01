<?php
/**
 * ECA Database Tables Initialization
 *
 * Bootstraps the ECA enrollments and attendance custom database tables.
 * Loaded by the ECA management toolkit init.php.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-eca-enrollments-db.php';
require_once __DIR__ . '/class-wp-mcp-ai-eca-attendance-db.php';
require_once __DIR__ . '/class-wp-mcp-ai-eca-classroom-helper.php';

WP_MCP_AI_ECA_Enrollments_DB::init();
WP_MCP_AI_ECA_Attendance_DB::init();

// Google Classroom sync engine. Loaded whenever ECA management is active; the
// helper only resolves Google classes when the integration flag is on, and the
// engine's hooks are self-gating (no sync targets ⇒ nothing scheduled).
if ( ! class_exists( 'WP_MCP_AI_Google_Classroom_Push' ) && file_exists( WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-push.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-classroom-push.php';
}
require_once __DIR__ . '/class-wp-mcp-ai-eca-classroom-sync.php';
WP_MCP_AI_ECA_Classroom_Sync::init();

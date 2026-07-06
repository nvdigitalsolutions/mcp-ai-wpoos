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

WP_MCP_AI_ECA_Enrollments_DB::init();
WP_MCP_AI_ECA_Attendance_DB::init();

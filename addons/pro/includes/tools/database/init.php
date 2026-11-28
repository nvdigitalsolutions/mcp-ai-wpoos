<?php
/**
 * Database toolkit initialiser.
 *
 * Proposal 167. This file runs only when the toolkit toggle is on, and it does
 * four things in order:
 *
 *  1. **Load the kernel and adapters** through {@see WP_MCP_AI_Db_Runtime}, which
 *     is the single availability gate. If the Pro kernel is absent or PHP is
 *     older than 8.2, everything below is skipped — no fatal, no half-loaded
 *     toolkit.
 *  2. **Load the tool classes**, including the shared base. The central registry
 *     also maps them; requiring here keeps the toolkit self-contained for tests
 *     and for any host that instantiates a tool directly.
 *  3. **Wire the Remote Sites credential resolver**, so an `rs:` connection's
 *     password is decrypted by the store that owns it.
 *  4. **Register the admin page** when in wp-admin.
 *
 * The write tools are loaded only when `enable_database_writes` is on. That is
 * the first of the two write gates; the second is the scope's tiers.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Db_Runtime' ) ) {
	return;
}

$wp_mcp_ai_db_settings = get_option( 'wp_mcp_ai_settings', array() );

if ( empty( $wp_mcp_ai_db_settings['enable_database_toolkit'] ) ) {
	return;
}

if ( ! WP_MCP_AI_Db_Runtime::load() ) {
	// Record why, so Site Health and the settings page can explain the absence
	// instead of leaving an operator with a toggle that appears to do nothing.
	WP_MCP_AI_Db_Runtime::record_status();

	return;
}

WP_MCP_AI_Db_Runtime::record_status();

require_once __DIR__ . '/class-wp-mcp-ai-database-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-db-export-job.php';

// The async export handler must exist in cron context too, which is why the
// registration sits with the class rather than behind the is_admin() guard.
WP_MCP_AI_Db_Export_Job::init();

$wp_mcp_ai_db_read_tools = array(
	'class-wp-mcp-ai-tool-db-list-connections.php',
	'class-wp-mcp-ai-tool-db-describe.php',
	'class-wp-mcp-ai-tool-db-profile-table.php',
	'class-wp-mcp-ai-tool-db-explain.php',
	'class-wp-mcp-ai-tool-db-select.php',
	'class-wp-mcp-ai-tool-db-aggregate.php',
	'class-wp-mcp-ai-tool-db-search.php',
	'class-wp-mcp-ai-tool-db-query.php',
	'class-wp-mcp-ai-tool-db-export.php',
	'class-wp-mcp-ai-tool-db-test-connection.php',
	'class-wp-mcp-ai-tool-db-audit-tail.php',
);

foreach ( $wp_mcp_ai_db_read_tools as $wp_mcp_ai_db_tool_file ) {
	require_once __DIR__ . '/' . $wp_mcp_ai_db_tool_file;
}

unset( $wp_mcp_ai_db_read_tools, $wp_mcp_ai_db_tool_file );

if ( ! empty( $wp_mcp_ai_db_settings['enable_database_writes'] ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-tool-db-propose-write.php';
	require_once __DIR__ . '/class-wp-mcp-ai-tool-db-apply-write.php';
}

if ( class_exists( 'WP_MCP_AI_Remote_Site_Connection_Provider' ) ) {
	$wp_mcp_ai_db_remote_provider = new WP_MCP_AI_Remote_Site_Connection_Provider();

	if ( $wp_mcp_ai_db_remote_provider->is_available() ) {
		$wp_mcp_ai_db_remote_provider->register_credential_filter();
	}

	unset( $wp_mcp_ai_db_remote_provider );
}

if ( is_admin() ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-database-settings-page.php';

	WP_MCP_AI_Database_Settings_Page::init();
}

unset( $wp_mcp_ai_db_settings );

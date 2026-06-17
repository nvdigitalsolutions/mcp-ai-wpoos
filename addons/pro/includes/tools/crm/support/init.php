<?php
/**
 * Support Ticket Tools Initialization
 *
 * Loads all support ticket AI tools for CRUD, classification,
 * escalation, merging, and SLA reporting.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if CRM toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_crm_toolkit'] );

if ( ! $is_enabled ) {
	return;
}

// Tools are auto-discovered by the tool registry via the directory structure.
// This file ensures all support ticket tool classes are loaded.
$_support_dir = __DIR__ . '/';

$_support_tools = array(
	'class-wp-mcp-ai-tool-create-support-ticket.php',
	'class-wp-mcp-ai-tool-get-support-ticket.php',
	'class-wp-mcp-ai-tool-list-support-tickets.php',
	'class-wp-mcp-ai-tool-update-support-ticket.php',
	'class-wp-mcp-ai-tool-resolve-support-ticket.php',
	'class-wp-mcp-ai-tool-reopen-support-ticket.php',
	'class-wp-mcp-ai-tool-escalate-support-ticket.php',
	'class-wp-mcp-ai-tool-merge-support-tickets.php',
	'class-wp-mcp-ai-tool-classify-support-ticket.php',
	'class-wp-mcp-ai-tool-get-ticket-sla-report.php',
);

foreach ( $_support_tools as $_file ) {
	$_path = $_support_dir . $_file;
	if ( file_exists( $_path ) ) {
		require_once $_path;
	}
}

// Load ticket automation (cron, auto-close, auto-escalate, SLA breach hooks).
$_automation = $_support_dir . 'class-wp-mcp-ai-crm-ticket-automation.php';
if ( file_exists( $_automation ) ) {
	require_once $_automation;
	WP_MCP_AI_CRM_Ticket_Automation::init();
}

// Load ticket notifications (email-to-ticket, SLA breach email, CSAT, close notify).
$_notifications = $_support_dir . 'class-wp-mcp-ai-crm-ticket-notifications.php';
if ( file_exists( $_notifications ) ) {
	require_once $_notifications;
	WP_MCP_AI_CRM_Ticket_Notifications::init();
}

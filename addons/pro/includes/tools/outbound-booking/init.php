<?php
/**
 * Outbound Appointment Booking Toolkit Initialization
 *
 * Loads the Outbound Booking Toolkit: automated multi-channel outreach on top
 * of CRM sequences and leads, an angle bank with weekly A/B tests, an
 * approval board, and booking links that route prospects into the calendar.
 *
 * Gated by the `enable_outbound_booking_toolkit` setting.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'wp_mcp_ai_settings', array() );
if ( empty( $settings['enable_outbound_booking_toolkit'] ) ) {
	return;
}

$_oa_base = WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/';

require_once $_oa_base . 'class-wp-mcp-ai-oa-settings.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-templates.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-angle-cpt.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-booking-link-cpt.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-outbox.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-channels.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-engine.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-notifications.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-booking.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-import.php';
require_once $_oa_base . 'class-wp-mcp-ai-oa-rest.php';

if ( is_admin() ) {
	require_once $_oa_base . 'class-wp-mcp-ai-oa-dashboard.php';
	WP_MCP_AI_OA_Dashboard::init();
}

WP_MCP_AI_OA_Angle_CPT::init();
WP_MCP_AI_OA_Booking_Link_CPT::init();
WP_MCP_AI_OA_Outbox::init();
WP_MCP_AI_OA_Engine::init();
WP_MCP_AI_OA_Booking::init();

add_action( 'rest_api_init', array( 'WP_MCP_AI_OA_REST', 'register_routes' ) );

/**
 * Register the outbound-booking MCP tools.
 *
 * @since 2.12.0
 * @param array $tools Existing tools map (class => file).
 * @return array Updated tools map.
 */
function wp_mcp_ai_pro_register_outbound_booking_tools( $tools ) {
	$outbound_tools = array(
		'WP_MCP_AI_Tool_Outbound_Pipeline'     => WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/tools/class-wp-mcp-ai-tool-outbound-pipeline.php',
		'WP_MCP_AI_Tool_Outbound_Import_Leads' => WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/tools/class-wp-mcp-ai-tool-outbound-import-leads.php',
		'WP_MCP_AI_Tool_Outbound_Manage_Angle' => WP_MCP_AI_PRO_PATH . 'includes/tools/outbound-booking/tools/class-wp-mcp-ai-tool-outbound-manage-angle.php',
	);
	return array_merge( $tools, $outbound_tools );
}
add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_outbound_booking_tools', 10 );

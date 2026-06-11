<?php
/**
 * CRM & Email Marketing Toolkit Initialization
 *
 * Loads the CRM & Email Marketing Toolkit system for contact management,
 * email campaigns, lead tracking, and customer relationship management.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if CRM toolkit is enabled.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_crm_toolkit'] );
$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

// Only load if enabled and not in base version.
if ( $is_enabled && ! $is_base ) {

	// ---- Phase A: Shared CRM engine (loaded before any tool) ----
	$crm_engine_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/';

	// Shared engine classes (mirrors Healthcare toolkit architecture).
	$_crm_files = array(
		'class-wp-mcp-ai-crm-engine.php',
		'class-wp-mcp-ai-crm-codes.php',
		'class-wp-mcp-ai-crm-audit.php',
		'class-wp-mcp-ai-crm-capabilities.php',
		'class-wp-mcp-ai-crm-consent.php',
		'class-wp-mcp-ai-crm-pipeline-stages.php',
		'class-wp-mcp-ai-crm-classifier.php',
	);
	foreach ( $_crm_files as $_file ) {
		$_path = $crm_engine_dir . $_file;
		if ( file_exists( $_path ) ) {
			require_once $_path;
		}
	}

	// Load shared blueprint installer (used by import_crm_blueprint and import_healthcare_blueprint).
	$_installer = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
	if ( file_exists( $_installer ) ) {
		require_once $_installer;
	}

	// Load Company CPT.
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-company-cpt.php';
	WP_MCP_AI_Company_CPT::init();

	// Phase B: Load Lead, Deal, Activity, Support Ticket, and Customer CPTs.
	$_phase_b_cpts = array(
		'class-wp-mcp-ai-lead-cpt.php',
		'class-wp-mcp-ai-deal-cpt.php',
		'class-wp-mcp-ai-crm-activity-cpt.php',
		'class-wp-mcp-ai-support-ticket-cpt.php',
		'class-wp-mcp-ai-customer-cpt.php',
	);
	foreach ( $_phase_b_cpts as $_cpt_file ) {
		$_cpt_path = WP_MCP_AI_PRO_PATH . 'includes/' . $_cpt_file;
		if ( file_exists( $_cpt_path ) ) {
			require_once $_cpt_path;
		}
	}
	WP_MCP_AI_Lead_CPT::init();
	WP_MCP_AI_Deal_CPT::init();
	WP_MCP_AI_CRM_Activity_CPT::init();
	WP_MCP_AI_Support_Ticket_CPT::init();
	WP_MCP_AI_Customer_CPT::init();

	// Phase D: Load Sequence and Workflow Rule CPTs.
	$_phase_d_cpts = array(
		'class-wp-mcp-ai-sequence-cpt.php',
		'class-wp-mcp-ai-crm-workflow-rule-cpt.php',
	);
	foreach ( $_phase_d_cpts as $_cpt_file ) {
		$_cpt_path = WP_MCP_AI_PRO_PATH . 'includes/' . $_cpt_file;
		if ( file_exists( $_cpt_path ) ) {
			require_once $_cpt_path;
		}
	}
	WP_MCP_AI_Sequence_CPT::init();
	WP_MCP_AI_CRM_Workflow_Rule_CPT::init();

	// Load CRM admin pages.
	if ( is_admin() ) {
		// Load CRM Admin Menu registry (top-level "NV CRM" menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-crm-admin-menu.php';
		WP_MCP_AI_CRM_Admin_Menu::init();

		// Load CRM Command Center page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-crm-command-center-page.php';
		WP_MCP_AI_CRM_Command_Center_Page::init();

		// Load CRM Settings page (now under NV CRM menu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-crm-settings-page.php';
		new WP_MCP_AI_CRM_Settings_Page();

		// Load per-CPT settings pages (under each CPT's menu, like Image Settings).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-company-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-lead-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-deal-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-support-ticket-settings-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-customer-settings-page.php';

		// Load Research & Add pages (per-CPT, under individual CPT menus).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-company-research-page.php';
		WP_MCP_AI_Company_Research_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-lead-research-page.php';
		WP_MCP_AI_Lead_Research_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-deal-research-page.php';
		WP_MCP_AI_Deal_Research_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-customer-research-page.php';
		WP_MCP_AI_Customer_Research_Page::init();

		// Load per-CPT Settings pages (under each CPT submenu).
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-company-settings-page.php';
		WP_MCP_AI_Company_Settings_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-lead-settings-page.php';
		WP_MCP_AI_Lead_Settings_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-deal-settings-page.php';
		WP_MCP_AI_Deal_Settings_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-support-ticket-settings-page.php';
		WP_MCP_AI_Support_Ticket_Settings_Page::init();

		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-customer-settings-page.php';
		WP_MCP_AI_Customer_Settings_Page::init();

		// Load CRM Blueprints page.
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-crm-blueprints-page.php';
		WP_MCP_AI_CRM_Blueprints_Page::init();
	}

	// Load CRM REST controller for Toolkit Shell SPA.
	require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-crm-rest-controller.php';
	WP_MCP_AI_CRM_REST_Controller::get_instance()->init();

	// Load Research & Add for CCT/CPT integration.
	require_once WP_MCP_AI_PRO_PATH . 'includes/research-add/class-wp-mcp-ai-crm-research-add.php';
	new WP_MCP_AI_CRM_Research_Add();

	// Load support ticket tools.
	$_support_tools_init = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/support/init.php';
	if ( file_exists( $_support_tools_init ) ) {
		require_once $_support_tools_init;
	}

	// Register tools will be loaded automatically via the tools directory structure.
	// Tools are located in: addons/pro/includes/tools/crm/.
	// Upwork sub-tools are in: addons/pro/includes/tools/crm/upwork/.

	// ---- Phase C: Chat channel message → CRM inbound pipeline listener ----
	add_action( 'wp_mcp_ai_chat_channel_message_received', 'wp_mcp_ai_crm_handle_chat_channel_message', 10, 6 );

	// ---- Phase C: IMAP polling job (stub — see class-wp-mcp-ai-crm-imap-listener.php) ----
	$_imap_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-crm-imap-listener.php';
	if ( file_exists( $_imap_file ) ) {
		require_once $_imap_file;
		WP_MCP_AI_CRM_IMAP_Listener::maybe_schedule();
	}

	// ---- Phase C: Web form → CRM lead pipeline listener ----
	$_webform_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-crm-web-form-listener.php';
	if ( file_exists( $_webform_file ) ) {
		require_once $_webform_file;
		WP_MCP_AI_CRM_Web_Form_Listener::init();
	}

	// ---- Phase C: Twilio SMS inbound webhook ----
	$_sms_webhook_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-crm-sms-webhook-listener.php';
	if ( file_exists( $_sms_webhook_file ) ) {
		require_once $_sms_webhook_file;
		add_action( 'rest_api_init', array( 'WP_MCP_AI_CRM_SMS_Webhook_Listener', 'register_route' ) );
	}

	// ---- Phase C: Meta WhatsApp inbound webhook ----
	$_wa_webhook_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-crm-whatsapp-webhook-listener.php';
	if ( file_exists( $_wa_webhook_file ) ) {
		require_once $_wa_webhook_file;
		add_action( 'rest_api_init', array( 'WP_MCP_AI_CRM_WhatsApp_Webhook_Listener', 'register_route' ) );
	}
}

/**
 * Enqueue CRM toolkit admin styles.
 */
function wp_mcp_ai_enqueue_crm_toolkit_admin_styles() {
	// Only load if toolkit is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_crm_toolkit'] ) ) {
		return;
	}

	// Check if we're on a relevant admin page.
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	// Enqueue admin styles if available.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-crm-toolkit.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-crm-toolkit-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-crm-toolkit.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_crm_toolkit_admin_styles' );

	/**
	 * Handle an inbound chat channel message by routing it to the CRM evaluation pipeline.
	 *
	 * Hooks into 'wp_mcp_ai_chat_channel_message_received' fired by
	 * WP_MCP_AI_Channel_Messages_CCT::insert() for every inbound message.
	 *
	 * @since 2.3.0
	 *
	 * @param int    $message_id        Row ID of the persisted message.
	 * @param string $channel           Channel slug (whatsapp, telegram, etc.).
	 * @param string $channel_contact_id Platform-side contact/user ID.
	 * @param string $contact_name      Display name of the sender.
	 * @param string $content           Message body.
	 * @param string $message_type      Message type (text, image, etc.).
	 * @param string $connection_id     Remote Site Manager connection ID.
	 */
function wp_mcp_ai_crm_handle_chat_channel_message( $message_id, $channel, $channel_contact_id, $contact_name, $content, $message_type, $connection_id = '' ) {
	// Only process text messages.
	if ( 'text' !== $message_type ) {
		return;
	}

	// Bail if the evaluate_inbound_message tool class isn't loaded.
	$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-evaluate-inbound-message.php';
	if ( ! file_exists( $_tool_file ) ) {
		return;
	}
	require_once $_tool_file;

	if ( ! class_exists( 'WP_MCP_AI_Tool_Evaluate_Inbound_Message' ) ) {
		return;
	}

	// Build arguments for evaluate_inbound_message.
	$arguments = array(
		'channel'            => $channel,
		'channel_contact_id' => $channel_contact_id,
		'sender_name'        => $contact_name,
		'message_body'       => $content,
		'message_id'         => $message_id,
		'connection_id'      => $connection_id,
		'source'             => 'chat_channel',
	);

	$tool    = new WP_MCP_AI_Tool_Evaluate_Inbound_Message();
	$context = array( 'user_id' => 0 ); // System-initiated, no user context.
	$tool->execute( $arguments, $context );
}

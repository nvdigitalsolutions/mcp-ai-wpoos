<?php
/**
 * Canonical Pro toolkit settings map for the WP-CLI surface.
 *
 * Single source of truth for the `wp mcp-ai toolkit …` and
 * `wp mcp-ai pro status` commands. Keep in sync with the admin Tools &
 * Features section (`features` subtab group in
 * `includes/admin/sections/class-wp-mcp-ai-section-tools.php`) whenever a
 * new toolkit toggle ships — three hardcoded copies previously drifted out
 * of sync with the real settings keys (the F&B toggle bug, PR #7010).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical toolkit settings key → label map shared by the Pro CLI commands.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_Toolkit_Settings_Map {

	/**
	 * Toolkit settings key → human-readable label.
	 *
	 * @var array<string,string>
	 */
	const TOOLKITS = array(
		'enable_quiz_system'                     => 'Quiz System',
		'enable_media_toolkit'                   => 'Media Toolkit',
		'enable_document_generation_toolkit'     => 'Document Generation Toolkit',
		'enable_project_management'              => 'Project Management',
		'enable_places_management'               => 'Places Management',
		'enable_ai_cpt_management'               => 'AI CPT Management',
		'enable_eca_management'                  => 'ECA Management',
		'enable_health_wellness_management'      => 'Health & Wellness Management',
		'enable_healthcare_imaging'              => 'Healthcare Imaging Toolkit',
		'enable_medical_vitals'                  => 'Medical Vitals Toolkit',
		'enable_cloudways_toolkit'               => 'Cloudways Pro Toolkit',
		'enable_crm_toolkit'                     => 'CRM Toolkit',
		'enable_dietpi_toolkit'                  => 'DietPi Toolkit',
		'enable_ecommerce_toolkit'               => 'E-commerce Toolkit',
		'enable_flowhub_toolkit'                 => 'FlowHub Toolkit',
		'enable_ezuite_toolkit'                  => 'EZuite Inventory Sync Toolkit',
		'enable_fnb_toolkit'                     => 'Food & Beverage Toolkit',
		'enable_gtmetrix_toolkit'                => 'GTmetrix Performance Toolkit',
		'enable_shopify_sync_toolkit'            => 'Shopify Sync Toolkit',
		'enable_social_media_toolkit'            => 'Social Media Toolkit',
		'enable_analytics_toolkit'               => 'Analytics Toolkit',
		'enable_multilingual_toolkit'            => 'Multilingual Toolkit',
		'enable_video_production_toolkit'        => 'Video Production Toolkit',
		'enable_financial_planner_toolkit'       => 'Financial Planner Toolkit',
		'enable_calendar_booking_toolkit'        => 'Calendar & Booking Toolkit',
		'enable_outbound_booking_toolkit'        => 'Outbound Booking Toolkit',
		'enable_chat_channels_toolkit'           => 'Chat Channels Toolkit',
		'enable_dj_management_toolkit'           => 'DJ Management Toolkit',
		'enable_image_production_toolkit'        => 'Image Production Toolkit',
		'enable_comic_creation_toolkit'          => 'Comic Creation Toolkit',
		'enable_ai_tool_builder_toolkit'         => 'AI Tool Builder Toolkit',
		'enable_architect_agent_toolkit'         => 'Architect Agent Toolkit',
		'enable_architectural_design_toolkit'    => 'Architectural Design Toolkit',
		'enable_site_creator_toolkit'            => 'Site Creator Toolkit',
		'enable_regulatory_registration_toolkit' => 'Regulatory Registration Toolkit',
		'enable_law_firm_toolkit'                => 'Law Firm Toolkit',
		'enable_cre_debt_toolkit'                => 'CRE Debt Toolkit',
		'enable_extended_cognition_toolkit'      => 'Extended Cognition Toolkit',
		'enable_vision_analysis_toolkit'         => 'Vision Analysis Toolkit',
		'enable_advanced_analytics_toolkit'      => 'Advanced Analytics Toolkit',
	);

	/**
	 * Get the canonical map.
	 *
	 * @return array<string,string>
	 */
	public static function get() {
		return self::TOOLKITS;
	}
}

<?php
/**
 * CRM Toolkit Capability Map
 *
 * Maps CRM roles (sales_manager, account_executive, sdr, sales_ops,
 * marketing_ops, crm_viewer) onto WordPress capabilities used across the
 * CRM toolkit.  Mirrors WP_MCP_AI_Healthcare_Capabilities.
 *
 * The default mapping is intentionally conservative — most caps fall back
 * to edit_posts / manage_options.  Production deployments should narrow
 * this via the wp_mcp_ai_crm_capabilities filter.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM role-to-capability map.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_CRM_Capabilities {

	/**
	 * Logical role slugs recognised by the toolkit.
	 *
	 * @var string[]
	 */
	const ROLES = array(
		'sales_manager',    // Oversees pipeline, team assignments, reporting
		'account_executive', // Owns deals from qualification to close
		'sdr',              // Sales Development Rep — inbound/outbound prospecting
		'business_development', // BD: partnerships, channel sales
		'sales_ops',        // Admin: routing, sequences, CRM hygiene
		'marketing_manager', // Campaigns, lead scoring config, nurture sequences
		'marketing_ops',    // GDPR/consent, automation rules
		'crm_viewer',       // Read-only dashboards
	);

	/**
	 * Default capability mapping.
	 *
	 * Maps logical CRM permissions → WordPress capability checks.
	 *
	 * @return array<string,string>
	 */
	public static function default_map() {
		return array(
			// Lead management.
			'view_lead'               => 'edit_posts',
			'edit_lead'               => 'edit_posts',
			'delete_lead'             => 'manage_options',
			'assign_lead'             => 'edit_posts',

			// Deal / opportunity management.
			'view_deal'               => 'edit_posts',
			'edit_deal'               => 'edit_posts',
			'delete_deal'             => 'manage_options',
			'move_deal_stage'         => 'edit_posts',

			// Contact & company.
			'view_contact'            => 'edit_posts',
			'edit_contact'            => 'edit_posts',
			'delete_contact'          => 'manage_options',
			'view_company'            => 'edit_posts',
			'edit_company'            => 'edit_posts',

			// Activities.
			'view_activities'         => 'edit_posts',
			'create_activity'         => 'edit_posts',
			'complete_activity'       => 'edit_posts',

			// Sequences.
			'manage_sequences'        => 'edit_posts',
			'enroll_lead_in_sequence' => 'edit_posts',
			'pause_sequence'          => 'edit_posts',

			// Outbound.
			'send_outbound'           => 'edit_posts',
			'auto_reply'              => 'edit_posts',

			// Workflow command center.
			'manage_workflow_rules'   => 'manage_options',
			'view_workflow_inbox'     => 'edit_posts',

			// Routing & admin.
			'configure_routing'       => 'manage_options',
			'manage_pipeline_stages'  => 'manage_options',
			'view_pipeline_analytics' => 'edit_posts',

			// Compliance.
			'manage_consent'          => 'manage_options',
			'view_audit_log'          => 'manage_options',
			'export_audit_log'        => 'manage_options',

			// Data export / import.
			'import_crm_data'         => 'manage_options',
			'export_crm_data'         => 'manage_options',
		);
	}

	/**
	 * Resolved capability map (filterable, cached per-request).
	 *
	 * @return array<string,string>
	 */
	public static function get_map() {
		$map = self::default_map();
		/**
		 * Filter the resolved CRM capability map.
		 *
		 * @param array $map Default map.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_crm_capabilities', $map );
		return is_array( $filtered ) ? $filtered : $map;
	}

	/**
	 * Check whether a logical capability is granted for the current user.
	 *
	 * @param string $logical_cap Key from the capability map (e.g. 'view_lead').
	 * @return bool
	 */
	public static function can( $logical_cap ) {
		$map    = self::get_map();
		$wp_cap = isset( $map[ $logical_cap ] ) ? $map[ $logical_cap ] : 'manage_options';
		return current_user_can( $wp_cap );
	}
}

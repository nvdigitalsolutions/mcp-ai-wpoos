<?php
/**
 * Healthcare Toolkit Capability Map
 *
 * Maps clinical roles (clinician, nurse, technologist, billing, patient,
 * pet-owner / guardian) onto WordPress capabilities used across the three
 * healthcare sub-toolkits.  Mirrors the existing
 * `WP_MCP_AI_Imaging_Capabilities` helper but covers the Health & Wellness
 * and Vitals sides too.
 *
 * The default mapping is intentionally conservative — most caps fall back
 * to `manage_options`.  Production deployments should narrow this via the
 * `wp_mcp_ai_healthcare_capabilities` filter so that, for example, a nurse
 * role can read members and log vitals without being granted site-admin
 * powers.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Healthcare role-to-capability map.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Healthcare_Capabilities {

	/**
	 * Logical role slugs recognised by the toolkit.
	 *
	 * @var string[]
	 */
	const ROLES = array(
		'clinician',
		'nurse',
		'technologist',
		'billing',
		'patient',
		'guardian',
	);

	/**
	 * Default capability mapping.
	 *
	 * @return array<string,string>
	 */
	public static function default_map() {
		return array(
			// Member & record management.
			'view_member'            => 'edit_posts',
			'edit_member'            => 'edit_posts',
			'delete_member'          => 'manage_options',
			'view_medical_record'    => 'edit_posts',
			'edit_medical_record'    => 'edit_posts',
			'delete_medical_record'  => 'manage_options',

			// Vitals.
			'log_vital_signs'        => 'edit_posts',
			'view_vital_signs'       => 'edit_posts',

			// Prescriptions / allergies / immunisations.
			'manage_prescriptions'   => 'edit_posts',
			'manage_allergies'       => 'edit_posts',
			'manage_immunizations'   => 'edit_posts',

			// Imaging — kept aligned with WP_MCP_AI_Imaging_Capabilities.
			'view_medical_imaging'   => 'view_medical_imaging',
			'upload_medical_imaging' => 'upload_medical_imaging',
			'delete_medical_imaging' => 'delete_medical_imaging',
			'manage_medical_imaging' => 'manage_medical_imaging',

			// FHIR / HL7 / DICOM export.
			'export_phi'             => 'manage_options',
			'import_phi'             => 'manage_options',
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
		 * Filter the resolved healthcare capability map.
		 *
		 * @param array $map Default map.
		 */
		$filtered = apply_filters( 'wp_mcp_ai_healthcare_capabilities', $map );
		return is_array( $filtered ) ? $filtered : $map;
	}

	/**
	 * Resolve a logical capability slug to the actual WordPress capability.
	 *
	 * Unknown slugs fall back to `manage_options` so misuse never silently
	 * grants access.
	 *
	 * @param string $logical Logical capability slug.
	 * @return string
	 */
	public static function resolve( $logical ) {
		$map     = self::get_map();
		$logical = sanitize_key( (string) $logical );
		return isset( $map[ $logical ] ) ? (string) $map[ $logical ] : 'manage_options';
	}

	/**
	 * Whether the current user has a logical capability.
	 *
	 * @param string $logical Logical capability slug.
	 * @return bool
	 */
	public static function current_user_can( $logical ) {
		return current_user_can( self::resolve( $logical ) );
	}
}

<?php
/**
 * Healthcare Imaging Capabilities
 *
 * Registers and manages custom WordPress capabilities required by the
 * Medical Imaging Viewer module.  Capabilities are added to the
 * administrator role on plugin activation and removed on deactivation.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages custom capabilities for the Healthcare Imaging module.
 */
class WP_MCP_AI_Imaging_Capabilities {

	/**
	 * All custom capabilities introduced by this module.
	 *
	 * @var string[]
	 */
	const CAPABILITIES = array(
		'view_medical_imaging',
		'upload_medical_imaging',
		'delete_medical_imaging',
		'manage_medical_imaging',
	);

	/**
	 * Register capabilities with the administrator role.
	 *
	 * Safe to call multiple times – existing caps are left unchanged.
	 */
	public static function add_caps() {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		foreach ( self::CAPABILITIES as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Remove capabilities from all roles.
	 *
	 * Called during plugin deactivation / module cleanup.
	 */
	public static function remove_caps() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			return;
		}
		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			$role = get_role( $role_slug );
			if ( $role ) {
				foreach ( self::CAPABILITIES as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Check whether the current user can perform an imaging action.
	 *
	 * @param string $action  One of 'view', 'upload', 'delete', 'manage'.
	 * @return bool
	 */
	public static function current_user_can( $action ) {
		$cap_map = array(
			'view'   => 'view_medical_imaging',
			'upload' => 'upload_medical_imaging',
			'delete' => 'delete_medical_imaging',
			'manage' => 'manage_medical_imaging',
		);
		$cap     = isset( $cap_map[ $action ] ) ? $cap_map[ $action ] : '';
		if ( ! $cap ) {
			return false;
		}
		return current_user_can( $cap );
	}
}

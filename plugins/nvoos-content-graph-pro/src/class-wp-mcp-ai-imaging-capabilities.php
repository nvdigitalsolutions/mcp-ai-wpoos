<?php
/**
 * includes/class-wp-mcp-ai-imaging-capabilities.php (ecosystem port — Wave F4, healthcare toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/class-wp-mcp-ai-imaging-capabilities.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path
 * (the `defined( 'WP_MCP_AI_PRO_VERSION' )` base-mode gates stay byte-identical).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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

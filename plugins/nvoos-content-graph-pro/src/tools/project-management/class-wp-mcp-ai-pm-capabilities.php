<?php
/**
 * PM Capabilities Map (ecosystem port — Wave F2, PM toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/project-management/class-wp-mcp-ai-pm-capabilities.php` (or
 * `addons/pro/includes/`) for the standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The
 * base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`; no path constants — no path swaps.
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
 * PM role-to-capability map.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_PM_Capabilities {

	/**
	 * Logical role slugs recognised by the toolkit.
	 *
	 * @var string[]
	 */
	const ROLES = array(
		'project_manager',  // Oversees projects, team assignments, reporting.
		'scrum_master',     // Facilitates sprints, removes blockers.
		'product_owner',    // Owns backlog, priorities, stakeholder alignment.
		'team_member',      // Executes tasks, updates status.
		'stakeholder',      // Read-only visibility into portfolio.
		'resource_manager', // Staffing and allocation.
		'pm_viewer',        // Read-only dashboards.
	);

	/**
	 * PM capability declarations.
	 *
	 * @var string[]
	 */
	const CAPABILITIES = array(
		'create_projects',
		'edit_projects',
		'delete_projects',
		'view_all_projects',
		'create_tasks',
		'edit_tasks',
		'delete_tasks',
		'assign_tasks',
		'manage_sprints',
		'view_analytics',
		'manage_workflows',
		'manage_templates',
		'export_reports',
		'manage_para',
	);

	/**
	 * Cached role-to-capability map.
	 *
	 * @var array<string,string[]>|null
	 */
	private static $map_cache = null;

	/**
	 * Get the role-to-capability map (filterable, cached per-request).
	 *
	 * @return array<string,string[]>
	 */
	public static function get_role_map() {
		if ( null !== self::$map_cache ) {
			return self::$map_cache;
		}

		$map = array(
			'project_manager'  => array(
				'create_projects',
				'edit_projects',
				'delete_projects',
				'view_all_projects',
				'create_tasks',
				'edit_tasks',
				'delete_tasks',
				'assign_tasks',
				'manage_sprints',
				'view_analytics',
				'manage_workflows',
				'manage_templates',
				'export_reports',
				'manage_para',
			),
			'scrum_master'     => array(
				'create_tasks',
				'edit_tasks',
				'assign_tasks',
				'manage_sprints',
				'view_analytics',
				'view_all_projects',
			),
			'product_owner'    => array(
				'create_tasks',
				'edit_tasks',
				'view_all_projects',
				'view_analytics',
				'manage_para',
			),
			'team_member'      => array(
				'create_tasks',
				'edit_tasks',
			),
			'stakeholder'      => array(
				'view_all_projects',
				'view_analytics',
			),
			'resource_manager' => array(
				'view_all_projects',
				'assign_tasks',
				'view_analytics',
			),
			'pm_viewer'        => array(
				'view_all_projects',
			),
		);

		/**
		 * Filter the resolved PM role-to-capability map.
		 *
		 * @param array $map Default map.
		 */
		$filtered        = apply_filters( 'wp_mcp_ai_pm_capabilities', $map );
		self::$map_cache = is_array( $filtered ) ? $filtered : $map;

		return self::$map_cache;
	}

	/**
	 * Get the capabilities granted to a specific role.
	 *
	 * @param string $role Role slug.
	 * @return string[] Capability slugs.
	 */
	public static function get_role_capabilities( $role ) {
		$map = self::get_role_map();
		return isset( $map[ sanitize_key( $role ) ] ) ? $map[ sanitize_key( $role ) ] : array();
	}

	/**
	 * Check whether a role has a given logical capability.
	 *
	 * @param string $role       Role slug.
	 * @param string $capability PM capability slug.
	 * @return bool
	 */
	public static function role_has_capability( $role, $capability ) {
		return in_array( sanitize_key( $capability ), self::get_role_capabilities( $role ), true );
	}

	/**
	 * Map a PM logical capability to its WordPress equivalent.
	 *
	 * @param string $pm_capability PM capability slug.
	 * @return string WordPress capability string.
	 */
	public static function get_wp_capability( $pm_capability ) {
		$wp_map = array(
			'create_projects'   => 'edit_posts',
			'edit_projects'     => 'edit_others_posts',
			'delete_projects'   => 'delete_others_posts',
			'view_all_projects' => 'edit_posts',
			'create_tasks'      => 'edit_posts',
			'edit_tasks'        => 'edit_others_posts',
			'delete_tasks'      => 'delete_others_posts',
			'assign_tasks'      => 'edit_others_posts',
			'manage_sprints'    => 'edit_posts',
			'view_analytics'    => 'edit_posts',
			'manage_workflows'  => 'manage_options',
			'manage_templates'  => 'edit_posts',
			'export_reports'    => 'edit_posts',
			'manage_para'       => 'edit_posts',
		);

		return isset( $wp_map[ sanitize_key( $pm_capability ) ] )
			? $wp_map[ sanitize_key( $pm_capability ) ]
			: 'edit_posts';
	}
}

<?php
/**
 * QMS Capabilities.
 *
 * Provides the `manage_qms` capability used by all QMS tools and admin pages.
 * The capability is mapped onto editors and administrators by default. Site
 * administrators can override the mapping via the `wp_mcp_ai_qms_capability_roles`
 * filter or the QMS settings.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability registration helper.
 */
class WP_MCP_AI_QMS_Capabilities {

	const CAP = 'manage_qms';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_caps' ), 30 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_to_admins' ), 10, 4 );
	}

	/**
	 * Add the capability to qualifying roles.
	 */
	public static function add_caps() {
		if ( ! function_exists( 'get_role' ) ) {
			return;
		}

		/**
		 * Filter the roles that receive the `manage_qms` capability.
		 *
		 * @since 1.2.0
		 *
		 * @param array<int,string> $roles Role slugs.
		 */
		$roles = apply_filters( 'wp_mcp_ai_qms_capability_roles', array( 'administrator', 'editor' ) );
		foreach ( $roles as $role_slug ) {
			$role = get_role( $role_slug );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	/**
	 * Belt-and-suspenders: also grant manage_qms to anyone with manage_options.
	 *
	 * This allows multisite super admins and sites with custom roles to access
	 * QMS without explicit role mapping. Filterable for stricter setups.
	 *
	 * @param array        $allcaps All caps.
	 * @param array        $caps    Required caps.
	 * @param array        $args    Args.
	 * @param WP_User|null $user User.
	 * @return array
	 */
	public static function grant_to_admins( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args, $user );
		/**
		 * Whether to implicitly grant manage_qms to users with manage_options.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $grant Default true.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_qms_grant_to_admins', true ) ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	/**
	 * Whether the QMS subsystem is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		// Pro-only feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return false;
		}
		return ! empty( $settings['enable_qms_compliance'] );
	}
}

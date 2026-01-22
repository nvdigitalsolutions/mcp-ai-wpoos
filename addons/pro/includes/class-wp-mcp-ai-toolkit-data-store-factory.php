<?php
/**
 * Toolkit Data Store Factory
 *
 * Factory pattern for creating appropriate storage backend (CCT or CPT).
 * Determines which storage backend to use based on JetEngine availability
 * and user settings.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory class for creating toolkit data stores.
 */
class WP_MCP_AI_Toolkit_Data_Store_Factory {

	/**
	 * Get appropriate data store for toolkit entity.
	 *
	 * @param string $toolkit_slug Toolkit identifier (e.g., 'ecommerce', 'social_media').
	 * @param string $entity_type  Entity type (e.g., 'products', 'customers').
	 * @return WP_MCP_AI_Toolkit_Data_Store Data store implementation.
	 */
	public static function get_store( $toolkit_slug, $entity_type ) {
		// Check if JetEngine CCT is available and preferred.
		if ( self::is_jetengine_cct_available() ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/data-stores/class-wp-mcp-ai-toolkit-cct-store.php';
			return new WP_MCP_AI_Toolkit_CCT_Store( $toolkit_slug, $entity_type );
		}

		// Fallback to Custom Post Type storage.
		require_once WP_MCP_AI_PRO_PATH . 'includes/data-stores/class-wp-mcp-ai-toolkit-cpt-store.php';
		return new WP_MCP_AI_Toolkit_CPT_Store( $toolkit_slug, $entity_type );
	}

	/**
	 * Check if JetEngine CCT is available for use.
	 *
	 * @return bool True if JetEngine is active and CCT is enabled.
	 */
	private static function is_jetengine_cct_available() {
		// Check if JetEngine is installed and active.
		if ( ! function_exists( 'jet_engine' ) ) {
			return false;
		}

		// Check if user has enabled JetEngine CCT in settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_jetengine_cct'] ) ) {
			return false;
		}

		// Check if JetEngine CCT module is available.
		$cct_module = self::get_jetengine_cct_module();
		if ( ! $cct_module ) {
			return false;
		}

		return true;
	}

	/**
	 * Get JetEngine CCT module.
	 *
	 * @return object|false JetEngine CCT module or false if not available.
	 */
	private static function get_jetengine_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return false;
		}

		$jet_engine = jet_engine();
		if ( ! isset( $jet_engine->modules ) ) {
			return false;
		}

		$modules = $jet_engine->modules;
		if ( ! isset( $modules->modules_manager ) ) {
			return false;
		}

		$module = $modules->modules_manager->get_module( 'custom-content-types' );
		if ( ! $module || ! $module->instance ) {
			return false;
		}

		return $module->instance;
	}

	/**
	 * Get storage backend type that would be used.
	 *
	 * @return string 'cct' or 'cpt'.
	 */
	public static function get_storage_type() {
		return self::is_jetengine_cct_available() ? 'cct' : 'cpt';
	}

	/**
	 * Check if JetEngine is installed.
	 *
	 * @return bool True if JetEngine plugin is active.
	 */
	public static function is_jetengine_installed() {
		return function_exists( 'jet_engine' );
	}
}

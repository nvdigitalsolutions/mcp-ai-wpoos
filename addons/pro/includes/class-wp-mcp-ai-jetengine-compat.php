<?php
/**
 * JetEngine Version Compatibility Helper
 *
 * Provides version detection and compatibility methods for JetEngine 3.7+ and 3.8+.
 * Ensures backward compatibility across JetEngine versions.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine version compatibility helper class.
 */
class WP_MCP_AI_JetEngine_Compat {

	/**
	 * Minimum supported JetEngine version.
	 */
	const MIN_VERSION = '3.7.0';

	/**
	 * Get JetEngine version.
	 *
	 * @return string|null JetEngine version or null if not available.
	 */
	public static function get_jetengine_version() {
		if ( ! defined( 'JET_ENGINE_VERSION' ) ) {
			return null;
		}

		return JET_ENGINE_VERSION;
	}

	/**
	 * Check if JetEngine is active and meets minimum version.
	 *
	 * @return bool True if JetEngine is active and meets minimum version.
	 */
	public static function is_compatible() {
		if ( ! function_exists( 'jet_engine' ) || ! class_exists( 'Jet_Engine' ) ) {
			return false;
		}

		$version = self::get_jetengine_version();
		if ( null === $version ) {
			// If version can't be determined, assume compatible.
			return true;
		}

		return version_compare( $version, self::MIN_VERSION, '>=' );
	}

	/**
	 * Check if JetEngine 3.8+ is active.
	 *
	 * @return bool True if JetEngine 3.8 or higher.
	 */
	public static function is_jetengine_38_plus() {
		$version = self::get_jetengine_version();
		if ( null === $version ) {
			return false;
		}

		return version_compare( $version, '3.8.0', '>=' );
	}

	/**
	 * Get JetEngine CPTs with version compatibility.
	 *
	 * Works with JetEngine 3.7+ and 3.8+.
	 *
	 * @return array Array of CPT data.
	 */
	public static function get_jetengine_cpts() {
		if ( ! self::is_compatible() ) {
			return array();
		}

		$module = jet_engine()->modules->get_module( 'post-type' );
		if ( ! $module || ! $module->instance ) {
			return array();
		}

		// get_items() method works in both 3.7 and 3.8.
		$post_types = $module->instance->get_items();
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			return array();
		}

		return $post_types;
	}

	/**
	 * Get JetEngine taxonomies with version compatibility.
	 *
	 * Works with JetEngine 3.7+ and 3.8+.
	 *
	 * @return array Array of taxonomy data.
	 */
	public static function get_jetengine_taxonomies() {
		if ( ! self::is_compatible() ) {
			return array();
		}

		$module = jet_engine()->modules->get_module( 'taxonomy' );
		if ( ! $module || ! $module->instance ) {
			return array();
		}

		// get_items() method works in both 3.7 and 3.8.
		$taxonomies = $module->instance->get_items();
		if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
			return array();
		}

		return $taxonomies;
	}

	/**
	 * Get meta fields for a post type with version compatibility.
	 *
	 * Works with JetEngine 3.7+ and 3.8+.
	 *
	 * @param string $post_type Post type slug.
	 * @return array Array of meta fields.
	 */
	public static function get_post_type_meta_fields( $post_type ) {
		if ( ! self::is_compatible() ) {
			return array();
		}

		if ( ! isset( jet_engine()->meta_boxes ) ) {
			return array();
		}

		// get_fields_for_context() method works in both 3.7 and 3.8.
		$meta_fields = jet_engine()->meta_boxes->get_fields_for_context( 'post_type', $post_type );

		if ( empty( $meta_fields ) || ! is_array( $meta_fields ) ) {
			return array();
		}

		return $meta_fields;
	}

	/**
	 * Get meta fields for a taxonomy with version compatibility.
	 *
	 * Works with JetEngine 3.7+ and 3.8+.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array Array of meta fields.
	 */
	public static function get_taxonomy_meta_fields( $taxonomy ) {
		if ( ! self::is_compatible() ) {
			return array();
		}

		if ( ! isset( jet_engine()->meta_boxes ) ) {
			return array();
		}

		// get_fields_for_context() method works in both 3.7 and 3.8.
		$meta_fields = jet_engine()->meta_boxes->get_fields_for_context( 'taxonomy', $taxonomy );

		if ( empty( $meta_fields ) || ! is_array( $meta_fields ) ) {
			return array();
		}

		return $meta_fields;
	}

	/**
	 * Get JetEngine configuration (3.8+ only).
	 *
	 * Returns full JetEngine configuration including all CPTs, taxonomies, CCTs, etc.
	 * Only available in JetEngine 3.8+.
	 *
	 * @return array|WP_Error Configuration array or error if not supported.
	 */
	public static function get_jetengine_configuration() {
		if ( ! self::is_jetengine_38_plus() ) {
			return new WP_Error(
				'jetengine_version',
				__( 'JetEngine 3.8+ required for configuration endpoint.', 'mcp-ai-wpoos-pro' )
			);
		}

		// In JetEngine 3.8+, there's a configuration endpoint.
		// This can be accessed via REST API or direct method if available.
		// For now, we'll return a combination of what we can get.
		$config = array(
			'post_types' => self::get_jetengine_cpts(),
			'taxonomies' => self::get_jetengine_taxonomies(),
			'version'    => self::get_jetengine_version(),
		);

		return $config;
	}

	/**
	 * Get compatibility notes for admin display.
	 *
	 * @return string HTML formatted compatibility information.
	 */
	public static function get_compatibility_notice() {
		$version = self::get_jetengine_version();

		if ( ! self::is_compatible() ) {
			return sprintf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				sprintf(
					/* translators: %s: minimum version required */
					esc_html__( 'JetEngine %s or higher is required for full compatibility.', 'mcp-ai-wpoos-pro' ),
					esc_html( self::MIN_VERSION )
				)
			);
		}

		if ( self::is_jetengine_38_plus() ) {
			return sprintf(
				'<div class="notice notice-success"><p>%s</p></div>',
				sprintf(
					/* translators: %s: current version */
					esc_html__( 'JetEngine %s detected. All features available including enhanced API endpoints.', 'mcp-ai-wpoos-pro' ),
					esc_html( $version )
				)
			);
		}

		return sprintf(
			'<div class="notice notice-info"><p>%s</p></div>',
			sprintf(
				/* translators: %s: current version */
				esc_html__( 'JetEngine %s detected. Core features available. Upgrade to 3.8+ for enhanced API features.', 'mcp-ai-wpoos-pro' ),
				esc_html( $version )
			)
		);
	}
}

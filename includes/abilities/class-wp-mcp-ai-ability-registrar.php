<?php
/**
 * Ability Registrar — bulk-registers eligible NV oOS tools as WordPress Abilities.
 *
 * Iterates the tool registry and bridges each eligible tool to a
 * wp_register_ability() call. Uses a curated allowlist for the
 * selective adoption strategy (Phase 1: ~20-50 discovery tools).
 *
 * All registrations are guarded by function_exists('wp_register_ability')
 * for backward compatibility with WordPress < 6.9.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk-registers eligible tools as WordPress Abilities.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Ability_Registrar {

	/**
	 * Mapping of tool slugs to their ability category.
	 *
	 * This is the curated allowlist for Phase 1 selective adoption.
	 * Tools not in this map are NOT registered as Abilities — they
	 * continue to work through the existing custom tool registry.
	 *
	 * @since 2.0.0
	 * @var array<string, string>
	 */
	const TOOL_CATEGORY_MAP = array(
		// nvoos-site — Site Information.
		'get_site_summary'               => 'nvoos-site',
		'get_user_info'                  => 'nvoos-site',
		'get_environment_status'         => 'nvoos-site',
		'get_profession'                 => 'nvoos-site',
		'get_system_logs'                => 'nvoos-site',
		'get_update_status'              => 'nvoos-site',

		// nvoos-content — Content & Publishing.
		'get_post'                       => 'nvoos-content',
		'get_recent_posts'               => 'nvoos-content',
		'get_post_type_schema'           => 'nvoos-content',
		'get_rankmath_seo'               => 'nvoos-content',
		'search_content'                 => 'nvoos-content',
		'get_jetengine_items'            => 'nvoos-content',
		'get_elementor_templates'        => 'nvoos-content',
		'get_jetformbuilder_forms'       => 'nvoos-content',
		'get_jetformbuilder_submissions' => 'nvoos-content',
		'get_elementor_form_submissions' => 'nvoos-content',
		'get_all_form_submissions'       => 'nvoos-content',
		'list_jetengine_rest_routes'     => 'nvoos-content',

		// nvoos-media — Media & Images.
		'search_attachments'             => 'nvoos-media',

		// nvoos-system — System & Diagnostics.
		'get_site_health'                => 'nvoos-system',
		'list_cron_jobs'                 => 'nvoos-system',
		'get_cron_job'                   => 'nvoos-system',
		'list_vector_stores'             => 'nvoos-system',
		'list_professions'               => 'nvoos-system',
		'get_all_import_status'          => 'nvoos-system',
		'get_batch_status'               => 'nvoos-system',
		'get_openai_file_details'        => 'nvoos-system',
		'list_all_export_templates'      => 'nvoos-system',
		'list_all_import_templates'      => 'nvoos-system',
		'list_batches'                   => 'nvoos-system',
		'list_openai_files'              => 'nvoos-system',

		// nvoos-discovery — AI Model Discovery.
		'list_available_models'          => 'nvoos-discovery',
		'get_model_information'          => 'nvoos-discovery',
		'suggest_best_model'             => 'nvoos-discovery',
		'discover_new_models'            => 'nvoos-discovery',
		'count_tokens'                   => 'nvoos-discovery',
		'get_woo_products'               => 'nvoos-discovery',
		'get_nhc_active_storms'          => 'nvoos-discovery',
		'get_open_meteo_forecast'        => 'nvoos-discovery',
		'get_gdacs_events'               => 'nvoos-discovery',
		'search_places'                  => 'nvoos-discovery',
	);

	/**
	 * Hook into wp_abilities_api_init.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_all' ) );
	}

	/**
	 * Register all eligible tools as Abilities.
	 *
	 * Called on wp_abilities_api_init. Iterates the tool registry and
	 * bridges each tool in the curated allowlist.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function register_all() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_all_tools();

		foreach ( $tools as $slug => $tool ) {
			if ( ! isset( self::TOOL_CATEGORY_MAP[ $slug ] ) ) {
				continue;
			}

			// Skip tools whose dependencies are not met.
			if ( ! self::is_tool_available( $tool ) ) {
				continue;
			}

			$category = self::TOOL_CATEGORY_MAP[ $slug ];
			WP_MCP_AI_Ability_Bridge::register( $tool, $category );
		}

		/**
		 * Fires after all NV oOS tool abilities are registered.
		 *
		 * @since 2.0.0
		 */
		do_action( 'wp_mcp_ai_abilities_registered' );
	}

	/**
	 * Check whether a tool is available for ability registration.
	 *
	 * Skips tools whose dependencies are not met (e.g. WooCommerce
	 * tools when WooCommerce is not active). Uses the tool's own
	 * is_available() static method if it exists, falling back to
	 * capability flag inspection.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool The tool instance.
	 * @return bool True if the tool is available.
	 */
	private static function is_tool_available( $tool ) {
		$class_name = get_class( $tool );

		// If the tool declares a static is_available() check, respect it.
		if ( is_callable( array( $class_name, 'is_available' ) ) ) {
			return (bool) call_user_func( array( $class_name, 'is_available' ) );
		}

		// Fallback: check capability flags for dependency requirements.
		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();

			// Tools that require a specific plugin should only register
			// if the tool itself is available (handled above). Without
			// is_available(), we cannot reliably detect plugin presence
			// from flags alone, so we err on the side of registering.
			if ( in_array( 'requires-plugin', $flags, true ) ) {
				// No static is_available() — skip to avoid errors.
				return false;
			}

			// Tools requiring credentials but without a configured key
			// should not be registered as Abilities.
			if ( in_array( 'requires-credentials', $flags, true ) ) {
				return false;
			}
		}

		return true;
	}
}

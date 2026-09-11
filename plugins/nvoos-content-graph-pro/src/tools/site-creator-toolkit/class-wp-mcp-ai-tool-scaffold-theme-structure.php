<?php
/**
 * WP_MCP_AI_Tool_Scaffold_Theme_Structure (ecosystem port - Wave F2, site-creator tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/site-creator-toolkit/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith); the theme-json-generator require gains an exists-check seam resolving from `src/helpers/`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);



if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * Scaffold Theme Structure Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Scaffold_Theme_Structure implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'scaffold_theme_structure';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Scaffold Theme Structure', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates complete WordPress theme scaffolding with templates, functions, styles, and best practices implementation.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'theme_name'       => array(
					'type'        => 'string',
					'description' => __( 'Theme name', 'nvoos-content-graph-pro' ),
				),
				'theme_type'       => array(
					'type'        => 'string',
					'description' => __( 'Theme type', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'classic', 'block', 'hybrid' ),
					'default'     => 'block',
				),
				'features'         => array(
					'type'        => 'array',
					'description' => __( 'Features to include', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'industry'         => array(
					'type'        => 'string',
					'description' => __( 'Industry type for color palette (technology, healthcare, finance, ecommerce)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'technology', 'healthcare', 'finance', 'ecommerce' ),
				),
				'custom_templates' => array(
					'type'        => 'array',
					'description' => __( 'Custom page templates to include', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'patterns'         => array(
					'type'        => 'array',
					'description' => __( 'Block pattern slugs to register', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'theme_name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Theme structure or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$theme_name       = isset( $arguments['theme_name'] ) ? sanitize_text_field( $arguments['theme_name'] ) : '';
		$theme_type       = isset( $arguments['theme_type'] ) ? sanitize_text_field( $arguments['theme_type'] ) : 'block';
		$features         = isset( $arguments['features'] ) && is_array( $arguments['features'] ) ?
			array_map( 'sanitize_text_field', $arguments['features'] ) : array();
		$industry         = isset( $arguments['industry'] ) ? sanitize_key( $arguments['industry'] ) : '';
		$custom_templates = isset( $arguments['custom_templates'] ) && is_array( $arguments['custom_templates'] ) ?
			$arguments['custom_templates'] : array();
		$patterns         = isset( $arguments['patterns'] ) && is_array( $arguments['patterns'] ) ?
			array_map( 'sanitize_text_field', $arguments['patterns'] ) : array();

		if ( empty( $theme_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Theme name is required.', 'nvoos-content-graph-pro' ) );
		}

		// Generate comprehensive theme.json if block or hybrid theme.
		$theme_json = null;
		if ( 'block' === $theme_type || 'hybrid' === $theme_type ) {
			// Load theme.json generator if not already loaded.
			if ( ! class_exists( 'WP_MCP_AI_Theme_JSON_Generator' ) ) {
				$nvoos_content_graph_pro_theme_json_generator = defined( 'WP_MCP_AI_PATH' )
					? dirname( dirname( __DIR__ ) ) . '/helpers/class-wp-mcp-ai-theme-json-generator.php'
					: NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/helpers/class-wp-mcp-ai-theme-json-generator.php';
				if ( file_exists( $nvoos_content_graph_pro_theme_json_generator ) ) {
					require_once $nvoos_content_graph_pro_theme_json_generator;
				}
			}

			$theme_json_args = array(
				'theme_name'       => $theme_name,
				'theme_type'       => $theme_type,
				'custom_templates' => $custom_templates,
				'patterns'         => $patterns,
			);

			// Add industry-specific color palette if provided.
			if ( ! empty( $industry ) ) {
				$theme_json_args['color_palette'] = WP_MCP_AI_Theme_JSON_Generator::get_industry_color_palette( $industry );
			}

			$theme_json_data = WP_MCP_AI_Theme_JSON_Generator::generate( $theme_json_args );

			// Validate theme.json.
			$validation = WP_MCP_AI_Theme_JSON_Generator::validate( $theme_json_data );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			// Convert to JSON string.
			$theme_json = WP_MCP_AI_Theme_JSON_Generator::to_json( $theme_json_data, true );
		}

		// Generate theme structure.
		$theme_structure = array(
			'name'       => $theme_name,
			'type'       => $theme_type,
			'slug'       => sanitize_title( $theme_name ),
			'files'      => $this->get_theme_files( $theme_type ),
			'templates'  => $this->get_theme_templates( $theme_type ),
			'features'   => $this->get_theme_features( $features ),
			'theme_json' => $theme_json,
		);

		return array(
			'success'         => true,
			'theme_structure' => $theme_structure,
			/* translators: 1: theme type, 2: theme name */
			'summary'         => sprintf( __( 'Generated %1$s theme structure for "%2$s" with comprehensive theme.json following 2025 best practices.', 'nvoos-content-graph-pro' ), $theme_type, $theme_name ),
			'timestamp'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Get theme files.
	 *
	 * @since 1.2.0
	 *
	 * @param string $theme_type Theme type.
	 * @return array Files list.
	 */
	private function get_theme_files( $theme_type ) {
		$base_files = array(
			'style.css',
			'functions.php',
			'index.php',
			'header.php',
			'footer.php',
			'screenshot.png',
		);

		if ( 'block' === $theme_type || 'hybrid' === $theme_type ) {
			$base_files[] = 'theme.json';
		}

		return $base_files;
	}

	/**
	 * Get theme templates.
	 *
	 * @since 1.2.0
	 *
	 * @param string $theme_type Theme type.
	 * @return array Templates list.
	 */
	private function get_theme_templates( $theme_type ) {
		return array(
			'home.php',
			'single.php',
			'page.php',
			'archive.php',
			'404.php',
			'search.php',
		);
	}

	/**
	 * Get theme features.
	 *
	 * @since 1.2.0
	 *
	 * @param array $features Requested features.
	 * @return array Features configuration.
	 */
	private function get_theme_features( $features ) {
		$default_features = array(
			'post-thumbnails',
			'title-tag',
			'custom-logo',
			'html5',
			'responsive-embeds',
		);

		return array_unique( array_merge( $default_features, $features ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'consumes-tokens', 'non-deterministic' );
	}
}

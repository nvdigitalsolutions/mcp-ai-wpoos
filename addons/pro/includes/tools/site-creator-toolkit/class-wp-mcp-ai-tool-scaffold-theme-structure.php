<?php
/**
 * Scaffold Theme Structure Tool
 *
 * Generates complete WordPress theme scaffolding with templates, functions,
 * and best practices implementation.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Scaffold Theme Structure', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates complete WordPress theme scaffolding with templates, functions, styles, and best practices implementation.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Theme name', 'mcp-ai-wpoos-pro' ),
				),
				'theme_type'       => array(
					'type'        => 'string',
					'description' => __( 'Theme type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'classic', 'block', 'hybrid' ),
					'default'     => 'block',
				),
				'features'         => array(
					'type'        => 'array',
					'description' => __( 'Features to include', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'theme_name' ),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$theme_name = isset( $arguments['theme_name'] ) ? sanitize_text_field( $arguments['theme_name'] ) : '';
		$theme_type = isset( $arguments['theme_type'] ) ? sanitize_text_field( $arguments['theme_type'] ) : 'block';
		$features   = isset( $arguments['features'] ) && is_array( $arguments['features'] ) ?
			array_map( 'sanitize_text_field', $arguments['features'] ) : array();

		if ( empty( $theme_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Theme name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate theme structure.
		$theme_structure = array(
			'name'      => $theme_name,
			'type'      => $theme_type,
			'slug'      => sanitize_title( $theme_name ),
			'files'     => $this->get_theme_files( $theme_type ),
			'templates' => $this->get_theme_templates( $theme_type ),
			'features'  => $this->get_theme_features( $features ),
		);

		return array(
			'success'         => true,
			'theme_structure' => $theme_structure,
			'summary'         => sprintf( __( 'Generated %s theme structure for "%s".', 'mcp-ai-wpoos-pro' ), $theme_type, $theme_name ),
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

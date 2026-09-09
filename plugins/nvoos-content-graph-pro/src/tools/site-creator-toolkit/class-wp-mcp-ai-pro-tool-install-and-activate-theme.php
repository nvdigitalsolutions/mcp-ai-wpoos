<?php
/**
 * WP_MCP_AI_Pro_Tool_Install_And_Activate_Theme (ecosystem port - Wave F2, site-creator tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/site-creator-toolkit/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith); the `WP_MCP_AI_PATH` upgrader-skin require gains a monolith-gated exists-check seam resolving from `src/class-wp-mcp-ai-upgrader-skin.php`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);



if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
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
 * Install and Activate Theme Tool
 *
 * Installs themes from the WordPress.org repository and activates them.
 */
class WP_MCP_AI_Pro_Tool_Install_And_Activate_Theme implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'install_and_activate_theme';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Install and Activate Theme', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Installs a theme from the WordPress.org repository and activates it. Requires the theme slug.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'    => array(
					'type'        => 'string',
					'description' => __( 'The slug of the theme from the WordPress.org repository (e.g., "astra").', 'nvoos-content-graph-pro' ),
				),
				'version' => array(
					'type'        => 'string',
					'description' => __( 'Optional specific version to install (e.g., "3.0.0"). Leave empty for latest.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'slug' ),
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
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator features are enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator'] ) || empty( $settings['site_creator_allow_theme_install'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The install_and_activate_theme tool is disabled. Enable it in NV oOS → Tools & Features → Site Creator settings.', 'nvoos-content-graph-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'install_themes' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to install themes.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! user_can( $user_id, 'switch_themes' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to activate themes.', 'nvoos-content-graph-pro' )
			);
		}

		$slug    = isset( $arguments['slug'] ) ? sanitize_key( $arguments['slug'] ) : '';
		$version = isset( $arguments['version'] ) ? sanitize_text_field( $arguments['version'] ) : '';

		if ( empty( $slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_slug',
				__( 'Theme slug not provided.', 'nvoos-content-graph-pro' )
			);
		}

		// Check if theme is already active.
		$current_theme = wp_get_theme();
		if ( $current_theme->get_stylesheet() === $slug ) {
			return array(
				'success'        => true,
				'theme_slug'     => $slug,
				'theme_name'     => $current_theme->get( 'Name' ),
				'already_active' => true,
				'message'        => sprintf(
					/* translators: %s: theme name */
					__( 'Theme "%s" is already active.', 'nvoos-content-graph-pro' ),
					$current_theme->get( 'Name' )
				),
			);
		}

		// Load required WordPress files.
		$this->load_required_files();

		// Check if theme exists.
		$theme = wp_get_theme( $slug );

		// Install if not already installed.
		if ( ! $theme->exists() ) {
			$install_result = $this->install_theme( $slug, $version );
			if ( is_wp_error( $install_result ) ) {
				return $install_result;
			}

			$theme = wp_get_theme( $slug );
		}

		// Activate the theme.
		$activation_result = $this->activate_theme( $slug );
		if ( is_wp_error( $activation_result ) ) {
			return $activation_result;
		}

		$new_theme = wp_get_theme();

		return array(
			'success'    => true,
			'theme_slug' => $new_theme->get_stylesheet(),
			'theme_name' => $new_theme->get( 'Name' ),
			'version'    => $new_theme->get( 'Version' ),
			'message'    => sprintf(
				/* translators: %s: theme name */
				__( 'Theme "%s" has been activated.', 'nvoos-content-graph-pro' ),
				$new_theme->get( 'Name' )
			),
		);
	}

	/**
	 * Load required WordPress admin files.
	 */
	private function load_required_files() {
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Upgrader_Skin' ) ) {
			$nvoos_content_graph_pro_upgrader_skin = defined( 'WP_MCP_AI_PATH' )
				? WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-upgrader-skin.php'
				: NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-upgrader-skin.php';
			if ( file_exists( $nvoos_content_graph_pro_upgrader_skin ) ) {
				require_once $nvoos_content_graph_pro_upgrader_skin;
			}
		}
	}

	/**
	 * Install a theme from WordPress.org repository.
	 *
	 * @param string $slug    Theme slug.
	 * @param string $version Optional specific version.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function install_theme( $slug, $version = '' ) {
		// Get theme information from WordPress.org API.
		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'description'     => false,
					'sections'        => false,
					'rating'          => false,
					'ratings'         => false,
					'downloaded'      => false,
					'downloadlink'    => true,
					'last_updated'    => false,
					'homepage'        => false,
					'tags'            => false,
					'template'        => false,
					'parent'          => false,
					'versions'        => true,
					'screenshot_url'  => false,
					'active_installs' => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return new WP_Error(
				'wp_mcp_ai_theme_api_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not retrieve theme information: %s', 'nvoos-content-graph-pro' ),
					$api->get_error_message()
				)
			);
		}

		// Determine download link.
		$download_link = $api->download_link;
		if ( ! empty( $version ) && isset( $api->versions[ $version ] ) ) {
			$download_link = $api->versions[ $version ];
		}

		// Install the theme.
		$skin     = new WP_MCP_AI_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $download_link );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_install_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Theme installation failed: %s', 'nvoos-content-graph-pro' ),
					$result->get_error_message()
				)
			);
		}

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_install_failed',
				__( 'Theme installation failed for an unknown reason.', 'nvoos-content-graph-pro' )
			);
		}

		return true;
	}

	/**
	 * Activate a theme.
	 *
	 * @param string $slug Theme slug.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function activate_theme( $slug ) {
		// Switch to the new theme.
		switch_theme( $slug );

		// Verify activation.
		$new_theme = wp_get_theme();
		if ( $new_theme->get_stylesheet() !== $slug ) {
			return new WP_Error(
				'wp_mcp_ai_activation_failed',
				sprintf(
					/* translators: %s: theme slug */
					__( 'Failed to activate theme "%s".', 'nvoos-content-graph-pro' ),
					$slug
				)
			);
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Installs and activates themes.
			'external-api',         // Calls WordPress.org API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires install_themes and switch_themes.
			'state-changing',       // Modifies site state.
			'async',                // May take significant time.
			'performance-impact',   // Changes site appearance immediately.
		);
	}
}

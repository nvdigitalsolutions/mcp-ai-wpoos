<?php
/**
 * Tool for installing and activating WordPress themes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Install and Activate Theme Tool
 *
 * Installs themes from the WordPress.org repository and activates them.
 */
class WP_MCP_AI_Tool_Install_And_Activate_Theme implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Install and Activate Theme', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Installs a theme from the WordPress.org repository and activates it. Requires the theme slug.', 'wp-mcp-ai' );
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
					'description' => __( 'The slug of the theme from the WordPress.org repository (e.g., "astra").', 'wp-mcp-ai' ),
				),
				'version' => array(
					'type'        => 'string',
					'description' => __( 'Optional specific version to install (e.g., "3.0.0"). Leave empty for latest.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'slug' ),
			'additionalProperties' => false,
		);
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
				__( 'The install_and_activate_theme tool is disabled. Enable it in WP oOS → Tools & Features → Site Creator settings.', 'wp-mcp-ai' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'install_themes' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to install themes.', 'wp-mcp-ai' )
			);
		}

		if ( ! user_can( $user_id, 'switch_themes' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to activate themes.', 'wp-mcp-ai' )
			);
		}

		$slug    = isset( $arguments['slug'] ) ? sanitize_key( $arguments['slug'] ) : '';
		$version = isset( $arguments['version'] ) ? sanitize_text_field( $arguments['version'] ) : '';

		if ( empty( $slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_slug',
				__( 'Theme slug not provided.', 'wp-mcp-ai' )
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
					__( 'Theme "%s" is already active.', 'wp-mcp-ai' ),
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
				__( 'Theme "%s" has been activated.', 'wp-mcp-ai' ),
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
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-upgrader-skin.php';
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
					__( 'Could not retrieve theme information: %s', 'wp-mcp-ai' ),
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
					__( 'Theme installation failed: %s', 'wp-mcp-ai' ),
					$result->get_error_message()
				)
			);
		}

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_install_failed',
				__( 'Theme installation failed for an unknown reason.', 'wp-mcp-ai' )
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
					__( 'Failed to activate theme "%s".', 'wp-mcp-ai' ),
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

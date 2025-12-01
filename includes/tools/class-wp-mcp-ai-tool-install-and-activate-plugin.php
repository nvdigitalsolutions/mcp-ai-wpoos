<?php
/**
 * Tool for installing and activating WordPress plugins.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Install and Activate Plugin Tool
 *
 * Installs plugins from the WordPress.org repository and activates them.
 */
class WP_MCP_AI_Tool_Install_And_Activate_Plugin implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'install_and_activate_plugin';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Install and Activate Plugin', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Installs a plugin from the WordPress.org repository and activates it. Requires the plugin slug.', 'wp-mcp-ai' );
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
					'description' => __( 'The slug of the plugin from the WordPress.org repository (e.g., "elementor").', 'wp-mcp-ai' ),
				),
				'version' => array(
					'type'        => 'string',
					'description' => __( 'Optional specific version to install (e.g., "3.0.0"). Leave empty for latest.', 'wp-mcp-ai' ),
				),
				'network' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to network activate the plugin on multisite. Default false.', 'wp-mcp-ai' ),
					'default'     => false,
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
		if ( empty( $settings['enable_site_creator'] ) || empty( $settings['site_creator_allow_plugin_install'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The install_and_activate_plugin tool is disabled. Enable it in WP oOS → Tools & Features → Site Creator settings.', 'wp-mcp-ai' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'install_plugins' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to install plugins.', 'wp-mcp-ai' )
			);
		}

		if ( ! user_can( $user_id, 'activate_plugins' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to activate plugins.', 'wp-mcp-ai' )
			);
		}

		$slug           = isset( $arguments['slug'] ) ? sanitize_key( $arguments['slug'] ) : '';
		$version        = isset( $arguments['version'] ) ? sanitize_text_field( $arguments['version'] ) : '';
		$network_active = isset( $arguments['network'] ) ? (bool) $arguments['network'] : false;

		if ( empty( $slug ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_slug',
				__( 'Plugin slug not provided.', 'wp-mcp-ai' )
			);
		}

		// Check for network activation permission on multisite.
		if ( $network_active && is_multisite() && ! user_can( $user_id, 'manage_network_plugins' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to network activate plugins.', 'wp-mcp-ai' )
			);
		}

		// Load required WordPress files.
		$this->load_required_files();

		// Check if plugin is already installed.
		$plugin_file = $this->find_plugin_file( $slug );

		// Install if not already installed.
		if ( ! $plugin_file ) {
			$install_result = $this->install_plugin( $slug, $version );
			if ( is_wp_error( $install_result ) ) {
				return $install_result;
			}

			$plugin_file = $install_result['plugin_file'];
			$plugin_name = $install_result['plugin_name'];
		} else {
			// Get plugin data for already installed plugin.
			$plugin_data = $this->get_plugin_data( $plugin_file );
			$plugin_name = $plugin_data['Name'];
		}

		// Activate the plugin.
		$activation_result = $this->activate_plugin( $plugin_file, $network_active );
		if ( is_wp_error( $activation_result ) ) {
			return $activation_result;
		}

		return array(
			'success'        => true,
			'plugin_file'    => $plugin_file,
			'plugin_name'    => $plugin_name,
			'network_active' => $network_active,
			'message'        => sprintf(
				/* translators: %s: plugin name */
				__( 'Plugin "%s" is installed and active.', 'wp-mcp-ai' ),
				$plugin_name
			),
		);
	}

	/**
	 * Load required WordPress admin files.
	 */
	private function load_required_files() {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Upgrader_Skin' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-upgrader-skin.php';
		}
	}

	/**
	 * Find the main plugin file from a slug.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|null Plugin file path or null if not found.
	 */
	private function find_plugin_file( $slug ) {
		$plugins = get_plugins();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			// Check if the plugin file matches the slug pattern.
			if ( strpos( $plugin_file, $slug . '/' ) === 0 || $plugin_file === $slug . '.php' ) {
				return $plugin_file;
			}
		}

		return null;
	}

	/**
	 * Install a plugin from WordPress.org repository.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Optional specific version.
	 * @return array|WP_Error Installation result or error.
	 */
	private function install_plugin( $slug, $version = '' ) {
		// Get plugin information from WordPress.org API.
		$api_params = array(
			'slug'   => $slug,
			'fields' => array(
				'short_description' => false,
				'sections'          => false,
				'requires'          => false,
				'rating'            => false,
				'ratings'           => false,
				'downloaded'        => false,
				'last_updated'      => false,
				'added'             => false,
				'tags'              => false,
				'compatibility'     => false,
				'homepage'          => false,
				'donate_link'       => false,
			),
		);

		$api = plugins_api( 'plugin_information', $api_params );

		if ( is_wp_error( $api ) ) {
			return new WP_Error(
				'wp_mcp_ai_plugin_api_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not retrieve plugin information: %s', 'wp-mcp-ai' ),
					$api->get_error_message()
				)
			);
		}

		// Determine download link.
		$download_link = $api->download_link;
		if ( ! empty( $version ) && isset( $api->versions[ $version ] ) ) {
			$download_link = $api->versions[ $version ];
		}

		// Install the plugin.
		$skin     = new WP_MCP_AI_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $download_link );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_install_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Plugin installation failed: %s', 'wp-mcp-ai' ),
					$result->get_error_message()
				)
			);
		}

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_install_failed',
				__( 'Plugin installation failed for an unknown reason.', 'wp-mcp-ai' )
			);
		}

		// Find the plugin file after installation.
		$plugin_file = $this->find_plugin_file( $slug );
		if ( ! $plugin_file ) {
			return new WP_Error(
				'wp_mcp_ai_plugin_not_found',
				__( 'Could not find plugin file after installation.', 'wp-mcp-ai' )
			);
		}

		return array(
			'plugin_file' => $plugin_file,
			'plugin_name' => $api->name,
		);
	}

	/**
	 * Activate a plugin.
	 *
	 * @param string $plugin_file    Plugin file path.
	 * @param bool   $network_active Whether to network activate.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function activate_plugin( $plugin_file, $network_active = false ) {
		// Check if already active.
		if ( $network_active && is_multisite() ) {
			if ( is_plugin_active_for_network( $plugin_file ) ) {
				return true;
			}
		} elseif ( is_plugin_active( $plugin_file ) ) {
			return true;
		}

		// Activate the plugin.
		$result = activate_plugin( $plugin_file, '', $network_active );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_activation_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Plugin activation failed: %s', 'wp-mcp-ai' ),
					$result->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Get plugin data for an installed plugin.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return array Plugin data.
	 */
	private function get_plugin_data( $plugin_file ) {
		$plugins = get_plugins();
		return isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ] : array( 'Name' => $plugin_file );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Installs and activates plugins.
			'external-api',         // Calls WordPress.org API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires install_plugins and activate_plugins.
			'state-changing',       // Modifies site state.
			'async',                // May take significant time.
			'performance-impact',   // May temporarily affect site performance.
		);
	}
}

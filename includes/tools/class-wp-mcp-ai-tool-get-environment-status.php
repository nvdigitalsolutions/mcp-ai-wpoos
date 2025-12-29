<?php
/**
 * Tool returning a diagnostic snapshot of the WP oOS environment.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Summarises WordPress, PHP, plugin, and assistant state for troubleshooting.
 */
class WP_MCP_AI_Tool_Get_Environment_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_environment_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get MCP Environment Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns WordPress, PHP, and WP oOS configuration details to accelerate troubleshooting on live sites.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'title'       => __( 'Check WP oOS environment health', 'wp-mcp-ai' ),
				'description' => __( 'Summarise WordPress versions, assistant defaults, and connector warnings for the current site.', 'wp-mcp-ai' ),
				'arguments'   => new stdClass(),
			),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect the WP oOS environment.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$environment = array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'environment_type'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'site_url'          => site_url(),
			'home_url'          => home_url(),
		);

		$plugin = array(
			'version'              => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			'default_provider'     => isset( $settings['default_provider'] ) ? $settings['default_provider'] : '',
			'default_model'        => isset( $settings['default_model'] ) ? $settings['default_model'] : '',
			'default_gemini_model' => isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : '',
			'request_timeout'      => isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30,
			'logging_enabled'      => ! empty( $settings['enable_logging'] ),
		);

		$assistants = $this->summarise_assistants( $settings );

		$supported_plugins = $this->get_supported_plugin_statuses();
		$warnings          = $this->build_warnings( $plugin, $settings, $assistants, $supported_plugins );

		return array(
			'checked_at'        => gmdate( 'c' ),
			'environment'       => $environment,
			'plugin'            => $plugin,
			'assistants'        => $assistants,
			'supported_plugins' => $supported_plugins,
			'warnings'          => $warnings,
		);
	}

	/**
	 * Build assistant summary metadata.
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	protected function summarise_assistants( array $settings ) {
		$default_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		$summary    = array(
			'default_assistant_id' => $default_id,
			'total_assistants'     => 0,
			'default_assistant'    => null,
		);

		if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$counts = wp_count_posts( WP_MCP_AI_Assistant_CPT::POST_TYPE );
			if ( $counts && isset( $counts->publish ) ) {
				$summary['total_assistants'] = (int) $counts->publish;
			}
		}

		if ( $default_id ) {
			$assistant_post = get_post( $default_id );
			if ( $assistant_post && WP_MCP_AI_Assistant_CPT::POST_TYPE === $assistant_post->post_type ) {
				$summary['default_assistant'] = array(
					'id'        => $assistant_post->ID,
					'title'     => get_the_title( $assistant_post ),
					'status'    => get_post_status( $assistant_post ),
					'permalink' => get_permalink( $assistant_post ),
				);

				if ( current_user_can( 'edit_post', $assistant_post->ID ) ) {
					$summary['default_assistant']['edit_link'] = get_edit_post_link( $assistant_post->ID, 'raw' );
				}
			}
		}

		return array(
			'summary'     => sprintf(
				/* translators: 1: plugin count, 2: total warnings */
				__( 'Environment status: %1$d plugin(s) checked, %2$d warning(s)', 'wp-mcp-ai' ),
				count( $summary['plugin_statuses'] ),
				count( $summary['warnings'] )
			),
			'environment' => $summary,
		);
	}

	/**
	 * Retrieve supported plugin statuses matching the CLI helper.
	 *
	 * @return array[]
	 */
	protected function get_supported_plugin_statuses() {
		$definitions = array(
			'woocommerce' => array(
				'name'        => __( 'WooCommerce', 'wp-mcp-ai' ),
				'slug'        => 'woocommerce',
				'plugin_file' => 'woocommerce/woocommerce.php',
				'description' => __( 'Enables WooCommerce aware WP oOS tools.', 'wp-mcp-ai' ),
			),
			'jet-engine'  => array(
				'name'        => __( 'JetEngine', 'wp-mcp-ai' ),
				'slug'        => 'jet-engine',
				'plugin_file' => 'jet-engine/jet-engine.php',
				'description' => __( 'Unlocks JetEngine powered WP oOS tools.', 'wp-mcp-ai' ),
			),
		);

		/**
		 * Filter the supported plugin list surfaced by the diagnostics tool.
		 *
		 * @param array[] $definitions Associative array of plugin metadata keyed by slug.
		 */
		$definitions = apply_filters( 'wp_mcp_ai_supported_plugins', $definitions );

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$statuses = array();

		foreach ( $definitions as $slug => $definition ) {
			$plugin_file = isset( $definition['plugin_file'] ) ? $definition['plugin_file'] : $slug . '/' . $slug . '.php';
			$status      = 'missing';

			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$status = is_plugin_active( $plugin_file ) ? 'active' : 'inactive';
			}

			$statuses[] = array(
				'slug'        => $slug,
				'name'        => isset( $definition['name'] ) ? $definition['name'] : $slug,
				'status'      => $status,
				'plugin_file' => $plugin_file,
				'description' => isset( $definition['description'] ) ? $definition['description'] : '',
			);
		}

		return $statuses;
	}

	/**
	 * Build warning messages highlighting potential misconfigurations.
	 *
	 * @param array   $plugin            Plugin defaults snapshot.
	 * @param array   $settings          Raw plugin settings.
	 * @param array   $assistants        Assistant summary.
	 * @param array[] $supported_plugins Supported plugin statuses.
	 * @return array
	 */
	protected function build_warnings( array $plugin, array $settings, array $assistants, array $supported_plugins ) {
		$warnings = array();

		$default_provider = isset( $plugin['default_provider'] ) ? $plugin['default_provider'] : '';

		if ( 'openai' === $default_provider && empty( $settings['openai_api_key'] ) ) {
			$warnings[] = __( 'OpenAI is the default provider but no API key is configured.', 'wp-mcp-ai' );
		}

		if ( 'gemini' === $default_provider && empty( $settings['gemini_api_key'] ) ) {
			$warnings[] = __( 'Gemini is the default provider but no API key is configured.', 'wp-mcp-ai' );
		}

		if ( empty( $assistants['total_assistants'] ) ) {
			$warnings[] = __( 'No assistants are published yet. Create or publish an assistant before exposing the chat surfaces.', 'wp-mcp-ai' );
		}

		if ( ! empty( $assistants['default_assistant_id'] ) && empty( $assistants['default_assistant'] ) ) {
			$warnings[] = __( 'The configured default assistant could not be loaded. Update the default assistant in Settings → WP oOS.', 'wp-mcp-ai' );
		}

		foreach ( $supported_plugins as $plugin_status ) {
			if ( 'missing' === $plugin_status['status'] ) {
				$plugin_name = isset( $plugin_status['name'] ) ? $plugin_status['name'] : $plugin_status['slug'];

				/* translators: %s: Supported plugin name. */
				$warnings[] = sprintf( __( '%s is not installed. Install it to unlock the related WP oOS tools.', 'wp-mcp-ai' ), $plugin_name );
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

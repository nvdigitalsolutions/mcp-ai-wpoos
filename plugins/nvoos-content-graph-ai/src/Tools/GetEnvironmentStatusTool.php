<?php
/**
 * Get Environment Status tool (D8 Cluster 2b port of the base plugin's
 * WP_MCP_AI_Tool_Get_Environment_Status — byte-identical slug, schema,
 * error codes, and envelope; per-mode settings seam).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

/**
 * Summarises the NV oOS runtime environment (versions, provider
 * defaults, assistants, and supported-plugin statuses).
 */
class GetEnvironmentStatusTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'get_environment_status';
	}

	public function getName(): string {
		return __( 'Get Environment Status', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Returns the current NV oOS environment status including WordPress/PHP versions, configured providers, assistant counts, and supported-plugin statuses.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect the NV oOS environment.', 'nvoos-content-graph-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
		}

		$settings = $this->get_settings();

		$environment = array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'environment_type'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'site_url'          => site_url(),
			'home_url'          => home_url(),
		);

		$plugin = array(
			'version'              => defined( 'NVOOS_CONTENT_GRAPH_AI_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_VERSION : 'dev',
			'default_provider'     => isset( $settings['default_provider'] ) ? $settings['default_provider'] : '',
			'default_model'        => isset( $settings['default_model'] ) ? $settings['default_model'] : '',
			'default_gemini_model' => isset( $settings['default_gemini_model'] ) ? $settings['default_gemini_model'] : '',
			'request_timeout'      => isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30,
			'logging_enabled'      => ! empty( $settings['enable_logging'] ),
		);

		$assistants = $this->summarise_assistants( $settings );

		$supported_plugins = $this->get_supported_plugin_statuses();
		$warnings          = $this->build_warnings( $plugin, $settings, $assistants, $supported_plugins );

		// Build the human-readable summary text here where the data is available.
		$summary_text = sprintf(
			/* translators: 1: plugin count, 2: total warnings */
			__( 'Environment status: %1$d plugin(s) checked, %2$d warning(s)', 'nvoos-content-graph-ai' ),
			count( $supported_plugins ),
			count( $warnings )
		);

		return array(
			'checked_at'        => gmdate( 'c' ),
			'environment'       => $environment,
			'plugin'            => $plugin,
			'assistants'        => $assistants,
			'supported_plugins' => $supported_plugins,
			'warnings'          => $warnings,
			'message'           => $summary_text,
			'summary'           => $summary_text,
		);
	}

	/**
	 * Resolve the settings array (per-install-mode seam).
	 *
	 * @return array
	 */
	private function get_settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			return is_array( $settings ) ? $settings : array();
		}

		$all = CoreBridge::instance()->settings->all();
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Summarise assistant state (byte-identical envelope).
	 *
	 * @param array $settings Settings array.
	 * @return array
	 */
	private function summarise_assistants( array $settings ) {
		$default_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		$summary    = array(
			'default_assistant_id' => $default_id,
			'total_assistants'     => 0,
			'default_assistant'    => null,
		);

		$counts = wp_count_posts( 'mcp_ai_assistant' );
		if ( $counts && isset( $counts->publish ) ) {
			$summary['total_assistants'] = (int) $counts->publish;
		}

		if ( $default_id ) {
			$assistant_post = get_post( $default_id );
			if ( $assistant_post && 'mcp_ai_assistant' === $assistant_post->post_type ) {
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
			'environment' => $summary,
		);
	}

	/**
	 * Report the status of supported third-party plugins.
	 *
	 * @return array
	 */
	private function get_supported_plugin_statuses() {
		$definitions = array(
			'woocommerce' => array(
				'name'        => __( 'WooCommerce', 'nvoos-content-graph-ai' ),
				'slug'        => 'woocommerce',
				'plugin_file' => 'woocommerce/woocommerce.php',
				'description' => __( 'Enables WooCommerce aware NV oOS tools.', 'nvoos-content-graph-ai' ),
			),
			'jet-engine'  => array(
				'name'        => __( 'JetEngine', 'nvoos-content-graph-ai' ),
				'slug'        => 'jet-engine',
				'plugin_file' => 'jet-engine/jet-engine.php',
				'description' => __( 'Unlocks JetEngine powered NV oOS tools.', 'nvoos-content-graph-ai' ),
			),
		);

		$definitions = apply_filters( 'wp_mcp_ai_supported_plugins', $definitions );

		if ( ! is_array( $definitions ) ) {
			$definitions = array();
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$statuses = array();

		foreach ( $definitions as $slug => $definition ) {
			$plugin_file = isset( $definition['plugin_file'] ) ? $definition['plugin_file'] : $slug . '/' . $slug . '.php';
			$status      = 'missing';

			if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$status = is_plugin_active( $plugin_file ) ? 'active' : 'inactive';
			}

			$statuses[] = array(
				'slug'        => $slug,
				'name'        => isset( $definition['name'] ) ? $definition['name'] : $slug,
				'status'      => $status,
				'plugin_file' => $plugin_file,
			);
		}

		return $statuses;
	}

	/**
	 * Build the warnings list (byte-identical warning strings to the base).
	 *
	 * @param array $plugin            Plugin facts.
	 * @param array $settings          Settings array.
	 * @param array $assistants        Assistant summary.
	 * @param array $supported_plugins Plugin statuses.
	 * @return array
	 */
	private function build_warnings( array $plugin, array $settings, array $assistants, array $supported_plugins ) {
		$warnings              = array();
		$default_provider      = isset( $plugin['default_provider'] ) ? $plugin['default_provider'] : '';
		$provider_key_map      = array(
			'openai'      => 'openai_api_key',
			'anthropic'   => 'anthropic_api_key',
			'gemini'      => 'gemini_api_key',
			'huggingface' => 'huggingface_api_key',
			'nvidia'      => 'nvidia_api_key',
			'cloudflare'  => 'cloudflare_api_token',
		);
		$provider_endpoint_map = array(
			'ollama'    => 'ollama_endpoint_url',
			'lm_studio' => 'lm_studio_endpoint_url',
		);
		$provider_labels       = array(
			'openai'      => 'OpenAI',
			'anthropic'   => 'Anthropic',
			'gemini'      => 'Gemini',
			'huggingface' => 'Hugging Face',
			'nvidia'      => 'NVIDIA',
			'cloudflare'  => 'Cloudflare',
			'ollama'      => 'Ollama',
			'lm_studio'   => 'LM Studio',
		);

		if ( isset( $provider_key_map[ $default_provider ] ) && empty( $settings[ $provider_key_map[ $default_provider ] ] ) ) {
			$label = isset( $provider_labels[ $default_provider ] ) ? $provider_labels[ $default_provider ] : $default_provider;
			/* translators: %s: AI provider name (e.g. OpenAI, Anthropic). */
			$warnings[] = sprintf( __( '%s is the default provider but no API key is configured.', 'nvoos-content-graph-ai' ), $label );
		}

		if ( isset( $provider_endpoint_map[ $default_provider ] ) && empty( $settings[ $provider_endpoint_map[ $default_provider ] ] ) ) {
			$label = isset( $provider_labels[ $default_provider ] ) ? $provider_labels[ $default_provider ] : $default_provider;
			/* translators: %s: AI provider name (e.g. Ollama, LM Studio). */
			$warnings[] = sprintf( __( '%s is the default provider but no endpoint URL is configured.', 'nvoos-content-graph-ai' ), $label );
		}

		if ( empty( $assistants['total_assistants'] ) ) {
			$warnings[] = __( 'No assistants are published yet. Create or publish an assistant before exposing the chat surfaces.', 'nvoos-content-graph-ai' );
		}

		if ( ! empty( $assistants['default_assistant_id'] ) && empty( $assistants['default_assistant'] ) ) {
			$warnings[] = __( 'The configured default assistant could not be loaded. Update the default assistant in Settings.', 'nvoos-content-graph-ai' );
		}

		foreach ( $supported_plugins as $plugin_status ) {
			if ( 'missing' === $plugin_status['status'] ) {
				$plugin_name = isset( $plugin_status['name'] ) ? $plugin_status['name'] : $plugin_status['slug'];

				/* translators: %s: Supported plugin name. */
				$warnings[] = sprintf( __( '%s is not installed. Install it to unlock the related NV oOS tools.', 'nvoos-content-graph-ai' ), $plugin_name );
			}
		}

		return $warnings;
	}
}

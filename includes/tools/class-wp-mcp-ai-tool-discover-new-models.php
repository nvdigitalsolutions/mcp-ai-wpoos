<?php
/**
 * Tool that discovers new AI models from provider APIs.
 *
 * This tool queries provider APIs to discover newly released models
 * and compares them against the existing configuration to identify
 * models that could be added.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Discover New Models Tool
 *
 * Queries provider APIs to discover new model releases and
 * recommends models to add to the configuration.
 */
class WP_MCP_AI_Tool_Discover_New_Models implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'discover_new_models';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Discover New AI Models', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Discover newly released AI models from providers by querying their APIs. Compares discovered models against existing configurations and recommends new models to add.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'providers'     => array(
					'type'        => 'array',
					'description' => __( 'List of providers to check. If empty, checks all configured providers.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'openai', 'anthropic', 'gemini', 'huggingface' ),
					),
					'default'     => array(),
				),
				'auto_research' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to automatically research specifications for newly discovered models.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'experimentation' ),

			'profession_tags'       => array( 'ai_researcher' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // Needs API keys.
			'external-api',         // Makes external API calls.
			'network-dependent',    // Requires internet.
			'read-only',            // Only reads data (unless auto_research is true).
			'cacheable',            // Results can be cached.
			'may-timeout',          // Discovery can take time.
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

		// Check permissions - requires manage_options capability.
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to discover models. This tool requires administrator privileges.', 'mcp-ai-wpoos' )
			);
		}

		$providers     = isset( $arguments['providers'] ) && is_array( $arguments['providers'] ) ? $arguments['providers'] : array();
		$auto_research = isset( $arguments['auto_research'] ) ? (bool) $arguments['auto_research'] : false;

		// If no providers specified, check all configured providers.
		if ( empty( $providers ) ) {
			$providers = $this->get_configured_providers();
		}

		if ( empty( $providers ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_providers',
				__( 'No AI providers configured. Please configure at least one provider in plugin settings.', 'mcp-ai-wpoos' )
			);
		}

		// Log discovery start.
		WP_MCP_AI_Logger::log_event(
			'model_discovery_started',
			'Starting model discovery',
			array(
				'providers'     => $providers,
				'auto_research' => $auto_research,
				'user_id'       => $user_id,
			)
		);

		$results = array(
			'discovered'      => array(),
			'already_exists'  => array(),
			'errors'          => array(),
			'recommendations' => array(),
		);

		// Discover models from each provider.
		foreach ( $providers as $provider ) {
			$provider_result = $this->discover_from_provider( $provider, $auto_research, $context );

			if ( is_wp_error( $provider_result ) ) {
				$results['errors'][ $provider ] = $provider_result->get_error_message();
				WP_MCP_AI_Logger::log_error(
					'Model discovery failed for provider: ' . $provider,
					array(
						'error' => $provider_result->get_error_message(),
					)
				);
				continue;
			}

			// Merge results.
			if ( isset( $provider_result['discovered'] ) ) {
				$results['discovered'] = array_merge( $results['discovered'], $provider_result['discovered'] );
			}

			if ( isset( $provider_result['already_exists'] ) ) {
				$results['already_exists'] = array_merge( $results['already_exists'], $provider_result['already_exists'] );
			}

			if ( isset( $provider_result['recommendations'] ) ) {
				$results['recommendations'] = array_merge( $results['recommendations'], $provider_result['recommendations'] );
			}
		}

		// Log completion.
		WP_MCP_AI_Logger::log_event(
			'model_discovery_completed',
			'Model discovery completed',
			array(
				'discovered_count' => count( $results['discovered'] ),
				'exists_count'     => count( $results['already_exists'] ),
				'error_count'      => count( $results['errors'] ),
			)
		);

		return $this->ensure_response_message(
			$results,
			sprintf(
				/* translators: %d: number of new models discovered */
				__( 'Found %d new models', 'mcp-ai-wpoos' ),
				count( $results['discovered'] )
			)
		);
	}

	/**
	 * Get list of configured providers.
	 *
	 * @return array List of provider names.
	 */
	protected function get_configured_providers() {
		$settings  = get_option( 'wp_mcp_ai_settings', array() );
		$providers = array();

		if ( ! empty( $settings['openai_api_key'] ) ) {
			$providers[] = 'openai';
		}

		if ( ! empty( $settings['anthropic_api_key'] ) ) {
			$providers[] = 'anthropic';
		}

		if ( ! empty( $settings['gemini_api_key'] ) ) {
			$providers[] = 'gemini';
		}

		if ( ! empty( $settings['huggingface_api_key'] ) && ! empty( $settings['huggingface_endpoint_url'] ) ) {
			$providers[] = 'huggingface';
		}

		return $providers;
	}

	/**
	 * Discover models from a specific provider.
	 *
	 * @param string $provider      Provider name.
	 * @param bool   $auto_research Whether to auto-research new models.
	 * @param array  $context       Execution context.
	 * @return array|WP_Error Discovery results or error.
	 */
	protected function discover_from_provider( $provider, $auto_research, $context ) {
		// Get models from provider API.
		$api_models = $this->fetch_provider_models( $provider );

		if ( is_wp_error( $api_models ) ) {
			return $api_models;
		}

		// Get existing configurations.
		$existing_configs = WP_MCP_AI_Model_Config::get_all_configs();

		$results = array(
			'discovered'      => array(),
			'already_exists'  => array(),
			'recommendations' => array(),
		);

		// Compare and identify new models.
		foreach ( $api_models as $model_id => $model_info ) {
			if ( isset( $existing_configs[ $model_id ] ) ) {
				$results['already_exists'][] = array(
					'model_id' => $model_id,
					'provider' => $provider,
					'name'     => isset( $model_info['name'] ) ? $model_info['name'] : $model_id,
				);
				continue;
			}

			// New model discovered.
			$discovered = array(
				'model_id' => $model_id,
				'provider' => $provider,
				'name'     => isset( $model_info['name'] ) ? $model_info['name'] : $model_id,
			);

			// Auto-research if requested.
			if ( $auto_research ) {
				// Use the research_model tool if available.
				if ( class_exists( 'WP_MCP_AI_Tool_Research_Model' ) ) {
					$research_tool = new WP_MCP_AI_Tool_Research_Model();
					$research      = $research_tool->execute(
						array(
							'model_id'       => $model_id,
							'provider'       => $provider,
							'use_web_search' => true,
						),
						$context
					);

					if ( ! is_wp_error( $research ) ) {
						$discovered['research'] = $research;
					}
				}
			}

			$results['discovered'][] = $discovered;

			// Add recommendation.
			$results['recommendations'][] = array(
				'model_id'   => $model_id,
				'provider'   => $provider,
				'name'       => isset( $model_info['name'] ) ? $model_info['name'] : $model_id,
				'action'     => 'research_and_add',
				'confidence' => $this->calculate_recommendation_confidence( $model_id, $provider ),
			);
		}

		return $results;
	}

	/**
	 * Fetch models from provider API.
	 *
	 * @param string $provider Provider name.
	 * @return array|WP_Error Array of models or error.
	 */
	protected function fetch_provider_models( $provider ) {
		switch ( $provider ) {
			case 'openai':
				return $this->fetch_openai_models();

			case 'anthropic':
				// Anthropic doesn't have a public models API, use known models.
				return $this->get_known_anthropic_models();

			case 'gemini':
				return $this->fetch_gemini_models();

			case 'huggingface':
				return $this->fetch_huggingface_models();

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Model discovery not supported for provider: %s', 'mcp-ai-wpoos' ),
						$provider
					)
				);
		}
	}

	/**
	 * Fetch OpenAI models from API.
	 *
	 * @return array|WP_Error Models array or error.
	 */
	protected function fetch_openai_models() {
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'OpenAI client not available.', 'mcp-ai-wpoos' ) );
		}

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->list_models();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$models = array();

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $model ) {
				if ( isset( $model['id'] ) ) {
					$models[ $model['id'] ] = array(
						'name'  => $model['id'],
						'owner' => isset( $model['owned_by'] ) ? $model['owned_by'] : 'openai',
					);
				}
			}
		}

		return $models;
	}

	/**
	 * Get known Anthropic models (no public API).
	 *
	 * @return array Models array.
	 */
	protected function get_known_anthropic_models() {
		// Anthropic doesn't expose a models list API, so we use known models.
		return array(
			'claude-sonnet-4.5'          => array( 'name' => 'Claude Sonnet 4.5' ),
			'claude-haiku-4.5'           => array( 'name' => 'Claude Haiku 4.5' ),
			'claude-opus-4.1'            => array( 'name' => 'Claude Opus 4.1' ),
			'claude-3-5-sonnet-20241022' => array( 'name' => 'Claude 3.5 Sonnet' ),
		);
	}

	/**
	 * Fetch Google Gemini models from API.
	 *
	 * @return array|WP_Error Models array or error.
	 */
	protected function fetch_gemini_models() {
		if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Gemini client not available.', 'mcp-ai-wpoos' ) );
		}

		$client = new WP_MCP_AI_Gemini_Client();
		$result = $client->list_models();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$models = array();

		if ( isset( $result['models'] ) && is_array( $result['models'] ) ) {
			foreach ( $result['models'] as $model ) {
				if ( isset( $model['name'] ) ) {
					// Extract model ID from full name (e.g., "models/gemini-pro" -> "gemini-pro").
					$model_id            = str_replace( 'models/', '', $model['name'] );
					$models[ $model_id ] = array(
						'name' => isset( $model['displayName'] ) ? $model['displayName'] : $model_id,
					);
				}
			}
		}

		return $models;
	}

	/**
	 * Fetch Hugging Face models from API.
	 *
	 * @return array|WP_Error Models array or error.
	 */
	protected function fetch_huggingface_models() {
		if ( ! class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			return new WP_Error( 'wp_mcp_ai_client_unavailable', __( 'Hugging Face client not available.', 'mcp-ai-wpoos' ) );
		}

		$client = new WP_MCP_AI_Huggingface_Client();
		$result = $client->list_models();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$models = array();

		if ( is_array( $result ) ) {
			foreach ( $result as $model ) {
				if ( isset( $model['id'] ) ) {
					$models[ $model['id'] ] = array(
						'name'  => $model['id'],
						'owner' => isset( $model['owned_by'] ) ? $model['owned_by'] : '',
					);
				}
			}
		}

		return $models;
	}

	/**
	 * Calculate recommendation confidence for a model.
	 *
	 * @param string $model_id Model identifier.
	 * @param string $provider Provider name.
	 * @return int Confidence score (0-100).
	 */
	protected function calculate_recommendation_confidence( $model_id, $provider ) {
		$confidence = 50; // Base confidence.

		// Higher confidence for models from major providers.
		if ( in_array( $provider, array( 'openai', 'anthropic', 'gemini' ), true ) ) {
			$confidence += 20;
		}

		// Higher confidence for models with standard naming patterns.
		if ( preg_match( '/(gpt-|claude-|gemini-)/', $model_id ) ) {
			$confidence += 15;
		}

		// Higher confidence for versioned models.
		if ( preg_match( '/\d+\.\d+/', $model_id ) ) {
			$confidence += 10;
		}

		// Lower confidence for experimental/preview models.
		if ( preg_match( '/(exp|experimental|preview|alpha|beta)/', $model_id ) ) {
			$confidence -= 20;
		}

		return max( 0, min( 100, $confidence ) );
	}
}

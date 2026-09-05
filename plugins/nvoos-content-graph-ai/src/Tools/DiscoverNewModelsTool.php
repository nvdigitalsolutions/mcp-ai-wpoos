<?php
/**
 * Discover New Models tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_Discover_New_Models — byte-identical slug, schema,
 * error codes, envelope, and recommendation scoring; per-mode provider
 * seam).
 *
 * Provider model listing is served by the nvoos-core provider clients
 * (CoreBridge → ProviderRouter), whose listModels() returns plain model
 * ID slugs. The base tool surfaced provider display names (e.g. Gemini
 * displayName); the port therefore reports the slug as the display name
 * — the envelope shape, ordering, dedupe, and scoring stay identical.
 * Anthropic has no public models API: the static known-model list is
 * ported verbatim from the base tool.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;
use NvoosContentGraphAi\Model\ModelConfigStore;

/**
 * Queries provider APIs to discover new model releases and recommends
 * models to add to the configuration.
 */
class DiscoverNewModelsTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'discover_new_models';
	}

	public function getName(): string {
		return __( 'Discover New AI Models', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Discover newly released AI models from providers by querying their APIs. Compares discovered models against existing configurations and recommends new models to add.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'providers'     => array(
					'type'        => 'array',
					'description' => __( 'List of providers to check. If empty, checks all configured providers.', 'nvoos-content-graph-ai' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia' ),
					),
					'default'     => array(),
				),
				'auto_research' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to automatically research specifications for newly discovered models.', 'nvoos-content-graph-ai' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function getCapabilityFlags(): array {
		return array( 'requires-credentials', 'external-api', 'network-dependent', 'read-only', 'cacheable', 'may-timeout' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to discover models. This tool requires administrator privileges.', 'nvoos-content-graph-ai' )
			);
		}

		$providers     = isset( $arguments['providers'] ) && is_array( $arguments['providers'] ) ? $arguments['providers'] : array();
		$auto_research = isset( $arguments['auto_research'] ) ? (bool) $arguments['auto_research'] : false;

		// If no providers specified, check all configured providers.
		if ( empty( $providers ) ) {
			$providers = $this->get_configured_providers();
		}

		if ( empty( $providers ) ) {
			return new \WP_Error(
				'wp_mcp_ai_no_providers',
				__( 'No AI providers configured. Please configure at least one provider in plugin settings.', 'nvoos-content-graph-ai' )
			);
		}

		$this->log_event(
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

		foreach ( $providers as $provider ) {
			$provider_result = $this->discover_from_provider( $provider, $auto_research, $context );

			if ( is_wp_error( $provider_result ) ) {
				$results['errors'][ $provider ] = $provider_result->get_error_message();
				$this->log_error(
					'Model discovery failed for provider: ' . $provider,
					array(
						'error' => $provider_result->get_error_message(),
					)
				);
				continue;
			}

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

		$this->log_event(
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
				__( 'Found %d new models', 'nvoos-content-graph-ai' ),
				count( $results['discovered'] )
			)
		);
	}

	/**
	 * Get list of configured providers (per-mode seam).
	 *
	 * @return array List of provider names.
	 */
	private function get_configured_providers() {
		$providers = array();

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			$settings = is_array( $settings ) ? $settings : array();

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

		// Standalone: the Content Graph credential resolver.
		$settings = CoreBridge::instance()->settings;

		if ( $settings->hasCredentials( 'openai' ) ) {
			$providers[] = 'openai';
		}

		if ( $settings->hasCredentials( 'anthropic' ) ) {
			$providers[] = 'anthropic';
		}

		if ( $settings->hasCredentials( 'gemini' ) ) {
			$providers[] = 'gemini';
		}

		if ( $settings->hasCredentials( 'huggingface' ) ) {
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
	 * @return array|\WP_Error Discovery results or error.
	 */
	private function discover_from_provider( $provider, $auto_research, $context ) {
		$api_models = $this->fetch_provider_models( $provider );

		if ( is_wp_error( $api_models ) ) {
			return $api_models;
		}

		$existing_configs = ModelConfigStore::get_all_configs();

		$results = array(
			'discovered'      => array(),
			'already_exists'  => array(),
			'recommendations' => array(),
		);

		foreach ( $api_models as $model_id => $model_info ) {
			if ( isset( $existing_configs[ $model_id ] ) ) {
				$results['already_exists'][] = array(
					'model_id' => $model_id,
					'provider' => $provider,
					'name'     => isset( $model_info['name'] ) ? $model_info['name'] : $model_id,
				);
				continue;
			}

			$discovered = array(
				'model_id' => $model_id,
				'provider' => $provider,
				'name'     => isset( $model_info['name'] ) ? $model_info['name'] : $model_id,
			);

			// Auto-research if requested (ported research tool seam).
			if ( $auto_research ) {
				$research_tool = new ResearchModelTool( $this->errors );
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

			$results['discovered'][] = $discovered;

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
	 * Fetch models from provider API (nvoos-core clients).
	 *
	 * @param string $provider Provider name.
	 * @return array|\WP_Error Array of models or error.
	 */
	private function fetch_provider_models( $provider ) {
		switch ( $provider ) {
			case 'openai':
			case 'gemini':
			case 'huggingface':
			case 'digitalocean':
				$client = CoreBridge::instance()->providers->get( $provider );

				if ( null === $client ) {
					return new \WP_Error(
						'wp_mcp_ai_client_unavailable',
						sprintf(
							/* translators: %s: provider name */
							__( 'Provider client not available: %s', 'nvoos-content-graph-ai' ),
							$provider
						)
					);
				}

				$listed = $client->listModels();

				if ( is_wp_error( $listed ) ) {
					return $listed;
				}

				$models = array();

				if ( is_array( $listed ) ) {
					foreach ( $listed as $model_id ) {
						if ( ! is_string( $model_id ) ) {
							continue;
						}

						$models[ $model_id ] = array(
							'name'  => $model_id,
							'owner' => $provider,
						);
					}
				}

				return $models;

			case 'anthropic':
				// Anthropic doesn't have a public models API, use known models.
				return $this->get_known_anthropic_models();

			default:
				return new \WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Model discovery not supported for provider: %s', 'nvoos-content-graph-ai' ),
						$provider
					)
				);
		}
	}

	/**
	 * Get known Anthropic models (no public API) — base-identical list.
	 *
	 * @return array Models array.
	 */
	private function get_known_anthropic_models() {
		return array(
			'claude-opus-5'              => array( 'name' => 'Claude Opus 5 (Jul 2026 - Flagship)' ),
			'claude-fable-5.1'           => array( 'name' => 'Claude Fable 5.1 (Sep 2026 - Top Tier)' ),
			'claude-mythos-5'            => array( 'name' => 'Claude Mythos 5 (Invitation-only)' ),
			'claude-sonnet-5'            => array( 'name' => 'Claude Sonnet 5 (Current)' ),
			'claude-opus-4-8'            => array( 'name' => 'Claude Opus 4.8 (May 2026)' ),
			'claude-opus-4-7'            => array( 'name' => 'Claude Opus 4.7' ),
			'claude-opus-4-6'            => array( 'name' => 'Claude Opus 4.6' ),
			'claude-sonnet-4-6'          => array( 'name' => 'Claude Sonnet 4.6' ),
			'claude-haiku-4-5'           => array( 'name' => 'Claude Haiku 4.5 (Fastest)' ),
			'claude-sonnet-4-5-20250929' => array( 'name' => 'Claude Sonnet 4.5 (Sep 2025)' ),
			'claude-haiku-4-5-20251001'  => array( 'name' => 'Claude Haiku 4.5 (Oct 2025)' ),
			'claude-opus-4-5-20251101'   => array( 'name' => 'Claude Opus 4.5 (Nov 2025)' ),
			'claude-opus-4-1-20250805'   => array( 'name' => 'Claude Opus 4.1 (Aug 2025)' ),
			'claude-sonnet-4-20250514'   => array( 'name' => 'Claude Sonnet 4 (May 2025)' ),
			'claude-opus-4-20250514'     => array( 'name' => 'Claude Opus 4 (May 2025)' ),
			'claude-3-7-sonnet-20250219' => array( 'name' => 'Claude 3.7 Sonnet (Feb 2025)' ),
			'claude-3-5-sonnet-20241022' => array( 'name' => 'Claude 3.5 Sonnet (Legacy)' ),
			'claude-3-5-haiku-20241022'  => array( 'name' => 'Claude 3.5 Haiku (Legacy)' ),
			'claude-3-haiku-20240307'    => array( 'name' => 'Claude 3 Haiku (Legacy)' ),
		);
	}

	/**
	 * Calculate recommendation confidence for a model (base-identical).
	 *
	 * @param string $model_id Model identifier.
	 * @param string $provider Provider name.
	 * @return int Confidence score (0-100).
	 */
	private function calculate_recommendation_confidence( $model_id, $provider ) {
		$confidence = 50; // Base confidence.

		if ( in_array( $provider, array( 'openai', 'anthropic', 'gemini' ), true ) ) {
			$confidence += 20;
		}

		if ( preg_match( '/(gpt-|claude-|gemini-)/', $model_id ) ) {
			$confidence += 15;
		}

		if ( preg_match( '/\d+\.\d+/', $model_id ) ) {
			$confidence += 10;
		}

		if ( preg_match( '/(exp|experimental|preview|alpha|beta)/', $model_id ) ) {
			$confidence -= 20;
		}

		return max( 0, min( 100, $confidence ) );
	}

	/**
	 * Ensure a response array has a message field (base-identical helper).
	 *
	 * @param array  $response         Existing response array.
	 * @param string $fallback_message Message to use if none exists.
	 * @return array Response with guaranteed message field.
	 */
	private function ensure_response_message( $response, $fallback_message = '' ) {
		if ( ! is_array( $response ) ) {
			$response = array( 'data' => $response );
		}

		$message_keys  = array( 'message', 'text', 'summary', 'description' );
		$found_message = '';

		foreach ( $message_keys as $key ) {
			if ( isset( $response[ $key ] ) && is_string( $response[ $key ] ) && ! empty( $response[ $key ] ) ) {
				$found_message = $response[ $key ];
				break;
			}
		}

		if ( empty( $found_message ) ) {
			$response['message'] = $fallback_message;
		} else {
			$response['message'] = $found_message;
		}

		return $response;
	}

	/**
	 * Log an activity event (per-mode seam).
	 *
	 * @param string $type    Event type.
	 * @param string $message Event message.
	 * @param array  $data    Event context.
	 * @return void
	 */
	private function log_event( $type, $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $type, $message, $data );
		}
	}

	/**
	 * Log an error event (per-mode seam).
	 *
	 * @param string $message Error message.
	 * @param array  $data    Error context.
	 * @return void
	 */
	private function log_error( $message, array $data = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $data );
		}
	}
}

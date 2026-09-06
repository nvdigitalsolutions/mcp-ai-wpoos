<?php
/**
 * Add Model Configuration tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_Add_Model_Config — byte-identical slug, schema, error
 * codes, and envelope; per-mode storage seam via ModelConfigStore).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\Model\ModelConfigStore;

/**
 * Adds or updates an AI model configuration in the orchestration layer.
 */
class AddModelConfigTool extends AbstractAiTool {

	/**
	 * Provider slugs accepted by the base tool.
	 *
	 * @var string[]
	 */
	private const VALID_PROVIDERS = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );

	public function getSlug(): string {
		return 'add_model_config';
	}

	public function getName(): string {
		return __( 'Add Model Configuration', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Add or update an AI model configuration in the orchestration layer. Takes model specification data and stores it for use in model selection and orchestration.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model_id'  => array(
					'type'        => 'string',
					'description' => __( 'Unique model identifier (e.g., gpt-4.5-turbo).', 'nvoos-content-graph-ai' ),
				),
				'config'    => array(
					'type'        => 'object',
					'description' => __( 'Model configuration object with specifications.', 'nvoos-content-graph-ai' ),
					'properties'  => array(
						'name'           => array(
							'type'        => 'string',
							'description' => __( 'Human-readable model name.', 'nvoos-content-graph-ai' ),
						),
						'provider'       => array(
							'type'        => 'string',
							'description' => __( 'Provider name.', 'nvoos-content-graph-ai' ),
							'enum'        => self::VALID_PROVIDERS,
						),
						'context_window' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum context window size in tokens.', 'nvoos-content-graph-ai' ),
						),
						'tpm'            => array(
							'type'        => 'integer',
							'description' => __( 'Tokens per minute rate limit.', 'nvoos-content-graph-ai' ),
						),
						'rpm'            => array(
							'type'        => 'integer',
							'description' => __( 'Requests per minute rate limit.', 'nvoos-content-graph-ai' ),
						),
						'tpd'            => array(
							'type'        => 'integer',
							'description' => __( 'Tokens per day rate limit.', 'nvoos-content-graph-ai' ),
						),
						'rpd'            => array(
							'type'        => 'integer',
							'description' => __( 'Requests per day rate limit.', 'nvoos-content-graph-ai' ),
						),
						'cost_per_1k'    => array(
							'type'        => 'number',
							'description' => __( 'Cost per 1000 tokens.', 'nvoos-content-graph-ai' ),
						),
						'status'         => array(
							'type'        => 'string',
							'description' => __( 'Model status.', 'nvoos-content-graph-ai' ),
							'enum'        => array( 'active', 'deprecated', 'experimental', 'preview' ),
						),
						'fallback_model' => array(
							'type'        => 'string',
							'description' => __( 'Fallback model identifier if this model fails.', 'nvoos-content-graph-ai' ),
						),
					),
					'required'    => array( 'name', 'provider', 'context_window' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to overwrite existing configuration if model already exists.', 'nvoos-content-graph-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'model_id', 'config' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function getCapabilityFlags(): array {
		return array( 'requires-capability', 'state-changing', 'write', 'idempotent', 'local-only' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to add model configurations. This tool requires administrator privileges.', 'nvoos-content-graph-ai' )
			);
		}

		if ( empty( $arguments['model_id'] ) || empty( $arguments['config'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'Both model_id and config are required.', 'nvoos-content-graph-ai' )
			);
		}

		$model_id  = sanitize_text_field( $arguments['model_id'] );
		$config    = $arguments['config'];
		$overwrite = isset( $arguments['overwrite'] ) ? (bool) $arguments['overwrite'] : false;

		if ( ! is_array( $config ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_config',
				__( 'Configuration must be an object/array.', 'nvoos-content-graph-ai' )
			);
		}

		$required_fields = array( 'name', 'provider', 'context_window' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $config[ $field ] ) ) {
				return new \WP_Error(
					'wp_mcp_ai_missing_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Required configuration field missing: %s', 'nvoos-content-graph-ai' ),
						$field
					)
				);
			}
		}

		if ( ! in_array( $config['provider'], self::VALID_PROVIDERS, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_provider',
				sprintf(
					/* translators: %s: provider name */
					__( 'Invalid provider: %s', 'nvoos-content-graph-ai' ),
					is_string( $config['provider'] ) ? $config['provider'] : ''
				)
			);
		}

		$existing_config = ModelConfigStore::get_model_config( $model_id );

		if ( $existing_config && ! $overwrite ) {
			return new \WP_Error(
				'wp_mcp_ai_model_exists',
				sprintf(
					/* translators: %s: model ID */
					__( 'Model configuration already exists for: %s. Set overwrite=true to update.', 'nvoos-content-graph-ai' ),
					$model_id
				)
			);
		}

		$sanitized_config = $this->sanitize_config( $config );

		// Add metadata about who added this configuration.
		if ( ! isset( $sanitized_config['_metadata'] ) ) {
			$sanitized_config['_metadata'] = array();
		}

		$sanitized_config['_metadata']['added_by']  = $user_id;
		$sanitized_config['_metadata']['added_at']  = current_time( 'mysql' );
		$sanitized_config['_metadata']['added_via'] = 'add_model_config_tool';
		$sanitized_config['_metadata']['is_custom'] = true;

		// If updating, preserve original metadata.
		if ( $existing_config && isset( $existing_config['_metadata'] ) ) {
			$sanitized_config['_metadata']['original_added_by'] = isset( $existing_config['_metadata']['added_by'] ) ? $existing_config['_metadata']['added_by'] : 0;
			$sanitized_config['_metadata']['original_added_at'] = isset( $existing_config['_metadata']['added_at'] ) ? $existing_config['_metadata']['added_at'] : '';
			$sanitized_config['_metadata']['updated_by']        = $user_id;
			$sanitized_config['_metadata']['updated_at']        = current_time( 'mysql' );
		}

		// Merge with research metadata if present.
		if ( isset( $config['_research_metadata'] ) && is_array( $config['_research_metadata'] ) ) {
			$sanitized_config['_research_metadata'] = $config['_research_metadata'];
		}

		$this->log_event(
			$existing_config ? 'model_config_updated' : 'model_config_added',
			$existing_config ? 'Model configuration updated' : 'Model configuration added',
			array(
				'model_id'  => $model_id,
				'provider'  => $sanitized_config['provider'],
				'user_id'   => $user_id,
				'overwrite' => $overwrite,
				'config'    => $sanitized_config,
			)
		);

		$result = ModelConfigStore::set_model_config( $model_id, $sanitized_config );

		if ( ! $result ) {
			return new \WP_Error(
				'wp_mcp_ai_save_failed',
				__( 'Failed to save model configuration.', 'nvoos-content-graph-ai' )
			);
		}

		$response = array(
			'success'  => true,
			'message'  => $existing_config
				? __( 'Model configuration updated successfully.', 'nvoos-content-graph-ai' )
				: __( 'Model configuration added successfully.', 'nvoos-content-graph-ai' ),
			'model_id' => $model_id,
			'config'   => $sanitized_config,
			'action'   => $existing_config ? 'updated' : 'added',
		);

		// Clear relevant caches (base-identical keys).
		wp_cache_delete( 'all_configs', ModelConfigStore::CACHE_GROUP );
		wp_cache_delete( 'model_' . md5( $model_id ), ModelConfigStore::CACHE_GROUP );

		return $response;
	}

	/**
	 * Sanitize model configuration (base-identical field sets and defaults).
	 *
	 * @param array $config Raw configuration.
	 * @return array Sanitized configuration.
	 */
	private function sanitize_config( $config ) {
		$sanitized = array();

		$string_fields = array( 'name', 'provider', 'fallback_model', 'status' );
		foreach ( $string_fields as $field ) {
			if ( isset( $config[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $config[ $field ] );
			}
		}

		$int_fields = array( 'context_window', 'tpm', 'rpm', 'tpd', 'rpd' );
		foreach ( $int_fields as $field ) {
			if ( isset( $config[ $field ] ) ) {
				$sanitized[ $field ] = absint( $config[ $field ] );
			}
		}

		if ( isset( $config['cost_per_1k'] ) ) {
			$sanitized['cost_per_1k'] = floatval( $config['cost_per_1k'] );
		}

		$defaults = array(
			'tpm'            => 80000,
			'rpm'            => 500,
			'tpd'            => 5000000,
			'rpd'            => 10000,
			'cost_per_1k'    => 0.0,
			'status'         => 'active',
			'fallback_model' => null,
		);

		foreach ( $defaults as $field => $default_value ) {
			if ( ! isset( $sanitized[ $field ] ) ) {
				$sanitized[ $field ] = $default_value;
			}
		}

		return $sanitized;
	}

	/**
	 * Log an activity event (per-mode seam: base logger in monolith
	 * installs, no-op standalone where the base log does not exist).
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
}

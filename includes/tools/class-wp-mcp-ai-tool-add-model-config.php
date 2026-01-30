<?php
/**
 * Tool that adds a researched AI model configuration to the orchestration layer.
 *
 * This tool takes model configuration data (typically from the research_model tool)
 * and adds it to the plugin's model configuration system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add Model Configuration Tool
 *
 * Adds or updates AI model configuration in the orchestration layer.
 * Integrates with WP_MCP_AI_Model_Config for persistent storage.
 */
class WP_MCP_AI_Tool_Add_Model_Config implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'add_model_config';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Model Configuration', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Add or update an AI model configuration in the orchestration layer. Takes model specification data and stores it for use in model selection and orchestration.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model_id'  => array(
					'type'        => 'string',
					'description' => __( 'Unique model identifier (e.g., gpt-4.5-turbo).', 'mcp-ai-wpoos' ),
				),
				'config'    => array(
					'type'        => 'object',
					'description' => __( 'Model configuration object with specifications.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'name'           => array(
							'type'        => 'string',
							'description' => __( 'Human-readable model name.', 'mcp-ai-wpoos' ),
						),
						'provider'       => array(
							'type'        => 'string',
							'description' => __( 'Provider name.', 'mcp-ai-wpoos' ),
							'enum'        => array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' ),
						),
						'context_window' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum context window size in tokens.', 'mcp-ai-wpoos' ),
						),
						'tpm'            => array(
							'type'        => 'integer',
							'description' => __( 'Tokens per minute rate limit.', 'mcp-ai-wpoos' ),
						),
						'rpm'            => array(
							'type'        => 'integer',
							'description' => __( 'Requests per minute rate limit.', 'mcp-ai-wpoos' ),
						),
						'tpd'            => array(
							'type'        => 'integer',
							'description' => __( 'Tokens per day rate limit.', 'mcp-ai-wpoos' ),
						),
						'rpd'            => array(
							'type'        => 'integer',
							'description' => __( 'Requests per day rate limit.', 'mcp-ai-wpoos' ),
						),
						'cost_per_1k'    => array(
							'type'        => 'number',
							'description' => __( 'Cost per 1000 tokens.', 'mcp-ai-wpoos' ),
						),
						'status'         => array(
							'type'        => 'string',
							'description' => __( 'Model status.', 'mcp-ai-wpoos' ),
							'enum'        => array( 'active', 'deprecated', 'experimental', 'preview' ),
						),
						'fallback_model' => array(
							'type'        => 'string',
							'description' => __( 'Fallback model identifier if this model fails.', 'mcp-ai-wpoos' ),
						),
					),
					'required'    => array( 'name', 'provider', 'context_window' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to overwrite existing configuration if model already exists.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'model_id', 'config' ),
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

			'profession_tags'       => array( 'machine_learning_engineer', 'mlops_specialist' ),

			'risk_level'            => 'standard',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability', // Requires manage_options.
			'state-changing',      // Modifies database.
			'write',               // Creates/modifies data.
			'idempotent',          // Can be called multiple times safely.
			'local-only',          // No external API calls.
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
				__( 'You do not have permission to add model configurations. This tool requires administrator privileges.', 'mcp-ai-wpoos' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['model_id'] ) || empty( $arguments['config'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_arguments',
				__( 'Both model_id and config are required.', 'mcp-ai-wpoos' )
			);
		}

		$model_id  = sanitize_text_field( $arguments['model_id'] );
		$config    = $arguments['config'];
		$overwrite = isset( $arguments['overwrite'] ) ? (bool) $arguments['overwrite'] : false;

		// Validate config is an array.
		if ( ! is_array( $config ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_config',
				__( 'Configuration must be an object/array.', 'mcp-ai-wpoos' )
			);
		}

		// Validate required config fields.
		$required_fields = array( 'name', 'provider', 'context_window' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $config[ $field ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Required configuration field missing: %s', 'mcp-ai-wpoos' ),
						$field
					)
				);
			}
		}

		// Validate provider.
		$valid_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' );
		if ( ! in_array( $config['provider'], $valid_providers, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_provider',
				sprintf(
					/* translators: %s: provider name */
					__( 'Invalid provider: %s', 'mcp-ai-wpoos' ),
					$config['provider']
				)
			);
		}

		// Check if model already exists.
		$existing_config = WP_MCP_AI_Model_Config::get_model_config( $model_id );

		if ( $existing_config && ! $overwrite ) {
			return new WP_Error(
				'wp_mcp_ai_model_exists',
				sprintf(
					/* translators: %s: model ID */
					__( 'Model configuration already exists for: %s. Set overwrite=true to update.', 'mcp-ai-wpoos' ),
					$model_id
				)
			);
		}

		// Sanitize and prepare the configuration.
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
			$sanitized_config['_metadata']['original_added_by'] = $existing_config['_metadata']['added_by'];
			$sanitized_config['_metadata']['original_added_at'] = $existing_config['_metadata']['added_at'];
			$sanitized_config['_metadata']['updated_by']        = $user_id;
			$sanitized_config['_metadata']['updated_at']        = current_time( 'mysql' );
		}

		// Merge with research metadata if present.
		if ( isset( $config['_research_metadata'] ) && is_array( $config['_research_metadata'] ) ) {
			$sanitized_config['_research_metadata'] = $config['_research_metadata'];
		}

		// Log the addition.
		WP_MCP_AI_Logger::log_event(
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

		// Save the configuration.
		$result = WP_MCP_AI_Model_Config::set_model_config( $model_id, $sanitized_config );

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_save_failed',
				__( 'Failed to save model configuration.', 'mcp-ai-wpoos' )
			);
		}

		// Build success response.
		$response = array(
			'success'  => true,
			'message'  => $existing_config
				? __( 'Model configuration updated successfully.', 'mcp-ai-wpoos' )
				: __( 'Model configuration added successfully.', 'mcp-ai-wpoos' ),
			'model_id' => $model_id,
			'config'   => $sanitized_config,
			'action'   => $existing_config ? 'updated' : 'added',
		);

		// Clear relevant caches.
		wp_cache_delete( 'all_configs', 'wp_mcp_ai_model_configs' );
		wp_cache_delete( 'model_' . md5( $model_id ), 'wp_mcp_ai_model_configs' );

		return $response;
	}

	/**
	 * Sanitize model configuration.
	 *
	 * @param array $config Raw configuration.
	 * @return array Sanitized configuration.
	 */
	protected function sanitize_config( $config ) {
		$sanitized = array();

		// String fields.
		$string_fields = array( 'name', 'provider', 'fallback_model', 'status' );
		foreach ( $string_fields as $field ) {
			if ( isset( $config[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $config[ $field ] );
			}
		}

		// Integer fields.
		$int_fields = array( 'context_window', 'tpm', 'rpm', 'tpd', 'rpd' );
		foreach ( $int_fields as $field ) {
			if ( isset( $config[ $field ] ) ) {
				$sanitized[ $field ] = absint( $config[ $field ] );
			}
		}

		// Float fields.
		if ( isset( $config['cost_per_1k'] ) ) {
			$sanitized['cost_per_1k'] = floatval( $config['cost_per_1k'] );
		}

		// Set defaults for optional fields.
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
}

<?php
/**
 * JetEngine Custom Content Type registration for model rate limits.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the model rate limits CCT for tracking TPM and other limits per model.
 */
class WP_MCP_AI_Model_Rate_Limits_CCT {
	const SLUG = 'ai_model_rate_limits';

	/**
	 * Hook into JetEngine to provision the model rate limits content type.
	 */
	public static function bootstrap() {
		// Run after JetEngine initialises the Custom Content Types module but before.
		// the manager registers existing instances (priority 10).
		// Using priority 5 to ensure translations are loaded (WordPress 6.7.0+ requirement).
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 5 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 5 );

		// Pre-populate default model data if CCT is empty.
		add_action( 'init', array( __CLASS__, 'maybe_populate_default_models' ), 20 );
	}

	/**
	 * Retrieve the model rate limits CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the model rate limits content type.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return null;
		}

		if ( empty( $module->manager ) ) {
			return null;
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Get model rate limit by model name.
	 *
	 * @param string $model Model identifier.
	 * @param bool   $auto_create Whether to auto-create a variant entry if not found. Default true.
	 * @return array|null Model rate limit data or null if not found.
	 */
	public static function get_model_limits( $model, $auto_create = true ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return null;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return null;
		}

		$model = sanitize_text_field( $model );

		// Query for exact match first.
		$items = $factory->db->query(
			array(
				'model_name' => $model,
			)
		);

		if ( ! empty( $items ) && is_array( $items ) ) {
			return reset( $items );
		}

		// Try prefix match for model families, preferring the longest match.
		// This ensures "gpt-5-2025-08-07" matches "gpt-5" correctly,.
		// even when both "gpt-5" and "gpt-5-nano" exist in the database.
		$all_items = $factory->db->query( array() );

		if ( empty( $all_items ) || ! is_array( $all_items ) ) {
			return null;
		}

		$best_match        = null;
		$best_match_length = 0;

		foreach ( $all_items as $item ) {
			if ( ! isset( $item['model_name'] ) ) {
				continue;
			}

			$stored_model = sanitize_text_field( $item['model_name'] );

			// Check if the input model starts with this stored model name.
			if ( 0 === strpos( $model, $stored_model ) ) {
				$match_length = strlen( $stored_model );

				// Keep the longest matching prefix.
				if ( $match_length > $best_match_length ) {
					$best_match        = $item;
					$best_match_length = $match_length;
				}
			}
		}

		// If we found a base model match and auto-create is enabled, create a variant entry.
		if ( $best_match && $auto_create && $model !== $best_match['model_name'] ) {
			$variant_data = self::create_model_variant( $model, $best_match );
			if ( $variant_data ) {
				WP_MCP_AI_Logger::log_event(
					'model_variant_auto_created',
					sprintf( 'Auto-created model variant entry for %s based on %s', $model, $best_match['model_name'] ),
					array(
						'model'      => $model,
						'base_model' => $best_match['model_name'],
					)
				);
				return $variant_data;
			}
		}

		return $best_match;
	}

	/**
	 * Create a new model variant entry based on a base model.
	 *
	 * @param string $variant_name The variant model name (e.g., gpt-5-2025-08-07).
	 * @param array  $base_model   The base model data to copy from.
	 * @return array|null The created variant data or null on failure.
	 */
	protected static function create_model_variant( $variant_name, $base_model ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return null;
		}

		// Create a new entry based on the base model.
		$variant_data = $base_model;

		// Update the model name.
		$variant_data['model_name'] = sanitize_text_field( $variant_name );

		// Add a note indicating this is an auto-created variant.
		$base_note             = isset( $variant_data['notes'] ) ? $variant_data['notes'] : '';
		$variant_data['notes'] = sprintf(
			'Auto-created variant of %s. %s',
			$base_model['model_name'],
			$base_note
		);

		// Remove the _ID field to create a new entry.
		unset( $variant_data['_ID'] );
		unset( $variant_data['cct_created'] );
		unset( $variant_data['cct_modified'] );
		unset( $variant_data['cct_author_id'] );

		try {
			$new_id = $handler->update_item( $variant_data );

			if ( $new_id ) {
				// Retrieve the newly created item.
				$factory = $handler->get_factory();
				if ( $factory && ! empty( $factory->db ) ) {
					$items = $factory->db->query(
						array(
							'_ID' => $new_id,
						)
					);

					if ( ! empty( $items ) && is_array( $items ) ) {
						return reset( $items );
					}
				}
			}
		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'Error creating model variant',
				array(
					'variant'    => $variant_name,
					'base_model' => $base_model['model_name'],
					'error'      => $e->getMessage(),
				)
			);
		}

		return null;
	}

	/**
	 * Get the configured fallback model for a specific model.
	 *
	 * @param string $model Model identifier.
	 * @return string|null Fallback model identifier or null if not configured.
	 */
	public static function get_model_fallback( $model ) {
		$model_data = self::get_model_limits( $model );

		if ( ! $model_data || ! isset( $model_data['fallback_model'] ) ) {
			return null;
		}

		$fallback = sanitize_text_field( $model_data['fallback_model'] );

		return ! empty( $fallback ) ? $fallback : null;
	}

	/**
	 * Automatically enable the JetEngine data stores module if it's not already active.
	 */
	public static function maybe_enable_data_stores() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return;
		}

		// Check if data stores module is already active.
		if ( $engine->modules->is_module_active( 'data-stores' ) ) {
			return;
		}

		// Check if the module exists.
		if ( ! method_exists( $engine->modules, 'get_module' ) ) {
			return;
		}

		$module = $engine->modules->get_module( 'data-stores' );

		if ( ! $module ) {
			return;
		}

		// Activate the data stores module.
		if ( method_exists( $engine->modules, 'activate_module' ) ) {
			$engine->modules->activate_module( 'data-stores' );
		}
	}

	/**
	 * Register the model rate limits CCT if it is missing.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		$data    = $module->manager->data;
		$request = self::get_registration_request();

		$data->set_request( $request );

		if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
			return;
		}

		$item = $data->sanitize_item_from_request();

		if ( empty( $item ) || ! is_array( $item ) ) {
			return;
		}

		$data->before_item_update( $item, true );

		$item_id = $data->update_item_in_db( $item );

		if ( ! $item_id ) {
			return;
		}

		$item['id'] = $item_id;

		$data->after_item_update( $item, true );

		if ( ! empty( $data->db ) && method_exists( $data->db, 'query_raw' ) ) {
			$data->db->query_raw( 'post_types' );
		}
	}

	/**
	 * Determine whether the model rate limits CCT already exists.
	 *
	 * @param \Jet_Engine\Modules\Custom_Content_Types\Module $module Module instance.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		$data = $module->manager->data;

		if ( empty( $data->db ) ) {
			return false;
		}

		$records = $data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Retrieve the JetEngine Custom Content Types module instance.
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Module|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );

		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Build the request payload used to register the content type.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'AI Model Rate Limits', 'wp-mcp-ai' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the model rate limits CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-performance',
			'capability'          => 'manage_options',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => true,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'manage_options',
			'rest_put_access'     => 'manage_options',
			'rest_post_access'    => 'manage_options',
			'rest_delete_access'  => 'manage_options',
			'admin_columns'       => array(
				'_ID'            => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'model_name'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'provider'       => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'tpm_limit'      => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'context_window' => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'tier'           => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'fallback_model' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'cct_created'    => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the model rate limits meta field configuration.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				20001,
				'model_name',
				__( 'Model Name', 'wp-mcp-ai' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Model identifier (e.g., gpt-4o, claude-3.5-sonnet, gemini-1.5-pro).', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20002,
				'provider',
				__( 'Provider', 'wp-mcp-ai' ),
				'select',
				array(
					'is_required' => true,
					'options'     => array(
						array(
							'key'   => 'openai',
							'value' => 'OpenAI',
						),
						array(
							'key'   => 'anthropic',
							'value' => 'Anthropic',
						),
						array(
							'key'   => 'google',
							'value' => 'Google',
						),
						array(
							'key'   => 'azure',
							'value' => 'Azure OpenAI',
						),
						array(
							'key'   => 'ollama',
							'value' => 'Ollama',
						),
						array(
							'key'   => 'lm_studio',
							'value' => 'LM Studio',
						),
						array(
							'key'   => 'huggingface',
							'value' => 'Hugging Face',
						),
						array(
							'key'   => 'other',
							'value' => 'Other',
						),
					),
					'description' => __( 'AI provider offering this model.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20003,
				'tpm_limit',
				__( 'TPM Limit (Tokens Per Minute)', 'wp-mcp-ai' ),
				'number',
				array(
					'is_required' => true,
					'min'         => 0,
					'step'        => 1000,
					'description' => __( 'Maximum tokens per minute allowed for this model (API rate limit).', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20004,
				'rpm_limit',
				__( 'RPM Limit (Requests Per Minute)', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'Maximum requests per minute allowed for this model.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20005,
				'context_window',
				__( 'Context Window (Max Tokens)', 'wp-mcp-ai' ),
				'number',
				array(
					'is_required' => true,
					'min'         => 0,
					'step'        => 1000,
					'description' => __( 'Maximum context window size in tokens for this model.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20006,
				'max_output_tokens',
				__( 'Max Output Tokens', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1000,
					'description' => __( 'Maximum output tokens the model can generate per request.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20007,
				'tier',
				__( 'Account Tier', 'wp-mcp-ai' ),
				'select',
				array(
					'options'     => array(
						array(
							'key'   => 'free',
							'value' => 'Free',
						),
						array(
							'key'   => 'tier-1',
							'value' => 'Tier 1',
						),
						array(
							'key'   => 'tier-2',
							'value' => 'Tier 2',
						),
						array(
							'key'   => 'tier-3',
							'value' => 'Tier 3',
						),
						array(
							'key'   => 'scale',
							'value' => 'Scale/Enterprise',
						),
					),
					'description' => __( 'Account tier these limits apply to (Free, Paid, Enterprise, etc.).', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20008,
				'supports_streaming',
				__( 'Supports Streaming', 'wp-mcp-ai' ),
				'switcher',
				array(
					'description' => __( 'Whether this model supports streaming responses.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20009,
				'supports_function_calling',
				__( 'Supports Function Calling', 'wp-mcp-ai' ),
				'switcher',
				array(
					'description' => __( 'Whether this model supports function/tool calling.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20010,
				'supports_vision',
				__( 'Supports Vision', 'wp-mcp-ai' ),
				'switcher',
				array(
					'description' => __( 'Whether this model can process images.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20011,
				'cost_per_1k_input_tokens',
				__( 'Cost Per 1K Input Tokens ($)', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 0.0001,
					'description' => __( 'Cost in USD per 1000 input tokens.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20012,
				'cost_per_1k_output_tokens',
				__( 'Cost Per 1K Output Tokens ($)', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 0.0001,
					'description' => __( 'Cost in USD per 1000 output tokens.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20013,
				'notes',
				__( 'Notes', 'wp-mcp-ai' ),
				'textarea',
				array(
					'rows'        => 3,
					'description' => __( 'Additional notes about this model configuration.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				20014,
				'fallback_model',
				__( 'High-Capacity Fallback Model', 'wp-mcp-ai' ),
				'text',
				array(
					'description' => __( 'Model to use when this model\'s TPM limit is exceeded (e.g., gemini-2.5-flash). Leave empty to use global fallback setting.', 'wp-mcp-ai' ),
				)
			),
		);

		foreach ( $fields as &$field ) {
			$field['show_in_rest'] = true;
		}

		return $fields;
	}

	/**
	 * Utility to construct a JetEngine meta field definition.
	 *
	 * @param int    $id        Deterministic field identifier.
	 * @param string $name      Field slug.
	 * @param string $label     Field label.
	 * @param string $type      JetEngine field type.
	 * @param array  $overrides Optional overrides for the base configuration.
	 * @return array
	 */
	protected static function build_field( $id, $name, $label, $type, $overrides = array() ) {
		$field = array(
			'id'          => absint( $id ),
			'name'        => sanitize_key( $name ),
			'title'       => $label,
			'object_type' => 'field',
			'type'        => $type,
			'width'       => '100%',
			'isNested'    => false,
			'options'     => array(),
		);

		return array_merge( $field, $overrides );
	}

	/**
	 * Populate default model rate limits data if the CCT is empty.
	 */
	public static function maybe_populate_default_models() {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return;
		}

		// Check if data already exists.
		$existing = $factory->db->query( array() );

		if ( ! empty( $existing ) && is_array( $existing ) && count( $existing ) > 0 ) {
			return;
		}

		// Populate with default models based on research.
		$default_models = self::get_default_model_data();

		foreach ( $default_models as $model_data ) {
			$handler->update_item( $model_data );
		}

		WP_MCP_AI_Logger::log_event(
			'model_rate_limits_populated',
			'Populated default model rate limits data.',
			array( 'count' => count( $default_models ) )
		);
	}

	/**
	 * Get default model rate limit data based on online research.
	 *
	 * @return array Array of model configurations.
	 */
	protected static function get_default_model_data() {
		return array(
			// OpenAI GPT-5 Series - Tier 1 (2025 Flagship Models).
			array(
				'model_name'                => 'gpt-5.1',
				'provider'                  => 'openai',
				'tpm_limit'                 => 500000,
				'rpm_limit'                 => 5000,
				'context_window'            => 400000,
				'max_output_tokens'         => 128000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00125,
				'cost_per_1k_output_tokens' => 0.01,
				'notes'                     => 'GPT-5.1 flagship model with agentic coding tools. Configurable reasoning effort with "none" mode for low latency.',
			),
			array(
				'model_name'                => 'gpt-5',
				'provider'                  => 'openai',
				'tpm_limit'                 => 400000,
				'rpm_limit'                 => 4000,
				'context_window'            => 400000,
				'max_output_tokens'         => 128000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00125,
				'cost_per_1k_output_tokens' => 0.01,
				'notes'                     => 'GPT-5 with configurable reasoning for coding, math, writing. $1.25 per 1M input tokens, $10 per 1M output tokens.',
			),
			array(
				'model_name'                => 'gpt-5-mini',
				'provider'                  => 'openai',
				'tpm_limit'                 => 600000,
				'rpm_limit'                 => 6000,
				'context_window'            => 400000,
				'max_output_tokens'         => 128000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00025,
				'cost_per_1k_output_tokens' => 0.002,
				'notes'                     => 'Cost-optimized GPT-5 variant. $0.25 per 1M input tokens. Faster with same context/output limits.',
			),
			array(
				'model_name'                => 'gpt-5-nano',
				'provider'                  => 'openai',
				'tpm_limit'                 => 800000,
				'rpm_limit'                 => 8000,
				'context_window'            => 400000,
				'max_output_tokens'         => 128000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00005,
				'cost_per_1k_output_tokens' => 0.0004,
				'notes'                     => 'Fastest, most affordable GPT-5. $0.05 per 1M input tokens. High-throughput simple queries.',
			),
			array(
				'model_name'                => 'gpt-5-pro',
				'provider'                  => 'openai',
				'tpm_limit'                 => 300000,
				'rpm_limit'                 => 3000,
				'context_window'            => 400000,
				'max_output_tokens'         => 128000,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.003,
				'cost_per_1k_output_tokens' => 0.02,
				'notes'                     => 'Enhanced precision and intelligence for advanced enterprise needs. Smarter, deeper reasoning.',
			),
			array(
				'model_name'                => 'gpt-5-codex',
				'provider'                  => 'openai',
				'tpm_limit'                 => 400000,
				'rpm_limit'                 => 4000,
				'context_window'            => 400000,
				'max_output_tokens'         => 128000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.00125,
				'cost_per_1k_output_tokens' => 0.01,
				'notes'                     => 'Optimized for agentic coding workflows and IDE integrations. Enhanced code generation and understanding.',
			),
			array(
				'model_name'                => 'gpt-5-codex-mini',
				'provider'                  => 'openai',
				'tpm_limit'                 => 600000,
				'rpm_limit'                 => 6000,
				'context_window'            => 400000,
				'max_output_tokens'         => 128000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.00025,
				'cost_per_1k_output_tokens' => 0.002,
				'notes'                     => 'Cost-optimized coding model for IDE extensions. Higher usage limits under ChatGPT subscriptions.',
			),

			// OpenAI GPT-4o Series - Tier 1 (Current Production Models).
			array(
				'model_name'                => 'gpt-4o',
				'provider'                  => 'openai',
				'tpm_limit'                 => 30000,
				'rpm_limit'                 => 500,
				'context_window'            => 128000,
				'max_output_tokens'         => 16384,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.005,
				'cost_per_1k_output_tokens' => 0.015,
				'notes'                     => 'GPT-4o standard tier. Scale tier supports up to 450,000 TPM.',
			),
			array(
				'model_name'                => 'gpt-4o-mini',
				'provider'                  => 'openai',
				'tpm_limit'                 => 200000,
				'rpm_limit'                 => 500,
				'context_window'            => 128000,
				'max_output_tokens'         => 16384,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00015,
				'cost_per_1k_output_tokens' => 0.0006,
				'notes'                     => 'Cost-effective variant of GPT-4o.',
			),

			// OpenAI Legacy Models - Tier 1 (Backward Compatibility).
			array(
				'model_name'                => 'gpt-4-turbo',
				'provider'                  => 'openai',
				'tpm_limit'                 => 80000,
				'rpm_limit'                 => 500,
				'context_window'            => 128000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.01,
				'cost_per_1k_output_tokens' => 0.03,
				'notes'                     => 'GPT-4 Turbo with vision capabilities (Legacy).',
			),
			array(
				'model_name'                => 'gpt-4',
				'provider'                  => 'openai',
				'tpm_limit'                 => 10000,
				'rpm_limit'                 => 500,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.03,
				'cost_per_1k_output_tokens' => 0.06,
				'notes'                     => 'Original GPT-4 model (Legacy).',
			),
			array(
				'model_name'                => 'gpt-3.5-turbo',
				'provider'                  => 'openai',
				'tpm_limit'                 => 60000,
				'rpm_limit'                 => 3500,
				'context_window'            => 16385,
				'max_output_tokens'         => 4096,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0005,
				'cost_per_1k_output_tokens' => 0.0015,
				'notes'                     => 'Fast and cost-effective for simpler tasks (Legacy).',
			),

			// Google Gemini 3.0 Series - Preview (Latest Generation).
			array(
				'model_name'                => 'gemini-3-pro-preview',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 2097152,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.0001,
				'cost_per_1k_output_tokens' => 0.0005,
				'notes'                     => 'Gemini 3 Pro (Preview). Latest generation with breakthrough long-context reasoning and multimodal understanding.',
			),

			// Google Gemini 2.5 Series - Stable (Production Models).
			array(
				'model_name'                => 'gemini-2.5-pro',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 2097152,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00015,
				'cost_per_1k_output_tokens' => 0.0006,
				'notes'                     => 'Gemini 2.5 Pro. Advanced reasoning, coding, STEM analysis. 2M context window.',
			),
			array(
				'model_name'                => 'gemini-2.5-flash',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 2000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.000075,
				'cost_per_1k_output_tokens' => 0.0003,
				'notes'                     => 'Gemini 2.5 Flash (Recommended). Fast, high-volume processing. Best performance/cost ratio for agentic use cases.',
			),
			array(
				'model_name'                => 'gemini-2.5-flash-lite',
				'provider'                  => 'google',
				'tpm_limit'                 => 1500000,
				'rpm_limit'                 => 3000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00004,
				'cost_per_1k_output_tokens' => 0.00015,
				'notes'                     => 'Gemini 2.5 Flash Lite. Ultra-fast, cost-efficient. Highest throughput for simple tasks.',
			),
			array(
				'model_name'                => 'gemini-live-2.5-flash-preview',
				'provider'                  => 'google',
				'tpm_limit'                 => 500000,
				'rpm_limit'                 => 1000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.0001,
				'cost_per_1k_output_tokens' => 0.0004,
				'notes'                     => 'Gemini Live 2.5 Flash (Preview). Real-time voice and multimodal interactions.',
			),

			// Google Gemini 2.0 Series - Stable.
			array(
				'model_name'                => 'gemini-2.0-flash',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.0001,
				'cost_per_1k_output_tokens' => 0.0004,
				'notes'                     => 'Gemini 2.0 Flash. Solid coding and reasoning. 1M context window.',
			),
			array(
				'model_name'                => 'gemini-2.0-flash-lite',
				'provider'                  => 'google',
				'tpm_limit'                 => 1500000,
				'rpm_limit'                 => 2000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00005,
				'cost_per_1k_output_tokens' => 0.0002,
				'notes'                     => 'Gemini 2.0 Flash Lite. Faster, lighter variant with large context window.',
			),

			// Google Gemini 1.5 Series - Legacy (Backward Compatibility).
			array(
				'model_name'                => 'gemini-1.5-pro',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 2097152,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.0035,
				'cost_per_1k_output_tokens' => 0.0105,
				'notes'                     => 'Gemini 1.5 Pro (Legacy). Large context window, excellent for long documents.',
			),
			array(
				'model_name'                => 'gemini-1.5-flash',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 2000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.000075,
				'cost_per_1k_output_tokens' => 0.0003,
				'notes'                     => 'Gemini 1.5 Flash (Legacy). Fast and cost-effective with large context.',
			),

			// Google Gemini Image Models.
			array(
				'model_name'                => 'gemini-2.5-flash-image',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => false,
				'supports_function_calling' => false,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0.03,
				'notes'                     => 'Advanced image generation with editing, multi-image blending, character consistency. ~$0.039 per image. SynthID watermarking included.',
			),
			array(
				'model_name'                => 'gemini-2.0-flash-image',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => false,
				'supports_function_calling' => false,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0.03,
				'notes'                     => 'Legacy Gemini image generation model. Consider upgrading to 2.5-flash-image for better quality and features.',
			),
			array(
				'model_name'                => 'imagen-3',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 8192,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => false,
				'supports_function_calling' => false,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0.04,
				'notes'                     => 'Google Imagen 3 for photorealistic text-to-image generation. Alternative to Gemini image models with different pricing.',
			),

			// Anthropic Claude 4 Series - 2025 Models.
			array(
				'model_name'                => 'claude-sonnet-4.5',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 80000,
				'rpm_limit'                 => 100,
				'context_window'            => 200000,
				'max_output_tokens'         => 32000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.003,
				'cost_per_1k_output_tokens' => 0.015,
				'notes'                     => 'Claude Sonnet 4.5 (Recommended). Exceptional coding, agentic tasks, long-form analysis. 200k context, 1M beta option.',
			),
			array(
				'model_name'                => 'claude-haiku-4.5',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 100000,
				'rpm_limit'                 => 150,
				'context_window'            => 200000,
				'max_output_tokens'         => 32000,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.0008,
				'cost_per_1k_output_tokens' => 0.004,
				'notes'                     => 'Claude Haiku 4.5 (Fastest). Most cost-efficient for real-time, high-volume workloads. 200k context.',
			),
			array(
				'model_name'                => 'claude-opus-4.1',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 40000,
				'rpm_limit'                 => 50,
				'context_window'            => 200000,
				'max_output_tokens'         => 32000,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.015,
				'cost_per_1k_output_tokens' => 0.075,
				'notes'                     => 'Claude Opus 4.1 (Flagship). Deep reasoning for specialized research, enterprise, advanced coding. 200k context, max 32k output.',
			),
			array(
				'model_name'                => 'claude-opus-4.0',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 30000,
				'rpm_limit'                 => 40,
				'context_window'            => 200000,
				'max_output_tokens'         => 32000,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.015,
				'cost_per_1k_output_tokens' => 0.075,
				'notes'                     => 'Claude Opus 4.0. Previous flagship with strong reasoning capabilities.',
			),

			// Anthropic Claude 3.5 Series - Legacy (Backward Compatibility).
			array(
				'model_name'                => 'claude-3-5-sonnet-20241022',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 40000,
				'rpm_limit'                 => 50,
				'context_window'            => 200000,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.003,
				'cost_per_1k_output_tokens' => 0.015,
				'notes'                     => 'Claude 3.5 Sonnet (Oct 2024 - Legacy). Balanced performance and cost. Scale tier: 400,000 TPM.',
			),
			array(
				'model_name'                => 'claude-3-5-haiku-20241022',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 50000,
				'rpm_limit'                 => 50,
				'context_window'            => 200000,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.0008,
				'cost_per_1k_output_tokens' => 0.004,
				'notes'                     => 'Claude 3.5 Haiku (Oct 2024 - Legacy). Fast and economical with performance matching Claude 3 Opus.',
			),

			// Azure OpenAI variants (examples).
			array(
				'model_name'                => 'gpt-4o',
				'provider'                  => 'azure',
				'tpm_limit'                 => 450000,
				'rpm_limit'                 => 1000,
				'context_window'            => 128000,
				'max_output_tokens'         => 16384,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.0025,
				'cost_per_1k_output_tokens' => 0.01,
				'notes'                     => 'Azure OpenAI deployment. Limits per region.',
			),

			// Additional OpenAI Models.
			array(
				'model_name'                => 'gpt-3.5-turbo-16k',
				'provider'                  => 'openai',
				'tpm_limit'                 => 60000,
				'rpm_limit'                 => 3500,
				'context_window'            => 16385,
				'max_output_tokens'         => 4096,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0005,
				'cost_per_1k_output_tokens' => 0.0015,
				'notes'                     => 'GPT-3.5 Turbo with extended 16K context window.',
			),
			array(
				'model_name'                => 'gpt-3.5-turbo-instruct',
				'provider'                  => 'openai',
				'tpm_limit'                 => 60000,
				'rpm_limit'                 => 3500,
				'context_window'            => 4096,
				'max_output_tokens'         => 4096,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => false,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0015,
				'cost_per_1k_output_tokens' => 0.002,
				'notes'                     => 'Completion-based variant optimized for instructions.',
			),

			// Google Gemini Additional Models.
			array(
				'model_name'                => 'gemini-1.5-pro-002',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 1000,
				'context_window'            => 2097152,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.00125,
				'cost_per_1k_output_tokens' => 0.005,
				'notes'                     => 'Latest Gemini Pro with 3x higher rate limits and lower latency.',
			),
			array(
				'model_name'                => 'gemini-1.5-flash-002',
				'provider'                  => 'google',
				'tpm_limit'                 => 1000000,
				'rpm_limit'                 => 2000,
				'context_window'            => 1048576,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-2',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.000075,
				'cost_per_1k_output_tokens' => 0.0003,
				'notes'                     => 'Latest Gemini Flash with improved performance.',
			),
			array(
				'model_name'                => 'gemini-pro',
				'provider'                  => 'google',
				'tpm_limit'                 => 125000,
				'rpm_limit'                 => 360,
				'context_window'            => 32768,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0005,
				'cost_per_1k_output_tokens' => 0.0015,
				'notes'                     => 'Original Gemini Pro model (32K context).',
			),

			// Anthropic Claude Additional Models.
			array(
				'model_name'                => 'claude-3.5-sonnet-v2',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 40000,
				'rpm_limit'                 => 50,
				'context_window'            => 200000,
				'max_output_tokens'         => 8192,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.003,
				'cost_per_1k_output_tokens' => 0.015,
				'notes'                     => 'Updated Claude 3.5 Sonnet with improved capabilities.',
			),
			array(
				'model_name'                => 'claude-2.1',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 20000,
				'rpm_limit'                 => 50,
				'context_window'            => 200000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => false,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.008,
				'cost_per_1k_output_tokens' => 0.024,
				'notes'                     => 'Previous generation Claude model.',
			),
			array(
				'model_name'                => 'claude-instant-1.2',
				'provider'                  => 'anthropic',
				'tpm_limit'                 => 50000,
				'rpm_limit'                 => 50,
				'context_window'            => 100000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => false,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.00163,
				'cost_per_1k_output_tokens' => 0.00551,
				'notes'                     => 'Fast and economical Claude instant model.',
			),

			// GPT-5 Models (2025 release).
			array(
				'model_name'                => 'gpt-5',
				'provider'                  => 'openai',
				'tpm_limit'                 => 500000,
				'rpm_limit'                 => 1000,
				'context_window'            => 128000,
				'max_output_tokens'         => 32768,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.01,
				'cost_per_1k_output_tokens' => 0.03,
				'notes'                     => 'GPT-5 Tier 1 (500K TPM). Tier 5 supports up to 40M TPM.',
			),
			array(
				'model_name'                => 'gpt-5-mini',
				'provider'                  => 'openai',
				'tpm_limit'                 => 500000,
				'rpm_limit'                 => 1000,
				'context_window'            => 128000,
				'max_output_tokens'         => 32768,
				'tier'                      => 'tier-1',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0.002,
				'cost_per_1k_output_tokens' => 0.006,
				'notes'                     => 'GPT-5-mini Tier 1 (500K TPM). Tier 5 supports up to 180M TPM.',
			),

			// Ollama Models (local deployment, no API rate limits).
			array(
				'model_name'                => 'llama3',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Llama 3 via Ollama. Local deployment, no rate limits. Supports up to 128K theoretical context.',
			),
			array(
				'model_name'                => 'llama3:70b',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Llama 3 70B via Ollama. Larger model with better capabilities.',
			),
			array(
				'model_name'                => 'mistral',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Mistral via Ollama. Local deployment, supports up to 128K theoretical context.',
			),
			array(
				'model_name'                => 'codellama',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 16384,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'CodeLlama via Ollama. Optimized for code generation with 16K context.',
			),
			array(
				'model_name'                => 'phi3',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 4096,
				'max_output_tokens'         => 2048,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => false,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Phi-3 Mini via Ollama. Efficient small model for quick tasks.',
			),
			array(
				'model_name'                => 'deepseek-coder',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 16384,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'DeepSeek Coder via Ollama. Specialized for code tasks.',
			),
			array(
				'model_name'                => 'deepseek-r1-0528-qwen3-8b',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 32768,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.00002,
				'cost_per_1k_output_tokens' => 0.0001,
				'notes'                     => 'DeepSeek R1 0528 Qwen3 8B via Ollama. Advanced reasoning model distilled from DeepSeek-R1, optimized for math, coding, and logic.',
			),
			array(
				'model_name'                => 'qwen2',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Qwen2 via Ollama. Large context window for document processing.',
			),
			array(
				'model_name'                => 'gemma2',
				'provider'                  => 'ollama',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => false,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Gemma 2 via Ollama. Google\'s efficient open model.',
			),

			// LM Studio Models (local deployment, OpenAI-compatible API).
			// Qwen models (function calling, coding, vision).
			array(
				'model_name'                => 'qwen/qwen3-coder-30b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Qwen 3 Coder 30B via LM Studio. Advanced coding model with function calling support.',
			),
			array(
				'model_name'                => 'qwen/qwen3-vl-30b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => true,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Qwen 3 Vision-Language 30B via LM Studio. Multimodal model with vision and function calling support.',
			),
			array(
				'model_name'                => 'qwen/qwen2.5-coder-32b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Qwen 2.5 Coder 32B via LM Studio. Specialized coding model with large context.',
			),
			array(
				'model_name'                => 'qwen/qwen2.5-32b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Qwen 2.5 32B via LM Studio. General-purpose model with large context.',
			),
			array(
				'model_name'                => 'qwen/qwen2.5-14b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Qwen 2.5 14B via LM Studio. Balanced model with good performance.',
			),
			array(
				'model_name'                => 'qwen/qwen2.5-7b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Qwen 2.5 7B via LM Studio. Efficient smaller model with large context.',
			),
			// Google Gemma models.
			array(
				'model_name'                => 'google/gemma-3-12b:2',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Gemma 3 12B (v2) via LM Studio. Google\'s latest efficient open model.',
			),
			array(
				'model_name'                => 'google/gemma-2-27b-it',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Gemma 2 27B Instruct via LM Studio. Larger instruction-tuned model.',
			),
			array(
				'model_name'                => 'google/gemma-2-9b-it',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Gemma 2 9B Instruct via LM Studio. Balanced instruction-tuned model.',
			),
			array(
				'model_name'                => 'google/gemma-2-2b-it',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 8192,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => false,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Gemma 2 2B Instruct via LM Studio. Small efficient instruction-tuned model.',
			),
			// Meta Llama models.
			array(
				'model_name'                => 'meta-llama/llama-3.3-70b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 128000,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Llama 3.3 70B via LM Studio. Latest large Llama model with extended context.',
			),
			array(
				'model_name'                => 'meta-llama/llama-3.2-3b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 128000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Llama 3.2 3B via LM Studio. Small efficient model with extended context.',
			),
			array(
				'model_name'                => 'meta-llama/llama-3.2-1b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 128000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Llama 3.2 1B via LM Studio. Tiny efficient model for edge deployment.',
			),
			array(
				'model_name'                => 'meta-llama/llama-3.1-8b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 128000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Llama 3.1 8B via LM Studio. Balanced model with extended context.',
			),
			// Mistral models.
			array(
				'model_name'                => 'mistralai/mistral-7b-v0.3',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Mistral 7B v0.3 via LM Studio. Latest Mistral base model with function calling.',
			),
			array(
				'model_name'                => 'mistralai/mixtral-8x7b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 32768,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Mixtral 8x7B via LM Studio. Mixture-of-experts model with strong performance.',
			),
			array(
				'model_name'                => 'mistralai/mixtral-8x22b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 65536,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Mixtral 8x22B via LM Studio. Larger mixture-of-experts model with extended context.',
			),
			// DeepSeek models.
			array(
				'model_name'                => 'deepseek-ai/deepseek-coder-33b',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 16384,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'DeepSeek Coder 33B via LM Studio. Advanced coding model with strong performance.',
			),
			array(
				'model_name'                => 'deepseek-ai/deepseek-v3',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 65536,
				'max_output_tokens'         => 8192,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'DeepSeek V3 via LM Studio. Latest DeepSeek model with extended context and strong reasoning.',
			),
			// Microsoft Phi models.
			array(
				'model_name'                => 'microsoft/phi-4',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 16384,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Phi-4 via LM Studio. Microsoft\'s latest small language model with strong capabilities.',
			),
			array(
				'model_name'                => 'microsoft/phi-3.5-mini',
				'provider'                  => 'lm_studio',
				'tpm_limit'                 => 0,
				'rpm_limit'                 => 0,
				'context_window'            => 128000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0,
				'cost_per_1k_output_tokens' => 0,
				'notes'                     => 'Phi-3.5 Mini via LM Studio. Efficient small model with extended context.',
			),

			// Hugging Face Models - Open-source models via Inference API (17 models).
			array(
				'model_name'                => 'meta-llama/Llama-3.3-70B-Instruct',
				'provider'                  => 'huggingface',
				'tpm_limit'                 => 50000,
				'rpm_limit'                 => 100,
				'context_window'            => 128000,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.001,
				'cost_per_1k_output_tokens' => 0.001,
				'fallback_model'            => 'meta-llama/Llama-3.1-8B-Instruct',
				'notes'                     => 'Llama 3.3 70B via Hugging Face Inference API. Latest Meta model with 128k context.',
			),
			array(
				'model_name'                => 'meta-llama/Llama-3.1-8B-Instruct',
				'provider'                  => 'huggingface',
				'tpm_limit'                 => 100000,
				'rpm_limit'                 => 200,
				'context_window'            => 128000,
				'max_output_tokens'         => 2048,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0003,
				'cost_per_1k_output_tokens' => 0.0003,
				'fallback_model'            => 'mistralai/Mistral-7B-Instruct-v0.3',
				'notes'                     => 'Llama 3.1 8B via Hugging Face. Fast, cost-effective with 128k context.',
			),
			array(
				'model_name'                => 'mistralai/Mistral-7B-Instruct-v0.3',
				'provider'                  => 'huggingface',
				'tpm_limit'                 => 100000,
				'rpm_limit'                 => 200,
				'context_window'            => 32768,
				'max_output_tokens'         => 2048,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0002,
				'cost_per_1k_output_tokens' => 0.0002,
				'fallback_model'            => 'microsoft/Phi-3-mini-4k-instruct',
				'notes'                     => 'Mistral 7B v0.3 via Hugging Face. Efficient 7B model with 32k context.',
			),
			array(
				'model_name'                => 'microsoft/Phi-3-mini-4k-instruct',
				'provider'                  => 'huggingface',
				'tpm_limit'                 => 150000,
				'rpm_limit'                 => 300,
				'context_window'            => 4096,
				'max_output_tokens'         => 2048,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0001,
				'cost_per_1k_output_tokens' => 0.0001,
				'notes'                     => 'Phi-3 Mini via Hugging Face. Most economical, compact 3B model.',
			),
			array(
				'model_name'                => 'Qwen/Qwen2.5-72B-Instruct',
				'provider'                  => 'huggingface',
				'tpm_limit'                 => 50000,
				'rpm_limit'                 => 100,
				'context_window'            => 32768,
				'max_output_tokens'         => 4096,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.001,
				'cost_per_1k_output_tokens' => 0.001,
				'fallback_model'            => 'Qwen/Qwen2.5-7B-Instruct',
				'notes'                     => 'Qwen 2.5 72B via Hugging Face. Multilingual with strong reasoning.',
			),
			array(
				'model_name'                => 'Qwen/Qwen2.5-7B-Instruct',
				'provider'                  => 'huggingface',
				'tpm_limit'                 => 120000,
				'rpm_limit'                 => 240,
				'context_window'            => 32768,
				'max_output_tokens'         => 2048,
				'tier'                      => 'free',
				'supports_streaming'        => true,
				'supports_function_calling' => true,
				'supports_vision'           => false,
				'cost_per_1k_input_tokens'  => 0.0002,
				'cost_per_1k_output_tokens' => 0.0002,
				'fallback_model'            => 'microsoft/Phi-3-mini-4k-instruct',
				'notes'                     => 'Qwen 2.5 7B via Hugging Face. Fast multilingual model.',
			),
		);
	}
}

WP_MCP_AI_Model_Rate_Limits_CCT::bootstrap();

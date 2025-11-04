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
		// Run after JetEngine initialises the Custom Content Types module but before
		// the manager registers existing instances (priority 10).
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 0 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 0 );

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
	 * @return array|null Model rate limit data or null if not found.
	 */
	public static function get_model_limits( $model ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return null;
		}

		$model = sanitize_text_field( $model );

		// Query for exact match first.
		$items = $handler->query_items(
			array(
				'model_name' => $model,
			)
		);

		if ( ! empty( $items ) && is_array( $items ) ) {
			return reset( $items );
		}

		// Try prefix match for model families (e.g., gpt-4o-mini matches gpt-4o).
		$all_items = $handler->query_items( array() );

		if ( empty( $all_items ) || ! is_array( $all_items ) ) {
			return null;
		}

		foreach ( $all_items as $item ) {
			if ( ! isset( $item['model_name'] ) ) {
				continue;
			}

			$stored_model = sanitize_text_field( $item['model_name'] );

			if ( 0 === strpos( $model, $stored_model ) ) {
				return $item;
			}
		}

		return null;
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
				'_ID'              => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'model_name'       => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'provider'         => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'tpm_limit'        => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'context_window'   => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'tier'             => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'cct_created'      => array(
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
					'options' => array(
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

		// Check if data already exists.
		$existing = $handler->query_items( array() );

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
			// OpenAI Models - Tier 1 (Standard/Paid).
			array(
				'model_name'                 => 'gpt-4o',
				'provider'                   => 'openai',
				'tpm_limit'                  => 30000,
				'rpm_limit'                  => 500,
				'context_window'             => 128000,
				'max_output_tokens'          => 16384,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.0025,
				'cost_per_1k_output_tokens'  => 0.01,
				'notes'                      => 'GPT-4o standard tier. Scale tier supports up to 450,000 TPM.',
			),
			array(
				'model_name'                 => 'gpt-4o-mini',
				'provider'                   => 'openai',
				'tpm_limit'                  => 200000,
				'rpm_limit'                  => 500,
				'context_window'             => 128000,
				'max_output_tokens'          => 16384,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.00015,
				'cost_per_1k_output_tokens'  => 0.0006,
				'notes'                      => 'Cost-effective variant of GPT-4o.',
			),
			array(
				'model_name'                 => 'gpt-4-turbo',
				'provider'                   => 'openai',
				'tpm_limit'                  => 80000,
				'rpm_limit'                  => 500,
				'context_window'             => 128000,
				'max_output_tokens'          => 4096,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.01,
				'cost_per_1k_output_tokens'  => 0.03,
				'notes'                      => 'GPT-4 Turbo with vision capabilities.',
			),
			array(
				'model_name'                 => 'gpt-4',
				'provider'                   => 'openai',
				'tpm_limit'                  => 10000,
				'rpm_limit'                  => 500,
				'context_window'             => 8192,
				'max_output_tokens'          => 4096,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => false,
				'cost_per_1k_input_tokens'   => 0.03,
				'cost_per_1k_output_tokens'  => 0.06,
				'notes'                      => 'Original GPT-4 model.',
			),
			array(
				'model_name'                 => 'gpt-3.5-turbo',
				'provider'                   => 'openai',
				'tpm_limit'                  => 60000,
				'rpm_limit'                  => 3500,
				'context_window'             => 16385,
				'max_output_tokens'          => 4096,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => false,
				'cost_per_1k_input_tokens'   => 0.0005,
				'cost_per_1k_output_tokens'  => 0.0015,
				'notes'                      => 'Fast and cost-effective for simpler tasks.',
			),
			array(
				'model_name'                 => 'o1-preview',
				'provider'                   => 'openai',
				'tpm_limit'                  => 200000,
				'rpm_limit'                  => 500,
				'context_window'             => 128000,
				'max_output_tokens'          => 32768,
				'tier'                       => 'tier-1',
				'supports_streaming'         => false,
				'supports_function_calling'  => false,
				'supports_vision'            => false,
				'cost_per_1k_input_tokens'   => 0.015,
				'cost_per_1k_output_tokens'  => 0.06,
				'notes'                      => 'Advanced reasoning model optimized for complex tasks.',
			),
			array(
				'model_name'                 => 'o1-mini',
				'provider'                   => 'openai',
				'tpm_limit'                  => 200000,
				'rpm_limit'                  => 500,
				'context_window'             => 128000,
				'max_output_tokens'          => 65536,
				'tier'                       => 'tier-1',
				'supports_streaming'         => false,
				'supports_function_calling'  => false,
				'supports_vision'            => false,
				'cost_per_1k_input_tokens'   => 0.003,
				'cost_per_1k_output_tokens'  => 0.012,
				'notes'                      => 'Smaller reasoning model, faster and more cost-effective.',
			),

			// Google Gemini Models - Paid Tier.
			array(
				'model_name'                 => 'gemini-1.5-pro',
				'provider'                   => 'google',
				'tpm_limit'                  => 1000000,
				'rpm_limit'                  => 1000,
				'context_window'             => 2097152,
				'max_output_tokens'          => 8192,
				'tier'                       => 'tier-2',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.00125,
				'cost_per_1k_output_tokens'  => 0.005,
				'notes'                      => 'Large context window, excellent for long documents.',
			),
			array(
				'model_name'                 => 'gemini-1.5-flash',
				'provider'                   => 'google',
				'tpm_limit'                  => 1000000,
				'rpm_limit'                  => 2000,
				'context_window'             => 1048576,
				'max_output_tokens'          => 8192,
				'tier'                       => 'tier-2',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.000075,
				'cost_per_1k_output_tokens'  => 0.0003,
				'notes'                      => 'Fast and cost-effective with large context.',
			),
			array(
				'model_name'                 => 'gemini-2.0-flash',
				'provider'                   => 'google',
				'tpm_limit'                  => 1000000,
				'rpm_limit'                  => 1000,
				'context_window'             => 1048576,
				'max_output_tokens'          => 8192,
				'tier'                       => 'tier-2',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.0001,
				'cost_per_1k_output_tokens'  => 0.0004,
				'notes'                      => 'Latest Gemini Flash model with improved performance.',
			),

			// Anthropic Claude Models - Build Tier.
			array(
				'model_name'                 => 'claude-3.5-sonnet',
				'provider'                   => 'anthropic',
				'tpm_limit'                  => 40000,
				'rpm_limit'                  => 50,
				'context_window'             => 200000,
				'max_output_tokens'          => 4096,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.003,
				'cost_per_1k_output_tokens'  => 0.015,
				'notes'                      => 'Balanced performance and cost. Scale tier: 400,000 TPM.',
			),
			array(
				'model_name'                 => 'claude-3-opus',
				'provider'                   => 'anthropic',
				'tpm_limit'                  => 20000,
				'rpm_limit'                  => 50,
				'context_window'             => 200000,
				'max_output_tokens'          => 4096,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.015,
				'cost_per_1k_output_tokens'  => 0.075,
				'notes'                      => 'Most capable Claude model. Scale tier: 400,000 TPM.',
			),
			array(
				'model_name'                 => 'claude-3-haiku',
				'provider'                   => 'anthropic',
				'tpm_limit'                  => 50000,
				'rpm_limit'                  => 50,
				'context_window'             => 200000,
				'max_output_tokens'          => 4096,
				'tier'                       => 'tier-1',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.00025,
				'cost_per_1k_output_tokens'  => 0.00125,
				'notes'                      => 'Fast and economical. Scale tier: 400,000 TPM.',
			),

			// Azure OpenAI variants (examples).
			array(
				'model_name'                 => 'gpt-4o',
				'provider'                   => 'azure',
				'tpm_limit'                  => 450000,
				'rpm_limit'                  => 1000,
				'context_window'             => 128000,
				'max_output_tokens'          => 16384,
				'tier'                       => 'tier-2',
				'supports_streaming'         => true,
				'supports_function_calling'  => true,
				'supports_vision'            => true,
				'cost_per_1k_input_tokens'   => 0.0025,
				'cost_per_1k_output_tokens'  => 0.01,
				'notes'                      => 'Azure OpenAI deployment. Limits per region.',
			),
		);
	}
}

WP_MCP_AI_Model_Rate_Limits_CCT::bootstrap();

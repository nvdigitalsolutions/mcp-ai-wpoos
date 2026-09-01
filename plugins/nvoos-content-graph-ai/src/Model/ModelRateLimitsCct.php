<?php
/**
 * Model Rate Limits CCT for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-model-rate-limits-cct.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). CCT slug, meta-field definitions, registration payload,
 * prefix-matching variant logic, catalog loading order, cache group/key,
 * and filters are byte-identical.
 *
 * Decoupling (documented, additive):
 * - The bundled catalog path falls back to `__DIR__/model-catalog.json`
 *   when the base plugin is absent (the base's own fallback pattern).
 * - Logging forwards to the base `WP_MCP_AI_Logger` in monolith installs
 *   only (CG-AI audit logger lands in Wave D4).
 * - `bootstrap()` (init hooks) is registered standalone-only by
 *   `Plugin.php` — the base owns the same hooks in monolith installs.
 *
 * @package NvoosContentGraphAi\Model
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model rate limits content type manager.
 *
 * @since 1.1.0
 */
class ModelRateLimitsCct {

	const SLUG        = 'ai_model_rate_limits';
	const CACHE_GROUP = 'wp_mcp_ai_model_catalog';
	const CACHE_KEY   = 'default_model_data';

	/**
	 * Hook into JetEngine to provision the model rate limits content type.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 5 );
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
	 * @param string $model       Model identifier.
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
				static::log_event(
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
		} catch ( \Exception $e ) {
			static::log_error(
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
	 *
	 * @return void
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
	 *
	 * @return void
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
	 * @param object $module Module instance.
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
	 * @return object|null
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
		$label = __( 'AI Model Rate Limits', 'nvoos-content-graph-ai' );

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
				__( 'Model Name', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Model identifier (e.g., gpt-4o, claude-3.5-sonnet, gemini-1.5-pro).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20002,
				'provider',
				__( 'Provider', 'nvoos-content-graph-ai' ),
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
							'key'   => 'gemini',
							'value' => 'Google Gemini',
						),
						array(
							'key'   => 'deepseek',
							'value' => 'DeepSeek',
						),
						array(
							'key'   => 'openrouter',
							'value' => 'OpenRouter',
						),
						array(
							'key'   => 'baseten',
							'value' => 'Baseten',
						),
						array(
							'key'   => 'kimi',
							'value' => 'Kimi (Moonshot AI)',
						),
						array(
							'key'   => 'digitalocean',
							'value' => 'DigitalOcean',
						),
						array(
							'key'   => 'cloudflare',
							'value' => 'Cloudflare',
						),
						array(
							'key'   => 'nvidia',
							'value' => 'NVIDIA NIM',
						),
						array(
							'key'   => 'huggingface',
							'value' => 'Hugging Face',
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
							'key'   => 'embedded',
							'value' => 'Embedded LLM',
						),
						array(
							'key'   => 'webllm',
							'value' => 'WebLLM',
						),
						array(
							'key'   => 'google',
							'value' => 'Google',
						),
						array(
							'key'   => 'other',
							'value' => 'Other',
						),
					),
					'description' => __( 'AI provider offering this model.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20003,
				'tpm_limit',
				__( 'TPM Limit (Tokens Per Minute)', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'is_required' => true,
					'min'         => 0,
					'step'        => 1000,
					'description' => __( 'Maximum tokens per minute allowed for this model (API rate limit).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20004,
				'rpm_limit',
				__( 'RPM Limit (Requests Per Minute)', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'Maximum requests per minute allowed for this model.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20005,
				'context_window',
				__( 'Context Window (Max Tokens)', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'is_required' => true,
					'min'         => 0,
					'step'        => 1000,
					'description' => __( 'Maximum context window size in tokens for this model.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20006,
				'max_output_tokens',
				__( 'Max Output Tokens', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1000,
					'description' => __( 'Maximum output tokens the model can generate per request.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20007,
				'tier',
				__( 'Account Tier', 'nvoos-content-graph-ai' ),
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
					'description' => __( 'Account tier these limits apply to (Free, Paid, Enterprise, etc.).', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20008,
				'supports_streaming',
				__( 'Supports Streaming', 'nvoos-content-graph-ai' ),
				'switcher',
				array(
					'description' => __( 'Whether this model supports streaming responses.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20009,
				'supports_function_calling',
				__( 'Supports Function Calling', 'nvoos-content-graph-ai' ),
				'switcher',
				array(
					'description' => __( 'Whether this model supports function/tool calling.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20010,
				'supports_vision',
				__( 'Supports Vision', 'nvoos-content-graph-ai' ),
				'switcher',
				array(
					'description' => __( 'Whether this model can process images.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20011,
				'cost_per_1k_input_tokens',
				__( 'Cost Per 1K Input Tokens ($)', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 0.0001,
					'description' => __( 'Cost in USD per 1000 input tokens.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20012,
				'cost_per_1k_output_tokens',
				__( 'Cost Per 1K Output Tokens ($)', 'nvoos-content-graph-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 0.0001,
					'description' => __( 'Cost in USD per 1000 output tokens.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20013,
				'notes',
				__( 'Notes', 'nvoos-content-graph-ai' ),
				'textarea',
				array(
					'rows'        => 3,
					'description' => __( 'Additional notes about this model configuration.', 'nvoos-content-graph-ai' ),
				)
			),
			self::build_field(
				20014,
				'fallback_model',
				__( 'High-Capacity Fallback Model', 'nvoos-content-graph-ai' ),
				'text',
				array(
					'description' => __( 'Model to use when this model\'s TPM limit is exceeded (e.g., gemini-2.5-flash). Leave empty to use global fallback setting.', 'nvoos-content-graph-ai' ),
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
	 *
	 * @return void
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

		static::log_event(
			'model_rate_limits_populated',
			'Populated default model rate limits data.',
			array( 'count' => count( $default_models ) )
		);
	}

	/**
	 * Get default model data for populating the CCT.
	 *
	 * @return array Default model data.
	 */
	public static function get_default_model_data() {
		return self::load_catalog();
	}

	/**
	 * Load the model catalog from the bundled JSON file.
	 *
	 * @return array Model catalog entries (each keyed by `model_name`).
	 */
	public static function load_catalog() {
		// Allow advanced tooling/tests to bypass the cache.
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$catalog = array();

		if ( ! defined( 'WP_MCP_AI_FORCE_HARDCODED_CATALOG' ) || ! WP_MCP_AI_FORCE_HARDCODED_CATALOG ) {
			$catalog = self::read_catalog_file();
		}

		/**
		 * Filter the loaded model catalog before it is cached.
		 *
		 * @since 1.1.0
		 *
		 * @param array $catalog Array of model entries (list, not associative).
		 */
		$catalog = apply_filters( 'wp_mcp_ai_model_catalog', $catalog );

		if ( ! is_array( $catalog ) ) {
			$catalog = array();
		}

		wp_cache_set( self::CACHE_KEY, $catalog, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $catalog;
	}

	/**
	 * Read and decode the model catalog JSON file.
	 *
	 * @return array Model catalog entries, or empty array on failure.
	 */
	protected static function read_catalog_file() {
		$default_path = defined( 'WP_MCP_AI_PATH' )
			? trailingslashit( WP_MCP_AI_PATH ) . 'includes/data/model-catalog.json'
			: __DIR__ . '/model-catalog.json';

		/**
		 * Filter the path to the JSON file used as the model catalog source.
		 *
		 * @since 1.1.0
		 *
		 * @param string $default_path Absolute filesystem path to the JSON file.
		 */
		$path = apply_filters( 'wp_mcp_ai_model_catalog_source_path', $default_path );

		// Allow site admins to drop a custom catalog into wp-content/uploads/mcp-ai/.
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$uploads = wp_get_upload_dir();
			if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$override = trailingslashit( $uploads['basedir'] ) . 'mcp-ai/model-catalog.json';
				if ( $override !== $path && file_exists( $override ) && is_readable( $override ) ) {
					$path = $override;
				}
			}
		}

		if ( empty( $path ) || ! is_string( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
			static::log_error(
				'Model catalog JSON file is missing or unreadable.',
				array( 'path' => $path )
			);
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading bundled JSON catalog.
		$raw = file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			static::log_error(
				'Failed to read model catalog JSON file.',
				array( 'path' => $path )
			);
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			static::log_error(
				'Model catalog JSON is invalid.',
				array(
					'path'  => $path,
					'error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '',
				)
			);
			return array();
		}

		// Accept either a wrapped object { "models": [...] } or a bare list.
		if ( isset( $decoded['models'] ) && is_array( $decoded['models'] ) ) {
			return array_values( $decoded['models'] );
		}

		return array_values( $decoded );
	}

	/**
	 * Flush the cached model catalog so the next access re-reads JSON + filters.
	 *
	 * @return void
	 */
	public static function flush_catalog_cache() {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Log an event through the base plugin's logger (monolith only).
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional event data.
	 * @return void
	 */
	protected static function log_event( $event, $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $event, $message, $data );
		}
	}

	/**
	 * Log an error through the base plugin's logger (monolith only).
	 *
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional error data.
	 * @return void
	 */
	protected static function log_error( $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $data );
		}
	}
}

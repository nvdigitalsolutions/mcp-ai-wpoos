<?php
/**
 * JetEngine Custom Content Type registration for AI chat transcripts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the AI chat transcript CCT exists and expose helper accessors.
 */
class WP_MCP_AI_JetEngine_CCT {
	const SLUG = 'ai_chat_transcripts';

	/**
	 * Hook into JetEngine to provision the transcript content type.
	 */
	public static function bootstrap() {
		// Run after JetEngine initialises the Custom Content Types module.
		// We use priority 100 to ensure JetEngine is fully loaded first.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 100 );
	}

	/**
	 * Retrieve the transcript CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the transcript content type.
	 *
	 * Consumers can use the returned handler similarly to \`jet_engine()->cct->item_handler\`
	 * when interacting with the transcript records programmatically.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			WP_MCP_AI_Logger::log_event(
				'warning',
				'JetEngine CCT: get_item_handler() failed - CCT module not available',
				array(
					'slug'             => self::SLUG,
					'jetengine_active' => function_exists( 'jet_engine' ),
					'reason'           => 'get_cct_module() returned null',
				)
			);
			return null;
		}

		if ( empty( $module->manager ) ) {
			WP_MCP_AI_Logger::log_event(
				'warning',
				'JetEngine CCT: get_item_handler() failed - manager not available',
				array(
					'slug'   => self::SLUG,
					'module' => is_object( $module ) ? get_class( $module ) : gettype( $module ),
					'reason' => 'module->manager is empty',
				)
			);
			return null;
		}

		// Ensure CCT is registered before trying to get handler.
		// In some environments (base + pro plugin), the init hook may have fired
		// but the CCT manager hasn't loaded content types into memory yet.
		$cct_exists_before = self::cct_exists( $module );
		if ( ! $cct_exists_before ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				'JetEngine CCT: CCT does not exist in database, attempting to register',
				array(
					'slug' => self::SLUG,
				)
			);
			// Try to register it now if it doesn't exist.
			self::maybe_register_cct();

			// Check if registration succeeded.
			$cct_exists_after = self::cct_exists( $module );
			WP_MCP_AI_Logger::log_event(
				$cct_exists_after ? 'info' : 'warning',
				'JetEngine CCT: Registration attempt completed',
				array(
					'slug'           => self::SLUG,
					'success'        => $cct_exists_after,
					'existed_before' => $cct_exists_before,
				)
			);
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				'JetEngine CCT: Content type not loaded in manager, forcing reload',
				array(
					'slug'       => self::SLUG,
					'cct_exists' => self::cct_exists( $module ),
				)
			);

			// Content type not loaded in manager yet. Force a reload.
			// The query_raw('post_types') method triggers JetEngine's CCT manager
			// to reload content types from the database into memory.
			if ( ! empty( $module->manager->data ) && ! empty( $module->manager->data->db ) ) {
				if ( method_exists( $module->manager->data->db, 'query_raw' ) ) {
					try {
						$module->manager->data->db->query_raw( 'post_types' );
						WP_MCP_AI_Logger::log_event(
							'info',
							'JetEngine CCT: Successfully called query_raw to reload content types',
							array(
								'slug' => self::SLUG,
							)
						);
					} catch ( Exception $e ) {
						// Log error but continue - handler will still be null.
						// We catch Exception (not Throwable) to avoid masking fatal errors.
						WP_MCP_AI_Logger::log_event(
							'error',
							'JetEngine CCT: Failed to reload content types',
							array(
								'exception' => $e->getMessage(),
								'slug'      => self::SLUG,
							)
						);
					}
				}
			}

			// Try again after reload.
			$instance = $module->manager->get_content_types( self::SLUG );

			if ( ! $instance ) {
				WP_MCP_AI_Logger::log_event(
					'warning',
					'JetEngine CCT: Content type still not available after reload',
					array(
						'slug' => self::SLUG,
					)
				);
			}
		}

		if ( ! $instance ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'JetEngine CCT: get_item_handler() failed - instance not found',
				array(
					'slug'       => self::SLUG,
					'cct_exists' => self::cct_exists( $module ),
					'reason'     => 'manager->get_content_types() returned null after all attempts',
				)
			);
			return null;
		}

		$handler = $instance->get_item_handler();

		if ( ! $handler ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'JetEngine CCT: get_item_handler() failed - handler is null',
				array(
					'slug'     => self::SLUG,
					'instance' => is_object( $instance ) ? get_class( $instance ) : gettype( $instance ),
					'reason'   => 'instance->get_item_handler() returned null',
				)
			);
		} else {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'JetEngine CCT: get_item_handler() successful',
				array(
					'slug'    => self::SLUG,
					'handler' => is_object( $handler ) ? get_class( $handler ) : gettype( $handler ),
				)
			);
		}

		return $handler;
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
	 * Register the transcript CCT if it is missing.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			WP_MCP_AI_Logger::log_event(
				'warning',
				'JetEngine CCT: maybe_register_cct() failed - module not available',
				array(
					'slug'   => self::SLUG,
					'reason' => 'get_cct_module() returned null',
				)
			);
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			WP_MCP_AI_Logger::log_event(
				'warning',
				'JetEngine CCT: maybe_register_cct() failed - manager or data not available',
				array(
					'slug'        => self::SLUG,
					'has_manager' => ! empty( $module->manager ),
					'has_data'    => ! empty( $module->manager ) && ! empty( $module->manager->data ),
				)
			);
			return;
		}

		if ( self::cct_exists( $module ) ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'JetEngine CCT: maybe_register_cct() - CCT already exists, skipping registration',
				array(
					'slug' => self::SLUG,
				)
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'info',
			'JetEngine CCT: Attempting to register CCT',
			array(
				'slug' => self::SLUG,
			)
		);

		$data    = $module->manager->data;
		$request = self::get_registration_request();

		$data->set_request( $request );

		if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'JetEngine CCT: Registration failed - sanitize_item_request returned false',
				array(
					'slug' => self::SLUG,
				)
			);
			return;
		}

		$item = $data->sanitize_item_from_request();

		if ( empty( $item ) || ! is_array( $item ) ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'JetEngine CCT: Registration failed - sanitize_item_from_request returned invalid data',
				array(
					'slug'      => self::SLUG,
					'item_type' => gettype( $item ),
					'is_empty'  => empty( $item ),
				)
			);
			return;
		}

		$data->before_item_update( $item, true );

		$item_id = $data->update_item_in_db( $item );

		if ( ! $item_id ) {
			WP_MCP_AI_Logger::log_event(
				'error',
				'JetEngine CCT: Registration failed - update_item_in_db returned false/null',
				array(
					'slug' => self::SLUG,
				)
			);
			return;
		}

		$item['id'] = $item_id;

		$data->after_item_update( $item, true );

		if ( ! empty( $data->db ) && method_exists( $data->db, 'query_raw' ) ) {
			$data->db->query_raw( 'post_types' );
		}

		WP_MCP_AI_Logger::log_event(
			'info',
			'JetEngine CCT: Successfully registered CCT',
			array(
				'slug'    => self::SLUG,
				'item_id' => $item_id,
			)
		);
	}

	/**
	 * Determine whether the transcript CCT already exists.
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
		$label = __( 'AI Chat Transcripts', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the transcript CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-format-chat',
			'capability'          => 'manage_options',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => false,
			'rest_post_enabled'   => false,
			'rest_delete_enabled' => false,
			'rest_get_access'     => 'manage_options',
			'rest_put_access'     => 'edit_posts',
			'rest_post_access'    => 'edit_posts',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'             => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'session_key'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'user_id'         => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'assistant_id'    => array(
					'enabled' => true,
				),
				'assistant_model' => array(
					'enabled' => true,
				),
				'latency_ms'      => array(
					'enabled' => true,
					'is_num'  => true,
				),
				'cct_created'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the transcript meta field configuration.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				10001,
				'session_key',
				__( 'Session Key', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Correlation key that groups related messages or turns.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				10002,
				'user_id',
				__( 'User ID', 'mcp-ai-wpoos' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'Numeric WordPress user ID associated with the session.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				10003,
				'assistant_id',
				__( 'Assistant ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Internal assistant identifier handling the request.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				10004,
				'assistant_model',
				__( 'Assistant Model', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Model string reported by the assistant response.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				10005,
				'request_payload',
				__( 'Request Payload', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Full request payload stored as JSON.', 'mcp-ai-wpoos' ),
					'rows'        => 8,
				)
			),
			self::build_field(
				10006,
				'response_payload',
				__( 'Response Payload', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Assistant response payload stored as JSON.', 'mcp-ai-wpoos' ),
					'rows'        => 8,
				)
			),
			self::build_field(
				10007,
				'metadata',
				__( 'Metadata', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Serialized metadata such as token usage, cost, and latency details.', 'mcp-ai-wpoos' ),
					'rows'        => 4,
				)
			),
			self::build_field(
				10008,
				'latency_ms',
				__( 'Latency (ms)', 'mcp-ai-wpoos' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'End-to-end response time measured in milliseconds.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				10009,
				'request_started_at',
				__( 'Request Started', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Timestamp for when the request processing began.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				10010,
				'response_completed_at',
				__( 'Response Completed', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Timestamp for when the assistant finished responding.', 'mcp-ai-wpoos' ),
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
}

WP_MCP_AI_JetEngine_CCT::bootstrap();

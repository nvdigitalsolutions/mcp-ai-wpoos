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
		// Run after JetEngine initialises the Custom Content Types module but before
		// the manager registers existing instances (priority 10).
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 0 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 0 );
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
		$label = __( 'AI Chat Transcripts', 'wp-mcp-ai' );

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
				__( 'Session Key', 'wp-mcp-ai' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Correlation key that groups related messages or turns.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10002,
				'user_id',
				__( 'User ID', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'Numeric WordPress user ID associated with the session.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10003,
				'assistant_id',
				__( 'Assistant ID', 'wp-mcp-ai' ),
				'text',
				array(
					'description' => __( 'Internal assistant identifier handling the request.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10004,
				'assistant_model',
				__( 'Assistant Model', 'wp-mcp-ai' ),
				'text',
				array(
					'description' => __( 'Model string reported by the assistant response.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10005,
				'request_payload',
				__( 'Request Payload', 'wp-mcp-ai' ),
				'textarea',
				array(
					'description' => __( 'Full request payload stored as JSON.', 'wp-mcp-ai' ),
					'rows'        => 8,
				)
			),
			self::build_field(
				10006,
				'response_payload',
				__( 'Response Payload', 'wp-mcp-ai' ),
				'textarea',
				array(
					'description' => __( 'Assistant response payload stored as JSON.', 'wp-mcp-ai' ),
					'rows'        => 8,
				)
			),
			self::build_field(
				10007,
				'metadata',
				__( 'Metadata', 'wp-mcp-ai' ),
				'textarea',
				array(
					'description' => __( 'Serialized metadata such as token usage, cost, and latency details.', 'wp-mcp-ai' ),
					'rows'        => 4,
				)
			),
			self::build_field(
				10008,
				'latency_ms',
				__( 'Latency (ms)', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'step'        => 1,
					'description' => __( 'End-to-end response time measured in milliseconds.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10009,
				'request_started_at',
				__( 'Request Started', 'wp-mcp-ai' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Timestamp for when the request processing began.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				10010,
				'response_completed_at',
				__( 'Response Completed', 'wp-mcp-ai' ),
				'datetime-local',
				array(
					'is_timestamp' => true,
					'description'  => __( 'Timestamp for when the assistant finished responding.', 'wp-mcp-ai' ),
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

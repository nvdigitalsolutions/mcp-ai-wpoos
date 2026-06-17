<?php
/**
 * JetEngine Custom Content Type registration for execution history.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the execution history CCT for Ralph orchestration pattern.
 * High-volume tool call logs for analytics and debugging.
 */
class WP_MCP_AI_Execution_History_CCT {
	const SLUG = 'mcp_execution_history';

	/**
	 * Base ID for meta field identifiers.
	 * Using 32000 range to avoid conflicts with other CCT fields.
	 */
	const FIELD_ID_BASE = 32000;

	/**
	 * Hook into JetEngine to provision the execution history content type.
	 */
	public static function bootstrap() {
		// JetEngine's CCT module hydrates its table cache on `init` at priorities
		// 1-10; registering inside that window races with it and stomps
		// JetEngine's CCT state. Priority 11 is the documented safe window.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 11 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 11 );
	}

	/**
	 * Retrieve the execution history CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the execution history content type.
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
	 * Get execution history for a session.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $args       Query arguments.
	 * @return array List of execution records.
	 */
	public static function get_session_history( $session_id, $args = array() ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return array();
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return array();
		}

		$defaults = array(
			'session_id' => $session_id,
			'orderby'    => 'executed_at',
			'order'      => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$items = $factory->db->query( $args );

		return is_array( $items ) ? $items : array();
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
	 * Register the execution history CCT if it is missing.
	 */
	public static function maybe_register_cct() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}

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
	 * Determine whether the execution history CCT already exists.
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
		$label = __( 'Execution History', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the execution history CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-chart-line',
			'capability'          => 'edit_posts',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => false,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'edit_posts',
			'rest_post_access'    => 'edit_posts',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'         => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'session_id'  => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'tool_name'   => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'success'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'duration_ms' => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'tokens_used' => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'executed_at' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the meta fields for the execution history CCT.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				32001,
				'session_id',
				__( 'Session ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Session identifier for this execution.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32002,
				'iteration',
				__( 'Iteration', 'mcp-ai-wpoos' ),
				'number',
				array(
					'is_required' => true,
					'description' => __( 'Iteration number within the session.', 'mcp-ai-wpoos' ),
					'min'         => 1,
				)
			),
			self::build_field(
				32003,
				'tool_name',
				__( 'Tool Name', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Name of the tool executed.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32004,
				'success',
				__( 'Success', 'mcp-ai-wpoos' ),
				'switcher',
				array(
					'is_required' => true,
					'description' => __( 'Whether the tool execution succeeded.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32005,
				'error_message',
				__( 'Error Message', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Error message if execution failed.', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			self::build_field(
				32006,
				'duration_ms',
				__( 'Duration (ms)', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Execution duration in milliseconds.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				32007,
				'tokens_used',
				__( 'Tokens Used', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Tokens consumed by this tool call.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				32008,
				'input_summary',
				__( 'Input Summary', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Summary of tool input arguments (first 500 chars).', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			self::build_field(
				32009,
				'output_summary',
				__( 'Output Summary', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Summary of tool output (first 500 chars).', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			self::build_field(
				32010,
				'executed_at',
				__( 'Executed At', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'is_required' => true,
					'description' => __( 'Timestamp when tool was executed.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				32011,
				'metadata',
				__( 'Metadata', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON metadata for additional execution data.', 'mcp-ai-wpoos' ),
					'rows'        => 2,
				)
			),
		);

		return $fields;
	}

	/**
	 * Build a standardized meta field definition.
	 *
	 * @param int    $id         Unique field identifier.
	 * @param string $name       Field name (key).
	 * @param string $title      Human-readable field title.
	 * @param string $type       Field type (text, textarea, number, select, etc.).
	 * @param array  $extra_args Optional. Additional field arguments.
	 * @return array
	 */
	protected static function build_field( $id, $name, $title, $type, $extra_args = array() ) {
		return wp_parse_args(
			$extra_args,
			array(
				'id'    => $id,
				'name'  => $name,
				'title' => $title,
				'type'  => $type,
			)
		);
	}
}

WP_MCP_AI_Execution_History_CCT::bootstrap();

<?php
/**
 * JetEngine Custom Content Type registration for task plans.
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
 * Manages the task plans CCT for Ralph orchestration pattern.
 */
class WP_MCP_AI_Task_Plans_CCT {
	const SLUG = 'mcp_task_plans';

	/**
	 * Base ID for meta field identifiers.
	 * Using 31000 range to avoid conflicts with other CCT fields.
	 */
	const FIELD_ID_BASE = 31000;

	/**
	 * Hook into JetEngine to provision the task plans content type.
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
	 * Retrieve the task plans CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the task plans content type.
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
	 * Get active task plans.
	 *
	 * @param array $args Query arguments.
	 * @return array List of active task plans.
	 */
	public static function get_active_plans( $args = array() ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return array();
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return array();
		}

		$defaults = array(
			'status' => 'active',
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
	 * Register the task plans CCT if it is missing.
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
	 * Determine whether the task plans CCT already exists.
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
		$label = __( 'Task Plans', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the task plans CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-list-view',
			'capability'          => 'edit_posts',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => true,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'edit_posts',
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
				'goal'            => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'status'          => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'task_count'      => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'completed_count' => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'progress'        => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'cct_created'     => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'cct_modified'    => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the meta fields for the task plans CCT.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				31001,
				'goal',
				__( 'Goal', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Primary goal or objective of this task plan.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				31002,
				'markdown_content',
				__( 'Markdown Content', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'is_required' => true,
					'description' => __( 'Task plan in markdown format with GFM checkboxes.', 'mcp-ai-wpoos' ),
					'rows'        => 15,
				)
			),
			self::build_field(
				31003,
				'status',
				__( 'Status', 'mcp-ai-wpoos' ),
				'select',
				array(
					'is_required' => true,
					'options'     => array(
						array(
							'key'   => 'draft',
							'value' => 'Draft',
						),
						array(
							'key'   => 'active',
							'value' => 'Active',
						),
						array(
							'key'   => 'paused',
							'value' => 'Paused',
						),
						array(
							'key'   => 'completed',
							'value' => 'Completed',
						),
						array(
							'key'   => 'archived',
							'value' => 'Archived',
						),
					),
					'description' => __( 'Current task plan status.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				31004,
				'task_count',
				__( 'Task Count', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Total number of tasks in the plan.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				31005,
				'completed_count',
				__( 'Completed Count', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Number of completed tasks.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				31006,
				'progress',
				__( 'Progress', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Progress percentage (0-100).', 'mcp-ai-wpoos' ),
					'min'         => 0,
					'max'         => 100,
				)
			),
			self::build_field(
				31007,
				'tasks_parsed',
				__( 'Parsed Tasks', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON array of parsed task objects with completion status (cached).', 'mcp-ai-wpoos' ),
					'rows'        => 5,
				)
			),
			self::build_field(
				31008,
				'project_id',
				__( 'Project ID', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Associated project ID (if linked).', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				31009,
				'template_id',
				__( 'Template ID', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Source template ID (if created from template).', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				31010,
				'completed_at',
				__( 'Completed At', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'description' => __( 'Timestamp when plan was completed.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				31011,
				'metadata',
				__( 'Metadata', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON metadata for additional plan data.', 'mcp-ai-wpoos' ),
					'rows'        => 3,
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

WP_MCP_AI_Task_Plans_CCT::bootstrap();

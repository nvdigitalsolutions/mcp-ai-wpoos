<?php
/**
 * JetEngine Custom Content Type registration for task templates.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the task templates CCT for Ralph orchestration pattern.
 * Reusable workflow templates for common task patterns.
 */
class WP_MCP_AI_Task_Templates_CCT {
	const SLUG = 'mcp_task_templates';

	/**
	 * Base ID for meta field identifiers.
	 * Using 33000 range to avoid conflicts with other CCT fields.
	 */
	const FIELD_ID_BASE = 33000;

	/**
	 * Hook into JetEngine to provision the task templates content type.
	 */
	public static function bootstrap() {
		// Run after JetEngine initialises the Custom Content Types module.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 5 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 5 );
	}

	/**
	 * Retrieve the task templates CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the task templates content type.
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
	 * Get published templates.
	 *
	 * @param array $args Query arguments.
	 * @return array List of published templates.
	 */
	public static function get_published_templates( $args = array() ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return array();
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return array();
		}

		$defaults = array(
			'status'  => 'published',
			'orderby' => 'usage_count',
			'order'   => 'DESC',
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
	 * Register the task templates CCT if it is missing.
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
	 * Determine whether the task templates CCT already exists.
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
		$label = __( 'Task Templates', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the task templates CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-format-aside',
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
				'_ID'           => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'template_name' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'category'      => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'status'        => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'usage_count'   => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'version'       => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'cct_created'   => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the meta fields for the task templates CCT.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				33001,
				'template_name',
				__( 'Template Name', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Name of this task template.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				33002,
				'description',
				__( 'Description', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'is_required' => true,
					'description' => __( 'Template description and use case.', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				)
			),
			self::build_field(
				33003,
				'category',
				__( 'Category', 'mcp-ai-wpoos' ),
				'select',
				array(
					'is_required' => true,
					'options'     => array(
						array(
							'key'   => 'research',
							'value' => 'Research',
						),
						array(
							'key'   => 'content',
							'value' => 'Content Creation',
						),
						array(
							'key'   => 'data_analysis',
							'value' => 'Data Analysis',
						),
						array(
							'key'   => 'development',
							'value' => 'Development',
						),
						array(
							'key'   => 'marketing',
							'value' => 'Marketing',
						),
						array(
							'key'   => 'custom',
							'value' => 'Custom',
						),
					),
					'description' => __( 'Template category for organization.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				33004,
				'markdown_template',
				__( 'Markdown Template', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'is_required' => true,
					'description' => __( 'Markdown template content with placeholder support.', 'mcp-ai-wpoos' ),
					'rows'        => 15,
				)
			),
			self::build_field(
				33005,
				'default_config',
				__( 'Default Config', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON configuration for default settings (iterations, tokens, etc.).', 'mcp-ai-wpoos' ),
					'rows'        => 5,
				)
			),
			self::build_field(
				33006,
				'tags',
				__( 'Tags', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Comma-separated tags for template discovery.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				33007,
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
							'key'   => 'published',
							'value' => 'Published',
						),
						array(
							'key'   => 'archived',
							'value' => 'Archived',
						),
					),
					'description' => __( 'Template availability status.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				33008,
				'usage_count',
				__( 'Usage Count', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Number of times this template has been used.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				33009,
				'success_rate',
				__( 'Success Rate', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Success rate percentage (0-100) based on completed plans.', 'mcp-ai-wpoos' ),
					'min'         => 0,
					'max'         => 100,
				)
			),
			self::build_field(
				33010,
				'avg_completion_time',
				__( 'Avg Completion Time', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Average completion time in minutes.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				33011,
				'version',
				__( 'Version', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Template version (e.g., 1.0.0).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				33012,
				'author_id',
				__( 'Author ID', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'WordPress user ID of template creator.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				33013,
				'metadata',
				__( 'Metadata', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'JSON metadata for additional template data.', 'mcp-ai-wpoos' ),
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

WP_MCP_AI_Task_Templates_CCT::bootstrap();

<?php
/**
 * JetEngine Custom Content Type registration for quiz submissions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the quiz submissions CCT exists and expose helper accessors.
 */
class WP_MCP_AI_JetEngine_Submissions_CCT {
	const SLUG = 'quiz_submissions';

	/**
	 * Base ID for meta field identifiers.
	 * Using 31000 range to avoid conflicts with other CCT fields.
	 */
	const FIELD_ID_BASE = 31000;

	/**
	 * Hook into JetEngine to provision the submissions content type.
	 */
	public static function bootstrap() {
		// Run after JetEngine initialises the Custom Content Types module but before.
		// the manager registers existing instances (priority 10).
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 0 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 0 );
	}

	/**
	 * Retrieve the submissions CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the submissions content type.
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
	 * Register the submissions CCT if it is missing.
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
	 * Determine whether the submissions CCT already exists.
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
		$label = __( 'Quiz Submissions', 'wp-mcp-ai' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the submissions CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-feedback',
			'capability'          => 'read',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => true,
			'rest_post_enabled'   => true,
			'rest_delete_enabled' => true,
			'rest_get_access'     => 'read',
			'rest_put_access'     => 'edit_posts',
			'rest_post_access'    => 'read',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'         => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'quiz_id'     => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'student_id'  => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'status'      => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'percentage'  => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'cct_created' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the submissions meta field configuration.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$base_id = self::FIELD_ID_BASE;

		$fields = array(
			self::build_field(
				++$base_id,
				'quiz_id',
				__( 'Quiz ID', 'wp-mcp-ai' ),
				'number',
				array(
					'is_required' => true,
					'description' => __( 'Associated quiz ID.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'student_id',
				__( 'Student ID', 'wp-mcp-ai' ),
				'number',
				array(
					'is_required' => true,
					'description' => __( 'Student user ID.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'status',
				__( 'Status', 'wp-mcp-ai' ),
				'text',
				array(
					'description' => __( 'Submission status (pending, graded).', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'earned_points',
				__( 'Earned Points', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'description' => __( 'Points earned.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'total_points',
				__( 'Total Points', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'description' => __( 'Total possible points.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'percentage',
				__( 'Percentage', 'wp-mcp-ai' ),
				'number',
				array(
					'min'         => 0,
					'max'         => 100,
					'description' => __( 'Score percentage.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'passed',
				__( 'Passed', 'wp-mcp-ai' ),
				'checkbox',
				array(
					'description' => __( 'Whether the student passed.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'graded_by',
				__( 'Graded By', 'wp-mcp-ai' ),
				'number',
				array(
					'description' => __( 'User ID who graded.', 'wp-mcp-ai' ),
				)
			),
			self::build_field(
				++$base_id,
				'cpt_post_id',
				__( 'CPT Post ID', 'wp-mcp-ai' ),
				'number',
				array(
					'description' => __( 'Linked CPT post ID.', 'wp-mcp-ai' ),
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

WP_MCP_AI_JetEngine_Submissions_CCT::bootstrap();

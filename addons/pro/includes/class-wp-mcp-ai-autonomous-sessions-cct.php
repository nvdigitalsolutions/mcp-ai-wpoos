<?php
/**
 * JetEngine Custom Content Type registration for autonomous sessions.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the autonomous sessions CCT for Ralph orchestration pattern.
 */
class WP_MCP_AI_Autonomous_Sessions_CCT {
	const SLUG = 'mcp_autonomous_sessions';

	/**
	 * Base ID for meta field identifiers.
	 * Using 30000 range to avoid conflicts with other CCT fields.
	 */
	const FIELD_ID_BASE = 30000;

	/**
	 * Hook into JetEngine to provision the autonomous sessions content type.
	 */
	public static function bootstrap() {
		// Run after JetEngine initialises the Custom Content Types module.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 5 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 5 );
	}

	/**
	 * Retrieve the autonomous sessions CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the autonomous sessions content type.
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
	 * Get active sessions.
	 *
	 * @param array $args Query arguments.
	 * @return array List of active sessions.
	 */
	public static function get_active_sessions( $args = array() ) {
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
	 * Register the autonomous sessions CCT if it is missing.
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
	 * Determine whether the autonomous sessions CCT already exists.
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
		$label = __( 'Autonomous Sessions', 'mcp-ai-wpoos' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_meta_fields(),
		);
	}

	/**
	 * Assemble the JetEngine arguments for the autonomous sessions CCT.
	 *
	 * @param string $label Human-readable label for the content type.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-update',
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
				'_ID'                  => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'session_id'           => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'plan_id'              => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'status'               => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'health'               => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'iterations'           => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'tokens_used'          => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'circuit_breaker_open' => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'cct_created'          => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'cct_modified'         => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Define the meta fields for the autonomous sessions CCT.
	 *
	 * @return array
	 */
	protected static function get_meta_fields() {
		$fields = array(
			self::build_field(
				30001,
				'session_id',
				__( 'Session ID', 'mcp-ai-wpoos' ),
				'text',
				array(
					'is_required' => true,
					'description' => __( 'Unique session identifier.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30002,
				'plan_id',
				__( 'Task Plan ID', 'mcp-ai-wpoos' ),
				'number',
				array(
					'is_required' => true,
					'description' => __( 'Associated task plan ID.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30003,
				'status',
				__( 'Status', 'mcp-ai-wpoos' ),
				'select',
				array(
					'is_required' => true,
					'options'     => array(
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
							'key'   => 'failed',
							'value' => 'Failed',
						),
						array(
							'key'   => 'expired',
							'value' => 'Expired',
						),
					),
					'description' => __( 'Current session status.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30004,
				'health',
				__( 'Health Status', 'mcp-ai-wpoos' ),
				'select',
				array(
					'is_required' => true,
					'options'     => array(
						array(
							'key'   => 'healthy',
							'value' => 'Healthy',
						),
						array(
							'key'   => 'warning',
							'value' => 'Warning',
						),
						array(
							'key'   => 'critical',
							'value' => 'Critical',
						),
					),
					'description' => __( 'Session health status based on errors and patterns.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30005,
				'iterations',
				__( 'Iterations', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Number of iterations executed.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				30006,
				'max_iterations',
				__( 'Max Iterations', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Maximum iterations allowed.', 'mcp-ai-wpoos' ),
					'min'         => 1,
				)
			),
			self::build_field(
				30007,
				'tokens_used',
				__( 'Tokens Used', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Total tokens consumed by this session.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				30008,
				'token_budget',
				__( 'Token Budget', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Maximum tokens allowed for this session.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				30009,
				'circuit_breaker_open',
				__( 'Circuit Breaker Open', 'mcp-ai-wpoos' ),
				'switcher',
				array(
					'description' => __( 'Whether the circuit breaker has opened (automatic pause on errors).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30010,
				'start_time',
				__( 'Start Time', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'description' => __( 'Session start timestamp.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30011,
				'last_activity',
				__( 'Last Activity', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'description' => __( 'Last activity timestamp.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30012,
				'expires_at',
				__( 'Expires At', 'mcp-ai-wpoos' ),
				'datetime-local',
				array(
					'description' => __( 'Session expiration timestamp (default: 24 hours).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30013,
				'completion_score',
				__( 'Completion Score', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Number of completion indicators detected.', 'mcp-ai-wpoos' ),
					'min'         => 0,
				)
			),
			self::build_field(
				30014,
				'exit_signal',
				__( 'Exit Signal', 'mcp-ai-wpoos' ),
				'switcher',
				array(
					'description' => __( 'Explicit exit signal (required along with completion score for dual-gate exit).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30015,
				'assistant_id',
				__( 'Assistant ID', 'mcp-ai-wpoos' ),
				'number',
				array(
					'description' => __( 'Associated assistant post ID.', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30016,
				'stop_reason',
				__( 'Stop Reason', 'mcp-ai-wpoos' ),
				'text',
				array(
					'description' => __( 'Reason for session termination (manual, completed, expired, etc.).', 'mcp-ai-wpoos' ),
				)
			),
			self::build_field(
				30017,
				'metadata',
				__( 'Metadata', 'mcp-ai-wpoos' ),
				'textarea',
				array(
					'description' => __( 'Additional session metadata (JSON).', 'mcp-ai-wpoos' ),
				)
			),
		);

		return $fields;
	}

	/**
	 * Build a field definition for JetEngine.
	 *
	 * @param int    $id Field ID.
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param string $type Field type.
	 * @param array  $args Additional arguments.
	 * @return array
	 */
	protected static function build_field( $id, $name, $label, $type, $args = array() ) {
		return array_merge(
			array(
				'id'          => (string) $id,
				'name'        => $name,
				'title'       => $label,
				'type'        => $type,
				'object_type' => 'field',
			),
			$args
		);
	}
}

// Bootstrap the CCT.
WP_MCP_AI_Autonomous_Sessions_CCT::bootstrap();

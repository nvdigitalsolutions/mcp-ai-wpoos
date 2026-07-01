<?php
/**
 * JetEngine Custom Content Type registration for autonomous sessions.
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
		// JetEngine's CCT module hydrates its table cache on `init` at priorities
		// 1-10; registering inside that window races with it and stomps
		// JetEngine's CCT state. Priority 11 is the documented safe window.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 11 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 11 );
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
	 * Check whether the autonomous sessions CCT is available for read/write.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return null !== self::get_item_handler();
	}

	/**
	 * Retrieve a single session by its session_id field.
	 *
	 * @param string $session_id Unique session identifier.
	 * @return array|null Session data array or null if not found.
	 */
	public static function get_session_by_id( $session_id ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return null;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return null;
		}

		$items = $factory->db->query(
			array(
				'session_id' => sanitize_text_field( $session_id ),
				'limit'      => 1,
			)
		);

		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}

		return reset( $items );
	}

	/**
	 * Count sessions by status.
	 *
	 * @param string $status Session status to count (default: 'active').
	 * @return int Number of matching sessions.
	 */
	public static function count_by_status( $status = 'active' ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return 0;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return 0;
		}

		$items = $factory->db->query(
			array(
				'status' => sanitize_key( $status ),
			)
		);

		return is_array( $items ) ? count( $items ) : 0;
	}

	/**
	 * Create a new session record in the CCT.
	 *
	 * @param array $data Session field data (must include session_id).
	 * @return int|false CCT item _ID on success, false on failure.
	 */
	public static function create_session( array $data ) {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return false;
		}

		$item_data = self::map_transient_to_cct( $data );

		$item_id = $handler->add_item( $item_data );

		return is_numeric( $item_id ) && $item_id > 0 ? (int) $item_id : false;
	}

	/**
	 * Update an existing session record. Finds by session_id field,
	 * then updates the CCT record.
	 *
	 * @param string $session_id Unique session identifier.
	 * @param array  $data       Field data to merge and update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_session( $session_id, array $data ) {
		// Find the existing CCT record.
		$existing = self::get_session_by_id( $session_id );

		if ( ! $existing || empty( $existing['_ID'] ) ) {
			return false;
		}

		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return false;
		}

		// Merge existing data with updates, then map to CCT field names.
		$merged      = array_merge( $existing, $data );
		$item_data   = self::map_transient_to_cct( $merged );
		$cct_item_id = (int) $existing['_ID'];

		$result = $handler->update_item( $cct_item_id, $item_data );

		return false !== $result;
	}

	/**
	 * Upsert a session: create if not found, update if exists.
	 *
	 * @param array $data Session field data (must include session_id).
	 * @return int|false CCT item _ID on success, false on failure.
	 */
	public static function upsert_session( array $data ) {
		if ( empty( $data['session_id'] ) ) {
			return false;
		}

		$existing = self::get_session_by_id( $data['session_id'] );

		if ( $existing ) {
			$updated = self::update_session( $data['session_id'], $data );
			return $updated ? (int) $existing['_ID'] : false;
		}

		return self::create_session( $data );
	}

	/**
	 * Delete sessions whose expires_at has passed.
	 *
	 * @return int Number of sessions deleted.
	 */
	public static function cleanup_expired() {
		$handler = self::get_item_handler();

		if ( ! $handler ) {
			return 0;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || empty( $factory->db ) ) {
			return 0;
		}

		// Query sessions where expires_at < now.
		$expired = $factory->db->query(
			array(
				'expires_at__lt' => current_time( 'mysql' ),
				'status'         => 'active',
			)
		);

		if ( ! is_array( $expired ) || empty( $expired ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $expired as $item ) {
			if ( ! empty( $item['_ID'] ) ) {
				$result = $handler->delete_item( (int) $item['_ID'] );
				if ( $result ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Map transient-style session data keys to CCT-compatible field names.
	 *
	 * The transient stores keys like 'health_status', 'iteration_count',
	 * 'started_at', 'token_usage', 'circuit_breaker', etc. The CCT uses
	 * different field names. This method normalises the keys and converts
	 * types where needed (e.g., 'circuit_breaker' string → boolean switcher).
	 *
	 * @param array $data Raw session data with transient-style keys.
	 * @return array Data mapped to CCT field names.
	 */
	private static function map_transient_to_cct( array $data ) {
		$mapped = array();

		// Direct 1:1 mappings.
		$direct = array(
			'session_id'       => 'session_id',
			'plan_id'          => 'plan_id',
			'status'           => 'status',
			'assistant_id'     => 'assistant_id',
			'max_iterations'   => 'max_iterations',
			'token_budget'     => 'token_budget',
			'completion_score' => 'completion_score',
			'last_activity'    => 'last_activity',
			'expires_at'       => 'expires_at',
			'stop_reason'      => 'stop_reason',
		);

		foreach ( $direct as $from => $to ) {
			if ( array_key_exists( $from, $data ) ) {
				$mapped[ $to ] = $data[ $from ];
			}
		}

		// Renamed keys.
		if ( array_key_exists( 'health_status', $data ) ) {
			$mapped['health'] = sanitize_key( $data['health_status'] );
		}
		if ( array_key_exists( 'iteration_count', $data ) ) {
			$mapped['iterations'] = absint( $data['iteration_count'] );
		}
		if ( array_key_exists( 'token_usage', $data ) ) {
			$mapped['tokens_used'] = absint( $data['token_usage'] );
		}
		if ( array_key_exists( 'started_at', $data ) ) {
			$mapped['start_time'] = sanitize_text_field( $data['started_at'] );
		}

		// Type conversion: transient stores 'open'/'closed' strings,
		// CCT uses a boolean switcher field.
		if ( array_key_exists( 'circuit_breaker', $data ) ) {
			$mapped['circuit_breaker_open'] = 'open' === $data['circuit_breaker'];
		}

		// Boolean exit_signal stays boolean.
		if ( array_key_exists( 'exit_signal', $data ) ) {
			$mapped['exit_signal'] = (bool) $data['exit_signal'];
		}

		// Store extra fields (user_id, error_count, success_rate, last_tool,
		// last_error, completed_at, etc.) as JSON in the metadata field.
		$extra_fields = array(
			'user_id',
			'error_count',
			'success_rate',
			'last_tool',
			'last_error',
			'completed_at',
		);

		$meta = array();
		foreach ( $extra_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$meta[ $field ] = $data[ $field ];
			}
		}

		// Preserve existing metadata if present in the input.
		if ( ! empty( $data['metadata'] ) && is_array( $data['metadata'] ) ) {
			$meta = array_merge( $meta, $data['metadata'] );
		}

		if ( ! empty( $meta ) ) {
			$mapped['metadata'] = wp_json_encode( $meta, JSON_UNESCAPED_SLASHES );
		}

		return $mapped;
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

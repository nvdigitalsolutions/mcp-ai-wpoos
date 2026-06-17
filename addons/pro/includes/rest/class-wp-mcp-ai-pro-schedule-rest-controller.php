<?php
/**
 * Pro Schedule REST Controller
 *
 * Exposes CRUD endpoints for the Schedule Manager so the React SPA
 * can list, create, update, delete, and trigger schedules.
 *
 * Namespace: /wp-json/mcp-ai-pro/v1/
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for schedule CRUD operations.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Schedule_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai-pro/v1';

	/**
	 * Required capability for schedule management.
	 *
	 * Filterable so Schedule Anything can swap in sa_manage_workspace.
	 *
	 * @since 1.0.0
	 *
	 * @return string Capability slug.
	 */
	protected function admin_capability() {
		/**
		 * Filter the capability required to manage schedules via REST.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Default: 'manage_options'.
		 */
		return apply_filters(
			'wp_mcp_ai_pro_schedule_rest_capability',
			defined( 'SA_PLATFORM_VERSION' ) ? 'sa_manage_workspace' : 'manage_options'
		);
	}

	/**
	 * Initialize the controller — hooks into rest_api_init.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		$instance = new self();
		add_action( 'rest_api_init', array( $instance, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return;
		}

		// List all schedules + create.
		register_rest_route(
			$this->namespace,
			'/schedules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_schedules' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_schedule' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => $this->get_create_args(),
				),
			)
		);

		// Single schedule CRUD.
		register_rest_route(
			$this->namespace,
			'/schedules/(?P<id>[A-Za-z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_schedule' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_schedule' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_schedule' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			)
		);

		// Trigger a schedule manually.
		register_rest_route(
			$this->namespace,
			'/schedules/(?P<id>[A-Za-z0-9]+)/trigger',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'trigger_schedule' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		// Toggle enabled/disabled.
		register_rest_route(
			$this->namespace,
			'/schedules/(?P<id>[A-Za-z0-9]+)/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_schedule' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		// Run history for a schedule.
		register_rest_route(
			$this->namespace,
			'/schedules/(?P<id>[A-Za-z0-9]+)/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * Permission callback: user must have the required capability.
	 *
	 * @since 1.0.0
	 *
	 * @return true|WP_Error
	 */
	public function permission_check() {
		if ( current_user_can( $this->admin_capability() ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to manage schedules.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * List all schedules.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_schedules( $request ) {
		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();

		// Optionally filter by schedule_type.
		$type = $request->get_param( 'schedule_type' );
		if ( $type ) {
			$schedules = array_filter(
				$schedules,
				function ( $s ) use ( $type ) {
					return isset( $s['schedule_type'] ) && $s['schedule_type'] === $type;
				}
			);
		}

		// Optionally filter by tag.
		$tag = $request->get_param( 'tag' );
		if ( $tag ) {
			$schedules = array_filter(
				$schedules,
				function ( $s ) use ( $tag ) {
					return isset( $s['tags'] ) && in_array( $tag, $s['tags'], true );
				}
			);
		}

		// Redact sensitive fields for public display.
		$schedules = array_map( array( 'WP_MCP_AI_Pro_Schedule_Manager', 'redact_envelope_for_public' ), $schedules );

		return rest_ensure_response(
			array(
				'ok'        => true,
				'schedules' => array_values( $schedules ),
				'total'     => count( $schedules ),
			)
		);
	}

	/**
	 * Get a single schedule by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_schedule( $request ) {
		$id       = sanitize_key( $request->get_param( 'id' ) );
		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );

		if ( ! $schedule ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Schedule not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'schedule' => WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $schedule ),
			)
		);
	}

	/**
	 * Create a new schedule.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_schedule( $request ) {
		$data = array(
			'name'                => $request->get_param( 'name' ),
			'description'         => $request->get_param( 'description' ),
			'schedule_type'       => $request->get_param( 'schedule_type' ),
			'hook'                => $request->get_param( 'hook' ),
			'args'                => $request->get_param( 'args' ),
			'workflow_steps'      => $request->get_param( 'workflow_steps' ),
			'assistant_config'    => $request->get_param( 'assistant_config' ),
			'broadcast_config'    => $request->get_param( 'broadcast_config' ),
			'workflow_builder_id' => $request->get_param( 'workflow_builder_id' ),
			'schedule'            => $request->get_param( 'schedule' ),
			'timestamp'           => $request->get_param( 'timestamp' ),
			'enabled'             => $request->get_param( 'enabled' ),
			'priority'            => $request->get_param( 'priority' ),
			'tags'                => $request->get_param( 'tags' ),
			'timeout'             => $request->get_param( 'timeout' ),
			'max_retries'         => $request->get_param( 'max_retries' ),
			'notify_on_failure'   => $request->get_param( 'notify_on_failure' ),
			'notify_email'        => $request->get_param( 'notify_email' ),
		);

		// Remove null values so defaults are used.
		$data = array_filter(
			$data,
			function ( $v ) {
				return null !== $v;
			}
		);

		$user_id     = get_current_user_id();
		$schedule_id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $data, $user_id );

		if ( is_wp_error( $schedule_id ) ) {
			return $schedule_id;
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );

		return rest_ensure_response(
			array(
				'ok'       => true,
				'schedule' => WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $schedule ),
			)
		);
	}

	/**
	 * Update an existing schedule.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_schedule( $request ) {
		$id       = sanitize_key( $request->get_param( 'id' ) );
		$existing = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );

		if ( ! $existing ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Schedule not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Merge existing with new data.
		$data = array_merge( $existing, $request->get_params() );
		unset( $data['id'] ); // Don't pass ID as a field.

		$result = WP_MCP_AI_Pro_Schedule_Manager::update_schedule( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );

		return rest_ensure_response(
			array(
				'ok'       => true,
				'schedule' => WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $schedule ),
			)
		);
	}

	/**
	 * Delete a schedule.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_schedule( $request ) {
		$id = sanitize_key( $request->get_param( 'id' ) );

		$result = WP_MCP_AI_Pro_Schedule_Manager::delete_schedule( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'deleted' => $id,
			)
		);
	}

	/**
	 * Trigger a schedule manually.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function trigger_schedule( $request ) {
		$id = sanitize_key( $request->get_param( 'id' ) );

		$result = WP_MCP_AI_Pro_Schedule_Manager::trigger_now( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'          => true,
				'schedule_id' => $id,
				'result'      => $result,
			)
		);
	}

	/**
	 * Toggle a schedule's enabled state.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_schedule( $request ) {
		$id = sanitize_key( $request->get_param( 'id' ) );

		$toggled = WP_MCP_AI_Pro_Schedule_Manager::toggle_schedule( $id );

		if ( is_wp_error( $toggled ) ) {
			return $toggled;
		}

		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $id );

		return rest_ensure_response(
			array(
				'ok'       => true,
				'enabled'  => (bool) $schedule['enabled'],
				'schedule' => WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $schedule ),
			)
		);
	}

	/**
	 * Get run history for a schedule.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_history( $request ) {
		$id      = sanitize_key( $request->get_param( 'id' ) );
		$history = WP_MCP_AI_Pro_Schedule_Manager::get_run_history( $id );

		if ( false === $history ) {
			return new WP_Error(
				'schedule_not_found',
				__( 'Schedule not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'ok'          => true,
				'schedule_id' => $id,
				'history'     => $history,
				'total'       => count( $history ),
			)
		);
	}

	/**
	 * Get the argument schema for creating a schedule.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array>
	 */
	private function get_create_args() {
		return array(
			'name'                => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'description'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'schedule_type'       => array(
				'required'          => true,
				'type'              => 'string',
				'enum'              => array( 'task', 'workflow', 'assistant_run', 'channel_broadcast', 'workflow_builder' ),
				'sanitize_callback' => 'sanitize_key',
			),
			'hook'                => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'schedule'            => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'timestamp'           => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'enabled'             => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'priority'            => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'minimum'           => 1,
				'maximum'           => 10,
			),
			'tags'                => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'timeout'             => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'minimum'           => 0,
			),
			'max_retries'         => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'minimum'           => 0,
				'maximum'           => 5,
			),
			'notify_on_failure'   => array(
				'type' => 'boolean',
			),
			'notify_email'        => array(
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
			),
			'workflow_steps'      => array(
				'type' => 'array',
			),
			'assistant_config'    => array(
				'type' => 'object',
			),
			'broadcast_config'    => array(
				'type' => 'object',
			),
			'workflow_builder_id' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}
}

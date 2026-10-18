<?php
/**
 * Schedule Anything REST Controller (presets + toolkits)
 *
 * Exposes the Schedule Anything surface the React SPA consumes:
 *   GET  /mcp-ai-pro/v1/toolkits
 *   POST /mcp-ai-pro/v1/toolkits/{slug}/toggle
 *   GET  /mcp-ai-pro/v1/presets
 *   GET  /mcp-ai-pro/v1/presets/{id}
 *   POST /mcp-ai-pro/v1/presets/{id}/install
 *
 * Tenancy is the current blog (Proposal 081, D3). The capability seam is
 * shared with the schedule CRUD controller so one filter governs the whole
 * surface.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for Schedule Anything presets and toolkits.
 *
 * @since 1.2.8
 */
class WP_MCP_AI_SA_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai-pro/v1';

	/**
	 * Required capability — same seam as schedule CRUD.
	 *
	 * @since 1.2.8
	 *
	 * @return string Capability slug.
	 */
	protected function admin_capability() {
		/**
		 * Filter the capability required to manage the Schedule Anything
		 * surface via REST. Shared with the schedule CRUD controller.
		 *
		 * @since 1.2.8
		 *
		 * @param string $capability Default: 'sa_manage_workspace' when the
		 *                           platform plugin is active, else 'manage_options'.
		 */
		return apply_filters(
			'wp_mcp_ai_pro_schedule_rest_capability',
			defined( 'SA_PLATFORM_VERSION' ) ? 'sa_manage_workspace' : 'manage_options'
		);
	}

	/**
	 * Initialize the controller — hooks into rest_api_init.
	 *
	 * @since 1.2.8
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
	 * @since 1.2.8
	 *
	 * @return void
	 */
	public function register_routes() {
		// Toolkit list.
		register_rest_route(
			$this->namespace,
			'/toolkits',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_toolkits' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		// Toolkit toggle.
		register_rest_route(
			$this->namespace,
			'/toolkits/(?P<slug>[a-z0-9-]+)/toggle',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle_toolkit' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'slug'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'enabled' => array(
						'required'          => true,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);

		// Preset list.
		register_rest_route(
			$this->namespace,
			'/presets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_presets' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'toolkit'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Single preset.
		register_rest_route(
			$this->namespace,
			'/presets/(?P<id>[a-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_preset' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Preset install.
		register_rest_route(
			$this->namespace,
			'/presets/(?P<id>[a-z0-9-]+)/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_preset' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'overrides' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Permission callback: user must have the required capability.
	 *
	 * @since 1.2.8
	 *
	 * @return true|WP_Error
	 */
	public function permission_check() {
		if ( current_user_can( $this->admin_capability() ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to manage the Schedule Anything workspace.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * GET /toolkits
	 *
	 * @since 1.2.8
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_toolkits( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return rest_ensure_response(
			array(
				'ok'       => true,
				'toolkits' => WP_MCP_AI_SA_Toolkit_Adapter::get_toolkits(),
			)
		);
	}

	/**
	 * POST /toolkits/{slug}/toggle
	 *
	 * @since 1.2.8
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function toggle_toolkit( $request ) {
		$slug    = $request->get_param( 'slug' );
		$enabled = $request->get_param( 'enabled' );

		$result = WP_MCP_AI_SA_Toolkit_Adapter::toggle_toolkit( $slug, $enabled );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'slug'    => $slug,
				'enabled' => (bool) $enabled,
			)
		);
	}

	/**
	 * GET /presets
	 *
	 * @since 1.2.8
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_presets( $request ) {
		$result = WP_MCP_AI_Pro_Schedule_Presets::get_presets(
			array(
				'search'   => $request->get_param( 'search' ),
				'category' => $request->get_param( 'category' ),
				'toolkit'  => $request->get_param( 'toolkit' ),
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
			)
		);

		return rest_ensure_response(
			array(
				'ok'      => true,
				'presets' => $result['presets'],
				'total'   => $result['total'],
			)
		);
	}

	/**
	 * GET /presets/{id}
	 *
	 * @since 1.2.8
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_preset( $request ) {
		$preset = WP_MCP_AI_Pro_Schedule_Presets::get_preset( $request->get_param( 'id' ) );

		if ( null === $preset ) {
			return new WP_Error(
				'sa_preset_not_found',
				__( 'Preset not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'preset' => $preset,
			)
		);
	}

	/**
	 * POST /presets/{id}/install
	 *
	 * Idempotent (Proposal 081, D6): replaying the same preset + overrides
	 * returns the previously created schedule with `replayed: true` instead
	 * of creating a duplicate.
	 *
	 * @since 1.2.8
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_preset( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return new WP_Error(
				'sa_schedule_manager_missing',
				__( 'The Schedule Manager is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$id = $request->get_param( 'id' );

		$preset = WP_MCP_AI_Pro_Schedule_Presets::get_preset( $id );
		if ( null === $preset ) {
			return new WP_Error(
				'sa_preset_not_found',
				__( 'Preset not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$raw_overrides = $request->get_param( 'overrides' );
		$raw_overrides = is_array( $raw_overrides ) ? $raw_overrides : array();
		$overrides     = WP_MCP_AI_Pro_Schedule_Presets::sanitize_overrides( $raw_overrides );

		$key = WP_MCP_AI_Pro_Schedule_Presets::install_key( $id, $raw_overrides );

		// Idempotent replay.
		$existing_id = WP_MCP_AI_Pro_Schedule_Presets::get_installed_schedule_id( $key );
		if ( $existing_id ) {
			$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
			if ( isset( $schedules[ $existing_id ] ) ) {
				return rest_ensure_response(
					array(
						'ok'        => true,
						'schedule'  => WP_MCP_AI_Pro_Schedule_Manager::redact_schedule_for_public( $schedules[ $existing_id ] ),
						'preset_id' => $id,
						'replayed'  => true,
					)
				);
			}
		}

		$payload = WP_MCP_AI_Pro_Schedule_Presets::build_install_payload( $preset, $overrides );

		$schedule_id = WP_MCP_AI_Pro_Schedule_Manager::create_schedule( $payload, get_current_user_id() );

		if ( is_wp_error( $schedule_id ) ) {
			return $schedule_id;
		}

		WP_MCP_AI_Pro_Schedule_Presets::record_install( $key, $schedule_id );

		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$created   = isset( $schedules[ $schedule_id ] ) ? $schedules[ $schedule_id ] : array( 'id' => $schedule_id );

		$response = rest_ensure_response(
			array(
				'ok'        => true,
				'schedule'  => WP_MCP_AI_Pro_Schedule_Manager::redact_schedule_for_public( $created ),
				'preset_id' => $id,
				'replayed'  => false,
			)
		);
		$response->set_status( 201 );

		return $response;
	}
}

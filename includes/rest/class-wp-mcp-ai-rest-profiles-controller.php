<?php
/**
 * REST Profiles Controller — Endpoints for profile CRUD.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_REST_Profiles_Controller
 *
 * @since 1.7.0
 * @package NV_oOS
 */
class WP_MCP_AI_REST_Profiles_Controller {

	/**
	 * The profile manager instance.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Profile_Manager
	 */
	private $profile_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		$this->profile_manager = new WP_MCP_AI_Profile_Manager();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_routes() {
		$namespace = 'mcp-ai/v1';

		register_rest_route(
			$namespace,
			'/profiles',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_profiles' ),
					'permission_callback' => array( $this, 'check_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_profile' ),
					'permission_callback' => array( $this, 'check_manage' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/profiles/(?P<name>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile' ),
					'permission_callback' => array( $this, 'check_read' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => array( $this, 'check_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_profile' ),
					'permission_callback' => array( $this, 'check_manage' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/profiles/(?P<name>[a-z0-9_-]+)/tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile_tools' ),
					'permission_callback' => array( $this, 'check_read' ),
				),
			)
		);
	}

	/**
	 * Check read permission for profile endpoints.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public function check_read() {
		return current_user_can( 'read' );
	}

	/**
	 * Check manage permission for profile endpoints.
	 *
	 * @since 1.7.0
	 * @return bool|WP_Error
	 */
	public function check_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /profiles
	 *
	 * @since 1.7.0
	 * @return array
	 */
	public function list_profiles() {
		$profiles = $this->profile_manager->list_profiles( get_current_user_id() );
		return array(
			'success' => true,
			'message' => '',
			'data'    => array( 'profiles' => $profiles ),
		);
	}

	/**
	 * GET /profiles/{name}
	 *
	 * @since 1.7.0
	 * @param WP_REST_Request $request The request object.
	 * @return array|WP_Error
	 */
	public function get_profile( $request ) {
		$name    = sanitize_key( $request->get_param( 'name' ) );
		$profile = $this->profile_manager->get_profile( $name, get_current_user_id() );

		if ( null === $profile ) {
			return new WP_Error( 'not_found', __( 'Profile not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		return array(
			'success' => true,
			'message' => '',
			'data'    => $profile,
		);
	}

	/**
	 * POST /profiles
	 *
	 * @since 1.7.0
	 * @param WP_REST_Request $request The request object.
	 * @return array|WP_Error
	 */
	public function create_profile( $request ) {
		$name   = sanitize_key( $request->get_param( 'name' ) );
		$label  = sanitize_text_field( $request->get_param( 'label' ) );
		$config = $request->get_param( 'config' );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		return $this->profile_manager->create_profile( $name, $label, $config, get_current_user_id() );
	}

	/**
	 * PUT /profiles/{name}
	 *
	 * @since 1.7.0
	 * @param WP_REST_Request $request The request object.
	 * @return array|WP_Error
	 */
	public function update_profile( $request ) {
		// Update = delete existing custom + recreate.
		$name   = sanitize_key( $request->get_param( 'name' ) );
		$label  = sanitize_text_field( $request->get_param( 'label' ) );
		$config = $request->get_param( 'config' );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		$this->profile_manager->delete_profile( $name );
		return $this->profile_manager->create_profile( $name, $label, $config, get_current_user_id() );
	}

	/**
	 * DELETE /profiles/{name}
	 *
	 * @since 1.7.0
	 * @param WP_REST_Request $request The request object.
	 * @return array|WP_Error
	 */
	public function delete_profile( $request ) {
		$name = sanitize_key( $request->get_param( 'name' ) );
		return $this->profile_manager->delete_profile( $name, get_current_user_id() );
	}

	/**
	 * GET /profiles/{name}/tools
	 *
	 * @since 1.7.0
	 * @param WP_REST_Request $request The request object.
	 * @return array
	 */
	public function get_profile_tools( $request ) {
		$name = sanitize_key( $request->get_param( 'name' ) );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array(
				'success' => true,
				'message' => '',
				'data'    => array( 'tools' => array() ),
			);
		}

		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools = $registry->get_tools();
		$filtered  = $this->profile_manager->filter_tools_for_profile( $name, $all_tools, get_current_user_id() );

		return array(
			'success' => true,
			'message' => '',
			'data'    => array( 'tools' => $filtered ),
		);
	}
}

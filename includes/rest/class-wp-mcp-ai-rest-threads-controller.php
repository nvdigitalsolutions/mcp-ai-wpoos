<?php
/**
 * REST Threads Controller — Endpoints for thread CRUD and messaging.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_REST_Threads_Controller
 *
 * @since 1.7.0
 */
class WP_MCP_AI_REST_Threads_Controller {

	/** @var WP_MCP_AI_REST */
	private $rest;

	/** @var WP_MCP_AI_Thread_Manager */
	private $thread_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 * @param WP_MCP_AI_REST $rest The main REST controller instance.
	 */
	public function __construct( $rest ) {
		$this->rest           = $rest;
		$this->thread_manager = new WP_MCP_AI_Thread_Manager();
	}

	/**
	 * Register routes.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_routes() {
		$namespace = 'mcp-ai/v1';

		// List / Create threads.
		register_rest_route( $namespace, '/threads', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_threads' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => $this->get_list_args(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_thread' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => $this->get_create_args(),
			),
		) );

		// Single thread operations.
		register_rest_route( $namespace, '/threads/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_thread' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_thread' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'archive_thread' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
			),
		) );

		// Thread lifecycle actions.
		register_rest_route( $namespace, '/threads/(?P<id>\d+)/restore', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_thread' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
			),
		) );

		register_rest_route( $namespace, '/threads/(?P<id>\d+)/summarize', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'summarize_thread' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
			),
		) );

		// Messages.
		register_rest_route( $namespace, '/threads/(?P<id>\d+)/messages', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_messages' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ),
				),
			),
		) );
	}

	// ── Permission Callbacks ──

	/** @return bool|WP_Error */
	public function check_read_permission() {
		return current_user_can( 'read' );
	}

	/** @return bool|WP_Error */
	public function check_edit_permission() {
		return current_user_can( 'edit_posts' );
	}

	// ── Endpoint Handlers ──

	/**
	 * GET /threads
	 */
	public function list_threads( $request ) {
		$user_id  = get_current_user_id();
		$status   = $request->get_param( 'status' );
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		return $this->thread_manager->list_threads( $user_id, $status, $page, $per_page );
	}

	/**
	 * POST /threads
	 */
	public function create_thread( $request ) {
		$user_id      = get_current_user_id();
		$assistant_id = absint( $request->get_param( 'assistant_id' ) );
		$model        = $request->get_param( 'model' );
		$profile      = sanitize_key( $request->get_param( 'profile' ) );
		$scope        = $request->get_param( 'scope' );

		if ( ! is_array( $model ) ) {
			$model = array();
		}
		if ( ! is_array( $scope ) ) {
			$scope = array();
		}

		return $this->thread_manager->create_thread( $user_id, $assistant_id, $model, $profile, $scope );
	}

	/**
	 * GET /threads/{id}
	 */
	public function get_thread( $request ) {
		$thread_id = absint( $request->get_param( 'id' ) );
		$user_id   = get_current_user_id();

		return $this->thread_manager->get_thread( $thread_id, $user_id );
	}

	/**
	 * PUT /threads/{id}
	 */
	public function update_thread( $request ) {
		$thread_id = absint( $request->get_param( 'id' ) );
		$user_id   = get_current_user_id();
		$fields    = $request->get_params();

		// Remove route params from fields.
		unset( $fields['id'] );

		return $this->thread_manager->update_thread( $thread_id, $user_id, $fields );
	}

	/**
	 * DELETE /threads/{id}
	 */
	public function archive_thread( $request ) {
		$thread_id = absint( $request->get_param( 'id' ) );
		$user_id   = get_current_user_id();

		return $this->thread_manager->archive_thread( $thread_id, $user_id );
	}

	/**
	 * POST /threads/{id}/restore
	 */
	public function restore_thread( $request ) {
		$thread_id = absint( $request->get_param( 'id' ) );
		$user_id   = get_current_user_id();

		return $this->thread_manager->restore_thread( $thread_id, $user_id );
	}

	/**
	 * POST /threads/{id}/summarize
	 */
	public function summarize_thread( $request ) {
		$thread_id = absint( $request->get_param( 'id' ) );
		$user_id   = get_current_user_id();

		return $this->thread_manager->summarize_thread( $thread_id, $user_id );
	}

	/**
	 * GET /threads/{id}/messages
	 */
	public function get_messages( $request ) {
		$thread_id = absint( $request->get_param( 'id' ) );
		$page      = absint( $request->get_param( 'page' ) );
		$per_page  = absint( $request->get_param( 'per_page' ) );

		return $this->thread_manager->get_messages( $thread_id, $page, $per_page );
	}

	// ── Args Schemas ──

	private function get_list_args() {
		return array(
			'status'   => array( 'type' => 'string', 'default' => 'active', 'enum' => array( 'active', 'archived', '' ) ),
			'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
			'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
		);
	}

	private function get_create_args() {
		return array(
			'assistant_id' => array( 'type' => 'integer', 'default' => 0 ),
			'model'        => array( 'type' => 'object', 'default' => array() ),
			'profile'      => array( 'type' => 'string', 'default' => 'write' ),
			'scope'        => array( 'type' => 'object', 'default' => array() ),
		);
	}
}

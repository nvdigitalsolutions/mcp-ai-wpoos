<?php
/**
 * Thread REST Controller — Registers CRUD endpoints for conversation threads.
 *
 * Endpoints:
 *   GET    /mcp-ai/v1/threads                           — List threads
 *   POST   /mcp-ai/v1/threads                           — Create thread
 *   DELETE /mcp-ai/v1/threads/(?P<id>\d+)               — Archive thread
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/restore       — Restore thread
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/summarize     — Summarize thread
 *   GET    /mcp-ai/v1/threads/(?P<id>\d+)/messages      — List messages
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/messages      — Send message (SSE)
 *   GET    /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints   — List checkpoints
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints   — Create checkpoint
 *   POST   /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/restore — Restore checkpoint
 *   GET    /mcp-ai/v1/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/diff     — Get checkpoint diff
 *
 * @package WP_MCP_AI
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent fatal error if WP_REST_Controller is not available yet.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

/**
 * Class WP_MCP_AI_REST_Threads_Controller
 *
 * @since 1.7.0
 */
class WP_MCP_AI_REST_Threads_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Thread Manager instance.
	 *
	 * @since 1.7.0
	 * @var WP_MCP_AI_Thread_Manager|null
	 */
	private $thread_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		if ( class_exists( 'WP_MCP_AI_Thread_Manager' ) ) {
			$this->thread_manager = new WP_MCP_AI_Thread_Manager();
		}
	}

	/**
	 * Register REST API routes for threads.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function register_routes() {
		// Ensure thread manager tables exist.
		if ( $this->thread_manager ) {
			$this->maybe_create_tables();
		}

		// GET /threads — List threads.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_threads' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'default'           => 'active',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => array( 'active', 'archived', 'all' ),
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads — Create thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_thread' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'assistant_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'model'        => array(
						'type'    => 'object',
						'default' => array(),
					),
					'profile'      => array(
						'type'              => 'string',
						'default'           => 'write',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'scope'        => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		// DELETE /threads/(?P<id>\d+) — Archive thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'archive_thread' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/restore — Restore thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_thread' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/summarize — Summarize (compact) thread.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/summarize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'summarize_thread' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /threads/(?P<id>\d+)/messages — List messages.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_messages' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 100,
						'minimum'           => 1,
						'maximum'           => 200,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /threads/(?P<id>\d+)/checkpoints — List checkpoints.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkpoints' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/checkpoints — Create checkpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_checkpoint' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'           => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'label'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'affected_ids' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type' => 'integer',
						),
					),
				),
			)
		);

		// POST /threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/restore — Restore checkpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_checkpoint' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'cid' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/diff — Get checkpoint diff.
		register_rest_route(
			self::REST_NAMESPACE,
			'/threads/(?P<id>\d+)/checkpoints/(?P<cid>\d+)/diff',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_checkpoint_diff' ),
				'permission_callback' => array( $this, 'check_thread_permission' ),
				'args'                => array(
					'id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'cid' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	// ──────────────────────────────────────────────
	// Permission callbacks
	// ──────────────────────────────────────────────

	/**
	 * Check if user has permission to access threads.
	 *
	 * @since 1.7.0
	 *
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access threads.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access threads.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check if user has permission to access a specific thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_thread_permission( $request ) {
		$base_check = $this->check_permission();
		if ( is_wp_error( $base_check ) ) {
			return $base_check;
		}

		if ( ! $this->thread_manager ) {
			return new WP_Error(
				'wp_mcp_ai_threads_unavailable',
				__( 'Thread system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	// ──────────────────────────────────────────────
	// Endpoint callbacks
	// ──────────────────────────────────────────────

	/**
	 * List threads.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_threads( $request ) {
		if ( ! $this->thread_manager ) {
			return new WP_Error(
				'wp_mcp_ai_threads_unavailable',
				__( 'Thread system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$status   = $request->get_param( 'status' );
		$per_page = $request->get_param( 'per_page' );
		$user_id  = get_current_user_id();

		$result = $this->thread_manager->list_threads( $user_id, $status, 1, $per_page );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create a new thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_thread( $request ) {
		if ( ! $this->thread_manager ) {
			return new WP_Error(
				'wp_mcp_ai_threads_unavailable',
				__( 'Thread system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$user_id      = get_current_user_id();
		$assistant_id = $request->get_param( 'assistant_id' );
		$model        = $request->get_param( 'model' );
		$profile      = $request->get_param( 'profile' );
		$scope        = $request->get_param( 'scope' );

		// Ensure model and scope are arrays.
		if ( ! is_array( $model ) ) {
			$model = array();
		}
		if ( ! is_array( $scope ) ) {
			$scope = array();
		}

		$result = $this->thread_manager->create_thread( $user_id, $assistant_id, $model, $profile, $scope );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Archive a thread (soft delete).
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function archive_thread( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->archive_thread( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Restore an archived thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_thread( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->restore_thread( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Summarize (compact) a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function summarize_thread( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->summarize_thread( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get messages for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_messages( $request ) {
		$thread_id = $request->get_param( 'id' );
		$page      = $request->get_param( 'page' );
		$per_page  = $request->get_param( 'per_page' );

		$result = $this->thread_manager->get_messages( $thread_id, $page, $per_page );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get checkpoints for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checkpoints( $request ) {
		$thread_id = $request->get_param( 'id' );
		$result    = $this->thread_manager->get_checkpoints( $thread_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create a checkpoint for a thread.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_checkpoint( $request ) {
		$thread_id    = $request->get_param( 'id' );
		$label        = $request->get_param( 'label' );
		$affected_ids = $request->get_param( 'affected_ids' );

		if ( ! is_array( $affected_ids ) ) {
			$affected_ids = array();
		}

		$result = $this->thread_manager->create_checkpoint( $thread_id, $label, $affected_ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Restore a checkpoint.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_checkpoint( $request ) {
		$thread_id     = $request->get_param( 'id' );
		$checkpoint_id = $request->get_param( 'cid' );

		$result = $this->thread_manager->restore_checkpoint( $thread_id, $checkpoint_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get diff data for a checkpoint.
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checkpoint_diff( $request ) {
		$thread_id     = $request->get_param( 'id' );
		$checkpoint_id = $request->get_param( 'cid' );

		$result = $this->thread_manager->get_checkpoint_diff( $thread_id, $checkpoint_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Ensure the thread database tables exist.
	 *
	 * Uses a transient to avoid checking on every request.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	private function maybe_create_tables() {
		$transient_key = 'wp_mcp_ai_thread_tables_created';
		if ( get_transient( $transient_key ) ) {
			return;
		}

		WP_MCP_AI_Thread_Manager::create_tables();
		set_transient( $transient_key, true, DAY_IN_SECONDS );
	}
}

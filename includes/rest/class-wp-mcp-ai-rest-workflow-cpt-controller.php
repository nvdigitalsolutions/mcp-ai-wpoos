<?php
/**
 * REST Controller: Workflow CPT CRUD + execute.
 *
 * Exposes CRUD operations and execution for `mcp_ai_workflow` posts via the
 * REST API. All routes require `manage_options`. Write routes verify the
 * standard WordPress nonce via the `X-WP-Nonce` header.
 *
 * Routes (namespace `mcp-ai/v1`):
 *   GET    /orchestration/workflows            — list
 *   POST   /orchestration/workflows            — create
 *   GET    /orchestration/workflows/{id}       — get single
 *   PUT    /orchestration/workflows/{id}       — update (auto-bumps patch)
 *   DELETE /orchestration/workflows/{id}       — trash
 *   POST   /orchestration/workflows/{id}/export   — export JSON
 *   POST   /orchestration/workflows/import        — import JSON
 *   POST   /orchestration/workflows/{id}/execute  — run via Engine V2
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for workflow CPT management.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_REST_Workflow_CPT_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 */
	const BASE = 'orchestration/workflows';

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Collection routes.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'name'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'graph' => array(
							'required' => false,
							'type'     => 'object',
						),
						'tags'  => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		// Import route (before the {id} route to avoid conflicts).
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'workflow_json' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);

		// Single item routes.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'id'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
						'graph' => array(
							'required' => false,
							'type'     => 'object',
						),
						'name'  => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tags'  => array(
							'required' => false,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
					),
				),
			)
		);

		// Export route.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>\d+)/export',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'export_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
					),
				),
			)
		);

		// Execute route.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>\d+)/execute',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'execute_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
					'args'                => array(
						'id'    => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
						'input' => array(
							'required' => false,
							'type'     => 'object',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check for read operations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access workflows.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission check for write operations (includes nonce verification).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function write_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to modify workflows.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /orchestration/workflows — list all workflows.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Workflow_CPT::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();

		foreach ( $posts as $post ) {
			$version  = get_post_meta( $post->ID, WP_MCP_AI_Workflow_CPT::META_VERSION, true );
			$tags_raw = get_post_meta( $post->ID, WP_MCP_AI_Workflow_CPT::META_TAGS, true );
			$tags     = array();

			if ( ! empty( $tags_raw ) ) {
				$decoded = json_decode( wp_unslash( $tags_raw ), true );
				if ( is_array( $decoded ) ) {
					$tags = $decoded;
				}
			}

			$items[] = array(
				'id'       => $post->ID,
				'title'    => esc_html( $post->post_title ),
				'version'  => esc_html( $version ? $version : '1.0.0' ),
				'tags'     => array_map( 'esc_html', $tags ),
				'modified' => esc_html( $post->post_modified ),
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * GET /orchestration/workflows/{id} — get single workflow.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || WP_MCP_AI_Workflow_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Workflow not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		$version  = get_post_meta( $id, WP_MCP_AI_Workflow_CPT::META_VERSION, true );
		$tags_raw = get_post_meta( $id, WP_MCP_AI_Workflow_CPT::META_TAGS, true );
		$tags     = array();

		if ( ! empty( $tags_raw ) ) {
			$decoded = json_decode( wp_unslash( $tags_raw ), true );
			if ( is_array( $decoded ) ) {
				$tags = $decoded;
			}
		}

		return rest_ensure_response(
			array(
				'id'          => $post->ID,
				'title'       => esc_html( $post->post_title ),
				'description' => esc_html( $post->post_content ),
				'version'     => esc_html( $version ? $version : '1.0.0' ),
				'tags'        => array_map( 'esc_html', $tags ),
				'graph'       => WP_MCP_AI_Workflow_CPT::get_graph( $id ),
				'modified'    => esc_html( $post->post_modified ),
			)
		);
	}

	/**
	 * POST /orchestration/workflows — create workflow.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$name  = sanitize_text_field( $request->get_param( 'name' ) );
		$graph = $request->get_param( 'graph' );
		$tags  = $request->get_param( 'tags' );

		$data = array(
			'name'    => $name,
			'version' => '1.0.0',
			'graph'   => is_array( $graph ) ? $graph : array(
				'nodes' => array(),
				'edges' => array(),
			),
			'tags'    => is_array( $tags ) ? $tags : array(),
		);

		$post_id = WP_MCP_AI_Workflow_CPT::import_json( $data );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return rest_ensure_response(
			array(
				'id'      => $post_id,
				'message' => __( 'Workflow created.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * PUT /orchestration/workflows/{id} — update workflow graph.
	 *
	 * Auto-bumps the patch version on each save.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || WP_MCP_AI_Workflow_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Workflow not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		$name  = $request->get_param( 'name' );
		$graph = $request->get_param( 'graph' );
		$tags  = $request->get_param( 'tags' );

		if ( ! empty( $name ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( $name ),
				)
			);
		}

		if ( is_array( $graph ) ) {
			WP_MCP_AI_Workflow_CPT::save_graph( $id, $graph );
		}

		if ( is_array( $tags ) ) {
			$clean_tags = array();
			foreach ( $tags as $tag ) {
				$clean_tags[] = sanitize_text_field( $tag );
			}
			update_post_meta( $id, WP_MCP_AI_Workflow_CPT::META_TAGS, wp_slash( wp_json_encode( $clean_tags ) ) );
		}

		$new_version = WP_MCP_AI_Workflow_CPT::bump_version( $id, 'patch' );

		return rest_ensure_response(
			array(
				'id'      => $id,
				'version' => esc_html( $new_version ),
				'message' => __( 'Workflow updated.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * DELETE /orchestration/workflows/{id} — trash workflow.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || WP_MCP_AI_Workflow_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Workflow not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		wp_trash_post( $id );

		return rest_ensure_response(
			array(
				'id'      => $id,
				'message' => __( 'Workflow trashed.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * POST /orchestration/workflows/{id}/export — export portable JSON.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || WP_MCP_AI_Workflow_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Workflow not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		$payload = WP_MCP_AI_Workflow_CPT::export_json( $id );

		return rest_ensure_response( $payload );
	}

	/**
	 * POST /orchestration/workflows/import — import from JSON body.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_item( $request ) {
		$workflow_json = $request->get_param( 'workflow_json' );

		if ( ! is_array( $workflow_json ) ) {
			return new WP_Error( 'invalid_data', __( 'workflow_json must be an object.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		$post_id = WP_MCP_AI_Workflow_CPT::import_json( $workflow_json );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return rest_ensure_response(
			array(
				'id'      => $post_id,
				'message' => __( 'Workflow imported.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * POST /orchestration/workflows/{id}/execute — run via Engine V2.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function execute_item( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$input = $request->get_param( 'input' );
		$input = is_array( $input ) ? $input : array();

		$result = WP_MCP_AI_Workflow_Engine_V2::execute( $id, $input );

		if ( isset( $result['success'] ) && ! $result['success'] ) {
			return new WP_Error(
				'execution_failed',
				isset( $result['message'] ) ? $result['message'] : __( 'Execution failed.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $result );
	}
}

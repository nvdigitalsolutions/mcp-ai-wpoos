<?php
/**
 * REST controller for workflow-node execution (Proposal 084 Phase 3b,
 * W10.2d).
 *
 * Bridges the wp-admin AJAX node executor for the console canvas:
 *   POST /workflow-builder/nodes/execute
 *
 * Wraps the Workflow Builder page's own execution methods (promoted to
 * public with optional-parameter seams — the AJAX path is unchanged) and
 * fires the same wp_mcp_ai_pro_workflow_node_executed action so the Pro
 * Workflow Bridge keeps mirroring runs into the base Workflow Run CPT
 * (whose existing /orchestration/runs REST serves the history read — no
 * new history controller needed).
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow node execution REST controller.
 */
class WP_MCP_AI_Pro_Workflow_Node_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/workflow-builder';

	/**
	 * Boot the controller (idempotent) — mirrors the Pro ops controller.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register the node-execution route.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/nodes/execute',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'execute_node' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'node_type'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'enum'              => array( 'action', 'tool', 'agent' ),
						),
						'node_id'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'workflow_id'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => '',
						),
						'context'        => array(
							'type'    => 'object',
							'default' => array(),
						),
						// Per-type payloads (validated downstream by the page).
						'command'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'params'         => array(
							'type' => 'object',
						),
						'tool_name'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tool_arguments' => array(
							'type' => 'object',
						),
						'agent_id'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'prompt'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check — node execution is administrator-only.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * POST /workflow-builder/nodes/execute — run a single node.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function execute_node( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Builder_Page' ) ) {
			return new WP_Error(
				'builder_unavailable',
				__( 'The workflow builder is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$node_type   = $request->get_param( 'node_type' );
		$node_id     = $request->get_param( 'node_id' );
		$workflow_id = $request->get_param( 'workflow_id' );
		$context     = is_array( $request->get_param( 'context' ) ) ? $request->get_param( 'context' ) : array();

		// The args enum guards dispatch; this guard keeps direct calls honest.
		if ( ! in_array( $node_type, array( 'action', 'tool', 'agent' ), true ) ) {
			return new WP_Error(
				'invalid_node_type',
				__( 'Unsupported node type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// The executor methods are stateless; a fresh instance is inert on
		// REST requests (its hooks fire on admin/ajax paths only).
		$builder = new WP_MCP_AI_Pro_Workflow_Builder_Page();

		switch ( $node_type ) {
			case 'action':
				$params = is_array( $request->get_param( 'params' ) ) ? wp_json_encode( $request->get_param( 'params' ) ) : '{}';
				$result = $builder->execute_action_node( $context, $request->get_param( 'command' ), $params );
				break;
			case 'tool':
				$arguments = is_array( $request->get_param( 'tool_arguments' ) ) ? wp_json_encode( $request->get_param( 'tool_arguments' ) ) : '{}';
				$result    = $builder->execute_tool_node( $context, $request->get_param( 'tool_name' ), $arguments );
				break;
			default: // agent.
				$result = $builder->execute_agent_node( $context, $request->get_param( 'agent_id' ), $request->get_param( 'prompt' ) );
		}

		/**
		 * Fires after a Pro workflow node has been executed — the same hook
		 * the AJAX executor fires, so the Pro Workflow Bridge keeps
		 * mirroring runs into the base Workflow Run CPT.
		 *
		 * @param string         $node_type   Node type: action|tool|agent.
		 * @param string         $node_id     Workflow-scoped node identifier.
		 * @param string         $workflow_id Pro workflow ID (sanitize_key form).
		 * @param array|WP_Error $result      Execution result or error.
		 * @param array          $context     Execution context from previous nodes.
		 */
		do_action( 'wp_mcp_ai_pro_workflow_node_executed', $node_type, $node_id, $workflow_id, $result, $context );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'result' => $this->sanitize_deep( $result ),
			)
		);
	}

	/**
	 * Recursive sanitizer for the execution result (strings only).
	 *
	 * @since 2.4.0
	 * @param mixed $value Value to sanitize.
	 * @return mixed
	 */
	private function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_key( $key ) ] = $this->sanitize_deep( $item );
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return $value;
	}
}

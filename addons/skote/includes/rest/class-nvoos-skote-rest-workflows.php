<?php
/**
 * NV oOS Skote — REST: Workflows + Tools
 *
 * Adapter to the NV oOS Pro workflow builder + tool registry. When Pro is
 * absent, every route returns `nvoos_skote_pro_required` (HTTP 501) so the
 * SPA can render an "Install Pro" upsell instead of a generic failure.
 *
 * Workflow IDs map to the Pro option `wp_mcp_ai_pro_workflows`. Dispatch
 * goes through the canonical `WP_MCP_AI_Workflow_Dispatcher::dispatch()`
 * single entry point.
 *
 * Tools call into `WP_MCP_AI_Tool_Registry::get_instance()` and pass through
 * the HITL approval queue (`mcp_ai_approval` CPT) when the tool is flagged
 * as state-changing. Phase 1 wires the routes only — execution is gated
 * behind a feature flag and returns `phase-1-stub` until Phase 5.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflows / Tools controller.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_REST_Workflows extends NVOOS_Skote_REST_Base {

	/**
	 * Register routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/workflows',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_workflows' ),
				'permission_callback' => self::require_cap( 'manage_options' ),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/workflows/(?P<id>[a-z0-9_\-]+)/dispatch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'dispatch_workflow' ),
				'permission_callback' => self::require_cap( 'manage_options' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_slug' ),
					),
				),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_tools' ),
				'permission_callback' => self::require_cap( 'manage_options' ),
			)
		);

		register_rest_route(
			NVOOS_SKOTE_REST_NAMESPACE,
			'/tools/(?P<slug>[a-z0-9_\-]+)/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'execute_tool' ),
				'permission_callback' => self::require_cap( 'manage_options' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_slug' ),
					),
				),
			)
		);
	}

	/**
	 * Guard helper.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Error|null
	 */
	protected static function require_pro() {
		if ( NV_oOS_Skote::is_pro_active() ) {
			return null;
		}
		return self::error(
			'nvoos_skote_pro_required',
			__( 'NV oOS Pro is required for this endpoint.', 'nvoos-skote' ),
			501
		);
	}

	/**
	 * GET /workflows.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_workflows( $request ) {
		unset( $request );
		$err = self::require_pro();
		if ( $err ) {
			return $err;
		}

		$workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
		if ( ! is_array( $workflows ) ) {
			$workflows = array();
		}

		$summary = array();
		foreach ( $workflows as $id => $wf ) {
			if ( ! is_array( $wf ) ) {
				continue;
			}
			$summary[] = array(
				'id'    => sanitize_key( (string) $id ),
				'name'  => isset( $wf['name'] ) ? self::sanitize_text( $wf['name'] ) : (string) $id,
				'nodes' => isset( $wf['nodes'] ) && is_array( $wf['nodes'] ) ? count( $wf['nodes'] ) : 0,
			);
		}
		return self::success( $summary );
	}

	/**
	 * POST /workflows/{id}/dispatch.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dispatch_workflow( $request ) {
		$err = self::require_pro();
		if ( $err ) {
			return $err;
		}

		$id    = self::sanitize_slug( $request['id'] );
		$body  = $request->get_json_params();
		$input = is_array( $body ) && isset( $body['input'] ) && is_array( $body['input'] ) ? $body['input'] : array();

		if ( ! class_exists( 'WP_MCP_AI_Workflow_Dispatcher' ) ) {
			return self::error(
				'nvoos_skote_dispatcher_missing',
				__( 'The workflow dispatcher is not available.', 'nvoos-skote' ),
				500
			);
		}

		$context = array(
			'source'  => 'nvoos-skote',
			'user_id' => get_current_user_id(),
		);

		$result = WP_MCP_AI_Workflow_Dispatcher::dispatch( $id, $input, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::success( $result );
	}

	/**
	 * GET /tools.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_tools( $request ) {
		unset( $request );
		$err = self::require_pro();
		if ( $err ) {
			return $err;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return self::error(
				'nvoos_skote_registry_missing',
				__( 'The tool registry is not available.', 'nvoos-skote' ),
				500
			);
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();
		$summary  = array();
		foreach ( (array) $tools as $slug => $tool ) {
			$slug    = sanitize_key( (string) $slug );
			$summary[] = array(
				'slug' => $slug,
				'name' => is_object( $tool ) && method_exists( $tool, 'get_definition' )
					? (string) ( $tool->get_definition()['name'] ?? $slug )
					: $slug,
			);
		}
		return self::success( $summary );
	}

	/**
	 * POST /tools/{slug}/execute.
	 *
	 * Phase 1 returns a `phase-1-stub` until Phase 5 wires the HITL flow.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function execute_tool( $request ) {
		$err = self::require_pro();
		if ( $err ) {
			return $err;
		}
		$slug = self::sanitize_slug( $request['slug'] );

		return self::error(
			'nvoos_skote_tool_execute_not_implemented',
			__( 'Tool execution from Skote is not yet enabled. Phase 5 wires this through the HITL approval queue.', 'nvoos-skote' ),
			501,
			array( 'slug' => $slug )
		);
	}
}

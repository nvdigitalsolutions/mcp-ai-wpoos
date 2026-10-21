<?php
/**
 * REST controller for workflow presets (Proposal 084 Phase 3b, W10.2c).
 *
 * Bridges the wp-admin AJAX preset surface for the console:
 *   GET  /workflow-presets            — categories + preset summaries
 *   POST /workflow-presets/{id}/install — full preset (nodes/edges) for
 *                                         loading into the console canvas
 *
 * Wraps WP_MCP_AI_Pro_Workflow_Presets (same class the builder page's
 * AJAX handlers use) — no logic forks. Preset graphs may carry richer
 * node types than the Engine V2 execution trio (input/output/condition,
 * report kinds); they are passed through untouched so the canvas can
 * preserve them on save.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow presets REST controller.
 */
class WP_MCP_AI_Pro_Workflow_Presets_REST_Controller {

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
	const ROUTE_BASE = '/workflow-presets';

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
	 * Register the preset routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_presets' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<id>[a-z0-9_\-]+)/install',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install_preset' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check — presets are administrator-only.
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
	 * GET /workflow-presets — categories + summaries (graphs stripped from
	 * the list; the install route returns the full graph per preset).
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function list_presets() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			return rest_ensure_response(
				array(
					'ok'         => true,
					'categories' => array(),
					'presets'    => array(),
				)
			);
		}

		$categories = array();
		foreach ( WP_MCP_AI_Pro_Workflow_Presets::get_categories() as $slug => $label ) {
			$categories[ sanitize_key( $slug ) ] = sanitize_text_field( $label );
		}

		$presets = array();
		foreach ( WP_MCP_AI_Pro_Workflow_Presets::get_presets() as $id => $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$presets[] = array(
				'id'          => sanitize_key( (string) $id ),
				'name'        => isset( $preset['name'] ) ? sanitize_text_field( $preset['name'] ) : '',
				'description' => isset( $preset['description'] ) ? sanitize_text_field( $preset['description'] ) : '',
				'category'    => isset( $preset['category'] ) ? sanitize_key( $preset['category'] ) : '',
				'icon'        => isset( $preset['icon'] ) ? sanitize_text_field( $preset['icon'] ) : '',
				'tags'        => isset( $preset['tags'] ) && is_array( $preset['tags'] )
					? array_map( 'sanitize_text_field', $preset['tags'] )
					: array(),
			);
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'categories' => $categories,
				'presets'    => $presets,
			)
		);
	}

	/**
	 * POST /workflow-presets/{id}/install — the full preset graph.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_preset( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Workflow_Presets' ) ) {
			return new WP_Error(
				'presets_unavailable',
				__( 'Workflow presets are not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$preset_id = $request->get_param( 'id' );
		$preset    = WP_MCP_AI_Pro_Workflow_Presets::get_preset( $preset_id );

		if ( null === $preset ) {
			return new WP_Error(
				'invalid_preset',
				sprintf(
					/* translators: %s: preset identifier */
					__( 'Workflow preset "%s" does not exist.', 'mcp-ai-wpoos-pro' ),
					sanitize_key( $preset_id )
				),
				array( 'status' => 404 )
			);
		}

		$data = WP_MCP_AI_Pro_Workflow_Presets::install_preset( $preset_id );
		if ( is_wp_error( $data ) ) {
			return new WP_Error(
				$data->get_error_code(),
				$data->get_error_message(),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'preset' => array(
					'id'          => sanitize_key( $preset_id ),
					'name'        => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
					'description' => isset( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '',
					'category'    => isset( $data['category'] ) ? sanitize_key( $data['category'] ) : '',
					'icon'        => isset( $data['icon'] ) ? sanitize_text_field( $data['icon'] ) : '',
					'tags'        => isset( $data['tags'] ) && is_array( $data['tags'] )
						? array_map( 'sanitize_text_field', $data['tags'] )
						: array(),
					'nodes'       => $this->sanitize_graph_nodes( isset( $data['nodes'] ) ? $data['nodes'] : array() ),
					'edges'       => $this->sanitize_graph_edges( isset( $data['edges'] ) ? $data['edges'] : array() ),
				),
			)
		);
	}

	/**
	 * Sanitize preset nodes — preserve arbitrary node types (the preset
	 * vocabulary is richer than the Engine V2 execution trio).
	 *
	 * @since 2.4.0
	 * @param mixed $nodes Raw nodes.
	 * @return array
	 */
	private function sanitize_graph_nodes( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			return array();
		}

		$clean = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$data    = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
			$clean[] = array(
				'id'       => isset( $node['id'] ) ? sanitize_key( (string) $node['id'] ) : '',
				'type'     => isset( $node['type'] ) ? sanitize_key( (string) $node['type'] ) : 'tool',
				'position' => $this->sanitize_deep( isset( $node['position'] ) ? $node['position'] : array() ),
				'data'     => $this->sanitize_deep( $data ),
			);
		}

		return $clean;
	}

	/**
	 * Sanitize preset edges.
	 *
	 * @since 2.4.0
	 * @param mixed $edges Raw edges.
	 * @return array
	 */
	private function sanitize_graph_edges( $edges ) {
		if ( ! is_array( $edges ) ) {
			return array();
		}

		$clean = array();
		foreach ( $edges as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}
			$clean[] = array(
				'id'     => isset( $edge['id'] ) ? sanitize_key( (string) $edge['id'] ) : '',
				'source' => isset( $edge['source'] ) ? sanitize_key( (string) $edge['source'] ) : '',
				'target' => isset( $edge['target'] ) ? sanitize_key( (string) $edge['target'] ) : '',
			);
		}

		return $clean;
	}

	/**
	 * Recursive sanitizer for open shapes (strings only — scalars pass).
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

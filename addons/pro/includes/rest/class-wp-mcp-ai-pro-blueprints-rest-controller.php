<?php
/**
 * REST controller for the assistant blueprints catalogue (Proposal 084
 * Phase 5c, gap P-unified-blueprints).
 *
 * Bridges the wp-admin Unified Blueprints page to the Complete Console:
 *   GET  /mcp-ai-pro/v1/blueprints                    — grouped catalogue
 *   GET  /mcp-ai-pro/v1/blueprints/{toolkit}/{slug}   — detail payload
 *   POST /mcp-ai-pro/v1/blueprints/{toolkit}/{slug}/install — install
 *
 * Reuses the page class's promoted seams
 * (WP_MCP_AI_Unified_Blueprints_Page::get_all_blueprints_grouped /
 * get_installed_blueprint_map / get_blueprint_details_data /
 * install_blueprint) — one implementation, no logic forks. Access mirrors
 * the wp-admin page: edit_posts (any editor may browse + install).
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blueprints catalogue REST controller.
 */
class WP_MCP_AI_Pro_Blueprints_REST_Controller {

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
	const ROUTE_BASE = '/blueprints';

	/**
	 * Boot the controller (module-registry pattern).
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register the blueprint routes.
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
					'callback'            => array( $this, 'get_list' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<toolkit>[a-z0-9-]+)/(?P<slug>[a-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_details' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_path_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<toolkit>[a-z0-9-]+)/(?P<slug>[a-z0-9-]+)/install',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->get_path_args(),
						array(
							'overwrite' => array(
								'required'          => false,
								'type'              => 'boolean',
								'default'           => false,
								'sanitize_callback' => 'rest_sanitize_boolean',
							),
						)
					),
				),
			)
		);
	}

	/**
	 * Path argument schemas shared by the detail + install routes.
	 *
	 * @since 2.4.0
	 * @return array<string,array>
	 */
	private function get_path_args() {
		return array(
			'toolkit' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			'slug'    => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Permission check — mirrors the wp-admin page's edit_posts gate.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage assistant blueprints.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * List the blueprint catalogue, grouped by enabled toolkit.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_list() {
		$grouped   = WP_MCP_AI_Unified_Blueprints_Page::get_all_blueprints_grouped();
		$labels    = WP_MCP_AI_Unified_Blueprints_Page::get_toolkit_labels();
		$installed = WP_MCP_AI_Unified_Blueprints_Page::get_installed_blueprint_map();

		$toolkits = array();
		$total    = 0;

		foreach ( $grouped as $dir => $blueprints ) {
			$items = array();
			foreach ( $blueprints as $slug => $bp ) {
				$name         = isset( $bp['name'] ) ? $bp['name'] : ( isset( $bp['post_title'] ) ? $bp['post_title'] : '' );
				$meta         = isset( $bp['meta'] ) ? $bp['meta'] : ( isset( $bp['meta_input'] ) ? $bp['meta_input'] : array() );
				$tools        = isset( $meta['available_tools'] ) ? $meta['available_tools'] : ( isset( $meta['_wp_mcp_ai_tools'] ) ? $meta['_wp_mcp_ai_tools'] : array() );
				$is_installed = isset( $installed[ $name ] );

				$items[] = array(
					'slug'         => sanitize_key( $slug ),
					'name'         => sanitize_text_field( $name ),
					'description'  => isset( $bp['description'] ) ? sanitize_text_field( $bp['description'] ) : '',
					'tool_count'   => is_array( $tools ) ? count( $tools ) : 0,
					'installed'    => (bool) $is_installed,
					'assistant_id' => $is_installed ? absint( $installed[ $name ] ) : 0,
				);
			}

			$total     += count( $items );
			$toolkits[] = array(
				'slug'       => sanitize_key( $dir ),
				'label'      => isset( $labels[ $dir ] ) ? $labels[ $dir ] : ucfirst( $dir ),
				'blueprints' => $items,
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'total'    => $total,
					'toolkits' => $toolkits,
				),
			)
		);
	}

	/**
	 * Detail payload for one blueprint (instructions, tools, defaults).
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_details( $request ) {
		$data = WP_MCP_AI_Unified_Blueprints_Page::get_blueprint_details_data(
			$request->get_param( 'toolkit' ),
			$request->get_param( 'slug' )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array_merge(
					array(
						'toolkit' => $request->get_param( 'toolkit' ),
						'slug'    => $request->get_param( 'slug' ),
					),
					$data
				),
			)
		);
	}

	/**
	 * Install a blueprint as a new assistant.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install( $request ) {
		$result = WP_MCP_AI_Unified_Blueprints_Page::install_blueprint(
			$request->get_param( 'toolkit' ),
			$request->get_param( 'slug' ),
			(bool) $request->get_param( 'overwrite' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'toolkit'      => $request->get_param( 'toolkit' ),
					'slug'         => $request->get_param( 'slug' ),
					'assistant_id' => isset( $result['assistant_id'] ) ? absint( $result['assistant_id'] ) : 0,
				),
			)
		);
	}
}

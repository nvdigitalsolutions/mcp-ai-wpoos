<?php
/**
 * REST API controller for the Skill Catalogue feature.
 *
 * Exposes admin-only endpoints under the `mcp-ai-pro/v1` namespace that wrap
 * `WP_MCP_AI_Skill_Catalogue_Service` so the Browse-catalogue admin tab can
 * list manifests and one-click-install skills sourced from registered remote
 * catalogues.
 *
 * Endpoints:
 *   GET  /catalogues                      – list registered sources.
 *   GET  /catalogues/{id}/skills          – manifest for one source.
 *   POST /catalogues/{id}/install         – install one skill (by repo path).
 *   POST /catalogues/{id}/refresh         – force-refresh a source's manifest.
 *
 * All endpoints require `manage_options`.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.11.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for skill catalogues.
 *
 * @since 1.11.0
 */
class WP_MCP_AI_Skill_Catalogue_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai-pro/v1';

	/**
	 * Base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'catalogues';

	/**
	 * Constructor — register routes on rest_api_init.
	 *
	 * @since 1.11.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sources' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		$id_pattern = '(?P<id>[a-z0-9][a-z0-9_-]{0,62}[a-z0-9]?)';

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . $id_pattern . '/skills',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_skills' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Bypass the manifest cache and re-fetch from the source.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . $id_pattern . '/install',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install_skill' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'path' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Repo-relative path to the skill folder, as listed in the manifest.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . $id_pattern . '/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission callback — admin only.
	 *
	 * @since 1.11.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage skill catalogues.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * GET /catalogues
	 *
	 * @since 1.11.0
	 * @return WP_REST_Response
	 */
	public function get_sources() {
		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$sources = $service->get_sources();
		// Strip nothing — these are admin-managed metadata, no secrets present.
		return rest_ensure_response( $sources );
	}

	/**
	 * GET /catalogues/{id}/skills
	 *
	 * @since 1.11.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_skills( $request ) {
		$id      = sanitize_key( $request->get_param( 'id' ) );
		$force   = (bool) $request->get_param( 'force' );
		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();

		$manifest = $service->get_manifest( $id, $force );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		// Annotate "installed" + "update available" flags so the UI can render
		// badges without a second round-trip.
		if ( class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			$registry = WP_MCP_AI_Skill_Registry::instance();
			foreach ( $manifest['skills'] as &$entry ) {
				$installed                 = $registry->get_skill( $entry['name'] );
				$entry['installed']        = ( null !== $installed );
				$entry['update_available'] = $entry['installed'] ? (bool) $service->has_update( $id, $entry['name'] ) : false;
			}
			unset( $entry );
		}

		return rest_ensure_response( $manifest );
	}

	/**
	 * POST /catalogues/{id}/install
	 *
	 * @since 1.11.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_skill( $request ) {
		$id   = sanitize_key( $request->get_param( 'id' ) );
		$path = (string) $request->get_param( 'path' );

		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();
		$result  = $service->install_from_catalogue( $id, $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response(
			array(
				'installed' => true,
				'name'      => isset( $result['name'] ) ? $result['name'] : '',
				'skill'     => $result,
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * POST /catalogues/{id}/refresh
	 *
	 * @since 1.11.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh( $request ) {
		$id      = sanitize_key( $request->get_param( 'id' ) );
		$service = WP_MCP_AI_Skill_Catalogue_Service::instance();

		$manifest = $service->get_manifest( $id, true );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		return rest_ensure_response(
			array(
				'refreshed'  => true,
				'fetched_at' => $manifest['fetched_at'],
				'count'      => count( $manifest['skills'] ),
			)
		);
	}
}

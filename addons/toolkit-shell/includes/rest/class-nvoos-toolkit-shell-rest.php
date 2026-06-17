<?php
/**
 * NV oOS Toolkit Shell — REST Controller
 *
 * Exposes the manifest registry over `/wp-json/nvoos-toolkit-shell/v1/*`.
 * Domain data still flows through the Pro toolkits' own
 * `/wp-json/mcp-ai-pro/v1/*` endpoints — this controller is for SPA-specific
 * concerns only (manifest discovery + health).
 *
 * @package NV_oOS_Toolkit_Shell
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller.
 *
 * @since 0.1.0
 */
class NV_oOS_Toolkit_Shell_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-toolkit-shell/v1';

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/manifests',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_manifests' ),
				'permission_callback' => array( __CLASS__, 'reader_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/manifests/(?P<toolkit>[a-z0-9_\-]{1,64})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_manifest' ),
				'permission_callback' => array( __CLASS__, 'reader_permission' ),
				'args'                => array(
					'toolkit' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);
	}

	/**
	 * Reader permission — any authenticated user with `read` capability.
	 *
	 * Manifests describe the SPA's available views/fields, not the underlying
	 * data. The actual data fetch is gated by each manifest's `capability`
	 * field, which the Pro REST controllers enforce when the SPA hits them.
	 *
	 * @return bool|WP_Error
	 */
	public static function reader_permission() {
		if ( current_user_can( 'read' ) ) {
			return true;
		}
		return new WP_Error(
			'forbidden',
			__( 'You must be logged in to access toolkit manifests.', 'nvoos-toolkit-shell' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Manage_options gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'forbidden',
			__( 'You do not have permission to access this endpoint.', 'nvoos-toolkit-shell' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * List all manifests.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_manifests() {
		$all = NV_oOS_Toolkit_Shell_Manifest_Registry::get_all();
		// Return only the lightweight summary by default.
		$summary = array();
		foreach ( $all as $slug => $manifest ) {
			$summary[] = array(
				'toolkit' => $slug,
				'label'   => isset( $manifest['label'] ) ? $manifest['label'] : $slug,
				'icon'    => isset( $manifest['icon'] ) ? $manifest['icon'] : 'admin-generic',
				'version' => isset( $manifest['version'] ) ? $manifest['version'] : '1.0',
			);
		}
		return rest_ensure_response( array( 'manifests' => $summary ) );
	}

	/**
	 * Get a single manifest by toolkit slug.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_manifest( $request ) {
		$toolkit  = sanitize_key( (string) $request->get_param( 'toolkit' ) );
		$manifest = NV_oOS_Toolkit_Shell_Manifest_Registry::get( $toolkit );
		if ( null === $manifest ) {
			return new WP_Error(
				'not_found',
				__( 'No manifest registered for this toolkit.', 'nvoos-toolkit-shell' ),
				array( 'status' => 404 )
			);
		}
		// Enforce the manifest's declared capability.
		$cap = isset( $manifest['capability'] ) ? $manifest['capability'] : 'edit_posts';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to view this toolkit.', 'nvoos-toolkit-shell' ),
				array( 'status' => 403 )
			);
		}
		/**
		 * Filter a manifest just before it is returned to the SPA.
		 *
		 * @param array  $manifest Sanitized manifest.
		 * @param string $toolkit  Toolkit slug.
		 */
		$manifest = apply_filters( 'nvoos_toolkit_shell_manifest', $manifest, $toolkit );
		return rest_ensure_response( $manifest );
	}

	/**
	 * Health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'         => 'ok',
				'version'        => defined( 'NVOOS_TOOLKIT_SHELL_VERSION' ) ? NVOOS_TOOLKIT_SHELL_VERSION : 'unknown',
				'manifest_count' => count( NV_oOS_Toolkit_Shell_Manifest_Registry::get_all() ),
			)
		);
	}
}

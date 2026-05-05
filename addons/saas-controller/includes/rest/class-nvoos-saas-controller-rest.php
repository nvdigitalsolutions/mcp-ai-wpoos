<?php
/**
 * REST API controller for the NV oOS SaaS Controller.
 *
 * Exposes the `/wp-json/nvoos-saas/v1/` namespace consumed by the React
 * admin bundle (`assets/build/index.js`). Every route in this controller is
 * gated on the `manage_options` capability — the addon has no public
 * surface.
 *
 * Phase 2 routes:
 *   GET    /healthz                — version + base-plugin liveness probe.
 *   GET    /credentials            — masked credentials (never returns plaintext).
 *   POST   /credentials            — set/update one or more credentials.
 *   DELETE /credentials            — clear all credentials.
 *
 * Subsequent phases will add `/connections/test`, `/plan`, `/apply`,
 * `/drift`, `/audit-log`, and `/smoke-tests` routes — all under the same
 * namespace and the same capability gate.
 *
 * @package NV_oOS_SaaS_Controller
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
class NVOOS_SaaS_Controller_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'nvoos-saas/v1';

	/**
	 * Required capability for every route in this controller.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Register hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/healthz',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_healthz' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/credentials',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'route_get_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'route_set_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'args'                => self::credentials_schema(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'route_clear_credentials' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Permission callback shared by every route.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|WP_Error True when allowed, WP_Error otherwise.
	 */
	public static function check_permission() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access the SaaS Controller.', 'nvoos-saas-controller' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Args schema for `POST /credentials`.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected static function credentials_schema() {
		$args = array();
		foreach ( NVOOS_SaaS_Controller_Credential_Store::ALLOWED_KEYS as $key ) {
			$args[ $key ] = array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			);
		}
		return $args;
	}

	/**
	 * GET /healthz handler.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_healthz() {
		return rest_ensure_response(
			array(
				'ok'           => true,
				'addon'        => 'nvoos-saas-controller',
				'version'      => defined( 'NVOOS_SAAS_CONTROLLER_VERSION' ) ? NVOOS_SAAS_CONTROLLER_VERSION : 'dev',
				'base_active'  => class_exists( 'WP_MCP_AI_Plugin' ),
				'time'         => time(),
			)
		);
	}

	/**
	 * GET /credentials handler — returns a masked snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_get_credentials() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		return rest_ensure_response(
			array(
				'credentials' => $store->get_masked(),
			)
		);
	}

	/**
	 * POST /credentials handler — encrypts and persists incoming values.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_set_credentials( WP_REST_Request $request ) {
		$values = array();
		foreach ( NVOOS_SaaS_Controller_Credential_Store::ALLOWED_KEYS as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}
			$values[ $key ] = (string) $value;
		}

		if ( empty( $values ) ) {
			return new WP_Error(
				'nvoos_saas_no_values',
				__( 'No credential values were provided.', 'nvoos-saas-controller' ),
				array( 'status' => 400 )
			);
		}

		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->set( $values );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'credentials' => $store->get_masked(),
			)
		);
	}

	/**
	 * DELETE /credentials handler.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_clear_credentials() {
		$store = NVOOS_SaaS_Controller_Credential_Store::instance();
		$store->clear_all();
		return rest_ensure_response(
			array(
				'ok'          => true,
				'credentials' => $store->get_masked(),
			)
		);
	}
}

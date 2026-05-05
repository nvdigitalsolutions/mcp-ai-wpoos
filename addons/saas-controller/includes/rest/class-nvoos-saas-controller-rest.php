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
 *   POST   /connections/test       — live preflight against Cloudflare/Stripe/OpenRouter.
 *
 * Subsequent phases will add `/plan`, `/apply`, `/drift`, `/audit-log`, and
 * `/smoke-tests` routes — all under the same namespace and the same
 * capability gate.
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

		register_rest_route(
			self::NAMESPACE,
			'/connections/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_test_connections' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => self::credentials_schema(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/deployment',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'route_get_deployment' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'route_set_deployment' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/plan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_run_plan' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit-log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'route_get_audit_log' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'args'                => array(
						'limit'  => array(
							'type'              => 'integer',
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
						'offset' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'route_clear_audit_log' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/smoke-tests/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_run_smoke_tests' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/smoke-tests/last',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_get_last_smoke_test' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/apply/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_apply_preview' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/apply/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_apply_run' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'apply_token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/drift/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_drift_check' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/drift/last',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_drift_last' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
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

	/**
	 * POST /connections/test handler.
	 *
	 * Runs live preflight checks against Cloudflare, Stripe, and OpenRouter
	 * using either the supplied credentials (when provided in the body) or
	 * the values currently in the credential store. Never echoes secrets.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function route_test_connections( WP_REST_Request $request ) {
		$supplied = array();
		foreach ( NVOOS_SaaS_Controller_Credential_Store::ALLOWED_KEYS as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}
			$supplied[ $key ] = (string) $value;
		}

		if ( ! class_exists( 'NVOOS_SaaS_Controller_Connection_Tester' ) ) {
			require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-connection-tester.php';
		}

		$tester  = new NVOOS_SaaS_Controller_Connection_Tester();
		$results = $tester->test_all( $supplied );

		return rest_ensure_response(
			array(
				'ok'      => self::all_ok( $results ),
				'results' => $results,
			)
		);
	}

	/**
	 * GET /deployment handler.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_get_deployment() {
		$config = NVOOS_SaaS_Controller_Deployment_Config::instance();
		return rest_ensure_response(
			array(
				'deployment' => $config->get(),
			)
		);
	}

	/**
	 * POST /deployment handler — replaces the desired config with the supplied
	 * (sanitised) value. Body is parsed as JSON.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function route_set_deployment( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$config  = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$updated = $config->set( $body );
		return rest_ensure_response(
			array(
				'ok'         => true,
				'deployment' => $updated,
			)
		);
	}

	/**
	 * POST /plan handler — runs the reconcile-plan generator against live
	 * Cloudflare state and returns the structured plan. Read-only.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_run_plan() {
		$config_store = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$desired      = $config_store->get();

		$account_override = ! empty( $desired['account_id'] ) ? (string) $desired['account_id'] : null;
		$client           = NVOOS_SaaS_Controller_Cloudflare_Client::from_credential_store( $account_override );
		if ( is_wp_error( $client ) ) {
			$client->add_data( array( 'status' => 412 ) );
			return $client;
		}

		$generator = new NVOOS_SaaS_Controller_Plan_Generator( $client );
		$plan      = $generator->generate( $desired );

		return rest_ensure_response(
			array(
				'ok'      => empty( $plan['errors'] ),
				'desired' => $desired,
				'plan'    => $plan,
			)
		);
	}

	/**
	 * Helper: did every preflight succeed?
	 *
	 * @since 0.1.0
	 *
	 * @param array $results Preflight results.
	 * @return bool
	 */
	protected static function all_ok( array $results ) {
		foreach ( $results as $r ) {
			if ( empty( $r['ok'] ) ) {
				return false;
			}
		}
		return ! empty( $results );
	}

	/**
	 * GET /audit-log — paginated, newest-first.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_get_audit_log( $request ) {
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );
		$log    = NVOOS_SaaS_Controller_Audit_Log::instance();
		return rest_ensure_response(
			array(
				'entries' => $log->get_recent( $limit > 0 ? $limit : 50, $offset ),
				'total'   => $log->count(),
			)
		);
	}

	/**
	 * DELETE /audit-log — clear the audit log.
	 *
	 * Recorded as an audit-log entry of its own (action `clear_audit_log`,
	 * channel `internal`) before the clear, so the operator can see who
	 * cleared it from the next run onward.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_clear_audit_log() {
		$log = NVOOS_SaaS_Controller_Audit_Log::instance();
		$log->record(
			array(
				'channel' => 'internal',
				'action'  => 'clear_audit_log',
				'status'  => 'ok',
				'message' => __( 'Audit log cleared.', 'nvoos-saas-controller' ),
			)
		);
		$log->clear();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /smoke-tests/run — execute the full smoke-test sequence.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_run_smoke_tests() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		return rest_ensure_response( $tester->run() );
	}

	/**
	 * GET /smoke-tests/last — return the most recent smoke-test result.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_get_last_smoke_test() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$last   = $tester->get_last_result();
		return rest_ensure_response( null === $last ? array( 'ok' => null, 'checks' => array() ) : $last );
	}

	/**
	 * POST /apply/preview — re-run the plan against live state and issue a
	 * single-use HITL apply token bound to the resulting plan.
	 *
	 * The plaintext token is returned only in this response (and is also
	 * never logged in plaintext — only its SHA-256 hash prefix appears in
	 * the audit log). The operator must echo it back via /apply/run within
	 * the configured TTL to actually mutate Cloudflare.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_apply_preview() {
		$config_store = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$desired      = $config_store->get();

		$account_override = ! empty( $desired['account_id'] ) ? (string) $desired['account_id'] : null;
		$client           = NVOOS_SaaS_Controller_Cloudflare_Client::from_credential_store( $account_override );
		if ( is_wp_error( $client ) ) {
			$client->add_data( array( 'status' => 412 ) );
			return $client;
		}

		// Phase 6 — optional Stripe / OpenRouter clients. Returning `null`
		// from `from_credential_store()` indicates the operator hasn't
		// opted in to that surface; the plan generator will silently skip
		// the corresponding section unless desired-config rows exist.
		$stripe     = class_exists( 'NVOOS_SaaS_Controller_Stripe_Client' )
			? NVOOS_SaaS_Controller_Stripe_Client::from_credential_store()
			: null;
		$openrouter = class_exists( 'NVOOS_SaaS_Controller_OpenRouter_Client' )
			? NVOOS_SaaS_Controller_OpenRouter_Client::from_credential_store()
			: null;

		$generator = new NVOOS_SaaS_Controller_Plan_Generator( $client, $stripe, $openrouter );
		$plan      = $generator->generate( $desired );

		if ( ! empty( $plan['errors'] ) ) {
			return new WP_Error(
				'plan_has_errors',
				__( 'Cannot issue an apply token while the plan reports errors. Resolve the errors and run preview again.', 'nvoos-saas-controller' ),
				array( 'status' => 409 )
			);
		}

		$issued = NVOOS_SaaS_Controller_Apply_Engine::issue_token( $plan );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'desired'     => $desired,
				'plan'        => $plan,
				'apply_token' => $issued['token'],
				'expires_in'  => $issued['expires_in'],
			)
		);
	}

	/**
	 * POST /apply/run — consume an apply token and mutate Cloudflare.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_apply_run( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'apply_token' );
		$plan  = NVOOS_SaaS_Controller_Apply_Engine::consume_token( $token );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$config_store     = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$desired          = $config_store->get();
		$account_override = ! empty( $desired['account_id'] ) ? (string) $desired['account_id'] : null;

		$mutating = NVOOS_SaaS_Controller_Cloudflare_Mutating_Client::from_credential_store( $account_override );
		if ( is_wp_error( $mutating ) ) {
			$mutating->add_data( array( 'status' => 412 ) );
			return $mutating;
		}

		// Phase 6 — same optional-client pattern as the preview route.
		$stripe     = class_exists( 'NVOOS_SaaS_Controller_Stripe_Client' )
			? NVOOS_SaaS_Controller_Stripe_Client::from_credential_store()
			: null;
		$openrouter = class_exists( 'NVOOS_SaaS_Controller_OpenRouter_Client' )
			? NVOOS_SaaS_Controller_OpenRouter_Client::from_credential_store()
			: null;

		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $mutating, $stripe, $openrouter );
		$out    = $engine->apply( $plan );

		$out['ok'] = empty( $out['summary']['error'] );
		return rest_ensure_response( $out );
	}

	/**
	 * POST /drift/check — run a fresh drift check against the deployed
	 * Worker and return the result. Always succeeds (drift / unknown /
	 * error all come back as 200 with a structured body); transport-level
	 * failures inside the detector surface as `status=error`.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_drift_check() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		return rest_ensure_response( $detector->check() );
	}

	/**
	 * GET /drift/last — return the most recent cached drift-check result,
	 * or `{ status: 'unknown', message: ... }` when no run has happened.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_drift_last() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$last     = $detector->get_last_result();
		if ( null === $last ) {
			return rest_ensure_response(
				array(
					'ok'      => false,
					'status'  => 'unknown',
					'message' => __( 'Drift check has not been run yet.', 'nvoos-saas-controller' ),
				)
			);
		}
		return rest_ensure_response( $last );
	}
}

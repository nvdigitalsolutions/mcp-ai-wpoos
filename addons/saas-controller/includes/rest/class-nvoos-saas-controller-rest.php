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

		// Phase 8 — background async Apply.
		register_rest_route(
			self::NAMESPACE,
			'/apply/enqueue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_apply_enqueue' ),
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
			'/apply/jobs/(?P<id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'route_apply_job_get' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/apply/jobs/(?P<id>[a-zA-Z0-9-]+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_apply_job_cancel' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Phase 10 — orphan cleanup (HITL-gated delete).
		// Distinct token namespace from /apply/preview + /apply/run; the
		// destructive surface is intentionally separable so a careless
		// click on the regular Apply button can never delete a resource.
		register_rest_route(
			self::NAMESPACE,
			'/apply/orphans/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_apply_orphans_preview' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/apply/orphans/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_apply_orphans_run' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'orphan_token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'selected'     => array(
						'type'     => 'array',
						'required' => true,
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

		// Phase 7 — Stripe webhook receiver.
		// `POST /webhooks/stripe` is the only route in this namespace that
		// is **not** capability-gated: Stripe is the caller and there is no
		// signed-in WP user. Authentication is performed inside the handler
		// by verifying the `Stripe-Signature` header against the stored
		// `stripe_webhook_secret`. Until the receiver verifies the
		// signature it treats the request as untrusted (returns 401 / 400
		// without persisting anything).
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/stripe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'route_stripe_webhook' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/webhooks/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'route_get_webhook_events' ),
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
					'callback'            => array( __CLASS__, 'route_clear_webhook_events' ),
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
				'ok'          => true,
				'addon'       => 'nvoos-saas-controller',
				'version'     => defined( 'NVOOS_SAAS_CONTROLLER_VERSION' ) ? NVOOS_SAAS_CONTROLLER_VERSION : 'dev',
				'base_active' => class_exists( 'WP_MCP_AI_Plugin' ),
				'time'        => time(),
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
		return rest_ensure_response(
			null === $last ? array(
				'ok'     => null,
				'checks' => array(),
			) : $last
		);
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
	 * POST /apply/enqueue — consume an apply token and start a background
	 * apply job (Phase 8).
	 *
	 * Returns the freshly-created job state projection (status, totals,
	 * empty results buffer). The admin UI polls `/apply/jobs/{id}` to
	 * watch progress until `status` reaches `completed | cancelled |
	 * failed`. The token is single-use exactly like `/apply/run`.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_apply_enqueue( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 'apply_token' );
		$state = NVOOS_SaaS_Controller_Apply_Job::enqueue_from_token( $token );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		return rest_ensure_response(
			array(
				'ok'  => true,
				'job' => $state,
			)
		);
	}

	/**
	 * GET /apply/jobs/{id} — return the current progress projection for
	 * a background apply job (Phase 8). Returns 404 if the job is
	 * unknown or the state transient has expired.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_apply_job_get( WP_REST_Request $request ) {
		$id    = (string) $request->get_param( 'id' );
		$state = NVOOS_SaaS_Controller_Apply_Job::get_progress( $id );
		if ( null === $state ) {
			return new WP_Error(
				'apply_job_not_found',
				__( 'Apply job not found or expired.', 'nvoos-saas-controller' ),
				array( 'status' => 404 )
			);
		}

		// Self-heal: when polling reveals a job that has sat in `queued`
		// past the stale threshold (typically because the WP-Cron
		// loopback is disabled or firewalled), schedule a shutdown kick
		// so the very next poll observes progress. The kick is a no-op
		// when status has advanced beyond `queued` (handle_tick checks
		// before doing any work) and runs at most once per request.
		// Mirrors the equivalent self-heal in the base plugin's
		// Mine Memories / Tool Async Executor REST routes.
		if ( 'queued' === $state['status'] ) {
			$age = time() - (int) $state['updated_at'];
			if ( $age >= NVOOS_SaaS_Controller_Apply_Job::STALE_QUEUED_THRESHOLD_SECONDS ) {
				$kick_id = (string) $state['id'];
				add_action(
					'shutdown',
					static function () use ( $kick_id ) {
						NVOOS_SaaS_Controller_Apply_Job::kick_inline( $kick_id );
					},
					20
				);
			}
		}

		return rest_ensure_response(
			array(
				'ok'  => true,
				'job' => $state,
			)
		);
	}

	/**
	 * POST /apply/jobs/{id}/cancel — cancel a queued or running
	 * background apply job (Phase 8). An already-firing tick will
	 * finish its current row before the cancelled status is observed.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_apply_job_cancel( WP_REST_Request $request ) {
		$id    = (string) $request->get_param( 'id' );
		$state = NVOOS_SaaS_Controller_Apply_Job::cancel( $id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		return rest_ensure_response(
			array(
				'ok'  => true,
				'job' => $state,
			)
		);
	}

	/**
	 * POST /apply/orphans/preview — re-run the plan and issue a separate
	 * single-use HITL token good only for {@see self::route_apply_orphans_run()}
	 * (Phase 10 — orphan cleanup).
	 *
	 * Returns the full `plan.orphans[]` list plus the orphan token. The
	 * caller is expected to render checkboxes (defaulting to *unchecked*)
	 * and only submit the operator's explicit selection in the run call.
	 *
	 * Plan-level errors do not block orphan preview: a Cloudflare list
	 * call that 5xx'd produces no orphans for that section, but the rest
	 * of the orphan list is still actionable. The structured `errors[]`
	 * array is surfaced to the caller for transparency.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_apply_orphans_preview() {
		$config_store = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$desired      = $config_store->get();

		$account_override = ! empty( $desired['account_id'] ) ? (string) $desired['account_id'] : null;
		$client           = NVOOS_SaaS_Controller_Cloudflare_Client::from_credential_store( $account_override );
		if ( is_wp_error( $client ) ) {
			$client->add_data( array( 'status' => 412 ) );
			return $client;
		}

		$stripe     = class_exists( 'NVOOS_SaaS_Controller_Stripe_Client' )
			? NVOOS_SaaS_Controller_Stripe_Client::from_credential_store()
			: null;
		$openrouter = class_exists( 'NVOOS_SaaS_Controller_OpenRouter_Client' )
			? NVOOS_SaaS_Controller_OpenRouter_Client::from_credential_store()
			: null;

		$generator = new NVOOS_SaaS_Controller_Plan_Generator( $client, $stripe, $openrouter );
		$plan      = $generator->generate( $desired );
		$orphans   = isset( $plan['orphans'] ) && is_array( $plan['orphans'] ) ? $plan['orphans'] : array();

		$issued = NVOOS_SaaS_Controller_Apply_Engine::issue_orphan_token( $orphans );

		return rest_ensure_response(
			array(
				'ok'           => true,
				'orphans'      => $orphans,
				'errors'       => isset( $plan['errors'] ) ? $plan['errors'] : array(),
				'orphan_token' => $issued['token'],
				'expires_in'   => $issued['expires_in'],
			)
		);
	}

	/**
	 * POST /apply/orphans/run — consume the orphan token and delete the
	 * operator's selected subset of orphans (Phase 10).
	 *
	 * The body is `{ orphan_token: string, selected: array }`. Each
	 * `selected[]` entry is matched against the cached preview list by an
	 * exact identity-key tuple so the browser cannot extend the delete
	 * set after issuance — see
	 * {@see NVOOS_SaaS_Controller_Apply_Engine::apply_orphans()}.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_apply_orphans_run( WP_REST_Request $request ) {
		$token   = (string) $request->get_param( 'orphan_token' );
		$orphans = NVOOS_SaaS_Controller_Apply_Engine::consume_orphan_token( $token );
		if ( is_wp_error( $orphans ) ) {
			return $orphans;
		}

		$selected_raw = $request->get_param( 'selected' );
		$selected     = is_array( $selected_raw ) ? $selected_raw : array();
		if ( empty( $selected ) ) {
			return new WP_Error(
				'no_orphans_selected',
				__( 'No orphans selected for deletion.', 'nvoos-saas-controller' ),
				array( 'status' => 400 )
			);
		}

		$config_store     = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$desired          = $config_store->get();
		$account_override = ! empty( $desired['account_id'] ) ? (string) $desired['account_id'] : null;

		$mutating = NVOOS_SaaS_Controller_Cloudflare_Mutating_Client::from_credential_store( $account_override );
		if ( is_wp_error( $mutating ) ) {
			$mutating->add_data( array( 'status' => 412 ) );
			return $mutating;
		}

		$stripe     = class_exists( 'NVOOS_SaaS_Controller_Stripe_Client' )
			? NVOOS_SaaS_Controller_Stripe_Client::from_credential_store()
			: null;
		$openrouter = class_exists( 'NVOOS_SaaS_Controller_OpenRouter_Client' )
			? NVOOS_SaaS_Controller_OpenRouter_Client::from_credential_store()
			: null;

		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $mutating, $stripe, $openrouter );
		$out    = $engine->apply_orphans( $selected, $orphans );

		$out['ok'] = empty( $out['summary']['error'] ) && empty( $out['summary']['rejected'] );
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

	/**
	 * POST /webhooks/stripe — Stripe webhook receiver (Phase 7).
	 *
	 * The route is publicly reachable (Stripe is the caller and has no WP
	 * session) but every request is gated by an HMAC-SHA256 signature
	 * check against the stored `stripe_webhook_secret`. The handler:
	 *
	 *  1. Loads the secret. If unconfigured → 412 (so the operator gets a
	 *     clear "configure the receiver before pointing Stripe at it"
	 *     signal during onboarding).
	 *  2. Verifies the `Stripe-Signature` header against the raw request
	 *     body. Returns 401 on signature failure / replay, 400 on
	 *     malformed payloads.
	 *  3. Records the event in the webhook event store (idempotent by
	 *     `event.id`) and mirrors a one-line summary to the audit log on
	 *     the `stripe` channel.
	 *  4. Returns `{ ok: true, event_id, duplicate }` with HTTP 200 — the
	 *     receiver intentionally returns 2xx as fast as possible so Stripe
	 *     doesn't queue retries.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function route_stripe_webhook( WP_REST_Request $request ) {
		$store     = NVOOS_SaaS_Controller_Credential_Store::instance();
		$plain     = $store->get_all();
		$secret    = isset( $plain['stripe_webhook_secret'] ) ? (string) $plain['stripe_webhook_secret'] : '';
		$audit     = NVOOS_SaaS_Controller_Audit_Log::instance();
		$event_bag = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();

		if ( '' === $secret ) {
			$audit->record(
				array(
					'channel' => 'stripe',
					'action'  => 'webhook_received',
					'status'  => 'error',
					'message' => __( 'Webhook rejected: stripe_webhook_secret is not configured.', 'nvoos-saas-controller' ),
				)
			);
			return new WP_Error(
				'stripe_webhook_secret_missing',
				__( 'Stripe webhook secret is not configured.', 'nvoos-saas-controller' ),
				array( 'status' => 412 )
			);
		}

		$raw_body  = (string) $request->get_body();
		$signature = (string) $request->get_header( 'stripe_signature' );
		if ( '' === $signature ) {
			// `WP_REST_Request::get_header()` translates the header name —
			// most setups receive `Stripe-Signature` and look it up via the
			// canonical lower-snake form, but some custom transports keep
			// the dashed form. Try the alternate key as a fallback.
			$signature = (string) $request->get_header( 'STRIPE-SIGNATURE' );
		}

		$verification = NVOOS_SaaS_Controller_Stripe_Webhook_Verifier::verify( $raw_body, $signature, $secret );
		if ( empty( $verification['ok'] ) ) {
			$audit->record(
				array(
					'channel' => 'stripe',
					'action'  => 'webhook_rejected',
					'status'  => 'error',
					'target'  => self::reason_to_target( $verification['reason'] ),
					'message' => self::reason_to_message( $verification['reason'] ),
				)
			);
			$status = self::reason_to_http_status( $verification['reason'] );
			return new WP_Error(
				'stripe_webhook_' . sanitize_key( $verification['reason'] ),
				self::reason_to_message( $verification['reason'] ),
				array( 'status' => $status )
			);
		}

		$existing = $event_bag->find_by_event_id( 'stripe', $verification['event_id'] );
		$entry    = $event_bag->record(
			array(
				'provider'         => 'stripe',
				'event_id'         => $verification['event_id'],
				'event_type'       => $verification['event_type'],
				'timestamp'        => $verification['timestamp'],
				'signature_status' => 'verified',
				'message'          => sprintf(
					/* translators: %s: Stripe event type, e.g. "invoice.paid" */
					__( 'Verified Stripe webhook (%s).', 'nvoos-saas-controller' ),
					$verification['event_type']
				),
			)
		);

		$is_duplicate = ( null !== $existing );

		// Only record the audit-log entry on first delivery. Stripe retries
		// the same event id on transient 5xx errors; double-recording would
		// flood the ring buffer.
		if ( ! $is_duplicate ) {
			$audit->record(
				array(
					'channel' => 'stripe',
					'action'  => 'webhook_received',
					'status'  => 'ok',
					'target'  => $verification['event_type'],
					'message' => sprintf(
						/* translators: %s: Stripe event id (e.g. evt_…) */
						__( 'Recorded Stripe webhook %s.', 'nvoos-saas-controller' ),
						$verification['event_id']
					),
				)
			);
		}

		$response = rest_ensure_response(
			array(
				'ok'         => true,
				'event_id'   => $verification['event_id'],
				'event_type' => $verification['event_type'],
				'duplicate'  => $is_duplicate,
				'recorded'   => null !== $entry,
			)
		);
		$response->set_status( 200 );
		return $response;
	}

	/**
	 * GET /webhooks/events — paginated, newest-first.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function route_get_webhook_events( $request ) {
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );
		$store  = NVOOS_SaaS_Controller_Webhook_Event_Store::instance();
		return rest_ensure_response(
			array(
				'entries' => $store->get_recent( $limit > 0 ? $limit : 50, $offset ),
				'total'   => $store->count(),
			)
		);
	}

	/**
	 * DELETE /webhooks/events — clear the webhook event store.
	 *
	 * Recorded as an audit-log entry of its own (action
	 * `clear_webhook_events`, channel `internal`) before the clear.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function route_clear_webhook_events() {
		$audit = NVOOS_SaaS_Controller_Audit_Log::instance();
		$audit->record(
			array(
				'channel' => 'internal',
				'action'  => 'clear_webhook_events',
				'status'  => 'ok',
				'message' => __( 'Webhook event store cleared.', 'nvoos-saas-controller' ),
			)
		);
		NVOOS_SaaS_Controller_Webhook_Event_Store::instance()->clear();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Map a verifier `reason` code to the audit-log `target` value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Verifier reason code.
	 * @return string
	 */
	protected static function reason_to_target( $reason ) {
		return is_string( $reason ) && '' !== $reason ? $reason : 'unknown';
	}

	/**
	 * Map a verifier `reason` code to a human-readable message.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Verifier reason code.
	 * @return string
	 */
	protected static function reason_to_message( $reason ) {
		switch ( (string) $reason ) {
			case 'missing_signature':
				return __( 'Missing Stripe-Signature header.', 'nvoos-saas-controller' );
			case 'malformed_signature':
				return __( 'Stripe-Signature header is malformed.', 'nvoos-saas-controller' );
			case 'empty_body':
				return __( 'Webhook body is empty.', 'nvoos-saas-controller' );
			case 'invalid_timestamp':
				return __( 'Webhook timestamp is invalid.', 'nvoos-saas-controller' );
			case 'timestamp_outside_tolerance':
				return __( 'Webhook timestamp is outside the tolerance window (replay protection).', 'nvoos-saas-controller' );
			case 'signature_mismatch':
				return __( 'Webhook signature did not match the stored secret.', 'nvoos-saas-controller' );
			case 'invalid_json':
				return __( 'Webhook body is not valid JSON.', 'nvoos-saas-controller' );
			case 'missing_secret':
				return __( 'Stripe webhook secret is not configured.', 'nvoos-saas-controller' );
			default:
				return __( 'Webhook verification failed.', 'nvoos-saas-controller' );
		}
	}

	/**
	 * Map a verifier `reason` code to an HTTP status.
	 *
	 * Signature failures and replays return 401 (unauthorised — the caller
	 * could not prove they hold the shared secret). Schema-level problems
	 * (malformed header, empty body, invalid JSON) return 400.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Verifier reason code.
	 * @return int
	 */
	protected static function reason_to_http_status( $reason ) {
		switch ( (string) $reason ) {
			case 'missing_signature':
			case 'signature_mismatch':
			case 'timestamp_outside_tolerance':
				return 401;
			case 'malformed_signature':
			case 'empty_body':
			case 'invalid_timestamp':
			case 'invalid_json':
				return 400;
			default:
				return 400;
		}
	}
}

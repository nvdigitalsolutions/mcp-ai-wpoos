<?php
/**
 * NV oOS Cloud — REST controller.
 *
 * Exposes plugin-side endpoints under `/mcp-ai-pro/v1/cloud/...` that the
 * admin UI uses to drive the connect/disconnect/top-up/balance/ledger flows.
 *
 * The controller is intentionally a thin proxy on top of the SaaS (which
 * holds the master OpenRouter key + Stripe customer); local state is the
 * connect token + cached balance only. All endpoints require
 * `manage_options`.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_REST_NV_Cloud_Controller' ) ) {

	/**
	 * REST controller for NV oOS Cloud admin operations.
	 */
	class WP_MCP_AI_REST_NV_Cloud_Controller {

		/**
		 * REST namespace.
		 *
		 * @var string
		 */
		const NAMESPACE_V1 = 'mcp-ai-pro/v1';

		/**
		 * Service helper.
		 *
		 * @var WP_MCP_AI_NV_Cloud_Service
		 */
		protected $service;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_NV_Cloud_Service|null $service Optional override.
		 */
		public function __construct( $service = null ) {
			$this->service = $service instanceof WP_MCP_AI_NV_Cloud_Service
				? $service
				: WP_MCP_AI_NV_Cloud_Service::get_instance();
		}

		/**
		 * Register routes.
		 */
		public function register_routes() {
			register_rest_route(
				self::NAMESPACE_V1,
				'/cloud/status',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'permission_check' ),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/cloud/connect',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'connect' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'token'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'account_id' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/cloud/disconnect',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'permission_check' ),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/cloud/refresh-balance',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'refresh_balance' ),
					'permission_callback' => array( $this, 'permission_check' ),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/cloud/topup-url',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_topup_url' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'amount_usd' => array(
							'required'          => true,
							'type'              => 'number',
							'sanitize_callback' => 'floatval',
						),
					),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/cloud/ledger',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_ledger' ),
					'permission_callback' => array( $this, 'permission_check' ),
				)
			);

			register_rest_route(
				self::NAMESPACE_V1,
				'/cloud/prefs',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_prefs' ),
					'permission_callback' => array( $this, 'permission_check' ),
				)
			);
		}

		/**
		 * Permission gate.
		 *
		 * @return bool
		 */
		public function permission_check() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * GET /cloud/status — connection meta + cached balance + prefs.
		 *
		 * @return WP_REST_Response
		 */
		public function get_status() {
			$cached = $this->service->get_cached_balance();
			return rest_ensure_response(
				array(
					'connected'     => $this->service->is_connected(),
					'meta'          => $this->service->get_connection_meta(),
					'balance_usd'   => (float) $cached['balance'],
					'currency'      => $cached['currency'],
					'refreshed_at'  => (int) $cached['refreshed_at'],
					'low_balance'   => (float) $cached['balance'] < WP_MCP_AI_NV_Cloud_Service::LOW_BALANCE_THRESHOLD_USD,
					'markup_rate'   => WP_MCP_AI_NV_Cloud_Service::MARKUP_RATE,
					'min_topup_usd' => WP_MCP_AI_NV_Cloud_Service::DEFAULT_MIN_TOPUP_USD,
					'prefs'         => $this->service->get_prefs(),
				)
			);
		}

		/**
		 * POST /cloud/connect — store a freshly-issued connect token.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function connect( $request ) {
			$token      = (string) $request->get_param( 'token' );
			$account_id = (string) $request->get_param( 'account_id' );

			$ok = $this->service->save_connection( $token, array( 'account_id' => $account_id ) );
			if ( ! $ok ) {
				return new WP_Error(
					'wp_mcp_ai_nv_cloud_invalid_token',
					__( 'Connect token was empty or invalid.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Best-effort initial balance fetch — failure is non-fatal.
			$this->refresh_balance_now();

			return rest_ensure_response( array( 'connected' => true ) );
		}

		/**
		 * POST /cloud/disconnect — wipe local state. Best-effort SaaS revoke.
		 *
		 * @return WP_REST_Response
		 */
		public function disconnect() {
			$token = $this->service->get_connect_token();
			if ( '' !== $token ) {
				$this->call_saas( 'POST', '/account/revoke', array() );
			}
			$this->service->forget_connection();
			return rest_ensure_response( array( 'connected' => false ) );
		}

		/**
		 * POST /cloud/refresh-balance — pull the live balance from the SaaS.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function refresh_balance() {
			$result = $this->refresh_balance_now();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( $result );
		}

		/**
		 * Issue a SaaS request to refresh the cached balance.
		 *
		 * @return array|WP_Error
		 */
		public function refresh_balance_now() {
			if ( ! $this->service->is_connected() ) {
				return new WP_Error(
					'wp_mcp_ai_nv_cloud_not_connected',
					__( 'NV oOS Cloud is not connected.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$response = $this->call_saas( 'GET', '/account/balance', array() );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$balance  = isset( $response['balance_usd'] ) ? (float) $response['balance_usd'] : 0.0;
			$currency = isset( $response['currency'] ) ? (string) $response['currency'] : 'USD';

			$this->service->set_cached_balance( $balance, $currency );

			return array(
				'balance_usd' => $balance,
				'currency'    => $currency,
			);
		}

		/**
		 * POST /cloud/topup-url — request a Stripe Checkout session URL.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public function create_topup_url( $request ) {
			if ( ! $this->service->is_connected() ) {
				return new WP_Error(
					'wp_mcp_ai_nv_cloud_not_connected',
					__( 'NV oOS Cloud is not connected.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$amount = (float) $request->get_param( 'amount_usd' );
			$min    = WP_MCP_AI_NV_Cloud_Service::DEFAULT_MIN_TOPUP_USD;
			if ( $amount < $min ) {
				return new WP_Error(
					'wp_mcp_ai_nv_cloud_topup_too_small',
					sprintf(
						/* translators: %s: minimum top-up dollar amount. */
						__( 'Minimum top-up is %s USD.', 'mcp-ai-wpoos' ),
						number_format_i18n( $min, 2 )
					),
					array( 'status' => 400 )
				);
			}

			$processor_fee = $this->service->compute_stripe_passthrough( $amount );

			$response = $this->call_saas(
				'POST',
				'/account/topup',
				array(
					'amount_usd'    => $amount,
					'processor_fee' => $processor_fee,
					'return_url'    => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=wp-mcp-ai-nv-cloud&topup=success' ) : '',
					'cancel_url'    => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=wp-mcp-ai-nv-cloud&topup=cancel' ) : '',
					'site_url'      => function_exists( 'home_url' ) ? esc_url_raw( home_url( '/' ) ) : '',
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return rest_ensure_response(
				array(
					'checkout_url'  => isset( $response['checkout_url'] ) ? esc_url_raw( $response['checkout_url'] ) : '',
					'amount_usd'    => $amount,
					'processor_fee' => $processor_fee,
				)
			);
		}

		/**
		 * GET /cloud/ledger — return the local mirror of recent activity.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public function get_ledger( $request ) {
			$limit = (int) $request->get_param( 'limit' );
			if ( $limit <= 0 ) {
				$limit = 50;
			}
			return rest_ensure_response(
				array(
					'entries' => $this->service->get_ledger( $limit ),
				)
			);
		}

		/**
		 * POST /cloud/prefs — save user preferences (auto-topup, default flag).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public function save_prefs( $request ) {
			$prefs = array(
				'use_as_default'        => (bool) $request->get_param( 'use_as_default' ),
				'auto_topup_enabled'    => (bool) $request->get_param( 'auto_topup_enabled' ),
				'auto_topup_amount_usd' => (float) $request->get_param( 'auto_topup_amount_usd' ),
			);
			$this->service->save_prefs( $prefs );
			return rest_ensure_response( array( 'prefs' => $this->service->get_prefs() ) );
		}

		/**
		 * Make an HTTP call to the SaaS, signed with the connect token.
		 *
		 * @param string $method  HTTP method.
		 * @param string $path    Path beneath the base URL.
		 * @param array  $payload JSON payload (POST) or query args (GET).
		 * @return array|WP_Error Decoded JSON body on 2xx, WP_Error otherwise.
		 */
		protected function call_saas( $method, $path, array $payload ) {
			$method = strtoupper( $method );
			$url    = $this->service->get_base_url() . $path;
			$token  = $this->service->get_connect_token();

			$args = array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'X-NV-Site-Url' => function_exists( 'home_url' ) ? esc_url_raw( home_url( '/' ) ) : '',
				),
				'timeout' => 30,
			);

			if ( 'GET' === $method ) {
				if ( ! empty( $payload ) ) {
					// `add_query_arg` already URL-encodes; passing rawurlencode-mapped
					// values here would double-encode them.
					$url = add_query_arg( $payload, $url );
				}
			} else {
				$args['body'] = wp_json_encode( $payload );
			}

			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( $code < 200 || $code >= 300 ) {
				$message = is_array( $data ) && isset( $data['error']['message'] )
					? $data['error']['message']
					: __( 'NV oOS Cloud SaaS returned an error.', 'mcp-ai-wpoos' );
				return new WP_Error(
					'wp_mcp_ai_nv_cloud_saas_error',
					$message,
					array(
						'status' => $code,
						'body'   => $data,
					)
				);
			}

			return is_array( $data ) ? $data : array();
		}
	}
}

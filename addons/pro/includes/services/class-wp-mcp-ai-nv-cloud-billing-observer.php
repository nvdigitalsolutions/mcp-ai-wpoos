<?php
/**
 * NV oOS Cloud — Billing observer.
 *
 * Hooks the base `wp_mcp_ai_cost_calculated` action to record per-request
 * wholesale + 7% service-fee ledger entries. The ledger is rendered in the
 * admin settings page so customers see exactly what the markup was on every
 * chat turn.
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

if ( ! class_exists( 'WP_MCP_AI_NV_Cloud_Billing_Observer' ) ) {

	/**
	 * Observes cost-calculated events for NV oOS Cloud requests.
	 */
	class WP_MCP_AI_NV_Cloud_Billing_Observer {

		/**
		 * Service helper.
		 *
		 * @var WP_MCP_AI_NV_Cloud_Service
		 */
		protected $service;

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_NV_Cloud_Billing_Observer|null
		 */
		protected static $instance = null;

		/**
		 * Bootstrap singleton + register hook.
		 *
		 * @param WP_MCP_AI_NV_Cloud_Service|null $service Optional override.
		 * @return WP_MCP_AI_NV_Cloud_Billing_Observer
		 */
		public static function init( $service = null ) {
			if ( null === self::$instance ) {
				self::$instance = new self( $service );
				add_action( 'wp_mcp_ai_cost_calculated', array( self::$instance, 'on_cost_calculated' ), 10, 5 );
			}
			return self::$instance;
		}

		/**
		 * Reset (tests only).
		 */
		public static function reset_instance() {
			if ( null !== self::$instance ) {
				remove_action( 'wp_mcp_ai_cost_calculated', array( self::$instance, 'on_cost_calculated' ), 10 );
			}
			self::$instance = null;
		}

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
		 * Handle the `wp_mcp_ai_cost_calculated` action.
		 *
		 * Only ledger-records the request when the assistant routed through
		 * NV oOS Cloud (`provider === 'nv_hosted'` in the request payload, or
		 * the response carries `nv_cloud_wholesale_usd`).
		 *
		 * @param array $cost_data    Cost data (cost_usd, prompt/completion tokens, etc.).
		 * @param int   $assistant_id Assistant post ID.
		 * @param int   $user_id      Acting user ID.
		 * @param array $response     Provider response (may include `nv_cloud_wholesale_usd`).
		 * @param array $request      Original request (for provider/model lookup).
		 */
		public function on_cost_calculated( $cost_data, $assistant_id, $user_id, $response, $request ) {
			if ( ! is_array( $cost_data ) ) {
				return;
			}

			$is_nv_hosted = false;
			if ( is_array( $request ) && isset( $request['provider'] ) && WP_MCP_AI_NV_Cloud_Client::PROVIDER_SLUG === $request['provider'] ) {
				$is_nv_hosted = true;
			}
			if ( is_array( $response ) && isset( $response['nv_cloud_wholesale_usd'] ) ) {
				$is_nv_hosted = true;
			}

			if ( ! $is_nv_hosted ) {
				return;
			}

			// Determine wholesale and total. Prefer the gateway-reported wholesale.
			$total_charged = isset( $cost_data['cost_usd'] ) ? (float) $cost_data['cost_usd'] : 0.0;

			if ( is_array( $response ) && isset( $response['nv_cloud_wholesale_usd'] ) ) {
				$wholesale = (float) $response['nv_cloud_wholesale_usd'];
			} else {
				// Fallback: derive wholesale from total-charged using the markup constant.
				// total = wholesale × (1 + markup) → wholesale = total / (1 + markup).
				$divisor   = 1.0 + WP_MCP_AI_NV_Cloud_Service::MARKUP_RATE;
				$wholesale = $divisor > 0 ? $total_charged / $divisor : 0.0;
			}

			$service_fee = $this->service->compute_markup( $wholesale );
			$total       = $wholesale + $service_fee;

			$model = '';
			if ( is_array( $response ) && ! empty( $response['model'] ) ) {
				$model = (string) $response['model'];
			} elseif ( is_array( $request ) && ! empty( $request['model'] ) ) {
				$model = (string) $request['model'];
			}

			$this->service->append_ledger_entry(
				array(
					'kind'            => 'usage',
					'wholesale_usd'   => $wholesale,
					'service_fee_usd' => $service_fee,
					'total_usd'       => $total,
					'model'           => $model,
					'assistant_id'    => absint( $assistant_id ),
					'timestamp'       => time(),
				)
			);

			/**
			 * Fires after a chat turn has been ledger-recorded.
			 *
			 * @since 1.7.0
			 *
			 * @param float $wholesale   Wholesale USD cost.
			 * @param float $service_fee Service-fee USD (7% markup).
			 * @param float $total       Total billed to the customer.
			 * @param array $cost_data   Original base cost data.
			 */
			do_action( 'wp_mcp_ai_nv_cloud_request_billed', $wholesale, $service_fee, $total, $cost_data );

			// Drift the cached balance locally so the UI reflects spend
			// immediately even between scheduled refreshes.
			$cached = $this->service->get_cached_balance();
			if ( ! empty( $cached['balance'] ) ) {
				$new_balance = max( 0.0, (float) $cached['balance'] - $total );
				$this->service->set_cached_balance( $new_balance, isset( $cached['currency'] ) ? $cached['currency'] : 'USD' );
			}
		}
	}
}

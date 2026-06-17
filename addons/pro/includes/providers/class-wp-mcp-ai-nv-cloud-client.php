<?php
/**
 * NV oOS Cloud — HTTP client.
 *
 * Talks to the NV oOS Cloud SaaS, which fronts OpenRouter via a Cloudflare AI
 * Gateway. Wire-format is OpenAI-compatible so we can reuse the OpenRouter
 * client's request/response pipeline 1:1; we only differ in:
 *
 *  - Base URL (NV oOS Cloud gateway, default `https://nvoos.cloud/v1`).
 *  - Auth header (NV Connect Token, not an OpenRouter API key).
 *  - Custom response headers used to record the wholesale cost so the local
 *    ledger can show the customer the exact 7% fee on every chat turn.
 *
 * The Cloudflare Worker holds the master OpenRouter key (it never leaves the
 * SaaS infrastructure) and debits the prepaid wallet at upstream cost × 1.07.
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

if ( ! class_exists( 'WP_MCP_AI_NV_Cloud_Client' ) ) {

	/**
	 * Thin OpenAI-compatible client that talks to the NV oOS Cloud gateway.
	 *
	 * Subclassing `WP_MCP_AI_OpenRouter_Client` keeps the request payload,
	 * tool-calling, JSON-mode and error-handling logic in lockstep with the
	 * upstream client and minimises the test surface.
	 */
	class WP_MCP_AI_NV_Cloud_Client extends WP_MCP_AI_OpenRouter_Client {

		/**
		 * Service slug used by the router.
		 *
		 * @var string
		 */
		const PROVIDER_SLUG = 'nv_hosted';

		/**
		 * User-Agent string sent to the gateway.
		 *
		 * @var string
		 */
		const USER_AGENT = 'WP-MCP-AI-NV-Cloud-Client/1.0';

		/**
		 * Wholesale cost recorded from the most recent response. Used by the
		 * billing observer when it fires `wp_mcp_ai_cost_calculated`.
		 *
		 * @var float|null
		 */
		protected $last_wholesale_usd = null;

		/**
		 * Service helper.
		 *
		 * @var WP_MCP_AI_NV_Cloud_Service
		 */
		protected $service;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_NV_Cloud_Service|null $service Optional service.
		 */
		public function __construct( $service = null ) {
			$this->service = $service instanceof WP_MCP_AI_NV_Cloud_Service
				? $service
				: WP_MCP_AI_NV_Cloud_Service::get_instance();
		}

		/**
		 * Override: connect token is the auth credential here.
		 *
		 * @return string
		 */
		public function get_api_key() {
			return $this->service->get_connect_token();
		}

		/**
		 * Override: NV oOS Cloud base URL.
		 *
		 * @return string
		 */
		public function get_base_url() {
			return $this->service->get_base_url();
		}

		/**
		 * Override: default model. The SaaS forwards to OpenRouter's `auto`
		 * router unless the assistant overrides `model`.
		 *
		 * @return string
		 */
		public function get_model() {
			return 'openrouter/auto';
		}

		/**
		 * Override: site/app metadata headers — the gateway uses them for
		 * per-site abuse review and audit logs.
		 *
		 * @return string
		 */
		public function get_site_url() {
			return function_exists( 'home_url' ) ? esc_url_raw( home_url( '/' ) ) : '';
		}

		/**
		 * Override.
		 *
		 * @return string
		 */
		public function get_app_title() {
			$title = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			return '' !== $title ? sanitize_text_field( $title ) : 'NV oOS Cloud Site';
		}

		/**
		 * Build request headers — same shape as OpenRouter but with an extra
		 * `X-NV-Connect-Token` so the gateway can audit-log the originating
		 * site even if a customer ever proxies through their own forward.
		 *
		 * @param string $api_key The connect token.
		 * @return array
		 */
		protected function build_request_headers( $api_key ) {
			$headers                       = parent::build_request_headers( $api_key );
			$headers['User-Agent']         = self::USER_AGENT;
			$headers['X-NV-Connect-Token'] = $api_key;
			$headers['X-NV-Site-Url']      = $this->get_site_url();

			/**
			 * Filter NV oOS Cloud request headers.
			 *
			 * @since 1.7.0
			 *
			 * @param array  $headers Headers.
			 * @param string $api_key Connect token.
			 */
			return apply_filters( 'wp_mcp_ai_nv_cloud_request_headers', $headers, $api_key );
		}

		/**
		 * Send a chat-completion request. Wraps the parent implementation so
		 * we can capture the gateway's `X-NV-Wholesale-Cost` response header
		 * — the local ledger uses it to surface "Service fee (7%)" line items.
		 *
		 * @param array $messages Messages.
		 * @param array $options  Options.
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			if ( '' === $this->get_api_key() ) {
				return new WP_Error(
					'wp_mcp_ai_nv_cloud_not_connected',
					__( 'NV oOS Cloud is not connected. Click "Connect NV oOS Cloud" in the settings to enable hosted token access.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$result = parent::create_chat_completion( $messages, $options );

			// The Cloudflare Worker is expected to embed the wholesale cost in
			// the response body under `nv_cloud.wholesale_usd`. We surface it
			// through the same `nv_cloud_wholesale_usd` key the cost-observer
			// reads from. Body-based metadata is more reliable than custom
			// response headers (some hosts strip non-allowlisted headers) and
			// avoids the global `http_response` filter footgun.
			if ( is_array( $result ) ) {
				if ( ! empty( $result['raw']['nv_cloud']['wholesale_usd'] ) ) {
					$wholesale                        = (float) $result['raw']['nv_cloud']['wholesale_usd'];
					$result['nv_cloud_wholesale_usd'] = $wholesale;
					$this->last_wholesale_usd         = $wholesale;
				} elseif ( ! empty( $result['raw']['usage']['nv_cloud_wholesale_usd'] ) ) {
					$wholesale                        = (float) $result['raw']['usage']['nv_cloud_wholesale_usd'];
					$result['nv_cloud_wholesale_usd'] = $wholesale;
					$this->last_wholesale_usd         = $wholesale;
				}
			}

			return $result;
		}

		/**
		 * Most-recent wholesale cost in USD. Useful for tests and the cost
		 * dashboard observer.
		 *
		 * @return float|null
		 */
		public function get_last_wholesale_usd() {
			return $this->last_wholesale_usd;
		}
	}
}

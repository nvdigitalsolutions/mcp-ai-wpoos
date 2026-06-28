<?php
/**
 * FlowHub API Client.
 *
 * Provides a wrapper around the FlowHub REST API (https://api.flowhub.co/v0/).
 * Authentication uses clientId and key headers populated from
 * the FlowHub toolkit settings (encrypted at rest).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_FlowHub_Client' ) ) {

	/**
	 * FlowHub REST API client.
	 *
	 * Handles API communication with FlowHub POS/inventory systems.
	 * WordPress integration and capability checks are handled by tool classes.
	 *
	 * @since 1.2.0
	 */
	class WP_MCP_AI_FlowHub_Client {

		/**
		 * FlowHub API base URL.
		 *
		 * @var string
		 */
		const DEFAULT_API_BASE_URL = 'https://api.flowhub.co/v0/';

		/**
		 * Maximum response body size in bytes (5 MB).
		 *
		 * @var int
		 */
		const MAX_RESPONSE_SIZE = 5242880;

		/**
		 * Default request timeout in seconds.
		 *
		 * @var int
		 */
		const DEFAULT_TIMEOUT = 30;

		/**
		 * Maximum number of retry attempts for transient errors.
		 *
		 * @var int
		 */
		const MAX_RETRIES = 3;

		/**
		 * Minimum delay between API requests in microseconds (200ms = 5 req/s).
		 *
		 * @var int
		 */
		const RATE_LIMIT_DELAY_US = 200000;

		/**
		 * FlowHub API client ID.
		 *
		 * @var string
		 */
		protected $client_id = '';

		/**
		 * FlowHub API key.
		 *
		 * @var string
		 */
		protected $api_key = '';

		/**
		 * FlowHub location ID for location-scoped endpoints.
		 *
		 * @var string
		 */
		protected $location_id = '';

		/**
		 * FlowHub API base URL.
		 *
		 * @var string
		 */
		protected $base_url = '';

		/**
		 * Request timeout in seconds.
		 *
		 * @var int
		 */
		protected $timeout = 30;

		/**
		 * Last HTTP response code.
		 *
		 * @var int|null
		 */
		protected $last_response_code = null;

		/**
		 * Last error message.
		 *
		 * @var string
		 */
		protected $last_error = '';

		/**
		 * Last request timestamp for rate limiting.
		 *
		 * @var float
		 */
		protected $last_request_time = 0.0;

		/**
		 * Constructor.
		 *
		 * @since 1.2.0
		 *
		 * @param string $client_id   FlowHub API client ID.
		 * @param string $api_key     FlowHub API key.
		 * @param string $base_url    Optional. API base URL override.
		 * @param int    $timeout     Optional. Request timeout in seconds.
		 * @param string $location_id Optional. FlowHub location ID for location-scoped endpoints.
		 */
		public function __construct( $client_id = '', $api_key = '', $base_url = '', $timeout = null, $location_id = '' ) {
			$this->client_id   = $client_id;
			$this->api_key     = $api_key;
			$this->location_id = $location_id;
			$this->base_url    = ! empty( $base_url ) ? trailingslashit( $base_url ) : self::DEFAULT_API_BASE_URL;
			$this->timeout     = null !== $timeout ? absint( $timeout ) : self::DEFAULT_TIMEOUT;
		}

		/**
		 * Create a client instance from toolkit settings.
		 *
		 * @since 1.2.0
		 *
		 * @return WP_MCP_AI_FlowHub_Client|WP_Error Client instance or WP_Error if credentials missing.
		 */
		public static function from_settings() {
			$settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );

			$client_id   = isset( $settings['client_id'] ) ? wp_unslash( $settings['client_id'] ) : '';
			$api_key     = isset( $settings['api_key'] ) ? wp_unslash( $settings['api_key'] ) : '';
			$base_url    = isset( $settings['api_base_url'] ) ? wp_unslash( $settings['api_base_url'] ) : '';
			$location_id = isset( $settings['location_id'] ) ? wp_unslash( $settings['location_id'] ) : '';

			if ( empty( $client_id ) || empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_missing_credentials',
					__( 'FlowHub API credentials are not configured. Please set up your client ID and API key in the FlowHub Toolkit settings.', 'mcp-ai-wpoos-pro' )
				);
			}

			return new self( $client_id, $api_key, $base_url, null, $location_id );
		}

		// ------------------------------------------------------------------ //
		// Public API methods                                                   //
		// ------------------------------------------------------------------ //

		/**
		 * Check connection health by making a lightweight API call.
		 *
		 * @since 1.2.0
		 *
		 * @return bool|WP_Error True if connection is healthy, WP_Error on failure.
		 */
		public function check_connection() {
			$endpoint = ! empty( $this->location_id )
				? 'locations/' . rawurlencode( $this->location_id ) . '/inventoryNonZero'
				: 'inventoryNonZero';
			$result = $this->request( $endpoint, array( 'limit' => 1 ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;
		}

		/**
		 * Get inventory items (single page).
		 *
		 * @since 1.2.0
		 *
		 * @param array $params Query parameters (limit, offset, etc.).
		 * @return array|WP_Error Response data array or WP_Error.
		 */
		public function get_inventory( $params = array() ) {
			$defaults         = array(
				'limit'  => 100,
				'offset' => 0,
			);
			$params           = wp_parse_args( $params, $defaults );
			$params['limit']  = min( absint( $params['limit'] ), 100 );
			$params['offset'] = absint( $params['offset'] );

			$endpoint = ! empty( $this->location_id )
				? 'locations/' . rawurlencode( $this->location_id ) . '/inventoryNonZero'
				: 'inventoryNonZero';
			$response = $this->request( $endpoint, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// The FlowHub API response contains a "data" key with an array of items
			// and a "meta" key with pagination info (total count, etc.).
			if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
				return array(
					'data' => array(),
					'meta' => isset( $response['meta'] ) ? $response['meta'] : array(),
					'page' => 1,
				);
			}

			return array(
				'data' => $response['data'],
				'meta' => isset( $response['meta'] ) ? $response['meta'] : array(),
				'page' => isset( $params['offset'] ) ? intval( $params['offset'] / $params['limit'] ) + 1 : 1,
			);
		}

		/**
		 * Get all inventory items across all pages.
		 *
		 * Paginates through the FlowHub API until all non-zero inventory items
		 * are retrieved. Accepts an optional progress callback.
		 *
		 * @since 1.2.0
		 *
		 * @param callable|null $progress Optional. Callback invoked with (page, total_pages, item_count).
		 * @param int           $per_page Optional. Items per page (max 100).
		 * @return array|WP_Error All inventory items or WP_Error.
		 */
		public function get_all_inventory( $progress = null, $per_page = 100 ) {
			$per_page    = min( absint( $per_page ), 100 );
			$all_items   = array();
			$offset      = 0;
			$page        = 1;
			$total_pages = 1;

			do {
				$result = $this->get_inventory(
					array(
						'limit'  => $per_page,
						'offset' => $offset,
					)
				);

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				if ( ! empty( $result['data'] ) ) {
					$all_items = array_merge( $all_items, $result['data'] );
				}

				// Determine total pages from meta if available.
				if ( isset( $result['meta']['total'] ) ) {
					$total_pages = max( 1, ceil( absint( $result['meta']['total'] ) / $per_page ) );
				}

				if ( is_callable( $progress ) ) {
					call_user_func( $progress, $page, $total_pages, count( $all_items ) );
				}

				$offset += $per_page;
				++$page;

				// Safety: stop if we got fewer items than requested (last page).
				if ( count( $result['data'] ) < $per_page ) {
					break;
				}

				// Safety: hard cap at 500 pages (50,000 items).
				if ( $page > 500 ) {
					break;
				}
			} while ( $page <= $total_pages );

			return $all_items;
		}

		/**
		 * Get a single product from FlowHub by product ID.
		 *
		 * @since 1.2.0
		 *
		 * @param string $product_id FlowHub product ID.
		 * @return array|WP_Error Product data or WP_Error.
		 */
		public function get_product( $product_id ) {
			$product_id = sanitize_text_field( $product_id );

			// FlowHub v0 API doesn't have a dedicated single-product endpoint.
			// We filter inventoryNonZero by productId query param if supported,
			// otherwise fall back to searching the full inventory.
			$all_items = $this->get_all_inventory();

			if ( is_wp_error( $all_items ) ) {
				return $all_items;
			}

			foreach ( $all_items as $item ) {
				if ( isset( $item['productId'] ) && $item['productId'] === $product_id ) {
					return $item;
				}
			}

			return new WP_Error(
				'wp_mcp_ai_flowhub_product_not_found',
				sprintf(
					/* translators: %s: product ID */
					__( 'Product with ID "%s" was not found in FlowHub inventory.', 'mcp-ai-wpoos-pro' ),
					$product_id
				)
			);
		}

		/**
		 * Get distinct locations from inventory data.
		 *
		 * FlowHub v0 API does not have a dedicated locations endpoint.
		 * We extract unique locations from inventory items.
		 *
		 * @since 1.2.0
		 *
		 * @return array|WP_Error Array of locations or WP_Error.
		 */
		public function get_locations() {
			$all_items = $this->get_all_inventory();

			if ( is_wp_error( $all_items ) ) {
				return $all_items;
			}

			$locations = array();
			$seen      = array();

			foreach ( $all_items as $item ) {
				$location_id = isset( $item['locationId'] ) ? $item['locationId'] : '';
				if ( empty( $location_id ) || isset( $seen[ $location_id ] ) ) {
					continue;
				}

				$seen[ $location_id ] = true;
				$locations[]          = array(
					'location_id'   => $location_id,
					'location_name' => isset( $item['locationName'] ) ? $item['locationName'] : '',
				);
			}

			return $locations;
		}

		/**
		 * Get the last error message.
		 *
		 * @since 1.2.0
		 *
		 * @return string
		 */
		public function get_last_error() {
			return $this->last_error;
		}

		/**
		 * Get the last HTTP response code.
		 *
		 * @since 1.2.0
		 *
		 * @return int|null
		 */
		public function get_last_response_code() {
			return $this->last_response_code;
		}

		// ------------------------------------------------------------------ //
		// Internal HTTP helpers                                                //
		// ------------------------------------------------------------------ //

		/**
		 * Make a GET request to the FlowHub API.
		 *
		 * @since 1.2.0
		 *
		 * @param string $endpoint API endpoint path (e.g. 'inventoryNonZero').
		 * @param array  $params   Query parameters.
		 * @return array|WP_Error Parsed JSON response or WP_Error.
		 */
		protected function request( $endpoint, $params = array() ) {
			// Rate limiting.
			$this->throttle();

			// Track API request count for telemetry.
			$this->track_api_request();

			$url = $this->base_url . ltrim( $endpoint, '/' );

			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}

			$args = array(
				'timeout'     => $this->timeout,
				'redirection' => 3,
				'httpversion' => '1.1',
				'headers'     => array(
					'clientId'     => $this->client_id,
					'key'          => $this->api_key,
					'Accept'       => 'application/json',
				),
			);

			$attempt  = 0;
			$response = null;

			while ( $attempt < self::MAX_RETRIES ) {
				$response = wp_remote_get( $url, $args );
				++$attempt;

				if ( is_wp_error( $response ) ) {
					$this->last_error         = $response->get_error_message();
					$this->last_response_code = null;
					continue; // Retry on network errors.
				}

				$this->last_response_code = wp_remote_retrieve_response_code( $response );
				$body                     = wp_remote_retrieve_body( $response );

				// Check response size.
				if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
					$this->last_error = __( 'Response body exceeds maximum allowed size.', 'mcp-ai-wpoos-pro' );
					return new WP_Error(
						'wp_mcp_ai_flowhub_response_too_large',
						$this->last_error
					);
				}

				// Handle HTTP errors.
				$code = $this->last_response_code;
				if ( $code >= 500 ) {
					// Server error — retry.
					$this->last_error = sprintf(
						/* translators: %d: HTTP status code */
						__( 'FlowHub API server error (HTTP %d).', 'mcp-ai-wpoos-pro' ),
						$code
					);
					continue;
				}

				if ( 429 === $code ) {
					// Rate limited — wait and retry.
					$this->last_error = __( 'FlowHub API rate limit exceeded.', 'mcp-ai-wpoos-pro' );
					sleep( 2 );
					continue;
				}

				if ( 401 === $code || 403 === $code ) {
					$this->last_error = __( 'FlowHub API authentication failed. Check your client ID and API key.', 'mcp-ai-wpoos-pro' );
					return new WP_Error(
						'wp_mcp_ai_flowhub_auth_failed',
						$this->last_error
					);
				}

				if ( $code < 200 || $code >= 300 ) {
					$this->last_error = sprintf(
						/* translators: %d: HTTP status code */
						__( 'FlowHub API returned unexpected status (HTTP %d).', 'mcp-ai-wpoos-pro' ),
						$code
					);
					return new WP_Error(
						'wp_mcp_ai_flowhub_http_error',
						$this->last_error
					);
				}

				// Parse JSON.
				$data = json_decode( $body, true );

				if ( null === $data && json_last_error() !== JSON_ERROR_NONE ) {
					$this->last_error = sprintf(
						/* translators: %s: JSON parse error */
						__( 'Failed to parse FlowHub API response: %s', 'mcp-ai-wpoos-pro' ),
						json_last_error_msg()
					);
					return new WP_Error(
						'wp_mcp_ai_flowhub_json_error',
						$this->last_error
					);
				}

				$this->last_error = '';
				return $data;
			}

			// Exhausted retries.
			if ( is_null( $response ) ) {
				$this->last_error = __( 'Failed to connect to FlowHub API after multiple attempts.', 'mcp-ai-wpoos-pro' );
			}

			return new WP_Error(
				'wp_mcp_ai_flowhub_request_failed',
				$this->last_error
			);
		}

		/**
		 * Enforce rate limiting between API requests.
		 *
		 * Sleeps if the last request was less than RATE_LIMIT_DELAY_US ago.
		 *
		 * @since 1.2.0
		 */
		protected function throttle() {
			$now     = microtime( true );
			$elapsed = $now - $this->last_request_time;

			if ( $elapsed < ( self::RATE_LIMIT_DELAY_US / 1000000 ) ) {
				$sleep = ( self::RATE_LIMIT_DELAY_US / 1000000 ) - $elapsed;
				usleep( intval( $sleep * 1000000 ) );
			}

			$this->last_request_time = microtime( true );
		}

		/**
		 * Track API request count for telemetry.
		 *
		 * @since 1.4.0
		 */
		protected function track_api_request() {
			$today  = gmdate( 'Y-m-d' );
			$stored = get_option( 'wp_mcp_ai_flowhub_api_requests', array() );

			if ( ! isset( $stored['date'] ) || $stored['date'] !== $today ) {
				$stored = array(
					'date'      => $today,
					'count'     => 0,
					'last_hour' => array(),
				);
			}

			++$stored['count'];

			// Sliding window for last hour.
			$now                   = time();
			$stored['last_hour'][] = $now;
			$stored['last_hour']   = array_filter(
				$stored['last_hour'],
				function ( $t ) use ( $now ) {
					return ( $now - $t ) < HOUR_IN_SECONDS;
				}
			);

			update_option( 'wp_mcp_ai_flowhub_api_requests', $stored );
		}

		/**
		 * Get API request statistics.
		 *
		 * @since 1.4.0
		 *
		 * @return array Request stats.
		 */
		public static function get_api_stats() {
			$stored   = get_option( 'wp_mcp_ai_flowhub_api_requests', array() );
			$today    = gmdate( 'Y-m-d' );
			$is_today = isset( $stored['date'] ) && $stored['date'] === $today;

			$last_sync_duration = get_option( 'wp_mcp_ai_flowhub_last_sync_duration', '' );
			$rate_limit_hits    = absint( get_option( 'wp_mcp_ai_flowhub_api_rate_limit_hits', 0 ) );

			return array(
				'today'              => $is_today ? absint( isset( $stored['count'] ) ? $stored['count'] : 0 ) : 0,
				'last_hour'          => $is_today ? count( isset( $stored['last_hour'] ) ? $stored['last_hour'] : array() ) : 0,
				'last_sync_duration' => $last_sync_duration,
				'rate_limit_hits'    => $rate_limit_hits,
			);
		}
	}
}

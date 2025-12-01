<?php
/**
 * Tool that retrieves product pricing from wholesale club websites using Crawl4AI web search.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Web_Search' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php';
}

/**
 * Finds pricing information for BJ's, Sam's Club, and Costco by querying Crawl4AI's web search endpoint.
 */
class WP_MCP_AI_Tool_Crawl4AI_Price_Lookup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DEFAULT_MAX_RESULTS = 5;

	/**
	 * Brands that should be queried for pricing data.
	 *
	 * @return array[]
	 */
	protected function get_brands() {
		return array(
			array(
				'slug'   => 'bjs',
				'name'   => __( "BJ's", 'wp-mcp-ai' ),
				'domain' => 'bjs.com',
			),
			array(
				'slug'   => 'sams-club',
				'name'   => __( "Sam's Club", 'wp-mcp-ai' ),
				'domain' => 'samsclub.com',
			),
			array(
				'slug'   => 'costco',
				'name'   => __( 'Costco', 'wp-mcp-ai' ),
				'domain' => 'costco.com',
			),
		);
	}

	/**
	 * Determine whether the Crawl4AI web search endpoint is configured.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( '' !== self::resolve_base_url( $settings ) ) {
			return true;
		}

		/**
		 * Filters whether the local web search fallback should be enabled.
		 *
		 * @since 1.7.0
		 *
		 * @param bool  $enabled  Whether the local fallback is enabled. Default true.
		 * @param array $settings Plugin settings array.
		 */
		$local_enabled = apply_filters( 'wp_mcp_ai_crawl4ai_price_lookup_local_enabled', true, $settings );

		return (bool) $local_enabled;
	}

	/**
	 * Reason displayed when the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The wholesale club price lookup tool requires Crawl4AI web search or the local fallback to be enabled.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'crawl4ai_price_lookup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Wholesale Club Price Lookup', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( "Uses Crawl4AI's web search endpoint to gather the latest pricing from BJ's, Sam's Club, and Costco.", 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product'     => array(
					'type'        => 'string',
					'description' => __( 'Product name or keywords to search for.', 'wp-mcp-ai' ),
				),
				'max_results' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of web search results to inspect per store (1-10).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => self::DEFAULT_MAX_RESULTS,
				),
			),
			'required'             => array( 'product' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_unavailable', __( 'Crawl4AI web search is not available on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search wholesale club pricing.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$product = isset( $arguments['product'] ) ? trim( sanitize_text_field( $arguments['product'] ) ) : '';

		if ( '' === $product ) {
			return new WP_Error( 'wp_mcp_ai_missing_product', __( 'A product name or keyword is required.', 'wp-mcp-ai' ) );
		}

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : self::DEFAULT_MAX_RESULTS;
		if ( $max_results <= 0 ) {
			$max_results = self::DEFAULT_MAX_RESULTS;
		}

		$max_results = min( 10, $max_results );

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$base_url = self::resolve_base_url( $settings, $context );

		$results = array();

		if ( '' === $base_url ) {
			foreach ( $this->get_brands() as $brand ) {
				$results[] = $this->lookup_brand_price_local( $brand, $product, $max_results, $settings, $context );
			}

			return array(
				'product'  => $product,
				'queried'  => current_time( 'mysql', true ),
				'brands'   => $results,
				'metadata' => array(
					'max_results'     => $max_results,
					'lookup_provider' => 'local',
				),
			);
		}

		foreach ( $this->get_brands() as $brand ) {
			$results[] = $this->lookup_brand_price( $brand, $product, $max_results, $settings, $context, $base_url );
		}

		return array(
			'product'  => $product,
			'queried'  => current_time( 'mysql', true ),
			'brands'   => $results,
			'metadata' => array(
				'max_results'     => $max_results,
				'lookup_provider' => 'crawl4ai',
			),
		);
	}

	/**
	 * Query Crawl4AI for a single brand and extract pricing information.
	 *
	 * @param array  $brand       Brand metadata.
	 * @param string $product     Sanitised product query.
	 * @param int    $max_results Maximum number of results to request.
	 * @param array  $settings    Plugin settings.
	 * @param array  $context     Execution context.
	 * @param string $base_url    Crawl4AI base URL.
	 *
	 * @return array
	 */
	protected function lookup_brand_price( array $brand, $product, $max_results, array $settings, array $context, $base_url ) {
		$query   = $this->build_brand_query( $brand, $product );
		$payload = array(
			'query'       => $query,
			'max_results' => $max_results,
		);

		$payload = apply_filters( 'wp_mcp_ai_crawl4ai_price_lookup_payload', $payload, $brand, $product, $max_results, $context );

		$response = $this->perform_web_search( $payload, $settings, $context, $base_url );

		if ( is_wp_error( $response ) ) {
			return array(
				'brand'   => $brand['name'],
				'domain'  => $brand['domain'],
				'query'   => $query,
				'status'  => 'error',
				'error'   => $response->get_error_message(),
				'details' => $response->get_error_data(),
			);
		}

		$parsed = $this->summarise_results( $response, $max_results );
		$price  = $this->extract_price_from_results( $parsed );

		$result = array(
			'brand'  => $brand['name'],
			'domain' => $brand['domain'],
			'query'  => $query,
			'status' => $price ? 'success' : 'not-found',
			'items'  => $parsed,
		);

		if ( $price ) {
			$result['price']     = $price['amount'];
			$result['raw_price'] = $price['raw'];
			$result['currency']  = 'USD';
			$result['source']    = $price['source'];
		} else {
			$result['note'] = __( 'No price was detected in the top results.', 'wp-mcp-ai' );
		}

		return $result;
	}

	/**
	 * Query the local web search fallback for a single brand and extract pricing information.
	 *
	 * @param array  $brand       Brand metadata.
	 * @param string $product     Sanitised product query.
	 * @param int    $max_results Maximum number of results to request.
	 * @param array  $settings    Plugin settings.
	 * @param array  $context     Execution context.
	 *
	 * @return array
	 */
	protected function lookup_brand_price_local( array $brand, $product, $max_results, array $settings, array $context ) {
		unset( $settings );

		$query   = $this->build_brand_query( $brand, $product );
		$payload = array(
			'query'       => $query,
			'max_results' => $max_results,
		);

		$payload = apply_filters( 'wp_mcp_ai_crawl4ai_price_lookup_local_payload', $payload, $brand, $product, $max_results, $context );

		$response = $this->perform_local_web_search( $payload, $context );

		if ( is_wp_error( $response ) ) {
			return array(
				'brand'   => $brand['name'],
				'domain'  => $brand['domain'],
				'query'   => $query,
				'status'  => 'error',
				'error'   => $response->get_error_message(),
				'details' => $response->get_error_data(),
			);
		}

		$parsed = $this->summarise_results( $response, $max_results );
		$price  = $this->extract_price_from_results( $parsed );

		$result = array(
			'brand'  => $brand['name'],
			'domain' => $brand['domain'],
			'query'  => $query,
			'status' => $price ? 'success' : 'not-found',
			'items'  => $parsed,
		);

		if ( $price ) {
			$result['price']     = $price['amount'];
			$result['raw_price'] = $price['raw'];
			$result['currency']  = 'USD';
			$result['source']    = $price['source'];
		} else {
			$result['note'] = __( 'No price was detected in the top results.', 'wp-mcp-ai' );
		}

		return $result;
	}

	/**
	 * Build the search query for a particular brand.
	 *
	 * @param array  $brand   Brand metadata.
	 * @param string $product Product query.
	 *
	 * @return string
	 */
	protected function build_brand_query( array $brand, $product ) {
		$domain  = isset( $brand['domain'] ) ? $brand['domain'] : '';
		$product = trim( $product );

		if ( '' === $domain ) {
			return $product;
		}

		return sprintf( '%s price site:%s', $product, $domain );
	}

	/**
	 * Send the web search request to Crawl4AI.
	 *
	 * @param array  $payload  Request payload.
	 * @param array  $settings Plugin settings array.
	 * @param array  $context  Execution context.
	 * @param string $base_url Crawl4AI base URL.
	 *
	 * @return array|WP_Error
	 */
	protected function perform_web_search( array $payload, array $settings, array $context, $base_url ) {
		$endpoint = trailingslashit( $base_url ) . 'web_search';
		$encoded  = wp_json_encode( $payload );

		if ( false === $encoded ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_encoding_error', __( 'Failed to encode the Crawl4AI web search payload.', 'wp-mcp-ai' ) );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $this->get_request_timeout( $settings ),
				'headers' => $this->build_headers( $settings, $context ),
				'body'    => $encoded,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_crawl4ai_http_error',
				__( 'The Crawl4AI web search request failed.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_crawl4ai_http_status',
				/* translators: %d: HTTP status code */
				sprintf( __( 'The Crawl4AI web search service returned HTTP %d.', 'wp-mcp-ai' ), $status ),
				array(
					'status' => $status,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$decoded = $this->decode_response_body( wp_remote_retrieve_body( $response ) );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( isset( $decoded['error'] ) && ! empty( $decoded['error'] ) ) {
			$message = is_string( $decoded['error'] ) ? $decoded['error'] : __( 'Crawl4AI web search reported an error.', 'wp-mcp-ai' );

			return new WP_Error( 'wp_mcp_ai_crawl4ai_api_error', $message, $decoded );
		}

		return $decoded;
	}

	/**
	 * Decode a JSON response from Crawl4AI.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return array|WP_Error
	 */
	protected function decode_response_body( $body ) {
		if ( '' === trim( (string) $body ) ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_empty_response', __( 'Crawl4AI returned an empty response.', 'wp-mcp-ai' ) );
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_bad_json', __( 'Crawl4AI returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_invalid_response', __( 'Crawl4AI returned an unexpected response structure.', 'wp-mcp-ai' ) );
		}

		return $decoded;
	}

	/**
	 * Summarise the Crawl4AI search results.
	 *
	 * @param array $response    Decoded response body.
	 * @param int   $max_results Maximum number of results to include.
	 *
	 * @return array[]
	 */
	protected function summarise_results( array $response, $max_results ) {
		if ( empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
			return array();
		}

		$summary = array();

		foreach ( $response['results'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$summary[] = array(
				'title'   => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '',
				'url'     => isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '',
				'snippet' => isset( $item['snippet'] ) ? sanitize_textarea_field( (string) $item['snippet'] ) : $this->extract_text_fallback( $item ),
			);

			if ( count( $summary ) >= $max_results ) {
				break;
			}
		}

		return $summary;
	}

	/**
	 * Perform a local web search using the bundled web search tool.
	 *
	 * @param array $payload Prepared payload.
	 * @param array $context Execution context.
	 *
	 * @return array|WP_Error
	 */
	protected function perform_local_web_search( array $payload, array $context ) {
		$tool = new WP_MCP_AI_Tool_Web_Search();

		$arguments = array(
			'query'       => isset( $payload['query'] ) ? $payload['query'] : '',
			'max_results' => isset( $payload['max_results'] ) ? absint( $payload['max_results'] ) : self::DEFAULT_MAX_RESULTS,
		);

		$result = $tool->execute( $arguments, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_local_invalid_response', __( 'The local web search returned an unexpected response.', 'wp-mcp-ai' ) );
		}

		$results = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array();

		return array(
			'results' => $results,
		);
	}

	/**
	 * Extract a text fallback from arbitrary result data.
	 *
	 * @param array $item Result item.
	 *
	 * @return string
	 */
	protected function extract_text_fallback( array $item ) {
		foreach ( array( 'markdown', 'text', 'content', 'description' ) as $field ) {
			if ( ! empty( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
				return sanitize_textarea_field( $item[ $field ] );
			}
		}

		return '';
	}

	/**
	 * Extract the first price found in the result summaries.
	 *
	 * @param array[] $items Result summaries.
	 *
	 * @return array|null
	 */
	protected function extract_price_from_results( array $items ) {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$price = $this->extract_price_from_text( $item );

			if ( $price ) {
				return $price;
			}
		}

		return null;
	}

	/**
	 * Extract the first price within a result item.
	 *
	 * @param array $item Result item containing title, url, and snippet.
	 *
	 * @return array|null
	 */
	protected function extract_price_from_text( array $item ) {
		$fields = array();

		foreach ( array( 'snippet', 'title' ) as $field ) {
			if ( ! empty( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
				$fields[] = $item[ $field ];
			}
		}

		if ( empty( $fields ) ) {
			return null;
		}

		$text    = implode( ' ', $fields );
		$pattern = '/\$\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]{2})?|[0-9]+(?:\.[0-9]{2})?)/';

		if ( preg_match( $pattern, $text, $matches ) ) {
			$raw    = isset( $matches[0] ) ? $matches[0] : '';
			$value  = isset( $matches[1] ) ? $matches[1] : '';
			$value  = str_replace( ',', '', $value );
			$amount = is_numeric( $value ) ? (float) $value : null;

			if ( null === $amount ) {
				return null;
			}

			return array(
				'amount' => $amount,
				'raw'    => $raw,
				'source' => array(
					'title' => isset( $item['title'] ) ? $item['title'] : '',
					'url'   => isset( $item['url'] ) ? $item['url'] : '',
				),
			);
		}

		return null;
	}

	/**
	 * Retrieve the configured Crawl4AI base URL.
	 *
	 * @param array $settings Plugin settings array.
	 * @param array $context  Execution context.
	 *
	 * @return string
	 */
	protected static function resolve_base_url( array $settings, array $context = array() ) {
		$base_url = '';

		if ( isset( $settings['crawl4ai_base_url'] ) ) {
			$base_url = (string) $settings['crawl4ai_base_url'];
		}

		$base_url = apply_filters( 'wp_mcp_ai_crawl4ai_base_url', $base_url, $settings, $context );

		if ( ! is_string( $base_url ) ) {
			return '';
		}

		$sanitised = esc_url_raw( trim( $base_url ) );

		if ( ! $sanitised ) {
			return '';
		}

		return untrailingslashit( $sanitised );
	}

	/**
	 * Build HTTP headers for Crawl4AI requests.
	 *
	 * @param array $settings Plugin settings array.
	 * @param array $context  Execution context.
	 *
	 * @return array
	 */
	protected function build_headers( array $settings, array $context ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		if ( ! empty( $settings['crawl4ai_api_key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $settings['crawl4ai_api_key'];
		}

		return apply_filters( 'wp_mcp_ai_crawl4ai_headers', $headers, $settings, $context );
	}

	/**
	 * Determine the HTTP timeout for Crawl4AI requests.
	 *
	 * @param array $settings Plugin settings array.
	 *
	 * @return int
	 */
	protected function get_request_timeout( array $settings ) {
		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		return max( 5, $timeout );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

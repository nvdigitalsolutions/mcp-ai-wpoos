<?php
/**
 * Tool for retrieving products from EZuite ERP system.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Provides EZuite ERP product listings with inventory and pricing details.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_EZuite_ERP_Get_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default limit for number of products to retrieve.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_LIMIT = 10;

	/**
	 * Maximum limit for number of products to retrieve.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_LIMIT = 100;

	/**
	 * Minimum limit for number of products to retrieve.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MIN_LIMIT = 1;

	/**
	 * Maximum number of requests allowed per minute per user.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const RATE_LIMIT_PER_MINUTE = 30;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ezuite_erp_get_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get EZuite ERP Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns products from EZuite ERP system with inventory levels, pricing, and product details. Simplified interface for retrieving product catalog information from the connected ERP system.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'The EZuite ERP connection ID. Call ezuite_erp tool with action "list_connections" first to get available connection IDs.', 'mcp-ai-wpoos-pro' ),
				),
				'location_code' => array(
					'type'        => 'string',
					'description' => __( 'Location code to filter products by (e.g., "MAIN", "WAREHOUSE1"). Use "ALL" to retrieve all products. Default is "ALL".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'ALL',
				),
				'item_code'     => array(
					'type'        => 'string',
					'description' => __( 'Optional specific item code to retrieve a single product.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => sprintf(
						/* translators: %d: default limit */
						__( 'Maximum number of products to return. Default is %d.', 'mcp-ai-wpoos-pro' ),
						self::DEFAULT_LIMIT
					),
					'minimum'     => self::MIN_LIMIT,
					'maximum'     => self::MAX_LIMIT,
					'default'     => self::DEFAULT_LIMIT,
				),
			),
			'required'             => array( 'connection_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                => 'ezuite_erp_get_products',
			'description'         => $this->get_description(),
			'required_capability' => 'edit_posts',
			'input_schema'        => $this->get_parameters_schema(),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user permissions.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to access EZuite ERP products.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check rate limiting.
		$rate_limit_check = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Get connection ID.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( empty( $connection_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_connection',
				__( 'Connection ID is required. Use the ezuite_erp tool with action "list_connections" to get available connection IDs.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get connection.
		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_not_found',
				sprintf(
					/* translators: %s: connection ID */
					__( 'Connection "%s" not found. Call ezuite_erp tool with action "list_connections" to see available connections.', 'mcp-ai-wpoos-pro' ),
					$connection_id
				)
			);
		}

		// Validate connection type.
		if ( empty( $connection['connection_type'] ) || 'ezuite_erp' !== $connection['connection_type'] ) {
			return new WP_Error(
				'wp_mcp_ai_pro_wrong_connection_type',
				__( 'This connection is not an EZuite ERP connection.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if enabled.
		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_disabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Connection "%s" is disabled.', 'mcp-ai-wpoos-pro' ),
					$connection['name']
				)
			);
		}

		// Check if connection is enabled for this assistant.
		if ( ! $this->is_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_not_enabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Connection "%s" is not enabled for this assistant.', 'mcp-ai-wpoos-pro' ),
					$connection['name']
				)
			);
		}

		// Build API request.
		$location_code = isset( $arguments['location_code'] ) ? sanitize_text_field( $arguments['location_code'] ) : 'ALL';
		$item_code     = isset( $arguments['item_code'] ) ? sanitize_text_field( $arguments['item_code'] ) : '';
		$limit         = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : self::DEFAULT_LIMIT;
		$limit         = $limit > 0 ? min( $limit, self::MAX_LIMIT ) : self::DEFAULT_LIMIT;

		// Prepare API body.
		$api_body = array(
			array(
				'Location_Code' => $location_code,
			),
		);

		// Add specific item code if provided.
		if ( ! empty( $item_code ) ) {
			$api_body[0]['Item_Code'] = $item_code;
		}

		// Make the ERP request.
		$result = $this->make_erp_request( $connection, 'LX_ItemPull', $api_body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Parse and format the response.
		$products = $this->format_products( $result, $limit );

		return array(
			'summary'         => sprintf(
				/* translators: 1: number of products returned, 2: connection name */
				__( 'Retrieved %1$d product(s) from %2$s', 'mcp-ai-wpoos-pro' ),
				count( $products ),
				$connection['name']
			),
			'products'        => $products,
			'count'           => count( $products ),
			'connection_name' => $connection['name'],
			'location_code'   => $location_code,
		);
	}

	/**
	 * Make an authenticated HTTP request to EZuite ERP API.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $connection Connection data.
	 * @param string $api_action ERP API action.
	 * @param array  $api_body   Request body.
	 * @return array|WP_Error Response data or error.
	 */
	protected function make_erp_request( $connection, $api_action, $api_body ) {
		$url = trailingslashit( $connection['url'] );

		// Get API key from connection.
		$api_key = isset( $connection['api_key'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] ) : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_api_key',
				__( 'API key is not configured for this connection.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare request body according to EZuite API format.
		$request_body = array(
			'API_Key'    => $api_key,
			'API_Action' => $api_action,
			'API_Body'   => $api_body,
		);

		$args = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $request_body ),
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$error_message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP error %d', 'mcp-ai-wpoos-pro' ),
				$status_code
			);

			$decoded = json_decode( $body, true );

			if ( isset( $decoded['Message'] ) ) {
				$error_message .= ': ' . $decoded['Message'];
			}

			return new WP_Error( 'wp_mcp_ai_pro_http_error', $error_message );
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_json',
				__( 'Invalid JSON response from EZuite ERP API.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check EZuite API response status.
		if ( isset( $decoded['Status_Code'] ) && 200 !== absint( $decoded['Status_Code'] ) ) {
			$error_message = isset( $decoded['Message'] ) ? $decoded['Message'] : __( 'Unknown error', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'wp_mcp_ai_pro_erp_error', $error_message );
		}

		return $decoded;
	}

	/**
	 * Format raw ERP product data into standardized array structure.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw_data Raw API response data.
	 * @param int   $limit    Maximum number of products to return.
	 * @return array Formatted product data.
	 */
	protected function format_products( $raw_data, $limit ) {
		$products = array();

		// Check if we have data in the response.
		if ( empty( $raw_data['Data'] ) || ! is_array( $raw_data['Data'] ) ) {
			return $products;
		}

		$count = 0;
		foreach ( $raw_data['Data'] as $item ) {
			if ( $count >= $limit ) {
				break;
			}

			// Format product data.
			$product = array(
				'item_code'        => isset( $item['Item_Code'] ) ? $item['Item_Code'] : '',
				'description'      => isset( $item['Description'] ) ? $item['Description'] : '',
				'location_code'    => isset( $item['Location_Code'] ) ? $item['Location_Code'] : '',
				'quantity_on_hand' => isset( $item['Quantity_On_Hand'] ) ? floatval( $item['Quantity_On_Hand'] ) : 0,
				'unit_price'       => isset( $item['Unit_Price'] ) ? floatval( $item['Unit_Price'] ) : 0,
				'unit_cost'        => isset( $item['Unit_Cost'] ) ? floatval( $item['Unit_Cost'] ) : 0,
				'category'         => isset( $item['Category'] ) ? $item['Category'] : '',
				'uom'              => isset( $item['UOM'] ) ? $item['UOM'] : '',
				'status'           => isset( $item['Status'] ) ? $item['Status'] : '',
			);

			// Add optional fields if present.
			if ( isset( $item['Barcode'] ) ) {
				$product['barcode'] = $item['Barcode'];
			}

			if ( isset( $item['Vendor_Code'] ) ) {
				$product['vendor_code'] = $item['Vendor_Code'];
			}

			if ( isset( $item['Last_Updated'] ) ) {
				$product['last_updated'] = $item['Last_Updated'];
			}

			$products[] = $product;
			++$count;
		}

		return $products;
	}

	/**
	 * Check if a connection is enabled for the current assistant.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $context       Execution context.
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_connection_enabled_for_assistant( $connection_id, $context ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			return true;
		}

		$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );

		if ( ! is_array( $enabled_connections ) ) {
			return true;
		}

		return in_array( $connection_id, $enabled_connections, true );
	}

	/**
	 * Check rate limiting for ERP API requests.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$user_id        = absint( $user_id );
		$transient_key  = 'wp_mcp_ai_pro_ezuite_erp_get_products_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = self::RATE_LIMIT_PER_MINUTE;

		/**
		 * Filter the maximum EZuite ERP API requests allowed per minute per user.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_per_minute Maximum requests per minute (default: 30).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_pro_ezuite_erp_get_products_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_pro_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum requests allowed per minute */
					__( 'EZuite ERP API rate limit exceeded. Maximum %d requests per minute allowed.', 'mcp-ai-wpoos-pro' ),
					$max_per_minute
				)
			);
		}

		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro feature.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls.
			'requires-capability',  // Requires 'edit_posts' capability.
			'requires-credentials', // Requires API key configuration.
			'network-dependent',    // Requires internet connectivity.
			'may-timeout',          // External API calls may timeout.
			'rate-limited',         // Subject to rate limiting.
		);
	}
}

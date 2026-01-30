<?php
/**
 * Tool that retrieves orders/transactions from Flowhub API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving orders and transactions from Flowhub.
 */
class WP_MCP_AI_Tool_Flowhub_Get_Orders implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_get_orders';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Flowhub Get Orders', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve order and transaction data from Flowhub dispensary system including sales, returns, customer details, and order status. Supports filtering and pagination.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Optional Remote Sites connection ID for Flowhub. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of orders to retrieve (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'offset'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders to skip for pagination.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by order status (e.g., "completed", "pending", "cancelled").', 'mcp-ai-wpoos' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array(),
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
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to retrieve Flowhub orders.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve order data.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		// Get connection_id if provided.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		// Validate connection if provided.
		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'flowhub' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not a Flowhub connection.', 'mcp-ai-wpoos' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos' )
				);
			}
		}

		$client  = new WP_MCP_AI_Flowhub_Client( $connection_id );
		$options = array();

		if ( isset( $arguments['limit'] ) ) {
			$options['limit'] = max( 1, min( 100, absint( $arguments['limit'] ) ) );
		}
		if ( isset( $arguments['offset'] ) ) {
			$options['offset'] = max( 0, absint( $arguments['offset'] ) );
		}
		if ( isset( $arguments['status'] ) ) {
			$options['status'] = sanitize_text_field( $arguments['status'] );
		}
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		$result = $client->get_orders( $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$summary = __( 'Retrieved orders from Flowhub', 'mcp-ai-wpoos' );
		if ( isset( $result['total'] ) ) {
			$summary = sprintf(
				/* translators: %d: number of orders */
				__( 'Retrieved %d orders from Flowhub', 'mcp-ai-wpoos' ),
				absint( $result['total'] )
			);
		}

		$result = array_merge(
			array(
				'message' => $summary,
				'summary' => $summary,
			),
			$result
		);

		/**
		 * Allow third parties to filter the Flowhub orders result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_flowhub_get_orders_result', $result, $arguments, $context );

		return $result;
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ecommerce_business',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'ecommerce_manager', 'sales_manager' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Flowhub API credentials.
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Only reads data, does not modify state.
			'pii-data',             // Returns customer information.
			'rate-limited',         // Subject to Flowhub API rate limits.
			'paginated',            // Supports pagination.
		);
	}
}

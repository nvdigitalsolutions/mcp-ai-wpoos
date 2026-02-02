<?php
/**
 * Tool that creates orders in Flowhub API.
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
 * Provides a tool for creating orders in Flowhub dispensary system.
 */
class WP_MCP_AI_Tool_Flowhub_Create_Order implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_create_order';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Flowhub Create Order', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new order/transaction in Flowhub dispensary system. Supports creating sales orders with customer information, line items, payment details, and compliance tracking.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'  => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for Flowhub. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos' ),
				),
				'customer_id'    => array(
					'type'        => 'string',
					'description' => __( 'Flowhub customer ID for the order.', 'mcp-ai-wpoos' ),
				),
				'items'          => array(
					'type'        => 'array',
					'description' => __( 'Array of order line items with product_id, quantity, and price.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'product_id' => array(
								'type'        => 'string',
								'description' => __( 'Product ID from Flowhub catalog.', 'mcp-ai-wpoos' ),
							),
							'quantity'   => array(
								'type'        => 'number',
								'description' => __( 'Quantity to order.', 'mcp-ai-wpoos' ),
								'minimum'     => 0.01,
							),
							'price'      => array(
								'type'        => 'number',
								'description' => __( 'Price per unit.', 'mcp-ai-wpoos' ),
								'minimum'     => 0,
							),
						),
						'required'   => array( 'product_id', 'quantity', 'price' ),
					),
				),
				'payment_method' => array(
					'type'        => 'string',
					'description' => __( 'Payment method (e.g., "cash", "debit", "credit").', 'mcp-ai-wpoos' ),
				),
				'notes'          => array(
					'type'        => 'string',
					'description' => __( 'Optional order notes or special instructions.', 'mcp-ai-wpoos' ),
				),
				'timeout'        => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array( 'customer_id', 'items' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to create Flowhub orders.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create orders.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		// Validate required parameters.
		if ( empty( $arguments['customer_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_customer_id',
				__( 'A customer_id is required to create an order.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $arguments['items'] ) || ! is_array( $arguments['items'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_items',
				__( 'Order items array is required to create an order.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
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

		$client     = new WP_MCP_AI_Flowhub_Client( $connection_id );
		$order_data = array(
			'customer_id' => sanitize_text_field( $arguments['customer_id'] ),
			'items'       => $arguments['items'],
		);

		if ( isset( $arguments['payment_method'] ) ) {
			$order_data['payment_method'] = sanitize_text_field( $arguments['payment_method'] );
		}
		if ( isset( $arguments['notes'] ) ) {
			$order_data['notes'] = sanitize_textarea_field( $arguments['notes'] );
		}

		$options = array();
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		$result = $client->create_order( $order_data, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$order_id = isset( $result['id'] ) ? $result['id'] : '';
		$summary  = sprintf(
			/* translators: %s: order ID */
			__( 'Successfully created order: %s', 'mcp-ai-wpoos' ),
			$order_id
		);

		$result = array_merge(
			array(
				'message' => $summary,
				'summary' => $summary,
			),
			$result
		);

		/**
		 * Allow third parties to filter the Flowhub create order result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_flowhub_create_order_result', $result, $arguments, $context );

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

			'profession_tags'       => array( 'sales_manager', 'customer_service_rep' ),

			'risk_level'            => 'standard',

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
			'write',                // Creates data.
			'state-changing',       // Modifies external system state.
			'rate-limited',         // Subject to Flowhub API rate limits.
		);
	}
}

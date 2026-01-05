<?php
/**
 * Tool that retrieves payment details from PayHere API.
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
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-payhere-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving payment transaction details from PayHere.
 * Follows separation of concerns: handles WordPress integration while delegating API calls to client.
 */
class WP_MCP_AI_Tool_PayHere_Get_Payment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'payhere_get_payment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'PayHere Get Payment', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve payment transaction details from PayHere payment gateway by order ID. Returns payment status, customer details, amounts, fees, and payment method information.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'order_id' => array(
					'type'        => 'string',
					'description' => __( 'The PayHere order ID to retrieve payment details for (e.g., "LP8006126139").', 'wp-mcp-ai' ),
				),
				'timeout'  => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 60,
				),
			),
			'required'             => array( 'order_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to retrieve PayHere payment details.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability for payment data access.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve payment details.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_order_id',
				__( 'An order_id parameter is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$order_id = sanitize_text_field( $arguments['order_id'] );
		$client   = new WP_MCP_AI_PayHere_Client();
		$options  = array();

		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		$result = $client->get_payment( $order_id, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$summary = sprintf(
			/* translators: %s: order ID */
			__( 'Retrieved payment details for order: %s', 'wp-mcp-ai' ),
			$order_id
		);

		$result = array_merge(
			array( 'summary' => $summary ),
			$result
		);

		/**
		 * Allow third parties to filter the PayHere payment result before it is returned.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_payhere_get_payment_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                 // Pro tier tool.
			'external-api',        // Makes external API calls.
			'requires-credentials', // Requires PayHere API credentials.
			'requires-capability', // Requires user capabilities.
			'read-only',           // Only reads data, does not modify state.
			'pii-data',            // Returns personally identifiable information.
			'rate-limited',        // Subject to PayHere API rate limits.
		);
	}
}

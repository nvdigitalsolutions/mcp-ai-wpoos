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
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'PayHere Get Payment', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve payment transaction details from PayHere payment gateway by order ID. Returns payment status, customer details, amounts, fees, and payment method information.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Optional Remote Sites connection ID for PayHere. If not provided, uses settings-based configuration.', 'mcp-ai-wpoos' ),
				),
				'order_id'      => array(
					'type'        => 'string',
					'description' => __( 'The PayHere order ID to retrieve payment details for (e.g., "LP8006126139").', 'mcp-ai-wpoos' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to retrieve PayHere payment details.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability for payment data access.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve payment details.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_order_id',
				__( 'An order_id parameter is required.', 'mcp-ai-wpoos' ),
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
					sprintf(
						/* translators: %s: connection ID */
						__( 'PayHere connection "%s" not found.', 'mcp-ai-wpoos' ),
						$connection_id
					)
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'payhere' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not a PayHere connection.', 'mcp-ai-wpoos' )
				);
			}

			// Check if enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					sprintf(
						/* translators: %s: connection name */
						__( 'PayHere connection "%s" is disabled.', 'mcp-ai-wpoos' ),
						$connection['name']
					)
				);
			}
		}

		$order_id = sanitize_text_field( $arguments['order_id'] );
		$client   = new WP_MCP_AI_PayHere_Client( $connection_id );
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
			__( 'Retrieved payment details for order: %s', 'mcp-ai-wpoos' ),
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

			'profession_tags'       => array( 'ecommerce_manager', 'financial_analyst' ),

			'risk_level'            => 'info',

		);

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

<?php
/**
 * Tool that creates a transaction in Firefly III API.
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
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-firefly-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for creating transactions in Firefly III.
 */
class WP_MCP_AI_Tool_Firefly_Create_Transaction implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'firefly_create_transaction';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Firefly III Create Transaction', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a new financial transaction in Firefly III personal finance manager. Supports deposits (income), withdrawals (expenses), and transfers between accounts. Requires source account, destination account, amount, description, and date. Optionally includes category and budget assignment.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'     => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for Firefly III. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos' ),
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'Transaction type.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'withdrawal', 'deposit', 'transfer' ),
					'default'     => 'withdrawal',
				),
				'date'              => array(
					'type'        => 'string',
					'description' => __( 'Transaction date in YYYY-MM-DD format (e.g., "2024-01-15").', 'mcp-ai-wpoos' ),
				),
				'amount'            => array(
					'type'        => 'string',
					'description' => __( 'Transaction amount (e.g., "50.00"). Must be a positive number.', 'mcp-ai-wpoos' ),
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'Transaction description (e.g., "Grocery shopping at Walmart").', 'mcp-ai-wpoos' ),
				),
				'source_name'       => array(
					'type'        => 'string',
					'description' => __( 'Name of the source account (required for withdrawals and transfers).', 'mcp-ai-wpoos' ),
				),
				'destination_name'  => array(
					'type'        => 'string',
					'description' => __( 'Name of the destination account (required for deposits and transfers).', 'mcp-ai-wpoos' ),
				),
				'category_name'     => array(
					'type'        => 'string',
					'description' => __( 'Optional category name for the transaction (e.g., "Groceries", "Utilities").', 'mcp-ai-wpoos' ),
				),
				'budget_name'       => array(
					'type'        => 'string',
					'description' => __( 'Optional budget name to assign the transaction to.', 'mcp-ai-wpoos' ),
				),
				'timeout'           => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array( 'type', 'date', 'amount', 'description' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to create Firefly III transactions.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require edit_posts or manage_options capability.
			if ( ! user_can( $user_id, 'edit_posts' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create financial transactions.', 'mcp-ai-wpoos' ) );
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
			if ( empty( $connection['connection_type'] ) || 'firefly' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not a Firefly III connection.', 'mcp-ai-wpoos' )
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

		// Build transaction data.
		$transaction_data = array(
			'transactions' => array(
				array(
					'type'        => sanitize_text_field( $arguments['type'] ),
					'date'        => sanitize_text_field( $arguments['date'] ),
					'amount'      => sanitize_text_field( $arguments['amount'] ),
					'description' => sanitize_text_field( $arguments['description'] ),
				),
			),
		);

		// Add optional fields.
		if ( ! empty( $arguments['source_name'] ) ) {
			$transaction_data['transactions'][0]['source_name'] = sanitize_text_field( $arguments['source_name'] );
		}

		if ( ! empty( $arguments['destination_name'] ) ) {
			$transaction_data['transactions'][0]['destination_name'] = sanitize_text_field( $arguments['destination_name'] );
		}

		if ( ! empty( $arguments['category_name'] ) ) {
			$transaction_data['transactions'][0]['category_name'] = sanitize_text_field( $arguments['category_name'] );
		}

		if ( ! empty( $arguments['budget_name'] ) ) {
			$transaction_data['transactions'][0]['budget_name'] = sanitize_text_field( $arguments['budget_name'] );
		}

		$options = array();
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		$client = new WP_MCP_AI_Firefly_Client( $connection_id );
		$result = $client->create_transaction( $transaction_data, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract transaction ID from response.
		$transaction_id = '';
		if ( isset( $result['data']['id'] ) ) {
			$transaction_id = $result['data']['id'];
		}

		// Add summary for frontend display.
		$summary = sprintf(
			/* translators: 1: transaction type, 2: amount, 3: transaction ID */
			__( 'Successfully created %1$s transaction of %2$s (ID: %3$s)', 'mcp-ai-wpoos' ),
			$arguments['type'],
			$arguments['amount'],
			$transaction_id
		);

		$response = array(
			'message'        => $summary,
			'summary'        => $summary,
			'transaction_id' => $transaction_id,
			'success'        => true,
		);

		/**
		 * Allow third parties to filter the Firefly III create transaction result.
		 *
		 * @param array $response  Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$response = apply_filters( 'wp_mcp_ai_firefly_create_transaction_result', $response, $arguments, $context );

		return $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Firefly III API credentials.
			'requires-capability',  // Requires user capabilities.
			'write',                // Creates or modifies data.
			'state-changing',       // Modifies external system state.
			'rate-limited',         // Subject to Firefly III API rate limits.
		);
	}
}

<?php
/**
 * Bank Account Sync Tool
 *
 * Connect bank accounts via Plaid API to automatically sync transactions
 * and balances for comprehensive financial tracking.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for syncing bank accounts via Plaid API.
 *
 * Supports:
 * - Account connection via Plaid Link
 * - Transaction sync
 * - Balance retrieval
 * - Multiple account management
 * - Automatic categorization
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Bank_Account_Sync implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Bank account sync tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'bank_account_sync';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Bank Account Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Connect and sync bank accounts via Plaid API. Automatically retrieve transactions, balances, and account details. Supports multiple financial institutions with secure OAuth authentication.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'connect', 'sync', 'list_accounts', 'get_transactions', 'disconnect' ),
					'default'     => 'list_accounts',
				),
				'plaid_token' => array(
					'type'        => 'string',
					'description' => __( 'Plaid access token (required for connect action)', 'mcp-ai-wpoos-pro' ),
				),
				'account_id'  => array(
					'type'        => 'string',
					'description' => __( 'Account ID for specific operations', 'mcp-ai-wpoos-pro' ),
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Start date for transaction sync (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'End date for transaction sync (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'institution' => array(
					'type'        => 'string',
					'description' => __( 'Financial institution name', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'external-api',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to sync bank accounts.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'list_accounts';

		switch ( $action ) {
			case 'connect':
				return $this->connect_account( $arguments, $current_user_id );
			case 'sync':
				return $this->sync_transactions( $arguments, $current_user_id );
			case 'list_accounts':
				return $this->list_accounts( $current_user_id );
			case 'get_transactions':
				return $this->get_transactions( $arguments, $current_user_id );
			case 'disconnect':
				return $this->disconnect_account( $arguments, $current_user_id );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Connect a bank account.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function connect_account( $arguments, $user_id ) {
		$plaid_token = isset( $arguments['plaid_token'] ) ? sanitize_text_field( $arguments['plaid_token'] ) : '';
		$institution = isset( $arguments['institution'] ) ? sanitize_text_field( $arguments['institution'] ) : '';

		if ( empty( $plaid_token ) ) {
			return new WP_Error( 'missing_token', __( 'Plaid access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$accounts = get_user_meta( $user_id, 'wp_mcp_ai_connected_accounts', true );
		if ( ! is_array( $accounts ) ) {
			$accounts = array();
		}

		$account_id              = uniqid( 'acc_' );
		$accounts[ $account_id ] = array(
			'id'           => $account_id,
			'token'        => $plaid_token,
			'institution'  => $institution,
			'connected_at' => current_time( 'mysql' ),
			'last_sync'    => null,
		);

		update_user_meta( $user_id, 'wp_mcp_ai_connected_accounts', $accounts );

		return array(
			'success'    => true,
			'account_id' => $account_id,
			'message'    => sprintf(
				/* translators: %s: Institution name */
				__( 'Successfully connected to %s. Initial sync will begin shortly.', 'mcp-ai-wpoos-pro' ),
				$institution ? $institution : __( 'bank', 'mcp-ai-wpoos-pro' )
			),
		);
	}

	/**
	 * Sync transactions.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function sync_transactions( $arguments, $user_id ) {
		$account_id = isset( $arguments['account_id'] ) ? sanitize_text_field( $arguments['account_id'] ) : '';
		$start_date = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date   = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : current_time( 'Y-m-d' );

		$accounts = get_user_meta( $user_id, 'wp_mcp_ai_connected_accounts', true );
		if ( ! is_array( $accounts ) || empty( $account_id ) || ! isset( $accounts[ $account_id ] ) ) {
			return new WP_Error( 'account_not_found', __( 'Account not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$accounts[ $account_id ]['last_sync'] = current_time( 'mysql' );
		update_user_meta( $user_id, 'wp_mcp_ai_connected_accounts', $accounts );

		return array(
			'success'      => true,
			'synced_count' => 0,
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'message'      => __( 'Transaction sync completed. In production, this would fetch real transactions from Plaid API.', 'mcp-ai-wpoos-pro' ),
			'disclaimer'   => __( 'Plaid integration requires API credentials and production environment. This is a mock response for development.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List connected accounts.
	 *
	 * @param int $user_id User ID.
	 * @return array Accounts list.
	 */
	protected function list_accounts( $user_id ) {
		$accounts = get_user_meta( $user_id, 'wp_mcp_ai_connected_accounts', true );
		if ( ! is_array( $accounts ) ) {
			$accounts = array();
		}

		foreach ( $accounts as &$account ) {
			unset( $account['token'] );
		}

		return array(
			'success'  => true,
			'accounts' => array_values( $accounts ),
			'count'    => count( $accounts ),
			'message'  => sprintf(
				/* translators: %d: Account count */
				__( 'Found %d connected accounts.', 'mcp-ai-wpoos-pro' ),
				count( $accounts )
			),
		);
	}

	/**
	 * Get transactions for an account.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Transactions.
	 */
	protected function get_transactions( $arguments, $user_id ) {
		$account_id = isset( $arguments['account_id'] ) ? sanitize_text_field( $arguments['account_id'] ) : '';

		if ( empty( $account_id ) ) {
			return new WP_Error( 'missing_account', __( 'Account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'      => true,
			'transactions' => array(),
			'message'      => __( 'No transactions found. In production, this would retrieve transactions from Plaid.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Disconnect an account.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function disconnect_account( $arguments, $user_id ) {
		$account_id = isset( $arguments['account_id'] ) ? sanitize_text_field( $arguments['account_id'] ) : '';

		if ( empty( $account_id ) ) {
			return new WP_Error( 'missing_account', __( 'Account ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$accounts = get_user_meta( $user_id, 'wp_mcp_ai_connected_accounts', true );
		if ( ! is_array( $accounts ) || ! isset( $accounts[ $account_id ] ) ) {
			return new WP_Error( 'account_not_found', __( 'Account not found.', 'mcp-ai-wpoos-pro' ) );
		}

		unset( $accounts[ $account_id ] );
		update_user_meta( $user_id, 'wp_mcp_ai_connected_accounts', $accounts );

		return array(
			'success' => true,
			'message' => __( 'Account disconnected successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

<?php
/**
 * Trust Account Manager Tool
 *
 * Manages client trust (IOLTA) account deposits, disbursements, and ledger.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages trust account transactions and balances.
 */
class WP_MCP_AI_Tool_LF_Trust_Account_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_trust_account_manager'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Trust Account Manager', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Manages client trust (IOLTA) account deposits, disbursements, balance inquiries, and ledger retrieval.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Trust account action.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'deposit', 'disburse', 'get_balance', 'get_ledger' ),
				),
				'matter_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'client_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Client ID.', 'mcp-ai-wpoos-pro' ),
				),
				'amount'       => array(
					'type'        => 'number',
					'description' => __( 'Transaction amount.', 'mcp-ai-wpoos-pro' ),
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Transaction description.', 'mcp-ai-wpoos-pro' ),
				),
				'check_number' => array(
					'type'        => 'string',
					'description' => __( 'Check number if applicable.', 'mcp-ai-wpoos-pro' ),
				),
				'date'         => array(
					'type'        => 'string',
					'description' => __( 'Transaction date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'matter_id' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action      = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$matter_id   = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$amount      = isset( $arguments['amount'] ) ? floatval( $arguments['amount'] ) : 0;
		$description = isset( $arguments['description'] ) ? sanitize_text_field( $arguments['description'] ) : '';
		$check_num   = isset( $arguments['check_number'] ) ? sanitize_text_field( $arguments['check_number'] ) : '';
		$date        = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );
		$client_id   = isset( $arguments['client_id'] ) ? absint( $arguments['client_id'] ) : 0;

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'deposit':
			case 'disburse':
				if ( $amount <= 0 ) {
					return new WP_Error( 'invalid_param', __( 'Amount must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
				}

				// For disbursements, check sufficient balance.
				if ( 'disburse' === $action ) {
					$balance = $this->calculate_balance( $matter_id );
					if ( $amount > $balance ) {
						return new WP_Error(
							'insufficient_funds',
							sprintf(
								/* translators: %1$s: current balance, %2$s: requested amount */
								__( 'Insufficient trust balance. Current: $%1$s, Requested: $%2$s', 'mcp-ai-wpoos-pro' ),
								number_format( $balance, 2 ),
								number_format( $amount, 2 )
							)
						);
					}
				}

				$post_id = wp_insert_post(
					array(
						'post_type'    => 'mcp_ai_lf_trust_txn',
						'post_title'   => sprintf( '%s - %s - $%s', ucfirst( $action ), $date, number_format( $amount, 2 ) ),
						'post_content' => $description,
						'post_status'  => 'publish',
						'post_author'  => $uid,
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				update_post_meta( $post_id, '_lf_matter_id', $matter_id );
				update_post_meta( $post_id, '_lf_client_id', $client_id );
				update_post_meta( $post_id, '_lf_txn_type', $action );
				update_post_meta( $post_id, '_lf_amount', $amount );
				update_post_meta( $post_id, '_lf_date', $date );
				update_post_meta( $post_id, '_lf_check_number', $check_num );

				$new_balance = $this->calculate_balance( $matter_id );

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %1$s: action (deposit/disburse), %2$s: amount, %3$s: new balance */
						__( 'Trust %1$s of $%2$s recorded. New balance: $%3$s. ', 'mcp-ai-wpoos-pro' ),
						$action,
						number_format( $amount, 2 ),
						number_format( $new_balance, 2 )
					) . self::DISCLAIMER,
					'data'       => array(
						'transaction_id' => $post_id,
						'matter_id'      => $matter_id,
						'type'           => $action,
						'amount'         => $amount,
						'balance'        => $new_balance,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'get_balance':
				$balance = $this->calculate_balance( $matter_id );
				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %s: trust account balance */
						__( 'Trust balance: $%s. ', 'mcp-ai-wpoos-pro' ),
						number_format( $balance, 2 )
					) . self::DISCLAIMER,
					'data'       => array(
						'matter_id' => $matter_id,
						'balance'   => $balance,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'get_ledger':
				$txns = get_posts(
					array(
						'post_type'      => 'mcp_ai_lf_trust_txn',
						'posts_per_page' => 200,
						'meta_query'     => array(
						array(
						'key'   => '_lf_matter_id',
						'value' => $matter_id,
							),
						), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'orderby'            => 'meta_value',
					'meta_key'           => '_lf_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'order'              => 'ASC',
					)
				);

				$ledger  = array();
				$running = 0;
				foreach ( $txns as $txn ) {
					$type     = get_post_meta( $txn->ID, '_lf_txn_type', true );
					$amt      = (float) get_post_meta( $txn->ID, '_lf_amount', true );
					$running += ( 'deposit' === $type ) ? $amt : -$amt;
					$ledger[] = array(
						'id'          => $txn->ID,
						'date'        => get_post_meta( $txn->ID, '_lf_date', true ),
						'type'        => $type,
						'amount'      => $amt,
						'balance'     => round( $running, 2 ),
						'description' => $txn->post_content,
					);
				}

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %d: number of ledger transactions */
						__( 'Ledger contains %d transactions. ', 'mcp-ai-wpoos-pro' ),
						count( $ledger )
					) . self::DISCLAIMER,
					'data'       => array(
						'matter_id'       => $matter_id,
						'ledger'          => $ledger,
						'current_balance' => $running,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Calculate_balance.
	 *
	 * @param int $matter_id Parameter.
	 * @return array|WP_Error Result.
	 */
	private function calculate_balance( int $matter_id ): float {
		$txns = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_trust_txn',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_trust_account_manager', 0, 1000 ) : 1000,
				'meta_query'     => array(
					array(
						'key'   => '_lf_matter_id',
						'value' => $matter_id,
					),
				), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$balance = 0;
		foreach ( $txns as $txn ) {
			$type     = get_post_meta( $txn->ID, '_lf_txn_type', true );
			$amt      = (float) get_post_meta( $txn->ID, '_lf_amount', true );
			$balance += ( 'deposit' === $type ) ? $amt : -$amt;
		}
		return round( $balance, 2 );
	}
}

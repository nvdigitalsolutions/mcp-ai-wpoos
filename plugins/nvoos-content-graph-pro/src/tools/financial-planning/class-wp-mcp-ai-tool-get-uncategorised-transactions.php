<?php
/**
 * Financial planning tool (ecosystem port — Wave F2, financial tools batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-get-uncategorised-transactions.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps where the
 * base references path constants (yfinance service require).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for querying uncategorised financial transactions.
 *
 * Queries the financial account transaction storage and returns
 * transactions that lack a category assignment.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Get_Uncategorised_Transactions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_uncategorised_transactions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Uncategorised Transactions', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves financial transactions that have not been categorised, optionally filtered by date range, account, or amount range. Useful for identifying transactions that need manual or rule-based categorisation.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_from'  => array(
					'type'        => 'string',
					'description' => __( 'Start date for transaction lookup (YYYY-MM-DD format).', 'nvoos-content-graph-pro' ),
					'format'      => 'date',
				),
				'date_to'    => array(
					'type'        => 'string',
					'description' => __( 'End date for transaction lookup (YYYY-MM-DD format).', 'nvoos-content-graph-pro' ),
					'format'      => 'date',
				),
				'account_id' => array(
					'type'        => 'integer',
					'description' => __( 'Financial account post ID to filter by. If omitted, queries across all accounts.', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'min_amount' => array(
					'type'        => 'number',
					'description' => __( 'Minimum transaction amount. Negative for debits.', 'nvoos-content-graph-pro' ),
				),
				'max_amount' => array(
					'type'        => 'number',
					'description' => __( 'Maximum transaction amount.', 'nvoos-content-graph-pro' ),
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of transactions to return. Default: 100.', 'nvoos-content-graph-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'financial_planning',
			'post_type'             => 'financial_account',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'financial_planner', 'accountant' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Financial Planner Toolkit to be enabled.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Get Uncategorised Transactions tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view financial transactions.', 'nvoos-content-graph-pro' )
			);
		}

		// Check if the tool is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$date_from  = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to    = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$account_id = isset( $arguments['account_id'] ) ? absint( $arguments['account_id'] ) : 0;
		$min_amount = isset( $arguments['min_amount'] ) ? floatval( $arguments['min_amount'] ) : null;
		$max_amount = isset( $arguments['max_amount'] ) ? floatval( $arguments['max_amount'] ) : null;
		$limit      = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 100;

		// Query transactions from the financial account post meta.
		$all_transactions = array();

		if ( $account_id > 0 ) {
			// Query specific account.
			$transactions = get_post_meta( $account_id, '_wp_mcp_ai_transactions', true );
			if ( is_array( $transactions ) ) {
				$all_transactions = $transactions;
			}
		} else {
			// Query all financial accounts.
			$posts = get_posts(
				array(
					'post_type'      => 'financial_account',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
				)
			);

			foreach ( $posts as $post_id ) {
				$transactions = get_post_meta( $post_id, '_wp_mcp_ai_transactions', true );
				if ( ! is_array( $transactions ) ) {
					continue;
				}
				foreach ( $transactions as $tx ) {
					$tx['account_id']    = $post_id;
					$tx['account_title'] = get_the_title( $post_id );
					$all_transactions[]  = $tx;
				}
			}
		}

		// Filter for uncategorised transactions.
		$uncategorised = array();
		foreach ( $all_transactions as $tx ) {
			// Skip if a category is already assigned.
			if ( ! empty( $tx['category'] ) || ! empty( $tx['category_id'] ) ) {
				continue;
			}

			$tx_date   = isset( $tx['date'] ) ? $tx['date'] : '';
			$tx_amount = isset( $tx['amount'] ) ? floatval( $tx['amount'] ) : 0;

			// Date range filter.
			if ( ! empty( $date_from ) && $tx_date < $date_from ) {
				continue;
			}
			if ( ! empty( $date_to ) && $tx_date > $date_to ) {
				continue;
			}

			// Amount range filter.
			if ( null !== $min_amount && $tx_amount < $min_amount ) {
				continue;
			}
			if ( null !== $max_amount && $tx_amount > $max_amount ) {
				continue;
			}

			$uncategorised[] = array(
				'transaction_id' => isset( $tx['id'] ) ? sanitize_text_field( $tx['id'] ) : '',
				'date'           => $tx_date,
				'description'    => isset( $tx['description'] ) ? sanitize_text_field( $tx['description'] ) : '',
				'merchant'       => isset( $tx['merchant'] ) ? sanitize_text_field( $tx['merchant'] ) : '',
				'amount'         => $tx_amount,
				'type'           => isset( $tx['type'] ) ? sanitize_text_field( $tx['type'] ) : '',
				'account_id'     => isset( $tx['account_id'] ) ? absint( $tx['account_id'] ) : ( $account_id ? absint( $account_id ) : 0 ),
				'account_title'  => isset( $tx['account_title'] ) ? sanitize_text_field( $tx['account_title'] ) : '',
			);
		}

		// Sort by date descending.
		usort(
			$uncategorised,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		// Apply limit.
		$total_count   = count( $uncategorised );
		$uncategorised = array_slice( $uncategorised, 0, $limit );

		$total_amount = round( array_sum( array_column( $uncategorised, 'amount' ) ), 2 );

		return array(
			'success'      => true,
			'total_found'  => $total_count,
			'returned'     => count( $uncategorised ),
			'total_amount' => $total_amount,
			'filters'      => array(
				'date_from'  => $date_from ? $date_from : null,
				'date_to'    => $date_to ? $date_to : null,
				'account_id' => $account_id ? absint( $account_id ) : null,
				'min_amount' => $min_amount,
				'max_amount' => $max_amount,
				'limit'      => $limit,
			),
			'transactions' => $uncategorised,
			'message'      => sprintf(
				/* translators: 1: Number found, 2: Total amount */
				__( 'Found %1$d uncategorised transactions totaling %2$s.', 'nvoos-content-graph-pro' ),
				$total_count,
				number_format( $total_amount, 2 )
			),
		);
	}
}

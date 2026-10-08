<?php
/**
 * F&B tool: duplicate invoice scan (A3-04).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Flag possible duplicate invoices — flag for checking, never conclude (A3-04).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Duplicate_Invoice_Scan extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_duplicate_invoice_scan';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Duplicate Invoice Scan', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Scan the expense ledger for possible duplicate invoices — same vendor, same invoice number and same amount appearing more than once. Flag for checking; do not conclude (A3-04). Also flags invoices without a budget line match and large price jumps.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'month' => array(
					'type'        => 'string',
					'description' => __( 'Restrict to a month (Y-m). Optional.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$month = $this->arg_month( $arguments );

		$ledger = WP_MCP_AI_Fnb_Data_Source::get_table( '14.0' );
		if ( is_wp_error( $ledger ) ) {
			return $ledger;
		}

		$headers    = $ledger['header_index'];
		$seen       = array();
		$duplicates = array();
		$unmatched  = array();

		$budget_lines = array();
		$budgets      = WP_MCP_AI_Fnb_Data_Source::get_table( '15.0' );
		if ( ! is_wp_error( $budgets ) ) {
			foreach ( $budgets['rows'] as $row ) {
				$budget_lines[] = (string) WP_MCP_AI_Fnb_Data_Source::cell( '15.0', $budgets['header_index'], $row, 'line' );
			}
		}

		foreach ( $ledger['rows'] as $row ) {
			if ( '' !== $month && WP_MCP_AI_Fnb_Data_Source::month_of( WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'for_month' ) ) !== $month ) {
				continue;
			}

			$vendor  = (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'vendor' );
			$invoice = (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'vendor_invoice' );
			$amount  = round( WP_MCP_AI_Fnb_Data_Source::float_cell( WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'amount_lkr' ) ) );
			$line    = (string) WP_MCP_AI_Fnb_Data_Source::cell( '14.0', $headers, $row, 'expense_line' );

			$key = strtolower( $vendor . '|' . $invoice . '|' . $amount );
			if ( isset( $seen[ $key ] ) ) {
				$duplicates[] = array(
					'vendor'     => $vendor,
					'invoice'    => $invoice,
					'amount_lkr' => $amount,
					'flag'       => 'Possible duplicate invoice — flag for checking (A3-04).',
				);
			}
			$seen[ $key ] = true;

			if ( ! in_array( $line, $budget_lines, true ) && ! in_array( $line, array( 'Food and beverage stock' ), true ) ) {
				$unmatched[] = array(
					'vendor'       => $vendor,
					'invoice'      => $invoice,
					'expense_line' => $line,
					'flag'         => 'No matching budget line.',
				);
			}
		}

		return array(
			'success'             => true,
			'month'               => '' !== $month ? $month : null,
			'possible_duplicates' => $duplicates,
			'no_budget_line'      => array_slice( $unmatched, 0, 50 ),
			'note'                => 'Flags only — verify before concluding.',
			'source'              => 'fnb_duplicate_invoice_scan',
		);
	}
}

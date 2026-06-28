<?php
/**
 * Mortgage Calculator Tool
 *
 * Calculate mortgage payments, amortization schedules, refinance analysis,
 * and total interest costs for home loans.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for calculating mortgage payments and refinance scenarios.
 *
 * Supports:
 * - Monthly payment calculation
 * - Amortization schedule generation
 * - Refinance analysis and comparison
 * - Total interest calculation
 * - PMI and property tax inclusion
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Mortgage_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

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

		return __( 'Mortgage calculator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'mortgage_calculator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Mortgage Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate mortgage payments and analyze refinancing options. Includes amortization schedules, total interest costs, PMI, property taxes, and break-even analysis for refinancing decisions.', 'mcp-ai-wpoos-pro' );
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
				'loan_amount'        => array(
					'type'        => 'number',
					'description' => __( 'Mortgage loan amount', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'interest_rate'      => array(
					'type'        => 'number',
					'description' => __( 'Annual interest rate (as percentage)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
				),
				'loan_term_years'    => array(
					'type'        => 'integer',
					'description' => __( 'Loan term in years', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 40,
					'default'     => 30,
				),
				'down_payment'       => array(
					'type'        => 'number',
					'description' => __( 'Down payment amount', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'property_tax'       => array(
					'type'        => 'number',
					'description' => __( 'Annual property tax', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'home_insurance'     => array(
					'type'        => 'number',
					'description' => __( 'Annual home insurance', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'pmi'                => array(
					'type'        => 'number',
					'description' => __( 'Monthly PMI (private mortgage insurance)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'refinance_analysis' => array(
					'type'        => 'boolean',
					'description' => __( 'Include refinance analysis', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'current_balance'    => array(
					'type'        => 'number',
					'description' => __( 'Current remaining balance (for refinance)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'new_interest_rate'  => array(
					'type'        => 'number',
					'description' => __( 'New interest rate (for refinance)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 20,
				),
				'closing_costs'      => array(
					'type'        => 'number',
					'description' => __( 'Refinance closing costs', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'export_xlsx'        => array(
					'type'        => 'boolean',
					'description' => __( 'Export full amortization schedule as an XLSX file URL (requires phpoffice/phpspreadsheet).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'loan_amount', 'interest_rate' ),
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
			'computation',
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the mortgage calculator.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$loan_amount        = isset( $arguments['loan_amount'] ) ? floatval( $arguments['loan_amount'] ) : 0;
		$interest_rate      = isset( $arguments['interest_rate'] ) ? floatval( $arguments['interest_rate'] ) : 0;
		$loan_term_years    = isset( $arguments['loan_term_years'] ) ? absint( $arguments['loan_term_years'] ) : 30;
		$down_payment       = isset( $arguments['down_payment'] ) ? floatval( $arguments['down_payment'] ) : 0;
		$property_tax       = isset( $arguments['property_tax'] ) ? floatval( $arguments['property_tax'] ) : 0;
		$home_insurance     = isset( $arguments['home_insurance'] ) ? floatval( $arguments['home_insurance'] ) : 0;
		$pmi                = isset( $arguments['pmi'] ) ? floatval( $arguments['pmi'] ) : 0;
		$refinance_analysis = isset( $arguments['refinance_analysis'] ) ? (bool) $arguments['refinance_analysis'] : false;

		if ( $loan_amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Loan amount must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		$monthly_rate = ( $interest_rate / 100 ) / 12;
		$num_payments = $loan_term_years * 12;

		if ( $monthly_rate > 0 ) {
			$monthly_payment = $loan_amount * ( $monthly_rate * pow( 1 + $monthly_rate, $num_payments ) ) / ( pow( 1 + $monthly_rate, $num_payments ) - 1 );
		} else {
			$monthly_payment = $loan_amount / $num_payments;
		}

		$monthly_property_tax  = $property_tax / 12;
		$monthly_insurance     = $home_insurance / 12;
		$total_monthly_payment = $monthly_payment + $monthly_property_tax + $monthly_insurance + $pmi;

		$total_paid     = $monthly_payment * $num_payments;
		$total_interest = $total_paid - $loan_amount;

		$result = array(
			'success'               => true,
			'loan_amount'           => $loan_amount,
			'down_payment'          => $down_payment,
			'interest_rate'         => $interest_rate,
			'loan_term_years'       => $loan_term_years,
			'monthly_payment'       => round( $monthly_payment, 2 ),
			'monthly_property_tax'  => round( $monthly_property_tax, 2 ),
			'monthly_insurance'     => round( $monthly_insurance, 2 ),
			'monthly_pmi'           => $pmi,
			'total_monthly_payment' => round( $total_monthly_payment, 2 ),
			'total_paid'            => round( $total_paid, 2 ),
			'total_interest'        => round( $total_interest, 2 ),
			'message'               => sprintf(
				/* translators: 1: Monthly payment, 2: Total interest */
				__( 'Monthly payment: $%1$s (P&I). Total interest: $%2$s.', 'mcp-ai-wpoos-pro' ),
				number_format( $monthly_payment, 2 ),
				number_format( $total_interest, 2 )
			),
		);

		if ( $refinance_analysis ) {
			$current_balance   = isset( $arguments['current_balance'] ) ? floatval( $arguments['current_balance'] ) : $loan_amount;
			$new_interest_rate = isset( $arguments['new_interest_rate'] ) ? floatval( $arguments['new_interest_rate'] ) : $interest_rate;
			$closing_costs     = isset( $arguments['closing_costs'] ) ? floatval( $arguments['closing_costs'] ) : 0;

			$new_monthly_rate = ( $new_interest_rate / 100 ) / 12;
			if ( $new_monthly_rate > 0 ) {
				$new_monthly_payment = $current_balance * ( $new_monthly_rate * pow( 1 + $new_monthly_rate, $num_payments ) ) / ( pow( 1 + $new_monthly_rate, $num_payments ) - 1 );
			} else {
				$new_monthly_payment = $current_balance / $num_payments;
			}

			$monthly_savings   = $monthly_payment - $new_monthly_payment;
			$break_even_months = $monthly_savings > 0 ? ceil( $closing_costs / $monthly_savings ) : 0;

			$result['refinance'] = array(
				'new_monthly_payment' => round( $new_monthly_payment, 2 ),
				'monthly_savings'     => round( $monthly_savings, 2 ),
				'closing_costs'       => $closing_costs,
				'break_even_months'   => $break_even_months,
				'break_even_years'    => round( $break_even_months / 12, 1 ),
				'recommended'         => $break_even_months > 0 && $break_even_months < 36,
			);

			$result['message'] .= ' ' . sprintf(
				/* translators: 1: Monthly savings, 2: Break-even months */
				__( 'Refinance saves $%1$s/month, break-even in %2$d months.', 'mcp-ai-wpoos-pro' ),
				number_format( $monthly_savings, 2 ),
				$break_even_months
			);
		}

		// ── XLSX amortization schedule export ─────────────────────────────────
		if ( ! empty( $arguments['export_xlsx'] ) ) {
			$xlsx_result = $this->export_amortization_xlsx(
				$loan_amount,
				$interest_rate,
				$loan_term_years,
				$monthly_payment
			);
			if ( ! is_wp_error( $xlsx_result ) ) {
				$result['amortization_xlsx'] = $xlsx_result;
			} else {
				$result['amortization_xlsx_error'] = $xlsx_result->get_error_message();
			}
		}

		return $result;
	}

	/**
	 * Generate a full amortization schedule and save it as an XLSX file.
	 *
	 * Uses phpoffice/phpspreadsheet (included in Pro vendor directory).
	 *
	 * @since 1.4.0
	 *
	 * @param float $loan_amount     Original loan principal.
	 * @param float $interest_rate   Annual interest rate (percentage).
	 * @param int   $loan_term_years Loan term in years.
	 * @param float $monthly_payment Computed monthly payment.
	 * @return string|WP_Error Public URL to the generated XLSX, or WP_Error on failure.
	 */
	private function export_amortization_xlsx( $loan_amount, $interest_rate, $loan_term_years, $monthly_payment ) {
		$autoload = WP_MCP_AI_PRO_PATH . 'vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			return new WP_Error(
				'phpspreadsheet_missing',
				__( 'phpoffice/phpspreadsheet vendor autoloader not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		require_once $autoload;

		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			return new WP_Error(
				'phpspreadsheet_missing',
				__( 'PhpSpreadsheet class not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'Amortization Schedule' );

		// Header row.
		$headers = array(
			'A1' => __( 'Payment #', 'mcp-ai-wpoos-pro' ),
			'B1' => __( 'Payment', 'mcp-ai-wpoos-pro' ),
			'C1' => __( 'Principal', 'mcp-ai-wpoos-pro' ),
			'D1' => __( 'Interest', 'mcp-ai-wpoos-pro' ),
			'E1' => __( 'Balance', 'mcp-ai-wpoos-pro' ),
		);
		foreach ( $headers as $cell => $label ) {
			$sheet->setCellValue( $cell, $label );
		}

		// Bold headers.
		$sheet->getStyle( 'A1:E1' )->getFont()->setBold( true );

		$monthly_rate = ( $interest_rate / 100 ) / 12;
		$balance      = $loan_amount;
		$num_payments = $loan_term_years * 12;
		$row          = 2;

		for ( $i = 1; $i <= $num_payments; $i++ ) {
			$interest_payment  = $balance * $monthly_rate;
			$principal_payment = $monthly_payment - $interest_payment;
			$balance           = max( 0, $balance - $principal_payment );

			$sheet->setCellValue( 'A' . $row, $i );
			$sheet->setCellValue( 'B' . $row, round( $monthly_payment, 2 ) );
			$sheet->setCellValue( 'C' . $row, round( $principal_payment, 2 ) );
			$sheet->setCellValue( 'D' . $row, round( $interest_payment, 2 ) );
			$sheet->setCellValue( 'E' . $row, round( $balance, 2 ) );
			++$row;
		}

		// Auto-size columns.
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $col ) {
			$sheet->getColumnDimension( $col )->setAutoSize( true );
		}

		// Save to uploads directory.
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-wpoos/exports/';
		wp_mkdir_p( $dir );

		$filename = 'amortization-' . uniqid( '', true ) . '.xlsx';
		$filepath = $dir . $filename;

		$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter( $spreadsheet, 'Xlsx' );
		$writer->save( $filepath );

		$public_url = trailingslashit( $upload_dir['baseurl'] ) . 'mcp-ai-wpoos/exports/' . $filename;

		return $public_url;
	}
}

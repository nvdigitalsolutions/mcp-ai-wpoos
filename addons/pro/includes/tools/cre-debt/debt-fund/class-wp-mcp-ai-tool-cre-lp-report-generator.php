<?php
/**
 * CRE LP Report Generator — Generate formatted quarterly LP reports
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Generates a formatted quarterly LP report with executive summary,
 * fund performance, capital account, portfolio summary, and market commentary.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_LP_Report_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_lp_report_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE LP Report Generator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Generate a formatted quarterly LP report with executive summary, fund performance metrics, capital account details, portfolio summary, and market commentary.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'fund_name'         => array(
					'type'        => 'string',
					'description' => __( 'Name of the fund.', 'mcp-ai-wpoos-pro' ),
				),
				'reporting_period'  => array(
					'type'        => 'string',
					'description' => __( 'Reporting period label (e.g. "Q4 2025").', 'mcp-ai-wpoos-pro' ),
				),
				'vintage_year'      => array(
					'type'        => 'integer',
					'description' => __( 'Fund vintage year.', 'mcp-ai-wpoos-pro' ),
				),
				'total_commitments' => array(
					'type'        => 'number',
					'description' => __( 'Total LP + GP commitments.', 'mcp-ai-wpoos-pro' ),
				),
				'called_capital'    => array(
					'type'        => 'number',
					'description' => __( 'Total capital called to date.', 'mcp-ai-wpoos-pro' ),
				),
				'distributions'     => array(
					'type'        => 'number',
					'description' => __( 'Total distributions paid to date.', 'mcp-ai-wpoos-pro' ),
				),
				'nav'               => array(
					'type'        => 'number',
					'description' => __( 'Current net asset value.', 'mcp-ai-wpoos-pro' ),
				),
				'net_irr'           => array(
					'type'        => 'number',
					'description' => __( 'Net IRR as decimal (e.g. 0.12 for 12%).', 'mcp-ai-wpoos-pro' ),
				),
				'gross_irr'         => array(
					'type'        => 'number',
					'description' => __( 'Gross IRR as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'equity_multiple'   => array(
					'type'        => 'number',
					'description' => __( 'Net equity multiple (e.g. 1.45).', 'mcp-ai-wpoos-pro' ),
				),
				'deployment_pct'    => array(
					'type'        => 'number',
					'description' => __( 'Capital deployment percentage as decimal.', 'mcp-ai-wpoos-pro' ),
				),
				'num_investments'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of investments in portfolio.', 'mcp-ai-wpoos-pro' ),
				),
				'portfolio_summary' => array(
					'type'        => 'array',
					'description' => __( 'Array of portfolio investment summary objects.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'    => array(
								'type'        => 'string',
								'description' => __( 'Investment name.', 'mcp-ai-wpoos-pro' ),
							),
							'balance' => array(
								'type'        => 'number',
								'description' => __( 'Current balance.', 'mcp-ai-wpoos-pro' ),
							),
							'status'  => array(
								'type'        => 'string',
								'description' => __( 'Investment status.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'name', 'balance', 'status' ),
					),
				),
				'market_commentary' => array(
					'type'        => 'string',
					'description' => __( 'Market commentary text for the reporting period.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'fund_name', 'reporting_period', 'total_commitments', 'called_capital', 'distributions', 'nav' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ): array|\WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$fund_name       = sanitize_text_field( $arguments['fund_name'] ?? '' );
		$period          = sanitize_text_field( $arguments['reporting_period'] ?? '' );
		$vintage         = (int) ( $arguments['vintage_year'] ?? 0 );
		$commitments     = (float) ( $arguments['total_commitments'] ?? 0 );
		$called          = (float) ( $arguments['called_capital'] ?? 0 );
		$distributions   = (float) ( $arguments['distributions'] ?? 0 );
		$nav             = (float) ( $arguments['nav'] ?? 0 );
		$net_irr         = (float) ( $arguments['net_irr'] ?? 0 );
		$gross_irr       = (float) ( $arguments['gross_irr'] ?? 0 );
		$equity_multiple = (float) ( $arguments['equity_multiple'] ?? 0 );
		$deployment_pct  = (float) ( $arguments['deployment_pct'] ?? 0 );
		$num_investments = (int) ( $arguments['num_investments'] ?? 0 );
		$portfolio       = $arguments['portfolio_summary'] ?? array();
		$commentary      = sanitize_textarea_field( $arguments['market_commentary'] ?? '' );

		if ( empty( $fund_name ) || empty( $period ) ) {
			return new \WP_Error( 'invalid_input', __( 'Fund name and reporting period are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Derived metrics.
		$unfunded    = max( 0, $commitments - $called );
		$total_value = $distributions + $nav;
		$dpi         = ( $called > 0 ) ? $distributions / $called : 0;
		$rvpi        = ( $called > 0 ) ? $nav / $called : 0;
		$tvpi        = $dpi + $rvpi;
		$pct_called  = ( $commitments > 0 ) ? $called / $commitments : 0;

		// Build report sections.
		$sections = array();

		// 1. Executive Summary.
		$sections['executive_summary'] = sprintf(
			"%s — %s Quarterly Report\n%s\n\n" .
			"Fund vintage: %s | Investments: %d | Deployment: %s\n\n" .
			'The fund has called %s of %s in total commitments (%s). ' .
			'Total value to date stands at %s, comprising %s in distributions and %s in residual NAV. ' .
			'The net equity multiple is %sx with a net IRR of %s.',
			$fund_name,
			$period,
			str_repeat( '=', 60 ),
			$vintage > 0 ? (string) $vintage : __( 'N/A', 'mcp-ai-wpoos-pro' ),
			$num_investments,
			$calc::format_percentage( $deployment_pct ),
			$calc::format_currency( $called ),
			$calc::format_currency( $commitments ),
			$calc::format_percentage( $pct_called ),
			$calc::format_currency( $total_value ),
			$calc::format_currency( $distributions ),
			$calc::format_currency( $nav ),
			number_format( $equity_multiple, 2 ),
			$calc::format_percentage( $net_irr )
		);

		// 2. Fund Performance.
		$sections['fund_performance'] = array(
			'gross_irr'       => $calc::format_percentage( $gross_irr ),
			'net_irr'         => $calc::format_percentage( $net_irr ),
			'equity_multiple' => round( $equity_multiple, 2 ) . 'x',
			'dpi'             => round( $dpi, 2 ) . 'x',
			'rvpi'            => round( $rvpi, 2 ) . 'x',
			'tvpi'            => round( $tvpi, 2 ) . 'x',
		);

		// 3. Capital Account.
		$sections['capital_account'] = array(
			'total_commitments' => $calc::format_currency( $commitments ),
			'called_capital'    => $calc::format_currency( $called ),
			'pct_called'        => $calc::format_percentage( $pct_called ),
			'unfunded'          => $calc::format_currency( $unfunded ),
			'distributions'     => $calc::format_currency( $distributions ),
			'current_nav'       => $calc::format_currency( $nav ),
			'total_value'       => $calc::format_currency( $total_value ),
		);

		// 4. Portfolio Summary.
		$portfolio_rows = array();
		foreach ( $portfolio as $inv ) {
			$inv_balance      = (float) ( $inv['balance'] ?? 0 );
			$portfolio_rows[] = array(
				'name'    => sanitize_text_field( $inv['name'] ?? '' ),
				'balance' => $calc::format_currency( $inv_balance ),
				'status'  => sanitize_text_field( $inv['status'] ?? '' ),
				'pct_nav' => ( $nav > 0 ) ? $calc::format_percentage( $inv_balance / $nav ) : '0.00%',
			);
		}
		$sections['portfolio_summary'] = $portfolio_rows;

		// 5. Market Commentary.
		$sections['market_commentary'] = ! empty( $commentary )
			? $commentary
			: __( 'No market commentary provided for this period.', 'mcp-ai-wpoos-pro' );

		// Generate full formatted report text.
		$report_text  = $sections['executive_summary'] . "\n\n";
		$report_text .= "FUND PERFORMANCE\n" . str_repeat( '-', 40 ) . "\n";
		foreach ( $sections['fund_performance'] as $label => $val ) {
			$report_text .= sprintf( "  %-20s %s\n", str_replace( '_', ' ', ucfirst( $label ) ) . ':', $val );
		}
		$report_text .= "\nCAPITAL ACCOUNT\n" . str_repeat( '-', 40 ) . "\n";
		foreach ( $sections['capital_account'] as $label => $val ) {
			$report_text .= sprintf( "  %-20s %s\n", str_replace( '_', ' ', ucfirst( $label ) ) . ':', $val );
		}
		if ( ! empty( $portfolio_rows ) ) {
			$report_text .= "\nPORTFOLIO SUMMARY\n" . str_repeat( '-', 40 ) . "\n";
			foreach ( $portfolio_rows as $row ) {
				$report_text .= sprintf( "  %-30s %15s  [%s]\n", $row['name'], $row['balance'], $row['status'] );
			}
		}
		$report_text .= "\nMARKET COMMENTARY\n" . str_repeat( '-', 40 ) . "\n";
		$report_text .= $sections['market_commentary'] . "\n";

		return array(
			'success'    => true,
			'message'    => __( 'LP report generated. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => array(
				'fund_name'        => $fund_name,
				'reporting_period' => $period,
				'sections'         => $sections,
				'report_text'      => $report_text,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

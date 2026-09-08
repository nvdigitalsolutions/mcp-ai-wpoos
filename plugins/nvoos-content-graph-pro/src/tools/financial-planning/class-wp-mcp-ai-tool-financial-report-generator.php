<?php
/**
 * Financial planning tool (ecosystem port — Wave F2, financial tools batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-financial-report-generator.php` for the standalone
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
 * Tool for generating structured financial reports.
 *
 * Supports:
 * - Portfolio summary reports
 * - Market analysis reports
 * - Investment thesis reports
 * - Risk assessment reports
 * - Earnings summary reports
 * - Sector comparison reports
 * - Markdown and HTML output formats
 * - Chart configuration suggestions
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Financial_Report_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Financial report generator tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'financial_report_generator';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Financial Report Generator', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate structured professional financial reports from provided data. Supports portfolio summaries, market analysis, investment theses, risk assessments, earnings summaries, and sector comparisons. Output in Markdown or HTML. EDUCATIONAL ONLY - Not investment advice.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_type'           => array(
					'type'        => 'string',
					'description' => __( 'Type of financial report to generate.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'portfolio_summary', 'market_analysis', 'investment_thesis', 'risk_assessment', 'earnings_summary', 'sector_comparison' ),
				),
				'title'                 => array(
					'type'        => 'string',
					'description' => __( 'Report title.', 'nvoos-content-graph-pro' ),
				),
				'data'                  => array(
					'type'        => 'object',
					'description' => __( 'Report data. Structure varies by report_type.', 'nvoos-content-graph-pro' ),
				),
				'sections'              => array(
					'type'        => 'array',
					'description' => __( 'Override which sections to include in the report.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'executive_summary', 'key_metrics', 'analysis', 'risks', 'recommendations', 'methodology', 'disclaimers' ),
					),
				),
				'format'                => array(
					'type'        => 'string',
					'description' => __( 'Output format.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'markdown', 'html' ),
					'default'     => 'markdown',
				),
				'include_charts_config' => array(
					'type'        => 'boolean',
					'description' => __( 'Include chart configuration suggestions for visualization.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'report_type', 'title', 'data' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
				__( 'You do not have permission to generate financial reports.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$report_type    = isset( $arguments['report_type'] ) ? sanitize_text_field( $arguments['report_type'] ) : '';
		$title          = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$data           = isset( $arguments['data'] ) && is_array( $arguments['data'] ) ? $arguments['data'] : array();
		$sections       = isset( $arguments['sections'] ) && is_array( $arguments['sections'] ) ? array_map( 'sanitize_text_field', $arguments['sections'] ) : array();
		$format         = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'markdown';
		$include_charts = isset( $arguments['include_charts_config'] ) ? (bool) $arguments['include_charts_config'] : true;

		$valid_types = array( 'portfolio_summary', 'market_analysis', 'investment_thesis', 'risk_assessment', 'earnings_summary', 'sector_comparison' );
		if ( ! in_array( $report_type, $valid_types, true ) ) {
			return new WP_Error(
				'invalid_report_type',
				__( 'Invalid report type. Must be one of: portfolio_summary, market_analysis, investment_thesis, risk_assessment, earnings_summary, sector_comparison.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'Report title is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'missing_data', __( 'Report data is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( ! in_array( $format, array( 'markdown', 'html' ), true ) ) {
			$format = 'markdown';
		}

		// Default sections if not specified.
		if ( empty( $sections ) ) {
			$sections = array( 'executive_summary', 'key_metrics', 'analysis', 'risks', 'recommendations', 'disclaimers' );
		}

		// Generate report content.
		$report_content = $this->generate_report( $report_type, $title, $data, $sections );

		// Convert to HTML if requested.
		if ( 'html' === $format ) {
			$report_content = $this->markdown_to_html( $report_content );
		}

		// Generate chart configurations.
		$charts_config = array();
		if ( $include_charts ) {
			$charts_config = $this->generate_charts_config( $report_type, $data );
		}

		return array(
			'success'       => true,
			'report_type'   => $report_type,
			'title'         => $title,
			'format'        => $format,
			'content'       => $report_content,
			'sections'      => $sections,
			'charts_config' => $charts_config,
			'generated_at'  => current_time( 'mysql' ),
			'disclaimer'    => __( 'EDUCATIONAL ONLY. This report is generated from user-provided data and is for informational purposes only. It does not constitute investment advice, financial analysis, or a recommendation to buy or sell securities. Always consult a licensed financial advisor.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Generate report content in Markdown format.
	 *
	 * @since 1.1.0
	 *
	 * @param string $report_type Report type.
	 * @param string $title       Report title.
	 * @param array  $data        Report data.
	 * @param array  $sections    Sections to include.
	 * @return string Markdown report content.
	 */
	private function generate_report( $report_type, $title, $data, $sections ) {
		$lines = array();

		$lines[] = '# ' . $title;
		$lines[] = '';
		$lines[] = '**' . __( 'Report Type', 'nvoos-content-graph-pro' ) . ':** ' . $this->format_report_type( $report_type );
		$lines[] = '**' . __( 'Generated', 'nvoos-content-graph-pro' ) . ':** ' . current_time( 'F j, Y g:i A' );
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';

		switch ( $report_type ) {
			case 'portfolio_summary':
				$lines = array_merge( $lines, $this->generate_portfolio_summary( $data, $sections ) );
				break;

			case 'market_analysis':
				$lines = array_merge( $lines, $this->generate_market_analysis( $data, $sections ) );
				break;

			case 'investment_thesis':
				$lines = array_merge( $lines, $this->generate_investment_thesis( $data, $sections ) );
				break;

			case 'risk_assessment':
				$lines = array_merge( $lines, $this->generate_risk_assessment( $data, $sections ) );
				break;

			case 'earnings_summary':
				$lines = array_merge( $lines, $this->generate_earnings_summary( $data, $sections ) );
				break;

			case 'sector_comparison':
				$lines = array_merge( $lines, $this->generate_sector_comparison( $data, $sections ) );
				break;
		}

		// Add disclaimers section.
		if ( in_array( 'disclaimers', $sections, true ) ) {
			$lines[] = '';
			$lines[] = '---';
			$lines[] = '';
			$lines[] = '## ' . __( 'Disclaimers', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '*' . __( 'This report is generated for educational and informational purposes only. It does not constitute investment advice, financial analysis, or a recommendation to buy, sell, or hold any security. Past performance does not guarantee future results. Always consult with a qualified financial advisor before making investment decisions.', 'nvoos-content-graph-pro' ) . '*';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Generate portfolio summary report sections.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data     Report data.
	 * @param array $sections Sections to include.
	 * @return array Lines of Markdown content.
	 */
	private function generate_portfolio_summary( $data, $sections ) {
		$lines    = array();
		$holdings = isset( $data['holdings'] ) && is_array( $data['holdings'] ) ? $data['holdings'] : array();

		$total_value     = 0;
		$total_cost      = 0;
		$allocation_data = array();

		foreach ( $holdings as $holding ) {
			$shares = isset( $holding['shares'] ) ? floatval( $holding['shares'] ) : 0;
			$price  = isset( $holding['current_price'] ) ? floatval( $holding['current_price'] ) : 0;
			$cost   = isset( $holding['cost_basis'] ) ? floatval( $holding['cost_basis'] ) : $price;
			$class  = isset( $holding['asset_class'] ) ? sanitize_text_field( $holding['asset_class'] ) : 'other';

			$value        = $shares * $price;
			$total_value += $value;
			$total_cost  += $shares * $cost;

			if ( ! isset( $allocation_data[ $class ] ) ) {
				$allocation_data[ $class ] = 0;
			}
			$allocation_data[ $class ] += $value;
		}

		$total_gain = $total_value - $total_cost;
		$return_pct = $total_cost > 0 ? ( $total_gain / $total_cost ) * 100 : 0;

		if ( in_array( 'executive_summary', $sections, true ) ) {
			$lines[] = '## ' . __( 'Executive Summary', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: 1: total value, 2: return percentage */
				__( 'Portfolio total value: **$%1$s** with a total return of **%2$s%%**. The portfolio contains %3$d holdings across %4$d asset classes.', 'nvoos-content-graph-pro' ),
				number_format( $total_value, 2 ),
				number_format( $return_pct, 2 ),
				count( $holdings ),
				count( $allocation_data )
			);
			$lines[] = '';
		}

		if ( in_array( 'key_metrics', $sections, true ) ) {
			$lines[] = '## ' . __( 'Key Metrics', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '| ' . __( 'Metric', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Value', 'nvoos-content-graph-pro' ) . ' |';
			$lines[] = '|---|---|';
			$lines[] = '| ' . __( 'Total Value', 'nvoos-content-graph-pro' ) . ' | $' . number_format( $total_value, 2 ) . ' |';
			$lines[] = '| ' . __( 'Total Cost Basis', 'nvoos-content-graph-pro' ) . ' | $' . number_format( $total_cost, 2 ) . ' |';
			$lines[] = '| ' . __( 'Total Gain/Loss', 'nvoos-content-graph-pro' ) . ' | $' . number_format( $total_gain, 2 ) . ' |';
			$lines[] = '| ' . __( 'Total Return', 'nvoos-content-graph-pro' ) . ' | ' . number_format( $return_pct, 2 ) . '% |';
			$lines[] = '| ' . __( 'Holdings Count', 'nvoos-content-graph-pro' ) . ' | ' . count( $holdings ) . ' |';
			$lines[] = '';
		}

		if ( in_array( 'analysis', $sections, true ) ) {
			$lines[] = '## ' . __( 'Asset Allocation', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '| ' . __( 'Asset Class', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Value', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Allocation', 'nvoos-content-graph-pro' ) . ' |';
			$lines[] = '|---|---|---|';
			foreach ( $allocation_data as $class => $value ) {
				$pct     = $total_value > 0 ? ( $value / $total_value ) * 100 : 0;
				$lines[] = '| ' . ucfirst( $class ) . ' | $' . number_format( $value, 2 ) . ' | ' . number_format( $pct, 1 ) . '% |';
			}
			$lines[] = '';

			$lines[] = '### ' . __( 'Holdings Detail', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '| ' . __( 'Ticker', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Shares', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Price', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Value', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Gain/Loss', 'nvoos-content-graph-pro' ) . ' |';
			$lines[] = '|---|---|---|---|---|';
			foreach ( $holdings as $h ) {
				$ticker = isset( $h['ticker'] ) ? sanitize_text_field( $h['ticker'] ) : 'N/A';
				$shares = isset( $h['shares'] ) ? floatval( $h['shares'] ) : 0;
				$price  = isset( $h['current_price'] ) ? floatval( $h['current_price'] ) : 0;
				$cost   = isset( $h['cost_basis'] ) ? floatval( $h['cost_basis'] ) : $price;
				$value  = $shares * $price;
				$gain   = $value - ( $shares * $cost );

				$lines[] = '| ' . $ticker . ' | ' . number_format( $shares, 2 ) . ' | $' . number_format( $price, 2 ) . ' | $' . number_format( $value, 2 ) . ' | $' . number_format( $gain, 2 ) . ' |';
			}
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * Generate market analysis report sections.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data     Report data.
	 * @param array $sections Sections to include.
	 * @return array Lines of Markdown content.
	 */
	private function generate_market_analysis( $data, $sections ) {
		$lines   = array();
		$indices = isset( $data['indices'] ) && is_array( $data['indices'] ) ? $data['indices'] : array();
		$trends  = isset( $data['trends'] ) && is_array( $data['trends'] ) ? $data['trends'] : array();

		if ( in_array( 'executive_summary', $sections, true ) ) {
			$lines[] = '## ' . __( 'Executive Summary', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$summary = isset( $data['summary'] ) ? sanitize_text_field( $data['summary'] ) : __( 'Market analysis based on provided index and trend data.', 'nvoos-content-graph-pro' );
			$lines[] = $summary;
			$lines[] = '';
		}

		if ( in_array( 'key_metrics', $sections, true ) && ! empty( $indices ) ) {
			$lines[] = '## ' . __( 'Market Indices', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '| ' . __( 'Index', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Value', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Change', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Change %', 'nvoos-content-graph-pro' ) . ' |';
			$lines[] = '|---|---|---|---|';
			foreach ( $indices as $idx ) {
				$name   = isset( $idx['name'] ) ? sanitize_text_field( $idx['name'] ) : '';
				$value  = isset( $idx['value'] ) ? floatval( $idx['value'] ) : 0;
				$change = isset( $idx['change'] ) ? floatval( $idx['change'] ) : 0;
				$pct    = isset( $idx['change_pct'] ) ? floatval( $idx['change_pct'] ) : 0;

				$lines[] = '| ' . $name . ' | ' . number_format( $value, 2 ) . ' | ' . number_format( $change, 2 ) . ' | ' . number_format( $pct, 2 ) . '% |';
			}
			$lines[] = '';
		}

		if ( in_array( 'analysis', $sections, true ) && ! empty( $trends ) ) {
			$lines[] = '## ' . __( 'Trend Analysis', 'nvoos-content-graph-pro' );
			$lines[] = '';
			foreach ( $trends as $trend ) {
				$name        = isset( $trend['name'] ) ? sanitize_text_field( $trend['name'] ) : '';
				$description = isset( $trend['description'] ) ? sanitize_text_field( $trend['description'] ) : '';
				$lines[]     = '- **' . $name . '**: ' . $description;
			}
			$lines[] = '';
		}

		if ( in_array( 'risks', $sections, true ) ) {
			$lines[]      = '## ' . __( 'Key Risks', 'nvoos-content-graph-pro' );
			$lines[]      = '';
			$risk_factors = isset( $data['risk_factors'] ) && is_array( $data['risk_factors'] ) ? $data['risk_factors'] : array();
			if ( ! empty( $risk_factors ) ) {
				foreach ( $risk_factors as $risk ) {
					$lines[] = '- ' . sanitize_text_field( is_string( $risk ) ? $risk : '' );
				}
			} else {
				$lines[] = __( 'No specific risk factors provided.', 'nvoos-content-graph-pro' );
			}
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * Generate investment thesis report sections.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data     Report data.
	 * @param array $sections Sections to include.
	 * @return array Lines of Markdown content.
	 */
	private function generate_investment_thesis( $data, $sections ) {
		$lines  = array();
		$ticker = isset( $data['ticker'] ) ? sanitize_text_field( $data['ticker'] ) : '';
		$thesis = isset( $data['thesis'] ) ? sanitize_text_field( $data['thesis'] ) : '';

		if ( in_array( 'executive_summary', $sections, true ) ) {
			$lines[] = '## ' . __( 'Investment Thesis', 'nvoos-content-graph-pro' );
			$lines[] = '';
			if ( ! empty( $ticker ) ) {
				$lines[] = '**' . __( 'Ticker', 'nvoos-content-graph-pro' ) . ':** ' . $ticker;
			}
			$lines[] = '';
			$lines[] = $thesis;
			$lines[] = '';
		}

		if ( in_array( 'analysis', $sections, true ) ) {
			$bull_case = isset( $data['bull_case'] ) && is_array( $data['bull_case'] ) ? $data['bull_case'] : array();
			$bear_case = isset( $data['bear_case'] ) && is_array( $data['bear_case'] ) ? $data['bear_case'] : array();
			$catalysts = isset( $data['catalysts'] ) && is_array( $data['catalysts'] ) ? $data['catalysts'] : array();

			$lines[] = '### ' . __( 'Bull Case', 'nvoos-content-graph-pro' );
			$lines[] = '';
			if ( ! empty( $bull_case ) ) {
				foreach ( $bull_case as $point ) {
					$lines[] = '- ' . sanitize_text_field( is_string( $point ) ? $point : '' );
				}
			} else {
				$lines[] = __( 'No bull case points provided.', 'nvoos-content-graph-pro' );
			}
			$lines[] = '';

			$lines[] = '### ' . __( 'Bear Case', 'nvoos-content-graph-pro' );
			$lines[] = '';
			if ( ! empty( $bear_case ) ) {
				foreach ( $bear_case as $point ) {
					$lines[] = '- ' . sanitize_text_field( is_string( $point ) ? $point : '' );
				}
			} else {
				$lines[] = __( 'No bear case points provided.', 'nvoos-content-graph-pro' );
			}
			$lines[] = '';

			$lines[] = '### ' . __( 'Catalysts', 'nvoos-content-graph-pro' );
			$lines[] = '';
			if ( ! empty( $catalysts ) ) {
				foreach ( $catalysts as $catalyst ) {
					$lines[] = '- ' . sanitize_text_field( is_string( $catalyst ) ? $catalyst : '' );
				}
			} else {
				$lines[] = __( 'No catalysts provided.', 'nvoos-content-graph-pro' );
			}
			$lines[] = '';
		}

		if ( in_array( 'key_metrics', $sections, true ) ) {
			$supporting = isset( $data['supporting_data'] ) && is_array( $data['supporting_data'] ) ? $data['supporting_data'] : array();
			if ( ! empty( $supporting ) ) {
				$lines[] = '## ' . __( 'Supporting Data', 'nvoos-content-graph-pro' );
				$lines[] = '';
				$lines[] = '| ' . __( 'Metric', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Value', 'nvoos-content-graph-pro' ) . ' |';
				$lines[] = '|---|---|';
				foreach ( $supporting as $key => $value ) {
					$lines[] = '| ' . sanitize_text_field( $key ) . ' | ' . sanitize_text_field( (string) $value ) . ' |';
				}
				$lines[] = '';
			}
		}

		return $lines;
	}

	/**
	 * Generate risk assessment report sections.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data     Report data.
	 * @param array $sections Sections to include.
	 * @return array Lines of Markdown content.
	 */
	private function generate_risk_assessment( $data, $sections ) {
		$lines        = array();
		$risk_factors = isset( $data['risk_factors'] ) && is_array( $data['risk_factors'] ) ? $data['risk_factors'] : array();

		if ( in_array( 'executive_summary', $sections, true ) ) {
			$lines[] = '## ' . __( 'Risk Assessment Summary', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: number of risk factors */
				__( 'This assessment identifies and evaluates %d risk factors.', 'nvoos-content-graph-pro' ),
				count( $risk_factors )
			);
			$lines[] = '';
		}

		if ( in_array( 'analysis', $sections, true ) && ! empty( $risk_factors ) ) {
			$lines[] = '## ' . __( 'Risk Factors', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '| ' . __( 'Risk', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Severity', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Likelihood', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Mitigation', 'nvoos-content-graph-pro' ) . ' |';
			$lines[] = '|---|---|---|---|';
			foreach ( $risk_factors as $risk ) {
				if ( is_array( $risk ) ) {
					$name       = isset( $risk['name'] ) ? sanitize_text_field( $risk['name'] ) : '';
					$severity   = isset( $risk['severity'] ) ? sanitize_text_field( $risk['severity'] ) : 'medium';
					$likelihood = isset( $risk['likelihood'] ) ? sanitize_text_field( $risk['likelihood'] ) : 'medium';
					$mitigation = isset( $risk['mitigation'] ) ? sanitize_text_field( $risk['mitigation'] ) : '';
					$lines[]    = '| ' . $name . ' | ' . ucfirst( $severity ) . ' | ' . ucfirst( $likelihood ) . ' | ' . $mitigation . ' |';
				} elseif ( is_string( $risk ) ) {
					$lines[] = '| ' . sanitize_text_field( $risk ) . ' | - | - | - |';
				}
			}
			$lines[] = '';
		}

		if ( in_array( 'recommendations', $sections, true ) ) {
			$lines[]     = '## ' . __( 'Risk Mitigation Recommendations', 'nvoos-content-graph-pro' );
			$lines[]     = '';
			$mitigations = isset( $data['mitigations'] ) && is_array( $data['mitigations'] ) ? $data['mitigations'] : array();
			if ( ! empty( $mitigations ) ) {
				foreach ( $mitigations as $idx => $mitigation ) {
					$lines[] = ( $idx + 1 ) . '. ' . sanitize_text_field( is_string( $mitigation ) ? $mitigation : '' );
				}
			} else {
				$lines[] = __( 'Diversification and regular portfolio review are recommended as general risk mitigation strategies.', 'nvoos-content-graph-pro' );
			}
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * Generate earnings summary report sections.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data     Report data.
	 * @param array $sections Sections to include.
	 * @return array Lines of Markdown content.
	 */
	private function generate_earnings_summary( $data, $sections ) {
		$lines   = array();
		$company = isset( $data['company'] ) ? sanitize_text_field( $data['company'] ) : '';
		$revenue = isset( $data['revenue'] ) ? floatval( $data['revenue'] ) : 0;
		$eps     = isset( $data['eps'] ) ? floatval( $data['eps'] ) : 0;

		$revenue_estimate = isset( $data['revenue_estimate'] ) ? floatval( $data['revenue_estimate'] ) : 0;
		$eps_estimate     = isset( $data['eps_estimate'] ) ? floatval( $data['eps_estimate'] ) : 0;
		$guidance         = isset( $data['guidance'] ) ? sanitize_text_field( $data['guidance'] ) : '';

		$revenue_beat = $revenue_estimate > 0 ? ( ( $revenue - $revenue_estimate ) / $revenue_estimate ) * 100 : 0;
		$eps_beat     = $eps_estimate > 0 ? ( ( $eps - $eps_estimate ) / $eps_estimate ) * 100 : 0;

		if ( in_array( 'executive_summary', $sections, true ) ) {
			$lines[] = '## ' . __( 'Earnings Summary', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '**' . __( 'Company', 'nvoos-content-graph-pro' ) . ':** ' . $company;
			$lines[] = '';

			$revenue_status = $revenue_beat > 0 ? __( 'BEAT', 'nvoos-content-graph-pro' ) : ( $revenue_beat < 0 ? __( 'MISSED', 'nvoos-content-graph-pro' ) : __( 'MET', 'nvoos-content-graph-pro' ) );
			$eps_status     = $eps_beat > 0 ? __( 'BEAT', 'nvoos-content-graph-pro' ) : ( $eps_beat < 0 ? __( 'MISSED', 'nvoos-content-graph-pro' ) : __( 'MET', 'nvoos-content-graph-pro' ) );

			$lines[] = sprintf(
				/* translators: 1: revenue status, 2: EPS status */
				__( 'Revenue: **%1$s** | EPS: **%2$s**', 'nvoos-content-graph-pro' ),
				$revenue_status,
				$eps_status
			);
			$lines[] = '';
		}

		if ( in_array( 'key_metrics', $sections, true ) ) {
			$lines[] = '## ' . __( 'Key Metrics', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = '| ' . __( 'Metric', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Actual', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Estimate', 'nvoos-content-graph-pro' ) . ' | ' . __( 'Beat/Miss', 'nvoos-content-graph-pro' ) . ' |';
			$lines[] = '|---|---|---|---|';
			$lines[] = '| ' . __( 'Revenue', 'nvoos-content-graph-pro' ) . ' | $' . number_format( $revenue, 2 ) . ' | $' . number_format( $revenue_estimate, 2 ) . ' | ' . number_format( $revenue_beat, 2 ) . '% |';
			$lines[] = '| ' . __( 'EPS', 'nvoos-content-graph-pro' ) . ' | $' . number_format( $eps, 2 ) . ' | $' . number_format( $eps_estimate, 2 ) . ' | ' . number_format( $eps_beat, 2 ) . '% |';
			$lines[] = '';
		}

		if ( in_array( 'analysis', $sections, true ) && ! empty( $guidance ) ) {
			$lines[] = '## ' . __( 'Forward Guidance', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = $guidance;
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * Generate sector comparison report sections.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data     Report data.
	 * @param array $sections Sections to include.
	 * @return array Lines of Markdown content.
	 */
	private function generate_sector_comparison( $data, $sections ) {
		$lines   = array();
		$sectors = isset( $data['sectors'] ) && is_array( $data['sectors'] ) ? $data['sectors'] : array();

		if ( in_array( 'executive_summary', $sections, true ) ) {
			$lines[] = '## ' . __( 'Sector Comparison Summary', 'nvoos-content-graph-pro' );
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: %d: sector count */
				__( 'Comparing %d sectors with standardized metrics.', 'nvoos-content-graph-pro' ),
				count( $sectors )
			);
			$lines[] = '';
		}

		if ( in_array( 'key_metrics', $sections, true ) && ! empty( $sectors ) ) {
			$lines[] = '## ' . __( 'Sector Metrics', 'nvoos-content-graph-pro' );
			$lines[] = '';

			// Build dynamic header from first sector's keys.
			$headers = array( __( 'Sector', 'nvoos-content-graph-pro' ) );
			$first   = reset( $sectors );
			if ( is_array( $first ) ) {
				foreach ( $first as $key => $value ) {
					if ( 'name' !== $key ) {
						$headers[] = ucwords( str_replace( '_', ' ', sanitize_text_field( $key ) ) );
					}
				}
			}

			$lines[] = '| ' . implode( ' | ', $headers ) . ' |';
			$lines[] = '|' . str_repeat( '---|', count( $headers ) );

			foreach ( $sectors as $sector ) {
				if ( ! is_array( $sector ) ) {
					continue;
				}
				$row   = array();
				$row[] = isset( $sector['name'] ) ? sanitize_text_field( $sector['name'] ) : '';
				foreach ( $sector as $key => $value ) {
					if ( 'name' !== $key ) {
						$row[] = is_numeric( $value ) ? number_format( floatval( $value ), 2 ) : sanitize_text_field( (string) $value );
					}
				}
				$lines[] = '| ' . implode( ' | ', $row ) . ' |';
			}
			$lines[] = '';
		}

		if ( in_array( 'recommendations', $sections, true ) ) {
			$recommendations = isset( $data['recommendations'] ) && is_array( $data['recommendations'] ) ? $data['recommendations'] : array();
			if ( ! empty( $recommendations ) ) {
				$lines[] = '## ' . __( 'Recommendations', 'nvoos-content-graph-pro' );
				$lines[] = '';
				foreach ( $recommendations as $rec ) {
					$lines[] = '- ' . sanitize_text_field( is_string( $rec ) ? $rec : '' );
				}
				$lines[] = '';
			}
		}

		return $lines;
	}

	/**
	 * Format report type for display.
	 *
	 * @since 1.1.0
	 *
	 * @param string $type Report type slug.
	 * @return string Formatted type name.
	 */
	private function format_report_type( $type ) {
		$types = array(
			'portfolio_summary' => __( 'Portfolio Summary', 'nvoos-content-graph-pro' ),
			'market_analysis'   => __( 'Market Analysis', 'nvoos-content-graph-pro' ),
			'investment_thesis' => __( 'Investment Thesis', 'nvoos-content-graph-pro' ),
			'risk_assessment'   => __( 'Risk Assessment', 'nvoos-content-graph-pro' ),
			'earnings_summary'  => __( 'Earnings Summary', 'nvoos-content-graph-pro' ),
			'sector_comparison' => __( 'Sector Comparison', 'nvoos-content-graph-pro' ),
		);
		return isset( $types[ $type ] ) ? $types[ $type ] : $type;
	}

	/**
	 * Convert basic Markdown to HTML.
	 *
	 * All captured content is escaped via esc_html() to prevent XSS,
	 * since report data may originate from user input.
	 *
	 * @since 1.1.0
	 *
	 * @param string $markdown Markdown content.
	 * @return string HTML content.
	 */
	private function markdown_to_html( $markdown ) {
		$html = esc_html( $markdown );

		// Convert headers.
		$html = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h2>$1</h2>', $html );
		$html = preg_replace( '/^# (.+)$/m', '<h1>$1</h1>', $html );

		// Convert bold.
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );

		// Convert italic.
		$html = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $html );

		// Convert list items.
		$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );

		// Convert numbered list items.
		$html = preg_replace( '/^\d+\. (.+)$/m', '<li>$1</li>', $html );

		// Convert horizontal rules.
		$html = preg_replace( '/^---$/m', '<hr>', $html );

		// Convert simple tables.
		$html = preg_replace( '/^\|---.*$/m', '', $html );

		// Convert line breaks.
		$html = nl2br( $html );

		return $html;
	}

	/**
	 * Generate chart configuration suggestions based on report type.
	 *
	 * @since 1.1.0
	 *
	 * @param string $report_type Report type.
	 * @param array  $data        Report data.
	 * @return array Chart configurations.
	 */
	private function generate_charts_config( $report_type, $data ) {
		$charts = array();

		switch ( $report_type ) {
			case 'portfolio_summary':
				$charts[] = array(
					'type'      => 'pie',
					'title'     => __( 'Asset Allocation', 'nvoos-content-graph-pro' ),
					'data_key'  => 'holdings.asset_class',
					'value_key' => 'market_value',
				);
				$charts[] = array(
					'type'      => 'bar',
					'title'     => __( 'Holdings by Value', 'nvoos-content-graph-pro' ),
					'data_key'  => 'holdings.ticker',
					'value_key' => 'market_value',
				);
				break;

			case 'market_analysis':
				$charts[] = array(
					'type'      => 'bar',
					'title'     => __( 'Index Performance', 'nvoos-content-graph-pro' ),
					'data_key'  => 'indices.name',
					'value_key' => 'change_pct',
				);
				break;

			case 'earnings_summary':
				$charts[] = array(
					'type'      => 'bar',
					'title'     => __( 'Actual vs Estimate', 'nvoos-content-graph-pro' ),
					'data_key'  => 'metrics',
					'value_key' => 'actual,estimate',
				);
				break;

			case 'sector_comparison':
				$charts[] = array(
					'type'      => 'radar',
					'title'     => __( 'Sector Comparison', 'nvoos-content-graph-pro' ),
					'data_key'  => 'sectors.name',
					'value_key' => 'all_metrics',
				);
				$charts[] = array(
					'type'      => 'bar',
					'title'     => __( 'Sector Performance', 'nvoos-content-graph-pro' ),
					'data_key'  => 'sectors.name',
					'value_key' => 'performance',
				);
				break;

			case 'risk_assessment':
				$charts[] = array(
					'type'      => 'heatmap',
					'title'     => __( 'Risk Matrix', 'nvoos-content-graph-pro' ),
					'data_key'  => 'risk_factors',
					'value_key' => 'severity,likelihood',
				);
				break;
		}

		return $charts;
	}
}

<?php
/**
 * CRE Property Budget Manager — Manage property-level operating budgets with variance analysis
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
 * Manages property-level operating budgets stored in wp_options. Supports
 * create, update, get, and list actions with revenue, OpEx, and CapEx
 * tracking plus variance analysis (actual vs. budget and year-over-year).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Property_Budget_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	const OPTION_KEY = 'wp_mcp_ai_cre_property_budgets';

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
		return 'cre_property_budget_manager';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Property Budget Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Manage property-level operating budgets with revenue, OpEx, and CapEx tracking. Supports create, update, get, and list actions with variance analysis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'         => array(
					'type'        => 'string',
					'description' => __( 'Budget action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'update', 'get', 'list' ),
				),
				'property_name'  => array(
					'type'        => 'string',
					'description' => __( 'Property name (required for create).', 'mcp-ai-wpoos-pro' ),
				),
				'budget_year'    => array(
					'type'        => 'integer',
					'description' => __( 'Budget year (required for create; optional filter for list).', 'mcp-ai-wpoos-pro' ),
				),
				'revenue_budget' => array(
					'type'        => 'number',
					'description' => __( 'Budgeted annual revenue.', 'mcp-ai-wpoos-pro' ),
				),
				'opex_budget'    => array(
					'type'        => 'number',
					'description' => __( 'Budgeted annual operating expenses.', 'mcp-ai-wpoos-pro' ),
				),
				'capex_budget'   => array(
					'type'        => 'number',
					'description' => __( 'Budgeted annual capital expenditures.', 'mcp-ai-wpoos-pro' ),
				),
				'actual_revenue' => array(
					'type'        => 'number',
					'description' => __( 'Actual revenue to date.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'actual_opex'    => array(
					'type'        => 'number',
					'description' => __( 'Actual operating expenses to date.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'actual_capex'   => array(
					'type'        => 'number',
					'description' => __( 'Actual capital expenditures to date.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
				),
				'budget_id'      => array(
					'type'        => 'string',
					'description' => __( 'Unique budget identifier (required for update/get).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = sanitize_text_field( $arguments['action'] ?? '' );

		switch ( $action ) {
			case 'create':
				return $this->create_budget( $arguments );
			case 'update':
				return $this->update_budget( $arguments );
			case 'get':
				return $this->get_budget( $arguments );
			case 'list':
				return $this->list_budgets( $arguments );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use: create, update, get, or list.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Create a new property budget.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	private function create_budget( array $arguments ): array|\WP_Error {
		$property_name = sanitize_text_field( $arguments['property_name'] ?? '' );
		$budget_year   = absint( $arguments['budget_year'] ?? 0 );

		if ( empty( $property_name ) ) {
			return new WP_Error( 'missing_field', __( 'property_name is required for create action.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $budget_year <= 0 ) {
			return new WP_Error( 'missing_field', __( 'budget_year is required for create action.', 'mcp-ai-wpoos-pro' ) );
		}

		$revenue_budget = (float) ( $arguments['revenue_budget'] ?? 0 );
		$opex_budget    = (float) ( $arguments['opex_budget'] ?? 0 );
		$capex_budget   = (float) ( $arguments['capex_budget'] ?? 0 );
		$actual_revenue = (float) ( $arguments['actual_revenue'] ?? 0 );
		$actual_opex    = (float) ( $arguments['actual_opex'] ?? 0 );
		$actual_capex   = (float) ( $arguments['actual_capex'] ?? 0 );

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$budgeted_noi = $revenue_budget - $opex_budget;
		$actual_noi   = $actual_revenue - $actual_opex;

		$budget_id = 'budget_' . wp_generate_uuid4();
		$budget    = array(
			'budget_id'      => $budget_id,
			'property_name'  => $property_name,
			'budget_year'    => $budget_year,
			'revenue_budget' => round( $revenue_budget, 2 ),
			'opex_budget'    => round( $opex_budget, 2 ),
			'capex_budget'   => round( $capex_budget, 2 ),
			'actual_revenue' => round( $actual_revenue, 2 ),
			'actual_opex'    => round( $actual_opex, 2 ),
			'actual_capex'   => round( $actual_capex, 2 ),
			'budgeted_noi'   => round( $budgeted_noi, 2 ),
			'actual_noi'     => round( $actual_noi, 2 ),
			'variances'      => $this->calculate_variances(
				$revenue_budget,
				$opex_budget,
				$capex_budget,
				$budgeted_noi,
				$actual_revenue,
				$actual_opex,
				$actual_capex,
				$actual_noi
			),
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$budgets               = get_option( self::OPTION_KEY, array() );
		$budgets[ $budget_id ] = $budget;
		update_option( self::OPTION_KEY, $budgets );

		$budget['revenue_budget'] = $calc::format_currency( $revenue_budget );
		$budget['opex_budget']    = $calc::format_currency( $opex_budget );
		$budget['capex_budget']   = $calc::format_currency( $capex_budget );
		$budget['actual_revenue'] = $calc::format_currency( $actual_revenue );
		$budget['actual_opex']    = $calc::format_currency( $actual_opex );
		$budget['actual_capex']   = $calc::format_currency( $actual_capex );
		$budget['budgeted_noi']   = $calc::format_currency( $budgeted_noi );
		$budget['actual_noi']     = $calc::format_currency( $actual_noi );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: property name */
				__( 'Budget for "%s" created successfully. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				$property_name
			),
			'data'       => $budget,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Update an existing property budget.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	private function update_budget( array $arguments ): array|\WP_Error {
		$budget_id = sanitize_text_field( $arguments['budget_id'] ?? '' );
		if ( empty( $budget_id ) ) {
			return new WP_Error( 'missing_field', __( 'budget_id is required for update action.', 'mcp-ai-wpoos-pro' ) );
		}

		$budgets = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $budgets[ $budget_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Budget not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$updatable_text    = array( 'property_name' );
		$updatable_int     = array( 'budget_year' );
		$updatable_numeric = array( 'revenue_budget', 'opex_budget', 'capex_budget', 'actual_revenue', 'actual_opex', 'actual_capex' );

		foreach ( $updatable_text as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$budgets[ $budget_id ][ $field ] = sanitize_text_field( $arguments[ $field ] );
			}
		}
		foreach ( $updatable_int as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$budgets[ $budget_id ][ $field ] = absint( $arguments[ $field ] );
			}
		}
		foreach ( $updatable_numeric as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$budgets[ $budget_id ][ $field ] = round( (float) $arguments[ $field ], 2 );
			}
		}

		$b = $budgets[ $budget_id ];

		$budgeted_noi = $b['revenue_budget'] - $b['opex_budget'];
		$actual_noi   = $b['actual_revenue'] - $b['actual_opex'];

		$budgets[ $budget_id ]['budgeted_noi'] = round( $budgeted_noi, 2 );
		$budgets[ $budget_id ]['actual_noi']   = round( $actual_noi, 2 );
		$budgets[ $budget_id ]['variances']    = $this->calculate_variances(
			$b['revenue_budget'],
			$b['opex_budget'],
			$b['capex_budget'],
			$budgeted_noi,
			$b['actual_revenue'],
			$b['actual_opex'],
			$b['actual_capex'],
			$actual_noi
		);
		$budgets[ $budget_id ]['updated_at']   = current_time( 'mysql' );

		update_option( self::OPTION_KEY, $budgets );

		$calc   = WP_MCP_AI_CRE_Debt_Calculator::class;
		$output = $budgets[ $budget_id ];

		$output['revenue_budget'] = $calc::format_currency( $output['revenue_budget'] );
		$output['opex_budget']    = $calc::format_currency( $output['opex_budget'] );
		$output['capex_budget']   = $calc::format_currency( $output['capex_budget'] );
		$output['actual_revenue'] = $calc::format_currency( $output['actual_revenue'] );
		$output['actual_opex']    = $calc::format_currency( $output['actual_opex'] );
		$output['actual_capex']   = $calc::format_currency( $output['actual_capex'] );
		$output['budgeted_noi']   = $calc::format_currency( $output['budgeted_noi'] );
		$output['actual_noi']     = $calc::format_currency( $output['actual_noi'] );

		return array(
			'success'    => true,
			'message'    => __( 'Budget updated successfully. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => $output,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Retrieve a single budget with year-over-year variance.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|\WP_Error
	 */
	private function get_budget( array $arguments ): array|\WP_Error {
		$budget_id = sanitize_text_field( $arguments['budget_id'] ?? '' );
		if ( empty( $budget_id ) ) {
			return new WP_Error( 'missing_field', __( 'budget_id is required for get action.', 'mcp-ai-wpoos-pro' ) );
		}

		$budgets = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $budgets[ $budget_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Budget not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc   = WP_MCP_AI_CRE_Debt_Calculator::class;
		$budget = $budgets[ $budget_id ];

		// Look for prior-year budget for YoY comparison.
		$prior_year    = $budget['budget_year'] - 1;
		$yoy_variances = null;

		foreach ( $budgets as $b ) {
			if ( $b['property_name'] === $budget['property_name'] && (int) $b['budget_year'] === $prior_year ) {
				$yoy_variances = array(
					'prior_year'           => $prior_year,
					'prior_revenue_budget' => $calc::format_currency( $b['revenue_budget'] ),
					'prior_opex_budget'    => $calc::format_currency( $b['opex_budget'] ),
					'prior_budgeted_noi'   => $calc::format_currency( $b['budgeted_noi'] ),
					'revenue_yoy_dollars'  => $calc::format_currency( $budget['revenue_budget'] - $b['revenue_budget'] ),
					'revenue_yoy_pct'      => ( 0 !== (float) $b['revenue_budget'] )
						? $calc::format_percentage( ( $budget['revenue_budget'] - $b['revenue_budget'] ) / $b['revenue_budget'] )
						: __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'opex_yoy_dollars'     => $calc::format_currency( $budget['opex_budget'] - $b['opex_budget'] ),
					'opex_yoy_pct'         => ( 0 !== (float) $b['opex_budget'] )
						? $calc::format_percentage( ( $budget['opex_budget'] - $b['opex_budget'] ) / $b['opex_budget'] )
						: __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'noi_yoy_dollars'      => $calc::format_currency( $budget['budgeted_noi'] - $b['budgeted_noi'] ),
					'noi_yoy_pct'          => ( 0 !== (float) $b['budgeted_noi'] )
						? $calc::format_percentage( ( $budget['budgeted_noi'] - $b['budgeted_noi'] ) / $b['budgeted_noi'] )
						: __( 'N/A', 'mcp-ai-wpoos-pro' ),
				);
				break;
			}
		}

		$output                   = $budget;
		$output['revenue_budget'] = $calc::format_currency( $budget['revenue_budget'] );
		$output['opex_budget']    = $calc::format_currency( $budget['opex_budget'] );
		$output['capex_budget']   = $calc::format_currency( $budget['capex_budget'] );
		$output['actual_revenue'] = $calc::format_currency( $budget['actual_revenue'] );
		$output['actual_opex']    = $calc::format_currency( $budget['actual_opex'] );
		$output['actual_capex']   = $calc::format_currency( $budget['actual_capex'] );
		$output['budgeted_noi']   = $calc::format_currency( $budget['budgeted_noi'] );
		$output['actual_noi']     = $calc::format_currency( $budget['actual_noi'] );
		$output['yoy_variances']  = $yoy_variances;

		return array(
			'success'    => true,
			'message'    => __( 'Budget retrieved. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'       => $output,
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List all budgets with optional year filter and summary totals.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function list_budgets( array $arguments ): array {
		$budgets     = get_option( self::OPTION_KEY, array() );
		$all_budgets = array_values( $budgets );
		$filter_year = absint( $arguments['budget_year'] ?? 0 );

		if ( $filter_year > 0 ) {
			$all_budgets = array_values(
				array_filter(
					$all_budgets,
					function ( $b ) use ( $filter_year ) {
						return (int) $b['budget_year'] === $filter_year;
					}
				)
			);
		}

		$calc           = WP_MCP_AI_CRE_Debt_Calculator::class;
		$total_revenue  = 0.0;
		$total_opex     = 0.0;
		$total_capex    = 0.0;
		$total_act_rev  = 0.0;
		$total_act_opex = 0.0;
		$total_act_cap  = 0.0;

		$formatted = array();
		foreach ( $all_budgets as $b ) {
			$total_revenue  += $b['revenue_budget'];
			$total_opex     += $b['opex_budget'];
			$total_capex    += $b['capex_budget'];
			$total_act_rev  += $b['actual_revenue'];
			$total_act_opex += $b['actual_opex'];
			$total_act_cap  += $b['actual_capex'];

			$entry                   = $b;
			$entry['revenue_budget'] = $calc::format_currency( $b['revenue_budget'] );
			$entry['opex_budget']    = $calc::format_currency( $b['opex_budget'] );
			$entry['capex_budget']   = $calc::format_currency( $b['capex_budget'] );
			$entry['actual_revenue'] = $calc::format_currency( $b['actual_revenue'] );
			$entry['actual_opex']    = $calc::format_currency( $b['actual_opex'] );
			$entry['actual_capex']   = $calc::format_currency( $b['actual_capex'] );
			$entry['budgeted_noi']   = $calc::format_currency( $b['budgeted_noi'] );
			$entry['actual_noi']     = $calc::format_currency( $b['actual_noi'] );
			$formatted[]             = $entry;
		}

		$summary_budgeted_noi = $total_revenue - $total_opex;
		$summary_actual_noi   = $total_act_rev - $total_act_opex;

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: budget count */
				__( '%d budget(s) found. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				count( $formatted )
			),
			'data'       => array(
				'total_budgets' => count( $formatted ),
				'summary'       => array(
					'total_revenue_budget' => $calc::format_currency( $total_revenue ),
					'total_opex_budget'    => $calc::format_currency( $total_opex ),
					'total_capex_budget'   => $calc::format_currency( $total_capex ),
					'total_actual_revenue' => $calc::format_currency( $total_act_rev ),
					'total_actual_opex'    => $calc::format_currency( $total_act_opex ),
					'total_actual_capex'   => $calc::format_currency( $total_act_cap ),
					'total_budgeted_noi'   => $calc::format_currency( $summary_budgeted_noi ),
					'total_actual_noi'     => $calc::format_currency( $summary_actual_noi ),
				),
				'budgets'       => $formatted,
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Calculate variance metrics between budgeted and actual amounts.
	 *
	 * @param float $rev_budget  Budgeted revenue.
	 * @param float $opex_budget Budgeted OpEx.
	 * @param float $capex_budget Budgeted CapEx.
	 * @param float $noi_budget  Budgeted NOI.
	 * @param float $rev_actual  Actual revenue.
	 * @param float $opex_actual Actual OpEx.
	 * @param float $capex_actual Actual CapEx.
	 * @param float $noi_actual  Actual NOI.
	 * @return array
	 */
	private function calculate_variances(
		float $rev_budget,
		float $opex_budget,
		float $capex_budget,
		float $noi_budget,
		float $rev_actual,
		float $opex_actual,
		float $capex_actual,
		float $noi_actual
	): array {
		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$rev_var   = $rev_actual - $rev_budget;
		$opex_var  = $opex_actual - $opex_budget;
		$capex_var = $capex_actual - $capex_budget;
		$noi_var   = $noi_actual - $noi_budget;

		return array(
			'revenue' => array(
				'variance_dollars' => $calc::format_currency( $rev_var ),
				'variance_pct'     => ( 0.0 !== $rev_budget )
					? $calc::format_percentage( $rev_var / $rev_budget )
					: __( 'N/A', 'mcp-ai-wpoos-pro' ),
			),
			'opex'    => array(
				'variance_dollars' => $calc::format_currency( $opex_var ),
				'variance_pct'     => ( 0.0 !== $opex_budget )
					? $calc::format_percentage( $opex_var / $opex_budget )
					: __( 'N/A', 'mcp-ai-wpoos-pro' ),
			),
			'capex'   => array(
				'variance_dollars' => $calc::format_currency( $capex_var ),
				'variance_pct'     => ( 0.0 !== $capex_budget )
					? $calc::format_percentage( $capex_var / $capex_budget )
					: __( 'N/A', 'mcp-ai-wpoos-pro' ),
			),
			'noi'     => array(
				'variance_dollars' => $calc::format_currency( $noi_var ),
				'variance_pct'     => ( 0.0 !== $noi_budget )
					? $calc::format_percentage( $noi_var / $noi_budget )
					: __( 'N/A', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}

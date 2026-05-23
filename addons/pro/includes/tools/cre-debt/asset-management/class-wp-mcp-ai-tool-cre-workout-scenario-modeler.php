<?php
/**
 * CRE Workout Scenario Modeler — Model and compare loan workout strategies ranked by NPV
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
 * Models and compares loan workout strategies including extension, modification,
 * restructure, note sale, foreclosure, and REO disposition. Ranks scenarios
 * by NPV of recovery to support workout decision-making.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Workout_Scenario_Modeler implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	const DISCOUNT_RATE = 0.10;

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
		return 'cre_workout_scenario_modeler';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Workout Scenario Modeler', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Model and compare loan workout strategies including extension, modification, restructure, note sale, foreclosure, and REO disposition. Ranks scenarios by NPV of recovery.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'loan_balance'   => array(
					'type'        => 'number',
					'description' => __( 'Current outstanding loan balance.', 'mcp-ai-wpoos-pro' ),
				),
				'current_rate'   => array(
					'type'        => 'number',
					'description' => __( 'Current annual interest rate as decimal (e.g. 0.05 for 5%).', 'mcp-ai-wpoos-pro' ),
				),
				'current_noi'    => array(
					'type'        => 'number',
					'description' => __( 'Current annual net operating income.', 'mcp-ai-wpoos-pro' ),
				),
				'property_value' => array(
					'type'        => 'number',
					'description' => __( 'Current estimated property value.', 'mcp-ai-wpoos-pro' ),
				),
				'scenarios'      => array(
					'type'        => 'array',
					'description' => __( 'Array of workout scenario objects to model.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'strategy'                    => array(
								'type'        => 'string',
								'description' => __( 'Workout strategy type.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'extension', 'modification', 'restructure', 'note_sale', 'foreclosure', 'reo_disposition' ),
							),
							'modified_rate'               => array(
								'type'        => 'number',
								'description' => __( 'Modified annual interest rate as decimal.', 'mcp-ai-wpoos-pro' ),
							),
							'extended_term_months'        => array(
								'type'        => 'integer',
								'description' => __( 'Extended loan term in months.', 'mcp-ai-wpoos-pro' ),
							),
							'principal_reduction'         => array(
								'type'        => 'number',
								'description' => __( 'Principal reduction amount. Default 0.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
							'note_sale_price_pct'         => array(
								'type'        => 'number',
								'description' => __( 'Note sale price as percentage of face (e.g. 0.65 for 65%). Default 0.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
							'foreclosure_timeline_months' => array(
								'type'        => 'integer',
								'description' => __( 'Foreclosure timeline in months. Default 18.', 'mcp-ai-wpoos-pro' ),
								'default'     => 18,
							),
							'reo_sale_price'              => array(
								'type'        => 'number',
								'description' => __( 'REO disposition sale price. Default 0 (uses property_value).', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
							'workout_costs'               => array(
								'type'        => 'number',
								'description' => __( 'Total workout-related costs (legal, advisory, etc.). Default 0.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
						),
						'required'   => array( 'strategy' ),
					),
				),
			),
			'required'   => array( 'loan_balance', 'current_rate', 'current_noi', 'property_value', 'scenarios' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$loan_balance   = (float) ( $arguments['loan_balance'] ?? 0 );
		$current_rate   = (float) ( $arguments['current_rate'] ?? 0 );
		$current_noi    = (float) ( $arguments['current_noi'] ?? 0 );
		$property_value = (float) ( $arguments['property_value'] ?? 0 );
		$scenarios      = $arguments['scenarios'] ?? array();

		if ( $loan_balance <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'loan_balance must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $property_value <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'property_value must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $scenarios ) || ! is_array( $scenarios ) ) {
			return new WP_Error( 'invalid_input', __( 'scenarios array is required and must not be empty.', 'mcp-ai-wpoos-pro' ) );
		}

		$calc         = WP_MCP_AI_CRE_Debt_Calculator::class;
		$monthly_disc = pow( 1 + self::DISCOUNT_RATE, 1.0 / 12 ) - 1;
		$results      = array();

		foreach ( $scenarios as $scenario ) {
			$strategy        = sanitize_text_field( $scenario['strategy'] ?? '' );
			$workout_costs   = (float) ( $scenario['workout_costs'] ?? 0 );
			$net_recovery    = 0.0;
			$timeline_months = 0;
			$loss            = 0.0;
			$details         = array();

			switch ( $strategy ) {
				case 'extension':
					$extended_months = absint( $scenario['extended_term_months'] ?? 0 );
					$monthly_payment = $loan_balance * $current_rate / 12;
					$total_payments  = $monthly_payment * $extended_months;
					$net_recovery    = $total_payments + $loan_balance - $workout_costs;
					$timeline_months = $extended_months;
					$details         = array(
						'monthly_payment' => $calc::format_currency( $monthly_payment ),
						'total_payments'  => $calc::format_currency( $total_payments ),
						'balance_at_end'  => $calc::format_currency( $loan_balance ),
					);
					break;

				case 'modification':
					$modified_rate       = (float) ( $scenario['modified_rate'] ?? $current_rate );
					$principal_reduction = (float) ( $scenario['principal_reduction'] ?? 0 );
					$extended_months     = absint( $scenario['extended_term_months'] ?? 12 );
					$new_balance         = $loan_balance - $principal_reduction;
					$modified_payment    = $new_balance * $modified_rate / 12;
					$original_payment    = $loan_balance * $current_rate / 12;
					$total_modified      = $modified_payment * $extended_months;
					$net_recovery        = $total_modified + $new_balance - $workout_costs;
					$payment_diff        = ( $original_payment - $modified_payment ) * $extended_months;
					$loss                = $principal_reduction + $payment_diff;
					$timeline_months     = $extended_months;
					$details             = array(
						'new_balance'          => $calc::format_currency( $new_balance ),
						'modified_rate'        => $calc::format_percentage( $modified_rate ),
						'modified_payment'     => $calc::format_currency( $modified_payment ),
						'original_payment'     => $calc::format_currency( $original_payment ),
						'principal_reduction'  => $calc::format_currency( $principal_reduction ),
						'payment_savings_loss' => $calc::format_currency( $payment_diff ),
					);
					break;

				case 'restructure':
					$modified_rate       = (float) ( $scenario['modified_rate'] ?? $current_rate );
					$principal_reduction = (float) ( $scenario['principal_reduction'] ?? 0 );
					$extended_months     = absint( $scenario['extended_term_months'] ?? 12 );
					$new_balance         = $loan_balance - $principal_reduction;
					$modified_payment    = $new_balance * $modified_rate / 12;
					$original_payment    = $loan_balance * $current_rate / 12;
					$total_modified      = $modified_payment * $extended_months;
					$net_recovery        = $total_modified + $new_balance - $workout_costs;
					$payment_diff        = ( $original_payment - $modified_payment ) * $extended_months;
					$loss                = $principal_reduction + $payment_diff;
					$timeline_months     = $extended_months;
					$details             = array(
						'new_balance'          => $calc::format_currency( $new_balance ),
						'modified_rate'        => $calc::format_percentage( $modified_rate ),
						'modified_payment'     => $calc::format_currency( $modified_payment ),
						'extended_term'        => $extended_months . ' months',
						'principal_reduction'  => $calc::format_currency( $principal_reduction ),
						'payment_savings_loss' => $calc::format_currency( $payment_diff ),
					);
					break;

				case 'note_sale':
					$sale_pct        = (float) ( $scenario['note_sale_price_pct'] ?? 0 );
					$sale_price      = $loan_balance * $sale_pct;
					$net_recovery    = $sale_price - $workout_costs;
					$timeline_months = 3;
					$details         = array(
						'sale_price_pct' => $calc::format_percentage( $sale_pct ),
						'sale_price'     => $calc::format_currency( $sale_price ),
					);
					break;

				case 'foreclosure':
					$fc_months       = absint( $scenario['foreclosure_timeline_months'] ?? 18 );
					$carrying_costs  = $property_value * 0.01 * $fc_months / 12;
					$net_recovery    = $property_value - $carrying_costs - $workout_costs;
					$timeline_months = $fc_months;
					$details         = array(
						'foreclosure_timeline' => $fc_months . ' months',
						'carrying_costs'       => $calc::format_currency( $carrying_costs ),
						'sale_at_value'        => $calc::format_currency( $property_value ),
					);
					break;

				case 'reo_disposition':
					$fc_months = absint( $scenario['foreclosure_timeline_months'] ?? 18 );
					$reo_price = (float) ( $scenario['reo_sale_price'] ?? 0 );
					if ( $reo_price <= 0 ) {
						$reo_price = $property_value;
					}
					$marketing_months = 6;
					$total_timeline   = $fc_months + $marketing_months;
					$monthly_carrying = $property_value * 0.01 / 12;
					$total_carrying   = $monthly_carrying * $total_timeline;
					$net_recovery     = $reo_price - $total_carrying - $workout_costs;
					$timeline_months  = $total_timeline;
					$details          = array(
						'foreclosure_timeline' => $fc_months . ' months',
						'marketing_period'     => $marketing_months . ' months',
						'total_timeline'       => $total_timeline . ' months',
						'monthly_carrying'     => $calc::format_currency( $monthly_carrying ),
						'total_carrying'       => $calc::format_currency( $total_carrying ),
						'reo_sale_price'       => $calc::format_currency( $reo_price ),
					);
					break;

				default:
					continue 2;
			}

			$loss_given_default = $loan_balance - $net_recovery;
			$recovery_rate      = ( $loan_balance > 0 ) ? $net_recovery / $loan_balance : 0;
			$npv                = ( $timeline_months > 0 )
				? $net_recovery / pow( 1 + $monthly_disc, $timeline_months )
				: $net_recovery;

			$results[] = array(
				'strategy'           => $strategy,
				'net_recovery'       => $net_recovery,
				'net_recovery_fmt'   => $calc::format_currency( $net_recovery ),
				'loss_given_default' => $calc::format_currency( $loss_given_default ),
				'recovery_rate'      => $calc::format_percentage( $recovery_rate ),
				'timeline_months'    => $timeline_months,
				'workout_costs'      => $calc::format_currency( $workout_costs ),
				'npv'                => $npv,
				'npv_fmt'            => $calc::format_currency( $npv ),
				'details'            => $details,
			);
		}

		// Rank by NPV descending.
		usort(
			$results,
			function ( $a, $b ) {
				return $b['npv'] <=> $a['npv'];
			}
		);

		$ranked = array();
		foreach ( $results as $index => $result ) {
			$result['rank'] = $index + 1;
			unset( $result['npv'], $result['net_recovery'] );
			$ranked[] = $result;
		}

		$best_scenario  = ! empty( $ranked ) ? $ranked[0]['strategy'] : __( 'N/A', 'mcp-ai-wpoos-pro' );
		$worst_scenario = ! empty( $ranked ) ? $ranked[ count( $ranked ) - 1 ]['strategy'] : __( 'N/A', 'mcp-ai-wpoos-pro' );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: scenario count */
				__( '%d workout scenarios modeled and ranked by NPV. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
				count( $ranked )
			),
			'data'       => array(
				'loan_summary'   => array(
					'loan_balance'   => $calc::format_currency( $loan_balance ),
					'current_rate'   => $calc::format_percentage( $current_rate ),
					'current_noi'    => $calc::format_currency( $current_noi ),
					'property_value' => $calc::format_currency( $property_value ),
					'current_ltv'    => ( $property_value > 0 ) ? $calc::format_percentage( $loan_balance / $property_value ) : __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'current_dscr'   => ( $loan_balance * $current_rate > 0 ) ? round( $current_noi / ( $loan_balance * $current_rate ), 2 ) . 'x' : __( 'N/A', 'mcp-ai-wpoos-pro' ),
					'discount_rate'  => $calc::format_percentage( self::DISCOUNT_RATE ),
				),
				'scenarios'      => $ranked,
				'recommendation' => array(
					'best_scenario'  => $best_scenario,
					'worst_scenario' => $worst_scenario,
				),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

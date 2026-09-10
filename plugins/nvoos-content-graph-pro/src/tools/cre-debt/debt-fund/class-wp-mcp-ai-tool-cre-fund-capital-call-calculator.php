<?php
/**
 * debt-fund/class-wp-mcp-ai-tool-cre-fund-capital-call-calculator.php (ecosystem port — Wave F4, cre-debt debt-fund batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-fund-capital-call-calculator.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the `__DIR__` calculator require resolves from the addon's already-ported
 * `src/tools/cre-debt/` copy.
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

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Calculates per-LP capital call amounts including pro-rata share,
 * overcall buffer, management fee component, and remaining unfunded commitment.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Fund_Capital_Call_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_fund_capital_call_calculator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Fund Capital Call Calculator', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Calculate LP-level capital call amounts with pro-rata allocation, overcall buffer, management fee component, and remaining unfunded commitment for each limited partner.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'call_amount'        => array(
					'type'        => 'number',
					'description' => __( 'Total capital call amount.', 'nvoos-content-graph-pro' ),
				),
				'lps'                => array(
					'type'        => 'array',
					'description' => __( 'Array of LP objects.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'           => array(
								'type'        => 'string',
								'description' => __( 'LP name or entity.', 'nvoos-content-graph-pro' ),
							),
							'commitment'     => array(
								'type'        => 'number',
								'description' => __( 'Total LP commitment amount.', 'nvoos-content-graph-pro' ),
							),
							'called_to_date' => array(
								'type'        => 'number',
								'description' => __( 'Capital already called from this LP.', 'nvoos-content-graph-pro' ),
							),
							'ownership_pct'  => array(
								'type'        => 'number',
								'description' => __( 'LP ownership percentage as decimal (e.g. 0.25 for 25%).', 'nvoos-content-graph-pro' ),
							),
						),
						'required'   => array( 'name', 'commitment', 'called_to_date', 'ownership_pct' ),
					),
				),
				'overcall_pct'       => array(
					'type'        => 'number',
					'description' => __( 'Overcall buffer percentage (0-25).', 'nvoos-content-graph-pro' ),
					'default'     => 0,
				),
				'management_fee_pct' => array(
					'type'        => 'number',
					'description' => __( 'Management fee percentage to include in call (0 if none).', 'nvoos-content-graph-pro' ),
					'default'     => 0,
				),
				'purpose'            => array(
					'type'        => 'string',
					'description' => __( 'Purpose of the capital call.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'call_amount', 'lps' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$call_amount = (float) ( $arguments['call_amount'] ?? 0 );
		$lps         = $arguments['lps'] ?? array();
		$overcall    = max( 0, min( 25, (float) ( $arguments['overcall_pct'] ?? 0 ) ) );
		$mgmt_fee    = (float) ( $arguments['management_fee_pct'] ?? 0 );
		$purpose     = sanitize_text_field( $arguments['purpose'] ?? '' );

		if ( $call_amount <= 0 ) {
			return new \WP_Error( 'invalid_input', __( 'Call amount must be positive.', 'nvoos-content-graph-pro' ) );
		}
		if ( empty( $lps ) || ! is_array( $lps ) ) {
			return new \WP_Error( 'invalid_input', __( 'At least one LP is required.', 'nvoos-content-graph-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		// Management fee amount allocated across the call.
		$fee_amount          = $call_amount * ( $mgmt_fee / 100 );
		$total_call_with_fee = $call_amount + $fee_amount;

		$lp_details       = array();
		$total_pro_rata   = 0.0;
		$total_overcall   = 0.0;
		$total_due        = 0.0;
		$total_fee_alloc  = 0.0;
		$total_commitment = 0.0;
		$total_called     = 0.0;
		$flagged_lps      = array();

		foreach ( $lps as $lp ) {
			$name           = sanitize_text_field( $lp['name'] ?? '' );
			$commitment     = (float) ( $lp['commitment'] ?? 0 );
			$called_to_date = (float) ( $lp['called_to_date'] ?? 0 );
			$ownership_pct  = (float) ( $lp['ownership_pct'] ?? 0 );

			$pro_rata_share  = $call_amount * $ownership_pct;
			$overcall_amount = $pro_rata_share * ( $overcall / 100 );
			$fee_share       = $fee_amount * $ownership_pct;
			$total_due_lp    = $pro_rata_share + $overcall_amount + $fee_share;
			$remaining       = $commitment - $called_to_date - $pro_rata_share;
			$pct_funded      = ( $commitment > 0 ) ? ( $called_to_date + $pro_rata_share ) / $commitment : 0;

			// Flag if this call would exceed unfunded commitment.
			$exceeds_unfunded = ( $pro_rata_share > ( $commitment - $called_to_date ) );
			if ( $exceeds_unfunded ) {
				$flagged_lps[] = $name;
			}

			$total_pro_rata   += $pro_rata_share;
			$total_overcall   += $overcall_amount;
			$total_due        += $total_due_lp;
			$total_fee_alloc  += $fee_share;
			$total_commitment += $commitment;
			$total_called     += $called_to_date;

			$lp_details[] = array(
				'name'               => $name,
				'commitment'         => $calc::format_currency( $commitment ),
				'called_to_date'     => $calc::format_currency( $called_to_date ),
				'ownership_pct'      => $calc::format_percentage( $ownership_pct ),
				'pro_rata_share'     => $calc::format_currency( $pro_rata_share ),
				'overcall_amount'    => $calc::format_currency( $overcall_amount ),
				'fee_share'          => $calc::format_currency( $fee_share ),
				'total_due'          => $calc::format_currency( $total_due_lp ),
				'remaining_unfunded' => $calc::format_currency( max( 0, $remaining ) ),
				'pct_funded_after'   => $calc::format_percentage( min( 1, $pct_funded ) ),
				'exceeds_unfunded'   => $exceeds_unfunded,
			);
		}

		return array(
			'success'    => true,
			'message'    => __( 'Capital call calculated. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
			'data'       => array(
				'call_amount'        => $calc::format_currency( $call_amount ),
				'purpose'            => $purpose,
				'overcall_pct'       => $overcall . '%',
				'management_fee_pct' => $mgmt_fee . '%',
				'fee_amount'         => $calc::format_currency( $fee_amount ),
				'total_call_due'     => $calc::format_currency( $total_due ),
				'lp_details'         => $lp_details,
				'summary'            => array(
					'total_pro_rata'     => $calc::format_currency( $total_pro_rata ),
					'total_overcall'     => $calc::format_currency( $total_overcall ),
					'total_fee_alloc'    => $calc::format_currency( $total_fee_alloc ),
					'total_due'          => $calc::format_currency( $total_due ),
					'total_commitment'   => $calc::format_currency( $total_commitment ),
					'total_called_after' => $calc::format_currency( $total_called + $total_pro_rata ),
					'num_lps'            => count( $lp_details ),
					'flagged_lps'        => $flagged_lps,
				),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}
}

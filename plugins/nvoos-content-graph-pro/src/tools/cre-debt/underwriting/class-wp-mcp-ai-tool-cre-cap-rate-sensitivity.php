<?php
/**
 * underwriting/class-wp-mcp-ai-tool-cre-cap-rate-sensitivity.php (ecosystem port — Wave F4, cre-debt underwriting batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cre-debt/underwriting/class-wp-mcp-ai-tool-cre-cap-rate-sensitivity.php` for the
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
 * Runs a sensitivity table showing property value, LTV, and equity across
 * a range of cap rate scenarios expressed as BPS offsets from a base rate.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Cap_Rate_Sensitivity implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_cap_rate_sensitivity';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Cap Rate Sensitivity', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Run a cap-rate sensitivity analysis. Provide NOI, a base cap rate, BPS offsets, and an optional loan amount. Returns property value, LTV, and equity at each scenario for quick risk assessment.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'noi'           => array(
					'type'        => 'number',
					'description' => __( 'Annual Net Operating Income.', 'nvoos-content-graph-pro' ),
				),
				'base_cap_rate' => array(
					'type'        => 'number',
					'description' => __( 'Base cap rate as decimal (e.g. 0.06 for 6%).', 'nvoos-content-graph-pro' ),
				),
				'scenarios_bps' => array(
					'type'        => 'array',
					'description' => __( 'Array of BPS offsets from the base cap rate (e.g. [-100, -50, 0, 50, 100, 150, 200]).', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'number',
					),
					'default'     => array( -100, -50, 0, 50, 100, 150, 200 ),
				),
				'loan_amount'   => array(
					'type'        => 'number',
					'description' => __( 'Loan amount (optional, for LTV & equity calculations).', 'nvoos-content-graph-pro' ),
					'default'     => 0,
				),
			),
			'required'   => array( 'noi', 'base_cap_rate' ),
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
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$noi       = (float) ( $arguments['noi'] ?? 0 );
		$base_cap  = (float) ( $arguments['base_cap_rate'] ?? 0 );
		$scenarios = $arguments['scenarios_bps'] ?? array( -100, -50, 0, 50, 100, 150, 200 );
		$loan      = (float) ( $arguments['loan_amount'] ?? 0 );

		if ( $noi <= 0 || $base_cap <= 0 ) {
			return new WP_Error( 'invalid_input', __( 'NOI and base cap rate must be greater than zero.', 'nvoos-content-graph-pro' ) );
		}

		$calc = WP_MCP_AI_CRE_Debt_Calculator::class;

		$base_value = $calc::calculate_value_direct_cap( $noi, $base_cap );

		$table = array();
		foreach ( $scenarios as $bps ) {
			$bps      = (int) $bps;
			$cap_rate = $base_cap + ( $bps / 10000 );

			if ( $cap_rate <= 0 ) {
				continue;
			}

			$value  = $calc::calculate_value_direct_cap( $noi, $cap_rate );
			$ltv    = ( $loan > 0 ) ? $calc::calculate_ltv( $loan, $value ) : null;
			$equity = ( $loan > 0 ) ? $value - $loan : null;

			$row = array(
				'cap_rate'         => $calc::format_percentage( $cap_rate ),
				'bps_offset'       => $bps,
				'property_value'   => $calc::format_currency( $value ),
				'value_change'     => $calc::format_currency( $value - $base_value ),
				'value_change_pct' => ( $base_value > 0 ) ? $calc::format_percentage( ( $value - $base_value ) / $base_value ) : 'N/A',
			);

			if ( $loan > 0 ) {
				$row['ltv']    = $calc::format_percentage( $ltv );
				$row['equity'] = $calc::format_currency( $equity );
			}

			$table[] = $row;
		}

		return array(
			'success' => true,
			'message' => __( 'Cap rate sensitivity analysis complete. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
			'data'    => array(
				'base_case'         => array(
					'noi'            => $calc::format_currency( $noi ),
					'cap_rate'       => $calc::format_percentage( $base_cap ),
					'property_value' => $calc::format_currency( $base_value ),
				),
				'loan_amount'       => ( $loan > 0 ) ? $calc::format_currency( $loan ) : null,
				'sensitivity_table' => $table,
			),
		);
	}
}

<?php
/**
 * matter-management/class-wp-mcp-ai-tool-lf-matter-budget-manager.php (ecosystem port — Wave F4, law-firm matter-management batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-budget-manager.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the `__DIR__` calculator require resolves from the
 * addon's already-ported `src/tools/law-firm/` copy.
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
 * Manages budgets and budget tracking for legal matters.
 */
class WP_MCP_AI_Tool_LF_Matter_Budget_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
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
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_matter_budget_manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Matter Budget Manager', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Manages budgets for legal matters including setting initial budgets, tracking utilization against time entries, and updating budget allocations with optional category breakdown.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'            => array(
					'type'        => 'string',
					'description' => __( 'Budget action to perform.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'set_budget', 'get_status', 'update_budget' ),
				),
				'matter_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The matter ID.', 'nvoos-content-graph-pro' ),
				),
				'budget_amount'     => array(
					'type'        => 'number',
					'description' => __( 'Total budget amount in dollars.', 'nvoos-content-graph-pro' ),
				),
				'budget_categories' => array(
					'type'        => 'object',
					'description' => __( 'Budget breakdown by category (e.g., {"attorney_fees": 50000, "filing_fees": 5000, "expert_witnesses": 10000}).', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'action', 'matter_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		if ( empty( $action ) || ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Action and matter ID are required.', 'nvoos-content-graph-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'nvoos-content-graph-pro' ) );
		}

		switch ( $action ) {
			case 'set_budget':
				$amount     = isset( $arguments['budget_amount'] ) ? floatval( $arguments['budget_amount'] ) : 0;
				$categories = array();
				if ( ! empty( $arguments['budget_categories'] ) && is_array( $arguments['budget_categories'] ) ) {
					foreach ( $arguments['budget_categories'] as $cat => $val ) {
						$categories[ sanitize_text_field( $cat ) ] = floatval( $val );
					}
				}

				if ( $amount <= 0 ) {
					return new WP_Error( 'invalid_param', __( 'Budget amount must be greater than zero.', 'nvoos-content-graph-pro' ) );
				}

				$budget = array(
					'total_amount' => $amount,
					'categories'   => $categories,
					'set_by'       => $uid,
					'set_at'       => current_time( 'Y-m-d H:i:s' ),
				);
				update_post_meta( $matter_id, '_lf_budget', $budget );

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %s: budget amount */
						__( 'Budget of $%s set successfully. ', 'nvoos-content-graph-pro' ),
						number_format( $amount, 2 )
					) . self::DISCLAIMER,
					'data'       => array(
						'matter_id' => $matter_id,
						'budget'    => $budget,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'get_status':
				$budget = get_post_meta( $matter_id, '_lf_budget', true );
				if ( ! is_array( $budget ) || empty( $budget['total_amount'] ) ) {
					return new WP_Error( 'no_budget', __( 'No budget has been set for this matter.', 'nvoos-content-graph-pro' ) );
				}

				// Calculate spent from time entries.
				$time_entries = get_post_meta( $matter_id, '_lf_time_entries', true );
				$total_spent  = 0.0;
				if ( is_array( $time_entries ) ) {
					foreach ( $time_entries as $entry ) {
						$hours        = (float) ( $entry['hours'] ?? 0 );
						$rate         = (float) ( $entry['rate'] ?? 0 );
						$total_spent += $hours * $rate;
					}
				}

				$total_budget = (float) $budget['total_amount'];
				$remaining    = $total_budget - $total_spent;
				$utilization  = $total_budget > 0 ? round( ( $total_spent / $total_budget ) * 100, 1 ) : 0;

				$status = 'on_track';
				if ( $utilization >= 100 ) {
					$status = 'over_budget';
				} elseif ( $utilization >= 80 ) {
					$status = 'at_risk';
				}

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: 1: utilization percentage, 2: budget status */
						__( 'Budget utilization: %1$s%% (%2$s). ', 'nvoos-content-graph-pro' ),
						$utilization,
						$status
					) . self::DISCLAIMER,
					'data'       => array(
						'matter_id'    => $matter_id,
						'total_budget' => $total_budget,
						'total_spent'  => round( $total_spent, 2 ),
						'remaining'    => round( $remaining, 2 ),
						'utilization'  => $utilization,
						'status'       => $status,
						'categories'   => $budget['categories'] ?? array(),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'update_budget':
				$budget = get_post_meta( $matter_id, '_lf_budget', true );
				if ( ! is_array( $budget ) ) {
					$budget = array();
				}

				if ( isset( $arguments['budget_amount'] ) ) {
					$budget['total_amount'] = floatval( $arguments['budget_amount'] );
				}
				if ( ! empty( $arguments['budget_categories'] ) && is_array( $arguments['budget_categories'] ) ) {
					$categories = array();
					foreach ( $arguments['budget_categories'] as $cat => $val ) {
						$categories[ sanitize_text_field( $cat ) ] = floatval( $val );
					}
					$budget['categories'] = $categories;
				}
				$budget['updated_by'] = $uid;
				$budget['updated_at'] = current_time( 'Y-m-d H:i:s' );
				update_post_meta( $matter_id, '_lf_budget', $budget );

				return array(
					'success'    => true,
					'message'    => __( 'Budget updated successfully. ', 'nvoos-content-graph-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'matter_id' => $matter_id,
						'budget'    => $budget,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid budget action.', 'nvoos-content-graph-pro' ) );
		}
	}
}

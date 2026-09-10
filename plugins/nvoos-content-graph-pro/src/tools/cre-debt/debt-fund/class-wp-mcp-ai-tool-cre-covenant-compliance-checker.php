<?php
/**
 * debt-fund/class-wp-mcp-ai-tool-cre-covenant-compliance-checker.php (ecosystem port — Wave F4, cre-debt debt-fund batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cre-debt/debt-fund/class-wp-mcp-ai-tool-cre-covenant-compliance-checker.php` for the
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
 * Checks financial covenant compliance (minimum/maximum thresholds)
 * and reporting deadline status for a CRE debt fund.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Covenant_Compliance_Checker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'cre_covenant_compliance_checker';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Covenant Compliance Checker', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Check fund-level financial covenant compliance (minimum/maximum thresholds with warning bands) and reporting deadline status. Identifies breaches, warnings, and overdue reports.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'covenants'           => array(
					'type'        => 'array',
					'description' => __( 'Array of covenant objects to check.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'          => array(
								'type'        => 'string',
								'description' => __( 'Covenant name (e.g. "Minimum DSCR").', 'nvoos-content-graph-pro' ),
							),
							'type'          => array(
								'type'        => 'string',
								'description' => __( 'Covenant type.', 'nvoos-content-graph-pro' ),
								'enum'        => array( 'minimum', 'maximum' ),
							),
							'threshold'     => array(
								'type'        => 'number',
								'description' => __( 'Covenant threshold value.', 'nvoos-content-graph-pro' ),
							),
							'current_value' => array(
								'type'        => 'number',
								'description' => __( 'Current measured value.', 'nvoos-content-graph-pro' ),
							),
						),
						'required'   => array( 'name', 'type', 'threshold', 'current_value' ),
					),
				),
				'reporting_deadlines' => array(
					'type'        => 'array',
					'description' => __( 'Array of reporting deadline objects.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'report_name' => array(
								'type'        => 'string',
								'description' => __( 'Report name (e.g. "Q4 Financial Statements").', 'nvoos-content-graph-pro' ),
							),
							'due_date'    => array(
								'type'        => 'string',
								'description' => __( 'Due date (YYYY-MM-DD).', 'nvoos-content-graph-pro' ),
							),
							'submitted'   => array(
								'type'        => 'boolean',
								'description' => __( 'Whether the report has been submitted.', 'nvoos-content-graph-pro' ),
							),
						),
						'required'   => array( 'report_name', 'due_date', 'submitted' ),
					),
				),
			),
			'required'   => array( 'covenants' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new \WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$covenants = $arguments['covenants'] ?? array();
		$deadlines = $arguments['reporting_deadlines'] ?? array();

		if ( empty( $covenants ) || ! is_array( $covenants ) ) {
			return new \WP_Error( 'invalid_input', __( 'At least one covenant is required.', 'nvoos-content-graph-pro' ) );
		}

		$today = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

		$covenant_results = array();
		$breaches         = array();
		$warnings         = array();
		$passes           = 0;

		foreach ( $covenants as $cov ) {
			$name      = sanitize_text_field( $cov['name'] ?? '' );
			$type      = sanitize_text_field( $cov['type'] ?? 'minimum' );
			$threshold = (float) ( $cov['threshold'] ?? 0 );
			$current   = (float) ( $cov['current_value'] ?? 0 );

			$status  = 'pass';
			$cushion = 0.0;

			if ( 'minimum' === $type ) {
				if ( $current < $threshold ) {
					$status     = 'breach';
					$breaches[] = $name;
				} elseif ( $threshold > 0 && $current < $threshold * 1.10 ) {
					$status     = 'warning';
					$warnings[] = $name;
				}
				$cushion = ( $threshold > 0 ) ? ( $current - $threshold ) / $threshold : 0;
			} elseif ( 'maximum' === $type ) {
				if ( $current > $threshold ) {
					$status     = 'breach';
					$breaches[] = $name;
				} elseif ( $threshold > 0 && $current > $threshold * 0.90 ) {
					$status     = 'warning';
					$warnings[] = $name;
				}
				$cushion = ( $threshold > 0 ) ? ( $threshold - $current ) / $threshold : 0;
			}

			if ( 'pass' === $status ) {
				++$passes;
			}

			$covenant_results[] = array(
				'name'          => $name,
				'type'          => $type,
				'threshold'     => $threshold,
				'current_value' => $current,
				'status'        => $status,
				'cushion_pct'   => round( $cushion * 100, 2 ) . '%',
			);
		}

		// Reporting deadlines.
		$deadline_results = array();
		$overdue_reports  = array();

		if ( is_array( $deadlines ) ) {
			foreach ( $deadlines as $dl ) {
				$report_name = sanitize_text_field( $dl['report_name'] ?? '' );
				$due_date    = sanitize_text_field( $dl['due_date'] ?? '' );
				$submitted   = ! empty( $dl['submitted'] );

				$is_overdue = false;
				$days_info  = '';

				if ( ! empty( $due_date ) ) {
					$due_dt    = new \DateTime( $due_date, new \DateTimeZone( 'UTC' ) );
					$diff      = $today->diff( $due_dt );
					$days_diff = (int) $diff->format( '%r%a' );

					if ( ! $submitted && $days_diff < 0 ) {
						$is_overdue        = true;
						$overdue_reports[] = $report_name;
						$days_info         = sprintf(
							/* translators: %d: number of days overdue */
							__( '%d days overdue', 'nvoos-content-graph-pro' ),
							abs( $days_diff )
						);
					} elseif ( $submitted ) {
						$days_info = __( 'Submitted', 'nvoos-content-graph-pro' );
					} else {
						$days_info = sprintf(
							/* translators: %d: number of days until due */
							__( '%d days remaining', 'nvoos-content-graph-pro' ),
							$days_diff
						);
					}
				}

				$deadline_results[] = array(
					'report_name' => $report_name,
					'due_date'    => $due_date,
					'submitted'   => $submitted,
					'is_overdue'  => $is_overdue,
					'status_info' => $days_info,
				);
			}
		}

		// Overall status.
		$overall = 'compliant';
		if ( ! empty( $breaches ) || ! empty( $overdue_reports ) ) {
			$overall = 'non_compliant';
		} elseif ( ! empty( $warnings ) ) {
			$overall = 'watch';
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: compliance status */
				__( 'Covenant compliance check complete — status: %s. ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
				strtoupper( str_replace( '_', '-', $overall ) )
			),
			'data'       => array(
				'overall_status'     => $overall,
				'covenants_checked'  => count( $covenant_results ),
				'covenants_passing'  => $passes,
				'covenants_breached' => count( $breaches ),
				'covenants_warning'  => count( $warnings ),
				'covenant_results'   => $covenant_results,
				'breached_covenants' => $breaches,
				'warning_covenants'  => $warnings,
				'reporting'          => array(
					'deadlines'       => $deadline_results,
					'overdue_reports' => $overdue_reports,
					'num_overdue'     => count( $overdue_reports ),
				),
			),
			'disclaimer' => __( 'ANALYSIS ONLY - Not investment advice.', 'nvoos-content-graph-pro' ),
		);
	}
}

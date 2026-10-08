<?php
/**
 * Food & Beverage Management Toolkit — Abstract Tool Base.
 *
 * Shared boilerplate for the fnb_* tools: capability gate, Pro flag, and a
 * standardised error envelope so every tool obeys the canonical-envelope and
 * two-gate sanitisation rules with minimal repetition.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the toolkit services so every tool can rely on them being present.
require_once __DIR__ . '/class-wp-mcp-ai-fnb-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-data-source.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-metrics.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-report-builder.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-draft-writer.php';

/**
 * Base class for F&B toolkit tools.
 *
 * @since 1.6.0
 */
abstract class WP_MCP_AI_Fnb_Tool_Base implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_slug();

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_name();

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_description();

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_parameters_schema();

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	abstract public function execute( array $arguments = array(), array $context = array() );

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Working with Surf Club / food-and-beverage operating data, metrics or reports.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Data outside the F&B data folder, or when the figure can be read from an existing report.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_read_table', 'fnb_calculate_metric', 'fnb_generate_report' ),
			'notes'           => __( 'All figures are computed from the data tables; cite source tables and rows in answers.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Required WordPress capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Whether this tool requires the Pro addon.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'cacheable', 'local-only', 'idempotent' );
	}

	/**
	 * Standard permission gate.
	 *
	 * @return WP_Error|null
	 */
	protected function guard() {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		return null;
	}

	/**
	 * Sanitised month argument (Y-m) or empty string.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string
	 */
	protected function arg_month( $arguments ) {
		$month = isset( $arguments['month'] ) ? sanitize_text_field( (string) $arguments['month'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return '';
		}

		return $month;
	}

	/**
	 * Sanitised date argument (Y-m-d) or empty string.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string
	 */
	protected function arg_date( $arguments ) {
		$date = isset( $arguments['date'] ) ? sanitize_text_field( (string) $arguments['date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		return $date;
	}
}

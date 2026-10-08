<?php
/**
 * F&B tool: variance split (M-07/M-08).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';

/**
 * Split a cost change into volume effect and per-cover effect (A3-01).
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Variance_Split extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_variance_split';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Variance Split', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Split a cost change between two months into the volume effect (M-07: cover-count driven) and the per-cover effect (M-08: everything else).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'month'      => array(
					'type'        => 'string',
					'description' => __( 'Current month (Y-m).', 'mcp-ai-wpoos-pro' ),
				),
				'prev_month' => array(
					'type'        => 'string',
					'description' => __( 'Previous month (Y-m).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'month', 'prev_month' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$month      = $this->arg_month( $arguments );
		$prev_month = isset( $arguments['prev_month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $arguments['prev_month'] ) ? sanitize_text_field( (string) $arguments['prev_month'] ) : '';
		if ( '' === $month || '' === $prev_month ) {
			return new WP_Error( 'wp_mcp_ai_fnb_missing_months', __( 'Both month and prev_month (Y-m) are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$cur         = WP_MCP_AI_Fnb_Metrics::total_operating_cost( $month );
		$prev        = WP_MCP_AI_Fnb_Metrics::total_operating_cost( $prev_month );
		$covers      = WP_MCP_AI_Fnb_Metrics::m02_covers( $month );
		$prev_covers = WP_MCP_AI_Fnb_Metrics::m02_covers( $prev_month );

		$volume    = WP_MCP_AI_Fnb_Metrics::m07_volume_effect( $prev['total'], $prev_covers['value'], $covers['value'] );
		$per_cover = WP_MCP_AI_Fnb_Metrics::m08_per_cover_effect( $cur['total'], $prev['total'], $volume );

		$lines = array();
		foreach ( $cur['lines'] as $line => $amount ) {
			$prev_amount = isset( $prev['lines'][ $line ] ) ? $prev['lines'][ $line ] : 0.0;
			$line_volume = WP_MCP_AI_Fnb_Metrics::m07_volume_effect( $prev_amount, $prev_covers['value'], $covers['value'] );
			$lines[]     = array(
				'line'             => $line,
				'current'          => round( $amount, 2 ),
				'previous'         => round( $prev_amount, 2 ),
				'change'           => round( $amount - $prev_amount, 2 ),
				'volume_effect'    => round( $line_volume, 2 ),
				'per_cover_effect' => round( ( $amount - $prev_amount ) - $line_volume, 2 ),
			);
		}

		return array(
			'success'          => true,
			'month'            => $month,
			'prev_month'       => $prev_month,
			'total_change'     => round( $cur['total'] - $prev['total'], 2 ),
			'volume_effect'    => $volume,
			'per_cover_effect' => $per_cover,
			'covers'           => round( $covers['value'] ),
			'prev_covers'      => round( $prev_covers['value'] ),
			'lines'            => $lines,
			'source'           => 'fnb_variance_split',
		);
	}
}

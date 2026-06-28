<?php
/**
 * Forecast Pipeline Revenue Tool — weighted revenue forecast.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Forecast Pipeline Revenue — weighted revenue forecast. */
class WP_MCP_AI_Tool_Forecast_Pipeline_Revenue implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	/**
	 * Reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'forecast_pipeline_revenue'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Forecast Pipeline Revenue', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Weighted pipeline revenue forecast by month/quarter with best-case, most-likely, and commit scenarios.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_owner' => array( 'type' => 'integer' ),
				'bucket'     => array(
					'type'    => 'string',
					'enum'    => array( 'month', 'quarter' ),
					'default' => 'month',
				),
				'months'     => array(
					'type'        => 'integer',
					'default'     => 6,
					'minimum'     => 1,
					'maximum'     => 24,
					'description' => __( 'Number of future periods to forecast.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}
	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts'; }

	/**
	 * Whether this tool requires base pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true; }

	/**
	 * Capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$bucket = sanitize_key( $arguments['bucket'] ?? 'month' );
		$months = min( 24, max( 1, absint( $arguments['months'] ?? 6 ) ) );

		$meta_q = array( 'relation' => 'AND' );
		// Exclude closed-won and closed-lost.
		$meta_q[] = array(
			'key'     => 'deal_stage',
			'value'   => array( 'closed_won', 'closed_lost' ),
			'compare' => 'NOT IN',
		);
		if ( ! empty( $arguments['deal_owner'] ) ) {
			$meta_q[] = array(
				'key'   => 'deal_owner',
				'value' => absint( $arguments['deal_owner'] ),
				'type'  => 'NUMERIC',
			);
		}

		$q = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'meta_query'     => $meta_q,
				'no_found_rows'  => true,
			)
		);

		$buckets = array();
		$totals  = array(
			'best_case'   => 0,
			'most_likely' => 0,
			'commit'      => 0,
		);

		foreach ( $q->posts as $deal ) {
			$amount = (float) get_post_meta( $deal->ID, 'deal_amount', true );
			$prob   = (float) get_post_meta( $deal->ID, 'deal_probability', true );
			$close  = get_post_meta( $deal->ID, 'close_date', true );
			$stage  = get_post_meta( $deal->ID, 'deal_stage', true );

			if ( ! $close || ! $amount ) {
				continue; }

			$ts = strtotime( $close );
			if ( ! $ts || $ts < time() ) {
				continue; }

			$key = 'quarter' === $bucket
				? gmdate( 'Y', $ts ) . '-Q' . ceil( (int) gmdate( 'n', $ts ) / 3 )
				: gmdate( 'Y-m', $ts );

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'best_case'   => 0,
					'most_likely' => 0,
					'commit'      => 0,
					'deal_count'  => 0,
				);
			}

			$buckets[ $key ]['best_case']   += $amount;
			$buckets[ $key ]['most_likely'] += $amount * $prob;
			$buckets[ $key ]['commit']      += ( $prob >= 0.75 ) ? $amount : 0;
			++$buckets[ $key ]['deal_count'];

			$totals['best_case']   += $amount;
			$totals['most_likely'] += $amount * $prob;
			$totals['commit']      += ( $prob >= 0.75 ) ? $amount : 0;
		}

		ksort( $buckets );

		// Format amounts.
		$eng = class_exists( 'WP_MCP_AI_CRM_Engine' ) ? 'WP_MCP_AI_CRM_Engine' : null;
		foreach ( $buckets as &$b ) {
			$b['formatted_best_case']   = $eng ? $eng::format_currency( $b['best_case'] ) : '$' . number_format_i18n( $b['best_case'], 2 );
			$b['formatted_most_likely'] = $eng ? $eng::format_currency( $b['most_likely'] ) : '$' . number_format_i18n( $b['most_likely'], 2 );
			$b['formatted_commit']      = $eng ? $eng::format_currency( $b['commit'] ) : '$' . number_format_i18n( $b['commit'], 2 );
		}
		unset( $b );

		return array(
			'success'  => true,
			'forecast' => array(
				'bucket_type' => $bucket,
				'buckets'     => array_values( $buckets ),
			),
			'totals'   => array(
				'best_case'             => $totals['best_case'],
				'most_likely'           => $totals['most_likely'],
				'commit'                => $totals['commit'],
				'formatted_best_case'   => $eng ? $eng::format_currency( $totals['best_case'] ) : '',
				'formatted_most_likely' => $eng ? $eng::format_currency( $totals['most_likely'] ) : '',
				'formatted_commit'      => $eng ? $eng::format_currency( $totals['commit'] ) : '',
			),
		);
	}
}

<?php
/**
 * Conversion Funnel Tool — stage-to-stage conversion rates.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Conversion Funnel — stage-to-stage conversion rates. */
class WP_MCP_AI_Tool_Get_Conversion_Funnel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'get_conversion_funnel'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Conversion Funnel', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Stage-to-stage conversion rates and weighted funnel analysis.', 'mcp-ai-wpoos-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_from'  => array(
					'type'        => 'string',
					'description' => __( 'Deals created on or after (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'    => array(
					'type'        => 'string',
					'description' => __( 'Deals created on or before (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'deal_owner' => array( 'type' => 'integer' ),
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

		$stages      = WP_MCP_AI_CRM_Pipeline_Stages::get_stages();
		$open_stages = array_keys( WP_MCP_AI_CRM_Pipeline_Stages::get_open_stages() );

		$meta_q = array( 'relation' => 'AND' );
		if ( ! empty( $arguments['deal_owner'] ) ) {
			$meta_q[] = array(
				'key'   => 'deal_owner',
				'value' => absint( $arguments['deal_owner'] ),
				'type'  => 'NUMERIC',
			);
		}

		// Count deals per stage.
		$stage_counts = array();
		foreach ( $stages as $sid => $sdef ) {
			$q_args = array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => array(
					array(
						'key'   => 'deal_stage',
						'value' => $sid,
					),
				),
			);
			if ( count( $meta_q ) > 1 ) {
				$q_args['meta_query'][] = $meta_q[1] ?? $meta_q[0];
			}
			if ( ! empty( $arguments['date_from'] ) || ! empty( $arguments['date_to'] ) ) {
				$dq = array();
				if ( ! empty( $arguments['date_from'] ) ) {
					$dq['after'] = sanitize_text_field( $arguments['date_from'] ); }
				if ( ! empty( $arguments['date_to'] ) ) {
					$dq['before']    = sanitize_text_field( $arguments['date_to'] );
					$dq['inclusive'] = true; }
				$q_args['date_query'] = array( $dq );
			}
			$q                    = new WP_Query( $q_args );
			$stage_counts[ $sid ] = $q->found_posts;
		}

		// Build funnel: conversion rates between consecutive stages.
		$funnel     = array();
		$prev_count = null;
		foreach ( $open_stages as $sid ) {
			$c          = $stage_counts[ $sid ] ?? 0;
			$funnel[]   = array(
				'stage_id'   => $sid,
				'label'      => $stages[ $sid ]['label'],
				'count'      => $c,
				'conversion' => ( null !== $prev_count && $prev_count > 0 ) ? round( $c / $prev_count * 100, 1 ) : null,
			);
			$prev_count = $c;
		}

		// Total won/lost.
		$won_count   = $stage_counts['closed_won'] ?? 0;
		$lost_count  = $stage_counts['closed_lost'] ?? 0;
		$total_deals = array_sum( $stage_counts );
		$win_rate    = $total_deals > 0 ? round( $won_count / $total_deals * 100, 1 ) : 0;

		return array(
			'success' => true,
			'funnel'  => $funnel,
			'summary' => array(
				'total_deals'  => $total_deals,
				'won'          => $won_count,
				'lost'         => $lost_count,
				'win_rate_pct' => $win_rate,
			),
		);
	}
}

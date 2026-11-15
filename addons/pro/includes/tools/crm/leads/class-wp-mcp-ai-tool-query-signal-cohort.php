<?php
/**
 * Query Signal Cohort — live must / must-not / range query.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 *
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cohort query tool.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Query_Signal_Cohort implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Availability.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'query_signal_cohort';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Query Signal Cohort', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Count and sample leads matching must-have signals, disqualifying signals and numeric ranges. A cohort is a query, not a saved list.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'When defining a campaign audience or checking whether enough prospects match a signal combination.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Single-lead evidence; use explain_prospect_fit. Saving a static list; use a sequence or export.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'explain_prospect_fit', 'collect_prospect_signals', 'export_outreach_csv' ),
			'notes'           => __( 'Only confirmed signals participate. Ranges use numeric signal values (e.g. blog_stale days).', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'must'       => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Signal keys that must be present.', 'mcp-ai-wpoos-pro' ),
				),
				'must_not'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Signal keys that must be absent.', 'mcp-ai-wpoos-pro' ),
				),
				'ranges'     => array(
					'type'        => 'object',
					'description' => __( 'Numeric ranges keyed by signal, e.g. {"blog_stale":{"min":90}}.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 500,
					'default' => 50,
				),
				'count_only' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Requires Base Pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'pii-access' );
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Arguments.
	 * @param array $context   Context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		if ( ! class_exists( 'WP_MCP_AI_CRM_Signals' ) ) {
			return new WP_Error( 'engine_missing', __( 'Signals engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$ranges = array();
		if ( ! empty( $arguments['ranges'] ) && is_array( $arguments['ranges'] ) ) {
			foreach ( $arguments['ranges'] as $key => $range ) {
				if ( ! is_array( $range ) ) {
					continue;
				}
				$ranges[ sanitize_key( $key ) ] = array(
					'min' => isset( $range['min'] ) ? (float) $range['min'] : null,
					'max' => isset( $range['max'] ) ? (float) $range['max'] : null,
				);
			}
		}

		$result = WP_MCP_AI_CRM_Signals::cohort(
			array(
				'must'       => array_map( 'sanitize_key', (array) ( $arguments['must'] ?? array() ) ),
				'must_not'   => array_map( 'sanitize_key', (array) ( $arguments['must_not'] ?? array() ) ),
				'ranges'     => $ranges,
				'limit'      => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50,
				'count_only' => ! empty( $arguments['count_only'] ),
			)
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: cohort size */
				__( '%d lead(s) match this cohort.', 'mcp-ai-wpoos-pro' ),
				(int) $result['count']
			),
			'count'   => $result['count'],
			'sample'  => $result['sample'] ?? array(),
			'query'   => $result['query'] ?? array(),
		);
	}
}

<?php
/**
 * Tool: check_member_allergies
 *
 * Quick-lookup that answers "is this member allergic to X?" by searching
 * existing `mcp_ai_allergy` posts for a given allergen substring and
 * returning severity, reactions and diagnosed date.  Read-only, no new
 * storage layer.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check member allergies tool.
 */
class WP_MCP_AI_Tool_Check_Member_Allergies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_member_allergies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Member Allergies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Determine whether a member has known allergies matching the supplied allergen names or substrings, and return severity, reactions, and diagnosed dates for any matches.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'allergens' => array(
					'type'        => 'array',
					'description' => __( 'Allergen names or substrings to test (case-insensitive).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 1,
				),
			),
			'required'   => array( 'member_id', 'allergens' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'pii-data', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view allergy data.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		if ( $member_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'A valid member_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$allergens_in = isset( $arguments['allergens'] ) && is_array( $arguments['allergens'] )
			? $arguments['allergens']
			: array();
		$allergens    = array();
		foreach ( $allergens_in as $a ) {
			$clean = trim( sanitize_text_field( (string) $a ) );
			if ( '' !== $clean ) {
				$allergens[] = strtolower( $clean );
			}
		}
		if ( empty( $allergens ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_allergens', __( 'At least one allergen name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Audit the read.
		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'read',
				'allergy',
				$member_id,
				array(
					'user_id' => $current_user_id,
					'tool'    => $this->get_slug(),
				)
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_allergy',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'check_member_allergies', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_allergy_member_id',
						'value' => $member_id,
					),
				),
				'no_found_rows'  => true,
			)
		);

		$matches      = array();
		$all_known    = array();
		$matched_keys = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$allergy_id = get_the_ID();
				$title      = get_the_title();
				$title_low  = strtolower( $title );
				$reactions  = (string) get_post_meta( $allergy_id, '_allergy_reactions', true );
				$severities = wp_get_object_terms( $allergy_id, 'mcp_ai_allergy_severity', array( 'fields' => 'slugs' ) );
				$severity   = ( ! empty( $severities ) && ! is_wp_error( $severities ) ) ? $severities[0] : '';
				$diagnosed  = (string) get_post_meta( $allergy_id, '_allergy_diagnosed_date', true );

				$all_known[] = $title;

				foreach ( $allergens as $needle ) {
					if ( '' === $needle ) {
						continue;
					}
					if ( false !== strpos( $title_low, $needle )
						|| false !== strpos( strtolower( $reactions ), $needle )
					) {
						$matches[]      = array(
							'allergy_id'     => $allergy_id,
							'allergen'       => $title,
							'matched_query'  => $needle,
							'severity'       => $severity,
							'reactions'      => $reactions,
							'diagnosed_date' => $diagnosed,
						);
						$matched_keys[] = $needle;
						break;
					}
				}
			}
			wp_reset_postdata();
		}

		$matched_keys = array_values( array_unique( $matched_keys ) );
		$unmatched    = array_values( array_diff( $allergens, $matched_keys ) );

		return array(
			'success'           => true,
			'member_id'         => $member_id,
			'has_match'         => ! empty( $matches ),
			'matches'           => $matches,
			'unmatched_queries' => $unmatched,
			'known_allergens'   => $all_known,
		);
	}
}

<?php
/**
 * Tool for checking member allergies.
 *
 * Safety tool for quickly checking if a member has any allergies, especially useful before prescribing medications.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quick allergy check for a member (safety tool).
 */
class WP_MCP_AI_Tool_Check_Member_Allergies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Safety tool to quickly check if a member has any known allergies. Returns all allergy records with severity levels. Critical for medication safety checks.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to check allergies for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'allergen'   => array(
					'type'        => 'string',
					'description' => __( 'Optional: Check for specific allergen (e.g., "Penicillin") (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'type'       => array(
					'type'        => 'string',
					'description' => __( 'Optional: Filter by allergy type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'food', 'medication', 'environmental', 'insect', 'other' ),
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * Check if the tool is available.
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to check allergies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$allergen  = isset( $arguments['allergen'] ) ? sanitize_text_field( $arguments['allergen'] ) : '';
		$type      = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_allergy',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'   => '_allergy_member_id',
					'value' => $member_id,
				),
			),
		);

		// Add type filter if provided.
		if ( $type ) {
			$query_args['meta_query'][] = array(
				'key'   => '_allergy_type',
				'value' => $type,
			);
		}

		// Add search for specific allergen if provided.
		if ( $allergen ) {
			$query_args['s'] = $allergen;
		}

		$query = new WP_Query( $query_args );

		$allergies         = array();
		$has_severe        = false;
		$has_life_threatening = false;

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$allergy_id = get_the_ID();
				$severity   = get_post_meta( $allergy_id, '_allergy_severity', true );

				// Track severity levels.
				if ( 'severe' === $severity ) {
					$has_severe = true;
				}
				if ( 'life-threatening' === $severity ) {
					$has_life_threatening = true;
				}

				$allergies[] = array(
					'id'             => $allergy_id,
					'allergen'       => get_the_title(),
					'type'           => get_post_meta( $allergy_id, '_allergy_type', true ),
					'severity'       => $severity,
					'reactions'      => get_post_meta( $allergy_id, '_allergy_reactions', true ),
					'diagnosed_date' => get_post_meta( $allergy_id, '_allergy_diagnosed_date', true ),
				);
			}
			wp_reset_postdata();
		}

		// Build warning message.
		$warning_level = 'none';
		if ( $has_life_threatening ) {
			$warning_level = 'critical';
		} elseif ( $has_severe ) {
			$warning_level = 'high';
		} elseif ( count( $allergies ) > 0 ) {
			$warning_level = 'caution';
		}

		$message = '';
		if ( 'critical' === $warning_level ) {
			$message = __( '⚠️ CRITICAL: Member has LIFE-THREATENING allergies. Review carefully before prescribing.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'high' === $warning_level ) {
			$message = __( '⚠️ WARNING: Member has SEVERE allergies. Review before prescribing.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'caution' === $warning_level ) {
			$message = __( 'ℹ️ CAUTION: Member has known allergies. Review before prescribing.', 'mcp-ai-wpoos-pro' );
		} else {
			$message = __( '✓ No known allergies on record for this member.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'              => true,
			'member_id'            => $member_id,
			'member_name'          => $member->post_title,
			'has_allergies'        => count( $allergies ) > 0,
			'total_allergies'      => count( $allergies ),
			'warning_level'        => $warning_level,
			'has_severe'           => $has_severe,
			'has_life_threatening' => $has_life_threatening,
			'allergies'            => $allergies,
			'message'              => $message,
		);
	}
}

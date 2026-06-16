<?php
/**
 * Tool for recalculating engagement scores for CRM contacts.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recalculates engagement scores for CRM contacts based on recent activity.
 *
 * Computes engagement scores using activity volume, recency, interaction
 * diversity, and response rate. Supports targeting specific contacts or
 * recalculating all contacts, with dry_run mode.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Tool_Recalculate_Engagement_Scores implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'recalculate_engagement_scores';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Recalculate Engagement Scores', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Recalculates engagement scores for CRM contacts based on recent activity metrics. Supports standard and custom scoring models, targeting specific contacts or all, with dry_run mode.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'contact_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of contact IDs to recalculate. If empty, recalculates all contacts.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'score_model' => array(
					'type'        => 'string',
					'description' => __( 'Scoring model to use. Default: default.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'default', 'custom' ),
					'default'     => 'default',
				),
				'dry_run'     => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview scores without saving. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'post_type'             => 'mcp_ai_lead',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'sales_manager', 'sales_ops' ),
			'risk_level'            => 'caution',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the CRM Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.9.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.9.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Recalculate Engagement Scores tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Recalculation result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'CRM Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$contact_ids = isset( $arguments['contact_ids'] ) ? array_map( 'absint', (array) $arguments['contact_ids'] ) : array();
		$score_model = isset( $arguments['score_model'] ) ? sanitize_text_field( $arguments['score_model'] ) : 'default';
		$dry_run     = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		// If no specific contact IDs provided, get all contacts from leads and customers.
		if ( empty( $contact_ids ) ) {
			$contact_ids = $this->get_all_contact_ids();
		}

		$scored = array();
		$failed = array();

		foreach ( $contact_ids as $contact_id ) {
			$post = get_post( $contact_id );
			if ( ! $post || ! in_array( $post->post_type, array( 'mcp_ai_lead', 'mcp_ai_customer' ), true ) ) {
				$failed[] = array(
					'id'     => $contact_id,
					'reason' => __( 'Contact not found or invalid type.', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			$score        = $this->calculate_engagement_score( $contact_id, $score_model );
			$score_detail = $this->get_score_breakdown( $contact_id );

			$result_item = array(
				'id'               => $contact_id,
				'type'             => $post->post_type,
				'title'            => get_the_title( $post ),
				'engagement_score' => $score,
				'score_breakdown'  => $score_detail,
			);

			if ( ! $dry_run ) {
				update_post_meta( $contact_id, '_engagement_score', $score );
				update_post_meta( $contact_id, '_engagement_score_breakdown', wp_json_encode( $score_detail ) );
				update_post_meta( $contact_id, '_engagement_score_date', gmdate( 'Y-m-d H:i:s' ) );
				update_post_meta( $contact_id, '_engagement_score_model', $score_model );
			}

			$scored[] = $result_item;
		}

		return array(
			'success'      => true,
			'dry_run'      => $dry_run,
			'score_model'  => $score_model,
			'action'       => $dry_run
				? __( 'Dry run completed. No scores were saved.', 'mcp-ai-wpoos-pro' )
				: __( 'Engagement scores recalculated successfully.', 'mcp-ai-wpoos-pro' ),
			'scored_count' => count( $scored ),
			'failed_count' => count( $failed ),
			'contacts'     => array(
				'scored' => $scored,
				'failed' => $failed,
			),
		);
	}

	/**
	 * Calculate engagement score for a contact.
	 *
	 * Uses activity volume (40%), recency (25%), channel diversity (20%),
	 * and completion rate (15%) to compute a score from 0-100.
	 *
	 * @since 2.9.0
	 * @param int    $contact_id  Contact post ID.
	 * @param string $score_model Scoring model identifier.
	 * @return int Score from 0-100.
	 */
	private function calculate_engagement_score( $contact_id, $score_model ) {
		$activities = $this->get_contact_activities( $contact_id );

		if ( empty( $activities ) ) {
			return 0;
		}

		// Volume score (40%): based on total activities in the scoring window.
		$volume_score = min( 100, count( $activities ) * 10 );

		// Recency score (25%): based on how recent the last activity was.
		$newest        = reset( $activities );
		$days_since    = max( 0, ( time() - strtotime( $newest->post_date ) ) / DAY_IN_SECONDS );
		$recency_score = max( 0, 100 - ( $days_since * 2 ) );

		// Diversity score (20%): based on unique activity types.
		$types           = array_unique(
			array_map(
				function ( $a ) {
					return get_post_meta( $a->ID, '_activity_type', true );
				},
				$activities
			)
		);
		$diversity_score = min( 100, count( $types ) * 25 );

		// Completion score (15%): based on completed vs total activities.
		$completed = 0;
		foreach ( $activities as $a ) {
			$outcome = get_post_meta( $a->ID, '_activity_outcome', true );
			if ( 'completed' === $outcome ) {
				++$completed;
			}
		}
		$completion_score = count( $activities ) > 0
			? min( 100, ( $completed / count( $activities ) ) * 100 )
			: 0;

		$total = round(
			( $volume_score * 0.40 ) +
			( $recency_score * 0.25 ) +
			( $diversity_score * 0.20 ) +
			( $completion_score * 0.15 )
		);

		return max( 0, min( 100, $total ) );
	}

	/**
	 * Get score breakdown details.
	 *
	 * @since 2.9.0
	 * @param int $contact_id Contact post ID.
	 * @return array Score component breakdown.
	 */
	private function get_score_breakdown( $contact_id ) {
		$activities = $this->get_contact_activities( $contact_id );

		if ( empty( $activities ) ) {
			return array(
				'volume'     => 0,
				'recency'    => 0,
				'diversity'  => 0,
				'completion' => 0,
			);
		}

		$volume_score = min( 100, count( $activities ) * 10 );

		$newest        = reset( $activities );
		$days_since    = max( 0, ( time() - strtotime( $newest->post_date ) ) / DAY_IN_SECONDS );
		$recency_score = max( 0, 100 - ( $days_since * 2 ) );

		$types           = array_unique(
			array_map(
				function ( $a ) {
					return get_post_meta( $a->ID, '_activity_type', true );
				},
				$activities
			)
		);
		$diversity_score = min( 100, count( $types ) * 25 );

		$completed = 0;
		foreach ( $activities as $a ) {
			$outcome = get_post_meta( $a->ID, '_activity_outcome', true );
			if ( 'completed' === $outcome ) {
				++$completed;
			}
		}
		$completion_score = count( $activities ) > 0
			? min( 100, ( $completed / count( $activities ) ) * 100 )
			: 0;

		return array(
			'volume'           => round( $volume_score ),
			'recency'          => round( $recency_score ),
			'diversity'        => round( $diversity_score ),
			'completion'       => round( $completion_score ),
			'total_activities' => count( $activities ),
			'days_since_last'  => round( $days_since ),
		);
	}

	/**
	 * Get all activities for a contact, ordered by most recent first.
	 *
	 * @since 2.9.0
	 * @param int $contact_id Contact post ID.
	 * @return array Array of post objects.
	 */
	private function get_contact_activities( $contact_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'posts_per_page' => 200,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_activity_contact_id',
						'value' => $contact_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		$activities = $query->posts;
		wp_reset_postdata();
		return $activities;
	}

	/**
	 * Get all lead and customer IDs.
	 *
	 * @since 2.9.0
	 * @return array Array of post IDs.
	 */
	private function get_all_contact_ids() {
		$ids = array();

		$leads = get_posts(
			array(
				'post_type'      => 'mcp_ai_lead',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);
		$ids   = array_merge( $ids, $leads );

		$customers = get_posts(
			array(
				'post_type'      => 'mcp_ai_customer',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			)
		);
		$ids       = array_merge( $ids, $customers );

		return $ids;
	}
}

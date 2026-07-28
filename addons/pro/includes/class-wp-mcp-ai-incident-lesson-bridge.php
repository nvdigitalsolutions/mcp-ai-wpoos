<?php
/**
 * Incident-Lesson Bridge
 *
 * Bridges the incident communication workflow (mcp_ai_incident CPT) with
 * the Incident Learning System (mcp_ai_lesson CPT, ISO 27001 A.5.27).
 * When an incident is resolved, an operator can optionally create a
 * post-mortem lesson that links back to the incident for traceability.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Incident_Lesson_Bridge' ) ) {
	/**
	 * Incident-Lesson Bridge class.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Incident_Lesson_Bridge {

		/**
		 * Initialize hooks.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public static function init(): void {
			// Add "Create Lesson" action link on resolved incidents.
			add_filter( 'post_row_actions', array( __CLASS__, 'add_lesson_action' ), 10, 2 );
		}

		/**
		 * Add a "Create Lesson" row action for resolved incidents.
		 *
		 * @since 1.4.0
		 *
		 * @param array   $actions Existing row actions.
		 * @param WP_Post $post    Post object.
		 * @return array
		 */
		public static function add_lesson_action( array $actions, WP_Post $post ): array {
			if ( WP_MCP_AI_Incident_CPT::POST_TYPE !== $post->post_type ) {
				return $actions;
			}

			$phase     = get_post_meta( $post->ID, '_mcp_ai_incident_phase', true );
			$lesson_id = (int) get_post_meta( $post->ID, '_mcp_ai_incident_lesson_id', true );

			if ( WP_MCP_AI_Incident_CPT::PHASE_RESOLVED !== $phase ) {
				return $actions;
			}

			if ( $lesson_id > 0 ) {
				$edit_url = get_edit_post_link( $lesson_id );
				if ( $edit_url ) {
					$actions['view_lesson'] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( $edit_url ),
						__( 'View Lesson', 'mcp-ai-wpoos-pro' )
					);
				}
			} else {
				$create_url = wp_nonce_url(
					add_query_arg(
						array(
							'action'      => 'wp_mcp_ai_create_incident_lesson',
							'incident_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'wp_mcp_ai_create_incident_lesson_' . $post->ID
				);

				$actions['create_lesson'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $create_url ),
					__( 'Create Lesson', 'mcp-ai-wpoos-pro' )
				);
			}

			return $actions;
		}

		/**
		 * Handle the "Create Lesson" admin-post action.
		 *
		 * Creates an mcp_ai_lesson post pre-populated from the incident data
		 * and links it back to the incident.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public static function handle_create_lesson(): void {
			$incident_id = isset( $_GET['incident_id'] ) ? absint( wp_unslash( $_GET['incident_id'] ) ) : 0;

			if ( 0 === $incident_id ) {
				wp_die( esc_html__( 'Invalid incident ID.', 'mcp-ai-wpoos-pro' ) );
			}

			check_admin_referer( 'wp_mcp_ai_create_incident_lesson_' . $incident_id );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'mcp-ai-wpoos-pro' ) );
			}

			$incident = get_post( $incident_id );
			if ( ! $incident || WP_MCP_AI_Incident_CPT::POST_TYPE !== $incident->post_type ) {
				wp_die( esc_html__( 'Incident not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Pre-populate lesson content from incident data.
			$timeline     = get_post_meta( $incident_id, '_mcp_ai_incident_timeline', true );
			$services_raw = get_post_meta( $incident_id, '_mcp_ai_incident_services', true );
			$services     = is_array( $services_raw ) ? $services_raw : array();

			$timeline_text = '';
			if ( is_array( $timeline ) ) {
				foreach ( $timeline as $entry ) {
					$phase_label    = WP_MCP_AI_Incident_CPT::get_phase_label( $entry['phase'] ?? '' );
					$timeline_text .= sprintf(
						"- [%s] %s: %s\n",
						gmdate( 'Y-m-d H:i', $entry['timestamp'] ?? 0 ),
						$phase_label,
						$entry['message'] ?? ''
					);
				}
			}

			$lesson_content = sprintf(
				"## Incident Summary\n\n%s\n\n## Timeline\n\n%s\n\n## Affected Services\n\n%s\n\n## Root Cause\n\n[To be completed]\n\n## Lessons Learned\n\n[To be completed]\n\n## Preventive Actions\n\n[To be completed]",
				$incident->post_title,
				$timeline_text,
				! empty( $services ) ? '- ' . implode( "\n- ", $services ) : __( 'None specified.', 'mcp-ai-wpoos-pro' )
			);

			$lesson_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_lesson',
					'post_title'   => sprintf(
						/* translators: %s: incident title */
						__( 'Lesson: %s', 'mcp-ai-wpoos-pro' ),
						$incident->post_title
					),
					'post_content' => $lesson_content,
					'post_status'  => 'draft',
					'meta_input'   => array(
						'_mcp_ai_lesson_incident_id' => $incident_id,
					),
				),
				true
			);

			if ( is_wp_error( $lesson_id ) ) {
				wp_die( esc_html( $lesson_id->get_error_message() ) );
			}

			// Link the lesson back to the incident.
			update_post_meta( $incident_id, '_mcp_ai_incident_lesson_id', $lesson_id );

			wp_safe_redirect( get_edit_post_link( $lesson_id, 'raw' ) );
			exit;
		}
	}

	// Bootstrap.
	WP_MCP_AI_Incident_Lesson_Bridge::init();
	add_action( 'admin_post_wp_mcp_ai_create_incident_lesson', array( 'WP_MCP_AI_Incident_Lesson_Bridge', 'handle_create_lesson' ) );
}

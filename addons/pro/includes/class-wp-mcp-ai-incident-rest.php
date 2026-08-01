<?php
/**
 * Incident REST Controller
 *
 * Provides REST endpoints for managing operational incidents. Write operations
 * require manage_options capability. Public read-only endpoints surface active
 * incidents for the status page.
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

if ( ! class_exists( 'WP_MCP_AI_Incident_REST_Controller' ) ) {
	/**
	 * Incident REST controller.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_Incident_REST_Controller {

		/**
		 * Register incident REST routes.
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			// Public: active incidents for the status page.
			register_rest_route(
				'mcp-ai/v1',
				'/status/incidents',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_public_incidents' ),
					'permission_callback' => '__return_true',
				)
			);

			// Admin: list all incidents.
			register_rest_route(
				'mcp-ai-pro/v1',
				'/incidents',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_incidents' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'phase'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'severity' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);

			// Admin: create incident.
			register_rest_route(
				'mcp-ai-pro/v1',
				'/incidents',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_incident' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::get_create_args(),
				)
			);

			// Admin: update incident (phase transition + timeline).
			register_rest_route(
				'mcp-ai-pro/v1',
				'/incidents/(?P<id>\d+)',
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_incident' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::get_update_args(),
				)
			);

			// Admin: resolve incident (convenience).
			register_rest_route(
				'mcp-ai-pro/v1',
				'/incidents/(?P<id>\d+)/resolve',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'resolve_incident' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'message' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);
		}

		/**
		 * Permission check.
		 *
		 * @since 1.4.0
		 *
		 * @return bool
		 */
		public static function check_admin_permission(): bool {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Get active incidents for public consumption.
		 *
		 * @since 1.4.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response
		 */
		public static function get_public_incidents( WP_REST_Request $request ): WP_REST_Response {
			unset( $request );

			$incidents = WP_MCP_AI_Incident_CPT::get_active_incidents( 10 );
			$data      = array();

			foreach ( $incidents as $incident ) {
				$data[] = self::format_public_incident( $incident );
			}

			header( 'Cache-Control: public, max-age=60, s-maxage=60' );

			return rest_ensure_response(
				array(
					'incidents' => $data,
					'total'     => count( $data ),
				)
			);
		}

		/**
		 * List all incidents (admin).
		 *
		 * @since 1.4.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response
		 */
		public static function get_incidents( WP_REST_Request $request ): WP_REST_Response {
			$phase    = $request->get_param( 'phase' );
			$severity = $request->get_param( 'severity' );
			$per_page = $request->get_param( 'per_page' );
			$page     = $request->get_param( 'page' );

			$meta_query = array();
			if ( '' !== $phase ) {
				$meta_query[] = array(
					'key'   => '_mcp_ai_incident_phase',
					'value' => $phase,
				);
			}
			if ( '' !== $severity ) {
				$meta_query[] = array(
					'key'   => '_mcp_ai_incident_severity',
					'value' => $severity,
				);
			}

			$args = array(
				'post_type'      => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			);

			if ( ! empty( $meta_query ) ) {
				$args['meta_query'] = $meta_query;
			}

			$query     = new WP_Query( $args );
			$incidents = array();

			foreach ( $query->posts as $post ) {
				$incidents[] = self::format_incident( $post );
			}

			return rest_ensure_response(
				array(
					'incidents'  => $incidents,
					'total'      => $query->found_posts,
					'pagination' => array(
						'page'     => $page,
						'per_page' => $per_page,
						'total'    => $query->found_posts,
					),
				)
			);
		}

		/**
		 * Create a new incident.
		 *
		 * @since 1.4.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function create_incident( WP_REST_Request $request ) {
			$title    = sanitize_text_field( $request->get_param( 'title' ) );
			$severity = sanitize_text_field( $request->get_param( 'severity' ) );

			if ( empty( $title ) ) {
				return new WP_Error(
					'missing_title',
					__( 'Incident title is required.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$initial_message = $request->get_param( 'message' );
			$initial_message = is_string( $initial_message ) ? sanitize_text_field( $initial_message ) : '';

			$services_raw = $request->get_param( 'services' );
			$services     = is_array( $services_raw ) ? $services_raw : array();

			$channels_raw = $request->get_param( 'notify_channels' );
			$channels     = is_array( $channels_raw ) ? $channels_raw : array();

			$post_id = wp_insert_post(
				array(
					'post_type'   => WP_MCP_AI_Incident_CPT::POST_TYPE,
					'post_title'  => $title,
					'post_status' => 'publish',
					'meta_input'  => array(
						'_mcp_ai_incident_phase'           => WP_MCP_AI_Incident_CPT::PHASE_DETECTED,
						'_mcp_ai_incident_severity'        => $severity,
						'_mcp_ai_incident_services'        => $services,
						'_mcp_ai_incident_notify_channels' => $channels,
						'_mcp_ai_incident_timeline'        => array(
							array(
								'timestamp'   => time(),
								'phase'       => WP_MCP_AI_Incident_CPT::PHASE_DETECTED,
								'message'     => '' !== $initial_message ? $initial_message : __( 'Incident detected.', 'mcp-ai-wpoos-pro' ),
								'operator_id' => get_current_user_id(),
							),
						),
					),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			/** Fires when a new incident is created. @since 1.4.0 */
			do_action( 'wp_mcp_ai_incident_created', $post_id, $request->get_params() );

			$post = get_post( $post_id );

			return rest_ensure_response( self::format_incident( $post ), 201 );
		}

		/**
		 * Update an incident (phase transition + timeline).
		 *
		 * @since 1.4.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function update_incident( WP_REST_Request $request ) {
			$post_id = (int) $request->get_param( 'id' );
			$post    = get_post( $post_id );

			if ( ! $post || WP_MCP_AI_Incident_CPT::POST_TYPE !== $post->post_type ) {
				return new WP_Error(
					'not_found',
					__( 'Incident not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}

			// Handle title update.
			if ( $request->has_param( 'title' ) ) {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => sanitize_text_field( $request->get_param( 'title' ) ),
					)
				);
			}

			// Handle severity update.
			if ( $request->has_param( 'severity' ) ) {
				update_post_meta( $post_id, '_mcp_ai_incident_severity', sanitize_text_field( $request->get_param( 'severity' ) ) );
			}

			// Handle phase transition.
			$new_phase = $request->get_param( 'phase' );
			$message   = $request->get_param( 'message' );
			$message   = is_string( $message ) ? sanitize_text_field( $message ) : '';

			if ( $request->has_param( 'phase' ) ) {
				$success = WP_MCP_AI_Incident_CPT::transition_phase( $post_id, $new_phase, $message );

				if ( ! $success ) {
					return new WP_Error(
						'invalid_transition',
						__( 'Invalid phase transition.', 'mcp-ai-wpoos-pro' ),
						array( 'status' => 400 )
					);
				}
			} elseif ( '' !== $message ) {
				// Just append a timeline update without phase change.
				$current_phase = get_post_meta( $post_id, '_mcp_ai_incident_phase', true );
				WP_MCP_AI_Incident_CPT::append_timeline_entry( $post_id, $current_phase, $message );
			}

			// Handle services update.
			if ( $request->has_param( 'services' ) ) {
				$services_raw = $request->get_param( 'services' );
				update_post_meta( $post_id, '_mcp_ai_incident_services', is_array( $services_raw ) ? $services_raw : array() );
			}

			$post = get_post( $post_id );

			/** Fires when an incident is updated. @since 1.4.0 */
			do_action( 'wp_mcp_ai_incident_updated', $post_id, $request->get_params() );

			return rest_ensure_response( self::format_incident( $post ) );
		}

		/**
		 * Resolve an incident.
		 *
		 * @since 1.4.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function resolve_incident( WP_REST_Request $request ) {
			$post_id = (int) $request->get_param( 'id' );
			$post    = get_post( $post_id );

			if ( ! $post || WP_MCP_AI_Incident_CPT::POST_TYPE !== $post->post_type ) {
				return new WP_Error(
					'not_found',
					__( 'Incident not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}

			$message = $request->get_param( 'message' );
			$message = is_string( $message ) && '' !== $message
				? sanitize_text_field( $message )
				: __( 'This incident has been resolved.', 'mcp-ai-wpoos-pro' );

			$success = WP_MCP_AI_Incident_CPT::transition_phase(
				$post_id,
				WP_MCP_AI_Incident_CPT::PHASE_RESOLVED,
				$message
			);

			if ( ! $success ) {
				return new WP_Error(
					'invalid_transition',
					__( 'Cannot resolve this incident in its current state.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$post = get_post( $post_id );

			return rest_ensure_response( self::format_incident( $post ) );
		}

		/**
		 * Create args schema.
		 *
		 * @since 1.4.0
		 *
		 * @return array
		 */
		private static function get_create_args(): array {
			return array(
				'title'           => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'severity'        => array(
					'type'              => 'string',
					'default'           => 'minor',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'services'        => array(
					'type'    => 'array',
					'items'   => array( 'type' => 'string' ),
					'default' => array(),
				),
				'notify_channels' => array(
					'type'    => 'array',
					'items'   => array( 'type' => 'string' ),
					'default' => array(),
				),
			);
		}

		/**
		 * Update args schema.
		 *
		 * @since 1.4.0
		 *
		 * @return array
		 */
		private static function get_update_args(): array {
			return array(
				'id'       => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'title'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'phase'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'severity' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'message'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'services' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			);
		}

		/**
		 * Format incident for public response (allowlisted fields).
		 *
		 * @since 1.4.0
		 *
		 * @param WP_Post $post Post object.
		 * @return array
		 */
		private static function format_public_incident( WP_Post $post ): array {
			$services_raw = get_post_meta( $post->ID, '_mcp_ai_incident_services', true );
			$tl_raw       = get_post_meta( $post->ID, '_mcp_ai_incident_timeline', true );

			return array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'phase'    => get_post_meta( $post->ID, '_mcp_ai_incident_phase', true ),
				'severity' => get_post_meta( $post->ID, '_mcp_ai_incident_severity', true ),
				'services' => is_array( $services_raw ) ? $services_raw : array(),
				'timeline' => is_array( $tl_raw ) ? $tl_raw : array(),
				'created'  => $post->post_date_gmt,
			);
		}

		/**
		 * Format incident for admin response (full fields).
		 *
		 * @since 1.4.0
		 *
		 * @param WP_Post $post Post object.
		 * @return array
		 */
		private static function format_incident( WP_Post $post ): array {
			$services_raw = get_post_meta( $post->ID, '_mcp_ai_incident_services', true );
			$channels_raw = get_post_meta( $post->ID, '_mcp_ai_incident_notify_channels', true );
			$tl_raw       = get_post_meta( $post->ID, '_mcp_ai_incident_timeline', true );

			return array(
				'id'              => $post->ID,
				'title'           => $post->post_title,
				'phase'           => get_post_meta( $post->ID, '_mcp_ai_incident_phase', true ),
				'phase_label'     => WP_MCP_AI_Incident_CPT::get_phase_label(
					get_post_meta( $post->ID, '_mcp_ai_incident_phase', true )
				),
				'severity'        => get_post_meta( $post->ID, '_mcp_ai_incident_severity', true ),
				'services'        => is_array( $services_raw ) ? $services_raw : array(),
				'notify_channels' => is_array( $channels_raw ) ? $channels_raw : array(),
				'timeline'        => is_array( $tl_raw ) ? $tl_raw : array(),
				'resolved_at'     => get_post_meta( $post->ID, '_mcp_ai_incident_resolved_at', true ),
				'lesson_id'       => (int) get_post_meta( $post->ID, '_mcp_ai_incident_lesson_id', true ),
				'created_at'      => $post->post_date_gmt,
				'updated_at'      => $post->post_modified_gmt,
			);
		}
	}

	// Bootstrap.
	add_action( 'rest_api_init', array( 'WP_MCP_AI_Incident_REST_Controller', 'register_routes' ) );
}

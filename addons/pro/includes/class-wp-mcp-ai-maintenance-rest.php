<?php
/**
 * Maintenance REST Controller
 *
 * Provides REST endpoints for managing maintenance windows. Write operations
 * require manage_options capability. A public read-only endpoint surfaces
 * upcoming and active maintenance windows for the status page.
 *
 * Routes:
 * - GET    /mcp-ai/v1/status/maintenance          — Public: upcoming + active
 * - GET    /mcp-ai-pro/v1/maintenance              — Admin: list all windows
 * - POST   /mcp-ai-pro/v1/maintenance              — Admin: create window
 * - PUT    /mcp-ai-pro/v1/maintenance/{id}         — Admin: update window
 * - DELETE /mcp-ai-pro/v1/maintenance/{id}         — Admin: cancel window
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Maintenance_REST_Controller' ) ) {
	/**
	 * Maintenance REST controller.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Maintenance_REST_Controller {

		/**
		 * Register the maintenance REST routes.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function register_routes(): void {
			// Public: upcoming + active windows for the status page.
			register_rest_route(
				'mcp-ai/v1',
				'/status/maintenance',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_public_windows' ),
					'permission_callback' => '__return_true',
				)
			);

			// Admin: list all windows.
			register_rest_route(
				'mcp-ai-pro/v1',
				'/maintenance',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_windows' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => array(
						'status'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);

			// Admin: create window.
			register_rest_route(
				'mcp-ai-pro/v1',
				'/maintenance',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_window' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::get_create_args(),
				)
			);

			// Admin: update window.
			register_rest_route(
				'mcp-ai-pro/v1',
				'/maintenance/(?P<id>\d+)',
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_window' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
					'args'                => self::get_update_args(),
				)
			);

			// Admin: cancel window.
			register_rest_route(
				'mcp-ai-pro/v1',
				'/maintenance/(?P<id>\d+)',
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'cancel_window' ),
					'permission_callback' => array( __CLASS__, 'check_admin_permission' ),
				)
			);
		}

		/**
		 * Permission check for admin routes.
		 *
		 * @since 1.3.0
		 *
		 * @return bool
		 */
		public static function check_admin_permission(): bool {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Get upcoming and active maintenance windows for public consumption.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response
		 */
		public static function get_public_windows( WP_REST_Request $request ): WP_REST_Response {
			unset( $request );

			$windows = array_merge(
				WP_MCP_AI_Maintenance_CPT::get_upcoming_windows( 5 ),
				array_filter( array( WP_MCP_AI_Maintenance_CPT::get_active_window() ) )
			);

			$data = array();
			foreach ( $windows as $window ) {
				$data[] = self::format_public_window( $window );
			}

			header( 'Cache-Control: public, max-age=60, s-maxage=60' );

			return rest_ensure_response(
				array(
					'windows' => $data,
					'total'   => count( $data ),
				)
			);
		}

		/**
		 * List all maintenance windows (admin).
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response
		 */
		public static function get_windows( WP_REST_Request $request ): WP_REST_Response {
			$status   = $request->get_param( 'status' );
			$per_page = $request->get_param( 'per_page' );

			$args = array(
				'post_type'      => WP_MCP_AI_Maintenance_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => $per_page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			);

			if ( '' !== $status ) {
				$args['meta_key']   = '_mcp_ai_maintenance_status';
				$args['meta_value'] = $status;
			}

			$query   = new WP_Query( $args );
			$windows = array();

			foreach ( $query->posts as $post ) {
				$windows[] = self::format_window( $post );
			}

			return rest_ensure_response(
				array(
					'windows'    => $windows,
					'total'      => $query->found_posts,
					'pagination' => array(
						'page'     => 1,
						'per_page' => $per_page,
						'total'    => $query->found_posts,
					),
				)
			);
		}

		/**
		 * Create a new maintenance window.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function create_window( WP_REST_Request $request ) {
			$title   = sanitize_text_field( $request->get_param( 'title' ) );
			$content = wp_kses_post( $request->get_param( 'content' ) ? $request->get_param( 'content' ) : '' );
			$start   = sanitize_text_field( $request->get_param( 'start' ) );
			$end     = sanitize_text_field( $request->get_param( 'end' ) );

			if ( empty( $title ) || empty( $start ) || empty( $end ) ) {
				return new WP_Error(
					'missing_fields',
					__( 'Title, start, and end are required.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			// Validate ISO 8601 dates.
			$start_ts = strtotime( $start );
			$end_ts   = strtotime( $end );

			if ( false === $start_ts || false === $end_ts ) {
				return new WP_Error(
					'invalid_date',
					__( 'Start and end must be valid ISO 8601 dates.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			if ( $end_ts <= $start_ts ) {
				return new WP_Error(
					'invalid_range',
					__( 'End time must be after start time.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => WP_MCP_AI_Maintenance_CPT::POST_TYPE,
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
					'meta_input'   => array(
						'_mcp_ai_maintenance_status'   => WP_MCP_AI_Maintenance_CPT::STATUS_SCHEDULED,
						'_mcp_ai_maintenance_start'    => $start,
						'_mcp_ai_maintenance_end'      => $end,
						'_mcp_ai_maintenance_services' => $request->get_param( 'services' ) ? $request->get_param( 'services' ) : array(),
						'_mcp_ai_maintenance_notify_channels' => $request->get_param( 'notify_channels' ) ? $request->get_param( 'notify_channels' ) : array(),
						'_mcp_ai_maintenance_notify_before' => absint( $request->get_param( 'notify_before' ) ? $request->get_param( 'notify_before' ) : 60 ),
						'_mcp_ai_maintenance_banner_enabled' => $request->get_param( 'banner_enabled' ) ?? true,
						'_mcp_ai_maintenance_reminder_sent' => false,
					),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			/** Fires when a new maintenance window is created. @since 1.3.0 */
			do_action( 'wp_mcp_ai_maintenance_scheduled', $post_id, $request->get_params() );

			$post = get_post( $post_id );

			return rest_ensure_response( self::format_window( $post ), 201 );
		}

		/**
		 * Update an existing maintenance window.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function update_window( WP_REST_Request $request ) {
			$post_id = (int) $request->get_param( 'id' );
			$post    = get_post( $post_id );

			if ( ! $post || WP_MCP_AI_Maintenance_CPT::POST_TYPE !== $post->post_type ) {
				return new WP_Error(
					'not_found',
					__( 'Maintenance window not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}

			$update = array(
				'ID' => $post_id,
			);

			if ( $request->has_param( 'title' ) ) {
				$update['post_title'] = sanitize_text_field( $request->get_param( 'title' ) );
			}

			if ( $request->has_param( 'content' ) ) {
				$update['post_content'] = wp_kses_post( $request->get_param( 'content' ) );
			}

			wp_update_post( $update, true );

			// Update meta fields.
			$meta_fields = array(
				'_mcp_ai_maintenance_start',
				'_mcp_ai_maintenance_end',
				'_mcp_ai_maintenance_notify_before',
			);

			foreach ( $meta_fields as $field ) {
				$param = str_replace( '_mcp_ai_maintenance_', '', $field );
				if ( $request->has_param( $param ) ) {
					update_post_meta( $post_id, $field, sanitize_text_field( $request->get_param( $param ) ) );
				}
			}

			// Array fields.
			if ( $request->has_param( 'services' ) ) {
				update_post_meta( $post_id, '_mcp_ai_maintenance_services', $request->get_param( 'services' ) );
			}

			if ( $request->has_param( 'notify_channels' ) ) {
				update_post_meta( $post_id, '_mcp_ai_maintenance_notify_channels', $request->get_param( 'notify_channels' ) );
			}

			if ( $request->has_param( 'banner_enabled' ) ) {
				update_post_meta( $post_id, '_mcp_ai_maintenance_banner_enabled', rest_sanitize_boolean( $request->get_param( 'banner_enabled' ) ) );
			}

			// Handle status transition.
			if ( $request->has_param( 'status' ) ) {
				$new_status = sanitize_text_field( $request->get_param( 'status' ) );
				WP_MCP_AI_Maintenance_CPT::transition_status( $post_id, $new_status );
			}

			$post = get_post( $post_id );

			return rest_ensure_response( self::format_window( $post ) );
		}

		/**
		 * Cancel a maintenance window.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request Request object.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function cancel_window( WP_REST_Request $request ) {
			$post_id = (int) $request->get_param( 'id' );
			$post    = get_post( $post_id );

			if ( ! $post || WP_MCP_AI_Maintenance_CPT::POST_TYPE !== $post->post_type ) {
				return new WP_Error(
					'not_found',
					__( 'Maintenance window not found.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 404 )
				);
			}

			$success = WP_MCP_AI_Maintenance_CPT::transition_status( $post_id, WP_MCP_AI_Maintenance_CPT::STATUS_CANCELLED );

			if ( ! $success ) {
				return new WP_Error(
					'invalid_transition',
					__( 'Cannot cancel this maintenance window in its current state.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			return rest_ensure_response(
				array(
					'id'      => $post_id,
					'status'  => WP_MCP_AI_Maintenance_CPT::STATUS_CANCELLED,
					'message' => __( 'Maintenance window cancelled.', 'mcp-ai-wpoos-pro' ),
				)
			);
		}

		/**
		 * Get REST args schema for create endpoint.
		 *
		 * @since 1.3.0
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
				'content'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
				'start'           => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'end'             => array(
					'required'          => true,
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
				'notify_before'   => array(
					'type'              => 'integer',
					'default'           => 60,
					'sanitize_callback' => 'absint',
				),
				'banner_enabled'  => array(
					'type'              => 'boolean',
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			);
		}

		/**
		 * Get REST args schema for update endpoint.
		 *
		 * @since 1.3.0
		 *
		 * @return array
		 */
		private static function get_update_args(): array {
			return array(
				'id'              => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'title'           => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'content'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
				'start'           => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'end'             => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'status'          => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'services'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'notify_channels' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'notify_before'   => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'banner_enabled'  => array(
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			);
		}

		/**
		 * Format a maintenance window post for REST response (public).
		 *
		 * @since 1.3.0
		 *
		 * @param WP_Post $post Post object.
		 * @return array
		 */
		private static function format_public_window( WP_Post $post ): array {
			$raw_services = get_post_meta( $post->ID, '_mcp_ai_maintenance_services', true );
			return array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'content'  => wp_kses_post( $post->post_content ),
				'status'   => get_post_meta( $post->ID, '_mcp_ai_maintenance_status', true ),
				'start'    => get_post_meta( $post->ID, '_mcp_ai_maintenance_start', true ),
				'end'      => get_post_meta( $post->ID, '_mcp_ai_maintenance_end', true ),
				'services' => is_array( $raw_services ) ? $raw_services : array(),
			);
		}

		/**
		 * Format a maintenance window post for REST response (admin).
		 *
		 * @since 1.3.0
		 *
		 * @param WP_Post $post Post object.
		 * @return array
		 */
		private static function format_window( WP_Post $post ): array {
			$services_raw = get_post_meta( $post->ID, '_mcp_ai_maintenance_services', true );
			$channels_raw = get_post_meta( $post->ID, '_mcp_ai_maintenance_notify_channels', true );
			return array(
				'id'              => $post->ID,
				'title'           => $post->post_title,
				'content'         => $post->post_content,
				'status'          => get_post_meta( $post->ID, '_mcp_ai_maintenance_status', true ),
				'start'           => get_post_meta( $post->ID, '_mcp_ai_maintenance_start', true ),
				'end'             => get_post_meta( $post->ID, '_mcp_ai_maintenance_end', true ),
				'services'        => is_array( $services_raw ) ? $services_raw : array(),
				'notify_channels' => is_array( $channels_raw ) ? $channels_raw : array(),
				'notify_before'   => (int) get_post_meta( $post->ID, '_mcp_ai_maintenance_notify_before', true ),
				'banner_enabled'  => (bool) get_post_meta( $post->ID, '_mcp_ai_maintenance_banner_enabled', true ),
				'reminder_sent'   => (bool) get_post_meta( $post->ID, '_mcp_ai_maintenance_reminder_sent', true ),
				'created_at'      => $post->post_date_gmt,
				'updated_at'      => $post->post_modified_gmt,
			);
		}
	}

	// Bootstrap.
	add_action( 'rest_api_init', array( 'WP_MCP_AI_Maintenance_REST_Controller', 'register_routes' ) );
}

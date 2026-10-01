<?php
/**
 * Outbound Appointment Booking — REST routes.
 *
 * Public booking submission (rate-limited, honeypotted, consent-gated),
 * token-authenticated reply ingestion for automation platforms, and an
 * admin pipeline stats endpoint.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_REST {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	const NS = 'nvoos-outbound/v1';

	/**
	 * Register routes.
	 *
	 * @since 2.12.0
	 */
	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/bookings',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_booking' ),
					// Public booking surface: protected by honeypot, per-IP
					// rate limiting, and a mandatory consent flag rather than
					// a capability. No stored secrets or admin data is exposed.
					'permission_callback' => '__return_true',
					'args'                => array(
						'booking_link_id' => array( 'type' => 'integer', 'required' => true ),
						'name'            => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'email'           => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
						'company'         => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'slot'            => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
						'consent'         => array( 'type' => 'boolean', 'required' => true ),
						'lead_id'         => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
						'lead_token'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
						'message'         => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
						'website'         => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ), // Honeypot.
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/replies',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'ingest_reply' ),
					'permission_callback' => array( __CLASS__, 'check_token' ),
					'args'                => array(
						'email'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
						'channel' => array( 'type' => 'string', 'default' => 'email', 'sanitize_callback' => 'sanitize_key' ),
						'body'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pipeline',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_pipeline' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
			)
		);
	}

	/**
	 * Create a booking via the public endpoint.
	 *
	 * @since 2.12.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_booking( WP_REST_Request $request ) {
		// Honeypot: bots fill the hidden field.
		if ( ! empty( $request['website'] ) ) {
			return new WP_Error( 'spam', __( 'Invalid request.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}
		if ( empty( $request['consent'] ) ) {
			return new WP_Error( 'consent_required', __( 'Consent is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}
		if ( ! self::check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many booking attempts. Please try again later.', 'mcp-ai-wpoos-pro' ), array( 'status' => 429 ) );
		}

		$lead_id = absint( $request['lead_id'] );
		if ( $lead_id && ! empty( $request['lead_token'] ) ) {
			if ( ! WP_MCP_AI_OA_Booking_Link_CPT::verify_lead_token( $lead_id, absint( $request['booking_link_id'] ), $request['lead_token'] ) ) {
				$lead_id = 0; // Bad attribution token — treat as anonymous.
			}
		}

		$result = WP_MCP_AI_OA_Booking::create_booking(
			absint( $request['booking_link_id'] ),
			$request['name'],
			$request['email'],
			$request['company'],
			$request['slot'],
			$lead_id,
			$request['message']
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Ingest a reply via the token-authenticated endpoint.
	 *
	 * @since 2.12.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function ingest_reply( WP_REST_Request $request ) {
		$leads = get_posts(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded reply-matching lookup.
				'meta_value'     => $request['email'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded reply-matching lookup.
			)
		);
		if ( empty( $leads ) ) {
			return new WP_Error( 'unknown_sender', __( 'No lead matches this email.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$result = WP_MCP_AI_OA_Engine::ingest_reply( (int) $leads[0], $request['channel'], $request['body'] );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'ingest_failed', __( 'Reply could not be processed.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $result );
	}

	/**
	 * Pipeline stats for admins.
	 *
	 * @since 2.12.0
	 * @return WP_REST_Response
	 */
	public static function get_pipeline() {
		return rest_ensure_response( WP_MCP_AI_OA_Engine::get_pipeline_stats() );
	}

	/**
	 * Token check for reply ingestion.
	 *
	 * @since 2.12.0
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function check_token( WP_REST_Request $request ) {
		$expected = WP_MCP_AI_OA_Settings::get_setting( 'reply_token', '' );
		if ( ! $expected ) {
			return new WP_Error( 'not_configured', __( 'Reply ingestion is not configured.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}
		$provided = $request->get_header( 'x_oa_token' );
		if ( ! $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'forbidden', __( 'Invalid token.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Capability check for the stats endpoint.
	 *
	 * @since 2.12.0
	 * @return bool|WP_Error
	 */
	public static function can_view() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Per-IP rate limit for booking submissions (5 per 15 minutes).
	 *
	 * @since 2.12.0
	 * @return bool True when allowed.
	 */
	private static function check_rate_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'oa_booking_rate_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 5 ) {
			return false;
		}
		set_transient( $key, $hits + 1, 15 * MINUTE_IN_SECONDS );
		return true;
	}
}

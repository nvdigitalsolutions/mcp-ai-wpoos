<?php
/**
 * JetBooking Adapter
 *
 * Bridges NV oOS Calendar Booking tools to Crocoblock's JetBooking plugin
 * via its REST API and direct database queries (for unit-level availability).
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Adapters
 * @since     1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_JetBooking_Adapter
 *
 * @since 1.5.0
 */
class WP_MCP_AI_JetBooking_Adapter implements WP_MCP_AI_Booking_Adapter_Interface {

	/**
	 * Adapter slug.
	 *
	 * @var string
	 */
	const SLUG = 'jetbooking';

	/**
	 * Transient key prefix for caching.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_jb_cache_';

	/**
	 * Cache TTL in seconds (5 minutes).
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		global $wpdb;

		// 1. JetBooking plugin class must exist.
		if ( ! class_exists( 'Jet_Booking' ) ) {
			return false;
		}

		// 2. Core booking tables must exist.
		$bookings_table = $wpdb->prefix . 'jet_apartment_bookings';
		$exists         = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $exists ) {
			return false;
		}

		// 3. JetBooking must be configured.
		$jb_settings = get_option( 'jet_booking_settings' );
		if ( empty( $jb_settings ) ) {
			return false;
		}

		// 4. Integration must not be explicitly disabled in NV oOS settings.
		$nv_settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( isset( $nv_settings['enable_jetbooking_integration'] )
			&& empty( $nv_settings['enable_jetbooking_integration'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'Jet_Booking' ) ) {
			return __( 'JetBooking plugin is not active.', 'mcp-ai-wpoos-pro' );
		}

		global $wpdb;
		$bookings_table = $wpdb->prefix . 'jet_apartment_bookings';
		$exists         = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $exists ) {
			return __( 'JetBooking database tables not found. Ensure the JetBooking plugin is installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		$jb_settings = get_option( 'jet_booking_settings' );
		if ( empty( $jb_settings ) ) {
			return __( 'JetBooking is not configured. Set up booking instances and orders CPT.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'JetBooking integration is disabled in NV oOS settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return self::SLUG;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return __( 'JetBooking', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $filters Filter criteria.
	 * @param int   $limit   Max results.
	 * @param int   $offset  Pagination offset.
	 */
	public function get_bookings( array $filters = array(), $limit = 50, $offset = 0 ) {
		$query_args = array(
			'per_page' => min( absint( $limit ), 100 ),
			'page'     => max( 1, floor( absint( $offset ) / max( 1, absint( $limit ) ) ) + 1 ),
		);

		if ( ! empty( $filters['date_from'] ) ) {
			$query_args['date_from'] = sanitize_text_field( $filters['date_from'] );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$query_args['date_to'] = sanitize_text_field( $filters['date_to'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$query_args['status'] = sanitize_key( $filters['status'] );
		}

		$response = $this->api_request( 'bookings', 'GET', $query_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = array();
		$raw   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		foreach ( $raw as $item ) {
			$items[] = $this->map_booking_to_canonical( $item );
		}

		return array(
			'success' => true,
			'items'   => $items,
			'total'   => isset( $response['total'] ) ? absint( $response['total'] ) : count( $items ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int|string $booking_id External booking ID.
	 */
	public function get_booking( $booking_id ) {
		$response = $this->api_request( 'bookings/' . absint( $booking_id ), 'GET' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = isset( $response['data'] ) ? $response['data'] : $response;

		return array(
			'success' => true,
			'booking' => $this->map_booking_to_canonical( $data ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $data Booking data.
	 */
	public function create_booking( array $data ) {
		$payload = array(
			'apartment_id'   => absint( $data['apartment_id'] ?? $data['instance_id'] ?? 0 ),
			'unit_id'        => absint( $data['unit_id'] ?? 0 ),
			'check_in_date'  => sanitize_text_field( $data['check_in_date'] ?? $data['check_in'] ?? '' ),
			'check_out_date' => sanitize_text_field( $data['check_out_date'] ?? $data['check_out'] ?? '' ),
			'status'         => sanitize_key( $data['status'] ?? 'on-hold' ),
			'email'          => sanitize_email( $data['email'] ?? $data['user_email'] ?? '' ),
		);

		if ( ! empty( $data['guests'] ) ) {
			$payload['guests'] = absint( $data['guests'] );
		}

		$response = $this->api_request( 'bookings', 'POST', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$created = isset( $response['data'] ) ? $response['data'] : $response;

		return array(
			'success'    => true,
			'booking_id' => isset( $created['id'] ) ? absint( $created['id'] ) : 0,
			'booking'    => $this->map_booking_to_canonical( $created ),
			'message'    => __( 'Booking created successfully in JetBooking.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int|string $booking_id External booking ID.
	 * @param array      $data       Fields to update.
	 */
	public function update_booking( $booking_id, array $data ) {
		$response = $this->api_request( 'bookings/' . absint( $booking_id ), 'PUT', $data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$updated = isset( $response['data'] ) ? $response['data'] : $response;

		return array(
			'success' => true,
			'booking' => $this->map_booking_to_canonical( $updated ),
			'message' => __( 'Booking updated in JetBooking.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int|string $booking_id External booking ID.
	 * @param string     $reason     Cancellation reason.
	 */
	public function cancel_booking( $booking_id, $reason = '' ) {
		$response = $this->api_request( 'bookings/' . absint( $booking_id ), 'DELETE' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'message' => __( 'Booking cancelled in JetBooking.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $start_time Start datetime.
	 * @param string $end_time   End datetime.
	 * @param array  $context    Optional context.
	 */
	public function check_availability( $start_time, $end_time, array $context = array() ) {
		$instance_id = isset( $context['instance_id'] ) ? absint( $context['instance_id'] ) : 0;

		if ( ! $instance_id ) {
			return array(
				'success'   => true,
				'available' => true,
				'conflicts' => array(),
				'reasons'   => array(),
				'message'   => __( 'No instance specified; skipping JetBooking availability check.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$check_in  = gmdate( 'Y-m-d', strtotime( $start_time ) );
		$check_out = gmdate( 'Y-m-d', strtotime( $end_time ) );

		$availability = $this->get_unit_availability( $instance_id, $check_in, $check_out );

		if ( is_wp_error( $availability ) ) {
			return $availability;
		}

		$available = ! empty( $availability['available_units'] );
		$reasons   = array();

		if ( ! $available ) {
			$reasons[] = __( 'No units available for the requested date range in JetBooking.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'   => true,
			'available' => $available,
			'conflicts' => $availability['unavailable_dates'] ?? array(),
			'reasons'   => $reasons,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $date             Date to query.
	 * @param int    $duration_minutes Slot duration.
	 * @param array  $context          Optional context.
	 */
	public function get_available_slots( $date, $duration_minutes = 60, array $context = array() ) {
		// JetBooking is daily-booking based, not slot-based.
		// Return one "slot" per available unit for the day.
		$instance_id = isset( $context['instance_id'] ) ? absint( $context['instance_id'] ) : 0;

		if ( ! $instance_id ) {
			return array(
				'success' => true,
				'date'    => $date,
				'slots'   => array(),
				'total'   => 0,
				'message' => __( 'No instance specified for JetBooking slot query.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$availability = $this->get_unit_availability( $instance_id, $date, $date );

		if ( is_wp_error( $availability ) ) {
			return $availability;
		}

		$slots = array();
		foreach ( $availability['available_units'] as $unit ) {
			$slots[] = array(
				'start_time' => $date . ' 00:00:00',
				'end_time'   => $date . ' 23:59:59',
				'available'  => true,
				'source'     => 'jetbooking',
				'unit_id'    => $unit['unit_id'],
				'unit_title' => $unit['title'],
			);
		}

		return array(
			'success' => true,
			'date'    => $date,
			'slots'   => $slots,
			'total'   => count( $slots ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $filters Optional filters.
	 */
	public function get_providers( array $filters = array() ) {
		// JetBooking doesn't have providers; return booking instances instead.
		return $this->get_booking_instances( $filters );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $filters Optional filters.
	 */
	public function get_services( array $filters = array() ) {
		// JetBooking doesn't have services in the JetAppointment sense.
		// Return empty with a helpful message.
		return array(
			'success'  => true,
			'services' => array(),
			'total'    => 0,
			'message'  => __( 'JetBooking uses booking instances and units rather than services. Use get_jetbooking_instances and get_jetbooking_units tools to explore available items.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function health_check() {
		$checks = array();

		// Check 1: Jet_Booking class exists.
		$checks['class_exists'] = class_exists( 'Jet_Booking' );

		// Check 2: Database tables exist.
		global $wpdb;
		$bookings_table           = $wpdb->prefix . 'jet_apartment_bookings';
		$units_table              = $wpdb->prefix . 'jet_apartment_units';
		$checks['bookings_table'] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$checks['units_table']    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $units_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Check 3: REST API reachable.
		$api_response     = $this->api_request( 'bookings', 'GET', array( 'per_page' => 1 ) );
		$checks['api_ok'] = ! is_wp_error( $api_response );

		// Check 4: Configuration present.
		$jb_settings             = get_option( 'jet_booking_settings', array() );
		$checks['is_configured'] = ! empty( $jb_settings );

		$all_healthy = $checks['class_exists'] && $checks['bookings_table'] && $checks['api_ok'] && $checks['is_configured'];

		$message = $all_healthy
			? __( 'JetBooking adapter is healthy.', 'mcp-ai-wpoos-pro' )
			: __( 'JetBooking adapter has issues.', 'mcp-ai-wpoos-pro' );

		if ( ! $checks['api_ok'] && is_wp_error( $api_response ) ) {
			$message .= ' ' . $api_response->get_error_message();
		}

		return array(
			'success' => true,
			'healthy' => $all_healthy,
			'checks'  => $checks,
			'message' => $message,
		);
	}

	// -------------------------------------------------------------------------
	// JetBooking-Specific Public Methods
	// -------------------------------------------------------------------------

	/**
	 * Get booking instances (the CPT posts configured as JetBooking instances).
	 *
	 * @since 1.5.0
	 * @param array $filters Optional filters.
	 * @return array{success:bool,instances:array,total:int}|WP_Error
	 */
	public function get_booking_instances( array $filters = array() ) {
		$jb_settings = get_option( 'jet_booking_settings', array() );
		$cpt_slug    = isset( $jb_settings['booking_instance_post_type'] ) ? sanitize_key( $jb_settings['booking_instance_post_type'] ) : '';

		if ( empty( $cpt_slug ) ) {
			return new WP_Error(
				'no_instance_cpt',
				__( 'No booking instance post type configured in JetBooking settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		$cache_key = self::CACHE_PREFIX . 'instances_' . md5( wp_json_encode( $filters ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$query_args = array(
			'post_type'      => $cpt_slug,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( ! empty( $filters['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $filters['search'] );
		}

		$query     = new WP_Query( $query_args );
		$instances = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$instance_id = get_the_ID();

				$instances[] = array(
					'id'          => $instance_id,
					'title'       => get_the_title(),
					'description' => wp_strip_all_tags( get_the_content() ),
					'post_type'   => $cpt_slug,
					'source'      => 'jetbooking',
					'unit_count'  => $this->count_units_for_instance( $instance_id ),
				);
			}
			wp_reset_postdata();
		}

		$result = array(
			'success'   => true,
			'instances' => $instances,
			'total'     => count( $instances ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Get units for a specific booking instance.
	 *
	 * @since 1.5.0
	 * @param int $instance_id Booking instance post ID.
	 * @return array{success:bool,units:array,total:int}|WP_Error
	 */
	public function get_units( $instance_id ) {
		global $wpdb;
		$units_table = $wpdb->prefix . 'jet_apartment_units';

		$units = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT unit_id, title FROM {$units_table} WHERE post_id = %d ORDER BY title ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe; built from wpdb->prefix.
				absint( $instance_id )
			)
		);

		$mapped = array();
		foreach ( $units as $unit ) {
			$mapped[] = array(
				'unit_id'     => absint( $unit->unit_id ),
				'title'       => sanitize_text_field( $unit->title ),
				'instance_id' => absint( $instance_id ),
			);
		}

		return array(
			'success' => true,
			'units'   => $mapped,
			'total'   => count( $mapped ),
		);
	}

	/**
	 * Get unit availability for a date range.
	 *
	 * JetBooking stores booked dates in wp_jet_apartment_units_dates.
	 * A unit is available for a date if no row exists with status
	 * 'confirmed' or 'on-hold'.
	 *
	 * @since 1.5.0
	 * @param int    $instance_id Booking instance post ID.
	 * @param string $check_in    Check-in date (Y-m-d).
	 * @param string $check_out   Check-out date (Y-m-d).
	 * @return array{success:bool,available_units:array,unavailable_dates:array}|WP_Error
	 */
	public function get_unit_availability( $instance_id, $check_in, $check_out ) {
		global $wpdb;

		$units_table = $wpdb->prefix . 'jet_apartment_units';
		$dates_table = $wpdb->prefix . 'jet_apartment_units_dates';

		// Get all units for this instance.
		$units = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT unit_id, title FROM {$units_table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $instance_id )
			)
		);

		if ( empty( $units ) ) {
			return array(
				'success'           => true,
				'available_units'   => array(),
				'unavailable_dates' => array(),
				'total_units'       => 0,
				'available_count'   => 0,
			);
		}

		$unit_ids = wp_list_pluck( $units, 'unit_id' );
		$unit_ids = array_map( 'absint', $unit_ids );

		// Build prepared placeholders.
		$placeholders = implode( ',', array_fill( 0, count( $unit_ids ), '%d' ) );

		// Get booked dates in range for all units.
		$prepare_args = array_merge( $unit_ids, array( $check_in, $check_out ) );
		$booked       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT unit_id, date FROM {$dates_table} WHERE unit_id IN ({$placeholders}) AND date >= %s AND date <= %s AND status IN ('confirmed', 'on-hold')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$prepare_args
			)
		);

		// Build availability map.
		$booked_map = array();
		foreach ( $booked as $row ) {
			$booked_map[ absint( $row->unit_id ) ][] = $row->date;
		}

		$available_units   = array();
		$unavailable_dates = array();

		foreach ( $units as $unit ) {
			$uid          = absint( $unit->unit_id );
			$booked_dates = isset( $booked_map[ $uid ] ) ? $booked_map[ $uid ] : array();

			if ( empty( $booked_dates ) ) {
				$available_units[] = array(
					'unit_id' => $uid,
					'title'   => sanitize_text_field( $unit->title ),
				);
			} else {
				$unavailable_dates[] = array(
					'unit_id'      => $uid,
					'title'        => sanitize_text_field( $unit->title ),
					'booked_dates' => $booked_dates,
				);
			}
		}

		return array(
			'success'           => true,
			'available_units'   => $available_units,
			'unavailable_dates' => $unavailable_dates,
			'total_units'       => count( $units ),
			'available_count'   => count( $available_units ),
		);
	}

	// -------------------------------------------------------------------------
	// Private Helpers
	// -------------------------------------------------------------------------

	/**
	 * Make an authenticated request to the JetBooking REST API.
	 *
	 * @since 1.5.0
	 * @param string $endpoint e.g. 'bookings', 'bookings/123'.
	 * @param string $method   HTTP method.
	 * @param array  $params   Query params or body.
	 * @return array|WP_Error
	 */
	private function api_request( $endpoint, $method = 'GET', array $params = array() ) {
		$url = rest_url( 'jet-booking/v2/' . $endpoint );

		$credentials = $this->get_api_credentials();
		if ( empty( $credentials ) ) {
			return new WP_Error(
				'jb_no_credentials',
				__( 'JetBooking API credentials are not configured. Add credentials in NV oOS → Settings → Calendar Booking.', 'mcp-ai-wpoos-pro' )
			);
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $credentials ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( 'GET' === strtoupper( $method ) && ! empty( $params ) ) {
			$url = add_query_arg( array_map( 'strval', $params ), $url );
		} elseif ( ! empty( $params ) ) {
			$args['body'] = wp_json_encode( $params );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			return new WP_Error(
				'jb_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: API error message */
					__( 'JetBooking API returned %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
					$code,
					isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : __( 'Unknown error', 'mcp-ai-wpoos-pro' )
				)
			);
		}

		return $data;
	}

	/**
	 * Get API credentials from Password Vault or legacy option.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	private function get_api_credentials() {
		if ( function_exists( 'wp_mcp_ai_get_secret' ) ) {
			$secret = wp_mcp_ai_get_secret( 'jetbooking_api' );
			if ( ! empty( $secret ) ) {
				return $secret;
			}
		}

		$legacy = get_option( 'wp_mcp_ai_jetbooking_api_credentials', '' );
		return is_string( $legacy ) ? $legacy : '';
	}

	/**
	 * Map a raw JetBooking record to the canonical booking envelope.
	 *
	 * @since 1.5.0
	 * @param array $raw Raw booking data.
	 * @return array
	 */
	private function map_booking_to_canonical( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'id'             => isset( $raw['id'] ) ? absint( $raw['id'] ) : ( isset( $raw['ID'] ) ? absint( $raw['ID'] ) : 0 ),
			'instance_id'    => isset( $raw['apartment_id'] ) ? absint( $raw['apartment_id'] ) : ( isset( $raw['booking_item'] ) ? absint( $raw['booking_item'] ) : 0 ),
			'unit_id'        => isset( $raw['unit_id'] ) ? absint( $raw['unit_id'] ) : 0,
			'unit_title'     => isset( $raw['unit_title'] ) ? sanitize_text_field( $raw['unit_title'] ) : '',
			'check_in_date'  => isset( $raw['check_in_date'] ) ? sanitize_text_field( $raw['check_in_date'] ) : ( isset( $raw['check_in'] ) ? sanitize_text_field( $raw['check_in'] ) : '' ),
			'check_out_date' => isset( $raw['check_out_date'] ) ? sanitize_text_field( $raw['check_out_date'] ) : ( isset( $raw['check_out'] ) ? sanitize_text_field( $raw['check_out'] ) : '' ),
			'status'         => isset( $raw['status'] ) ? sanitize_key( $raw['status'] ) : 'on-hold',
			'guest_count'    => isset( $raw['guests'] ) ? absint( $raw['guests'] ) : 0,
			'user_email'     => isset( $raw['email'] ) ? sanitize_email( $raw['email'] ) : ( isset( $raw['user_email'] ) ? sanitize_email( $raw['user_email'] ) : '' ),
			'order_id'       => isset( $raw['order_id'] ) ? absint( $raw['order_id'] ) : 0,
			'price'          => isset( $raw['price'] ) ? floatval( $raw['price'] ) : 0,
			'source'         => 'jetbooking',
		);
	}

	/**
	 * Count units for a booking instance.
	 *
	 * @since 1.5.0
	 * @param int $instance_id Booking instance post ID.
	 * @return int
	 */
	private function count_units_for_instance( $instance_id ) {
		global $wpdb;
		$units_table = $wpdb->prefix . 'jet_apartment_units';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$units_table} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $instance_id )
			)
		);
	}
}

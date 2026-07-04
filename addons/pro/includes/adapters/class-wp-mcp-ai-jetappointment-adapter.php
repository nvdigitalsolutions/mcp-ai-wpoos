<?php
/**
 * JetAppointment Adapter
 *
 * Bridges NV oOS Calendar Booking tools to Crocoblock's JetAppointment plugin
 * via its REST API. Handles authentication, data mapping, availability queries,
 * and appointment CRUD.
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
 * Class WP_MCP_AI_JetAppointment_Adapter
 *
 * @since 1.5.0
 */
class WP_MCP_AI_JetAppointment_Adapter implements WP_MCP_AI_Booking_Adapter_Interface {

	/**
	 * Adapter slug.
	 *
	 * @var string
	 */
	const SLUG = 'jetappointment';

	/**
	 * Transient key prefix for caching provider/service lists.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_ja_cache_';

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

		// 1. JetEngine must be active (JetAppointment depends on it).
		if ( ! function_exists( 'jet_engine' ) ) {
			return false;
		}

		// 2. JetAppointment DB table must exist.
		$table  = $wpdb->prefix . 'jet_appointment';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check; no caching needed.
		if ( ! $exists ) {
			return false;
		}

		// 3. JetAppointment must be configured (settings option exists).
		$ja_settings = get_option( 'jet_appointment_settings' );
		if ( empty( $ja_settings ) ) {
			return false;
		}

		// 4. Integration must not be explicitly disabled in NV oOS settings.
		$nv_settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( isset( $nv_settings['enable_jetappointment_integration'] )
			&& empty( $nv_settings['enable_jetappointment_integration'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return __( 'JetEngine plugin is not active. JetAppointment requires JetEngine.', 'mcp-ai-wpoos-pro' );
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'jet_appointment';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $exists ) {
			return __( 'JetAppointment database table not found. Ensure the JetAppointment plugin is installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		$ja_settings = get_option( 'jet_appointment_settings' );
		if ( empty( $ja_settings ) ) {
			return __( 'JetAppointment is not configured. Set up provider and service CPTs in JetAppointment settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'JetAppointment integration is disabled in NV oOS settings.', 'mcp-ai-wpoos-pro' );
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
		return __( 'JetAppointment', 'mcp-ai-wpoos-pro' );
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
		if ( ! empty( $filters['provider_id'] ) ) {
			$query_args['provider'] = absint( $filters['provider_id'] );
		}
		if ( ! empty( $filters['service_id'] ) ) {
			$query_args['service'] = absint( $filters['service_id'] );
		}

		$response = $this->api_request( 'appointments-list', 'GET', $query_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = array();
		$raw   = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		foreach ( $raw as $item ) {
			$items[] = $this->map_appointment_to_canonical( $item );
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
		$response = $this->api_request( 'get-appointment', 'GET', array( 'id' => absint( $booking_id ) ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = isset( $response['data'] ) ? $response['data'] : $response;

		return array(
			'success' => true,
			'booking' => $this->map_appointment_to_canonical( $data ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $data Booking data.
	 */
	public function create_booking( array $data ) {
		$payload = array(
			array(
				'service'            => absint( $data['service'] ?? 0 ),
				'provider'           => absint( $data['provider'] ?? 0 ),
				'date'               => sanitize_text_field( $data['date'] ?? '' ),
				'date_timestamp'     => absint( $data['date_timestamp'] ?? 0 ),
				'slot'               => sanitize_text_field( $data['slot'] ?? '' ),
				'slot_end'           => sanitize_text_field( $data['slot_end'] ?? '' ),
				'slot_timestamp'     => absint( $data['slot_timestamp'] ?? 0 ),
				'slot_end_timestamp' => absint( $data['slot_end_timestamp'] ?? 0 ),
				'status'             => sanitize_key( $data['status'] ?? 'pending' ),
				'user_email'         => sanitize_email( $data['user_email'] ?? '' ),
			),
		);

		$response = $this->api_request( 'add-appointment', 'POST', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$created = isset( $response['data'][0] ) ? $response['data'][0] : array();

		return array(
			'success'    => true,
			'booking_id' => isset( $created['ID'] ) ? absint( $created['ID'] ) : 0,
			'booking'    => $this->map_appointment_to_canonical( $created ),
			'message'    => __( 'Appointment created successfully in JetAppointment.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int|string $booking_id External booking ID.
	 * @param array      $data       Fields to update.
	 */
	public function update_booking( $booking_id, array $data ) {
		$data['id'] = absint( $booking_id );

		$response = $this->api_request( 'update-appointment', 'POST', $data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$updated = isset( $response['data'] ) ? $response['data'] : $response;

		return array(
			'success' => true,
			'booking' => $this->map_appointment_to_canonical( $updated ),
			'message' => __( 'Appointment updated in JetAppointment.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param int|string $booking_id External booking ID.
	 * @param string     $reason     Cancellation reason.
	 */
	public function cancel_booking( $booking_id, $reason = '' ) {
		$response = $this->api_request( 'delete-appointment', 'DELETE', array( 'id' => absint( $booking_id ) ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'message' => __( 'Appointment cancelled in JetAppointment.', 'mcp-ai-wpoos-pro' ),
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
		$service_id  = isset( $context['service_id'] ) ? absint( $context['service_id'] ) : 0;
		$provider_id = isset( $context['provider_id'] ) ? absint( $context['provider_id'] ) : 0;

		$query_args = array(
			'service'  => $service_id,
			'provider' => $provider_id,
		);

		$response = $this->api_request( 'refresh-date', 'GET', $query_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$excluded_dates = isset( $response['data']['excludedDates'] ) ? $response['data']['excludedDates'] : array();
		$start_ts       = strtotime( $start_time );
		$end_ts         = strtotime( $end_time );

		$conflicts = array();
		foreach ( $excluded_dates as $excluded ) {
			$ex_start = isset( $excluded['start'] ) ? absint( $excluded['start'] ) : 0;
			$ex_end   = isset( $excluded['end'] ) ? absint( $excluded['end'] ) : 0;

			// Check if requested range overlaps with excluded range.
			if ( $start_ts < $ex_end && $end_ts > $ex_start ) {
				$conflicts[] = array(
					'start'   => gmdate( 'Y-m-d H:i:s', $ex_start ),
					'end'     => gmdate( 'Y-m-d H:i:s', $ex_end ),
					'service' => isset( $excluded['service'] ) ? absint( $excluded['service'] ) : 0,
					'is_full' => ! empty( $excluded['is_full'] ),
				);
			}
		}

		$available = empty( $conflicts );
		$reasons   = array();

		if ( ! $available ) {
			$reasons[] = sprintf(
				/* translators: %d: number of conflicting slots in JetAppointment */
				_n(
					'%d conflicting time slot found in JetAppointment.',
					'%d conflicting time slots found in JetAppointment.',
					count( $conflicts ),
					'mcp-ai-wpoos-pro'
				),
				count( $conflicts )
			);
		}

		return array(
			'success'   => true,
			'available' => $available,
			'conflicts' => $conflicts,
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
		$service_id  = isset( $context['service_id'] ) ? absint( $context['service_id'] ) : 0;
		$provider_id = isset( $context['provider_id'] ) ? absint( $context['provider_id'] ) : 0;

		$query_args = array(
			'service'  => $service_id,
			'provider' => $provider_id,
		);

		$response = $this->api_request( 'refresh-date', 'GET', $query_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data           = isset( $response['data'] ) ? $response['data'] : array();
		$available_days = isset( $data['availableWeekDays'] ) ? (array) $data['availableWeekDays'] : array();
		$excluded_dates = isset( $data['excludedDates'] ) ? (array) $data['excludedDates'] : array();

		$day_of_week = strtolower( gmdate( 'l', strtotime( $date ) ) );

		if ( ! in_array( $day_of_week, $available_days, true ) ) {
			return array(
				'success' => true,
				'date'    => $date,
				'slots'   => array(),
				'total'   => 0,
				'message' => __( 'This day is not available for appointments.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Build time slots based on available hours.
		$slots      = array();
		$start_hour = 9;
		$end_hour   = 17;

		for ( $hour = $start_hour; $hour < $end_hour; $hour++ ) {
			$slot_start = strtotime( $date . ' ' . sprintf( '%02d:00:00', $hour ) );
			$slot_end   = $slot_start + ( absint( $duration_minutes ) * 60 );

			// Check if this slot is excluded.
			$is_excluded = false;
			foreach ( $excluded_dates as $excluded ) {
				$ex_start = isset( $excluded['start'] ) ? absint( $excluded['start'] ) : 0;
				$ex_end   = isset( $excluded['end'] ) ? absint( $excluded['end'] ) : 0;
				if ( $slot_start < $ex_end && $slot_end > $ex_start ) {
					$is_excluded = true;
					break;
				}
			}

			if ( ! $is_excluded ) {
				$slots[] = array(
					'start_time' => gmdate( 'Y-m-d H:i:s', $slot_start ),
					'end_time'   => gmdate( 'Y-m-d H:i:s', $slot_end ),
					'available'  => true,
					'source'     => 'jetappointment',
				);
			}
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
		$ja_settings = get_option( 'jet_appointment_settings', array() );
		$cpt_slug    = isset( $ja_settings['provider_post_type'] ) ? sanitize_key( $ja_settings['provider_post_type'] ) : '';

		if ( empty( $cpt_slug ) ) {
			return new WP_Error(
				'no_provider_cpt',
				__( 'No provider post type configured in JetAppointment settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check cache.
		$cache_key = self::CACHE_PREFIX . 'providers_' . md5( wp_json_encode( $filters ) );
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
		$providers = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$providers[] = array(
					'id'          => get_the_ID(),
					'name'        => get_the_title(),
					'description' => wp_strip_all_tags( get_the_content() ),
					'post_type'   => $cpt_slug,
					'source'      => 'jetappointment',
				);
			}
			wp_reset_postdata();
		}

		$result = array(
			'success'   => true,
			'providers' => $providers,
			'total'     => count( $providers ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $filters Optional filters.
	 */
	public function get_services( array $filters = array() ) {
		$ja_settings = get_option( 'jet_appointment_settings', array() );
		$cpt_slug    = isset( $ja_settings['service_post_type'] ) ? sanitize_key( $ja_settings['service_post_type'] ) : '';

		if ( empty( $cpt_slug ) ) {
			return new WP_Error(
				'no_service_cpt',
				__( 'No service post type configured in JetAppointment settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check cache.
		$cache_key = self::CACHE_PREFIX . 'services_' . md5( wp_json_encode( $filters ) );
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

		$query    = new WP_Query( $query_args );
		$services = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$services[] = array(
					'id'               => get_the_ID(),
					'name'             => get_the_title(),
					'description'      => wp_strip_all_tags( get_the_content() ),
					'post_type'        => $cpt_slug,
					'source'           => 'jetappointment',
					'duration_minutes' => absint( get_post_meta( get_the_ID(), '_service_duration', true ) ) ? absint( get_post_meta( get_the_ID(), '_service_duration', true ) ) : 60,
					'price'            => floatval( get_post_meta( get_the_ID(), '_service_price', true ) ),
				);
			}
			wp_reset_postdata();
		}

		$result = array(
			'success'  => true,
			'services' => $services,
			'total'    => count( $services ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function health_check() {
		$checks = array();

		// Check 1: JetEngine active.
		$checks['jetengine_active'] = function_exists( 'jet_engine' );

		// Check 2: Database table exists.
		global $wpdb;
		$table                  = $wpdb->prefix . 'jet_appointment';
		$checks['table_exists'] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// Check 3: REST API reachable.
		$api_response     = $this->api_request( 'appointments-list', 'GET', array( 'per_page' => 1 ) );
		$checks['api_ok'] = ! is_wp_error( $api_response );

		// Check 4: Configuration present.
		$ja_settings             = get_option( 'jet_appointment_settings', array() );
		$checks['is_configured'] = ! empty( $ja_settings );

		$all_healthy = $checks['jetengine_active'] && $checks['table_exists'] && $checks['api_ok'] && $checks['is_configured'];

		$message = $all_healthy
			? __( 'JetAppointment adapter is healthy.', 'mcp-ai-wpoos-pro' )
			: __( 'JetAppointment adapter has issues.', 'mcp-ai-wpoos-pro' );

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
	// Private Helpers
	// -------------------------------------------------------------------------

	/**
	 * Make an authenticated request to the JetAppointment REST API.
	 *
	 * @since 1.5.0
	 * @param string $endpoint Relative endpoint path (e.g. 'add-appointment').
	 * @param string $method   HTTP method (GET, POST, DELETE).
	 * @param array  $params   Query params for GET, body for POST.
	 * @return array|WP_Error  Decoded JSON response or error.
	 */
	private function api_request( $endpoint, $method = 'GET', array $params = array() ) {
		$url = rest_url( 'jet-engine/v2/appointment-' . $endpoint );

		$credentials = $this->get_api_credentials();
		if ( empty( $credentials ) ) {
			return new WP_Error(
				'ja_no_credentials',
				__( 'JetAppointment API credentials are not configured. Add credentials in NV oOS → Settings → Calendar Booking.', 'mcp-ai-wpoos-pro' )
			);
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $credentials ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Application Password auth requires Base64 encoding per RFC 7617.
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
				'ja_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: API error message */
					__( 'JetAppointment API returned %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
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
	 * @return string "username:application_password" for Basic Auth.
	 */
	private function get_api_credentials() {
		// Try Password Vault first.
		if ( function_exists( 'wp_mcp_ai_get_secret' ) ) {
			$secret = wp_mcp_ai_get_secret( 'jetappointment_api' );
			if ( ! empty( $secret ) ) {
				return $secret;
			}
		}

		// Fallback: legacy option.
		$legacy = get_option( 'wp_mcp_ai_jetappointment_api_credentials', '' );
		return is_string( $legacy ) ? $legacy : '';
	}

	/**
	 * Map a raw JetAppointment record to the canonical booking envelope.
	 *
	 * @since 1.5.0
	 * @param array $raw Raw appointment data from REST API.
	 * @return array Canonical booking array.
	 */
	private function map_appointment_to_canonical( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();

		$date     = isset( $raw['date'] ) ? sanitize_text_field( $raw['date'] ) : '';
		$slot     = isset( $raw['slot'] ) ? sanitize_text_field( $raw['slot'] ) : '';
		$slot_end = isset( $raw['slot_end'] ) ? sanitize_text_field( $raw['slot_end'] ) : '';
		$date_ts  = isset( $raw['date_timestamp'] ) ? absint( $raw['date_timestamp'] ) : 0;

		// Build ISO datetime strings.
		$date_obj   = $date_ts ? gmdate( 'Y-m-d', $date_ts ) : $date;
		$start_time = $date_obj && $slot ? $date_obj . ' ' . $slot : '';
		$end_time   = $date_obj && $slot_end ? $date_obj . ' ' . $slot_end : '';

		return array(
			'id'                 => isset( $raw['ID'] ) ? absint( $raw['ID'] ) : 0,
			'service_id'         => isset( $raw['service'] ) ? absint( $raw['service'] ) : 0,
			'provider_id'        => isset( $raw['provider'] ) ? absint( $raw['provider'] ) : 0,
			'date'               => $date_obj,
			'start_time'         => $start_time,
			'end_time'           => $end_time,
			'status'             => isset( $raw['status'] ) ? sanitize_key( $raw['status'] ) : 'pending',
			'user_email'         => isset( $raw['user_email'] ) ? sanitize_email( $raw['user_email'] ) : '',
			'date_timestamp'     => $date_ts,
			'slot_timestamp'     => isset( $raw['slot_timestamp'] ) ? absint( $raw['slot_timestamp'] ) : 0,
			'slot_end_timestamp' => isset( $raw['slot_end_timestamp'] ) ? absint( $raw['slot_end_timestamp'] ) : 0,
			'source'             => 'jetappointment',
		);
	}
}

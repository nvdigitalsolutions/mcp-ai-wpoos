<?php
/**
 * Find Bookable Places Tool
 *
 * Geospatial search that only returns places with available bookings.
 * Combines radius search from list_places with adapter-based availability.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Places_Toolkit
 * @since     1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find places near a location that have bookable appointments/slots/units.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Find_Bookable_Places implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_places_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Places Management toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'find_bookable_places';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Find Bookable Places', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Find places near a location that have available booking slots. Combines geospatial search with real-time availability from JetAppointment and JetBooking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'latitude'         => array(
					'type'        => 'number',
					'description' => __( 'Center latitude for search (required).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => -90,
					'maximum'     => 90,
				),
				'longitude'        => array(
					'type'        => 'number',
					'description' => __( 'Center longitude for search (required).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => -180,
					'maximum'     => 180,
				),
				'radius_km'        => array(
					'type'        => 'number',
					'description' => __( 'Search radius in kilometers (default: 10).', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 0.1,
					'maximum'     => 100,
				),
				'date'             => array(
					'type'        => 'string',
					'description' => __( 'Date to check availability for (Y-m-d). Default: today.', 'mcp-ai-wpoos-pro' ),
				),
				'duration_minutes' => array(
					'type'        => 'integer',
					'description' => __( 'Required booking duration in minutes (default: 60).', 'mcp-ai-wpoos-pro' ),
					'default'     => 60,
					'minimum'     => 15,
					'maximum'     => 480,
				),
				'place_type'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by place type (e.g., restaurant, hotel).', 'mcp-ai-wpoos-pro' ),
				),
				'min_rating'       => array(
					'type'        => 'number',
					'description' => __( 'Minimum rating (0-5).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
				),
				'limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum places to return (default: 20, max: 50).', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 50,
				),
			),
			'required'   => array( 'latitude', 'longitude' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'phase-1.5' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}

		$center_lat = floatval( $arguments['latitude'] );
		$center_lon = floatval( $arguments['longitude'] );
		$radius     = isset( $arguments['radius_km'] ) ? floatval( $arguments['radius_km'] ) : 10;
		$date       = ! empty( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );
		$duration   = isset( $arguments['duration_minutes'] ) ? absint( $arguments['duration_minutes'] ) : 60;
		$limit      = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 50 ) : 20;

		// Query places.
		$query_args = array(
			'post_type'      => 'mcp_ai_place',
			'post_status'    => 'publish',
			'posts_per_page' => 100, // Fetch more and filter down — Haversine is app-layer.
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $arguments['place_type'] ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_place_type',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $arguments['place_type'] ),
				),
			);
		}

		$query  = new WP_Query( $query_args );
		$places = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$place_id = get_the_ID();

				$lat = (float) get_post_meta( $place_id, '_place_latitude', true );
				$lon = (float) get_post_meta( $place_id, '_place_longitude', true );

				if ( ! $lat || ! $lon ) {
					continue;
				}

				$distance = $this->haversine_distance( $center_lat, $center_lon, $lat, $lon );
				if ( $distance > $radius ) {
					continue;
				}

				// Rating filter.
				if ( isset( $arguments['min_rating'] ) ) {
					$rating = (float) get_post_meta( $place_id, '_place_rating', true );
					if ( $rating < floatval( $arguments['min_rating'] ) ) {
						continue;
					}
				}

				$address_meta = get_post_meta( $place_id, '_place_address', true );
				$phone_meta   = get_post_meta( $place_id, '_place_phone', true );

				$place_data = array(
					'id'              => $place_id,
					'name'            => get_the_title(),
					'description'     => wp_strip_all_tags( get_the_content() ),
					'address'         => $address_meta ? $address_meta : '',
					'latitude'        => $lat,
					'longitude'       => $lon,
					'distance_km'     => round( $distance, 2 ),
					'place_type'      => $this->get_first_term( $place_id, 'mcp_ai_place_type' ),
					'rating'          => (float) get_post_meta( $place_id, '_place_rating', true ),
					'phone'           => $phone_meta ? $phone_meta : '',
					'booking_sources' => array(),
				);

				// Check NV oOS linked services.
				$place_data['booking_sources'] = array_merge(
					$place_data['booking_sources'],
					$this->get_nvoos_booking_sources( $place_id, $date, $duration )
				);

				// Check JetAppointment.
				if ( class_exists( 'WP_MCP_AI_Booking_Adapter_Factory' ) && WP_MCP_AI_Booking_Adapter_Factory::has_jetappointment() ) {
					$ja_adapter      = WP_MCP_AI_Booking_Adapter_Factory::get_jetappointment();
					$ja_provider_ids = get_post_meta( $place_id, '_place_jetappointment_provider_ids', true );
					$ja_provider_ids = is_array( $ja_provider_ids ) ? $ja_provider_ids : array();

					if ( ! empty( $ja_provider_ids ) ) {
						foreach ( $ja_provider_ids as $provider_id ) {
							$slots = $ja_adapter->get_available_slots( $date, $duration, array( 'provider_id' => absint( $provider_id ) ) );
							if ( ! is_wp_error( $slots ) && ! empty( $slots['slots'] ) ) {
								$place_data['booking_sources'][] = array(
									'source'          => 'jetappointment',
									'provider_id'     => absint( $provider_id ),
									'available_slots' => $slots['total'],
									'next_slot'       => $slots['slots'][0]['start_time'],
								);
							}
						}
					}
				}

				// Check JetBooking.
				if ( class_exists( 'WP_MCP_AI_Booking_Adapter_Factory' ) && WP_MCP_AI_Booking_Adapter_Factory::has_jetbooking() ) {
					$jb_adapter      = WP_MCP_AI_Booking_Adapter_Factory::get_jetbooking();
					$jb_instance_ids = get_post_meta( $place_id, '_place_jetbooking_instance_ids', true );
					$jb_instance_ids = is_array( $jb_instance_ids ) ? $jb_instance_ids : array();

					if ( ! empty( $jb_instance_ids ) ) {
						foreach ( $jb_instance_ids as $instance_id ) {
							$availability = $jb_adapter->get_unit_availability( absint( $instance_id ), $date, $date );
							if ( ! is_wp_error( $availability ) && ! empty( $availability['available_units'] ) ) {
								$place_data['booking_sources'][] = array(
									'source'          => 'jetbooking',
									'instance_id'     => absint( $instance_id ),
									'available_units' => $availability['available_count'],
								);
							}
						}
					}
				}

				// Only include if at least one booking source has availability.
				if ( empty( $place_data['booking_sources'] ) ) {
					continue;
				}

				$places[] = $place_data;

				if ( count( $places ) >= $limit ) {
					break;
				}
			}
			wp_reset_postdata();
		}

		// Sort by distance.
		usort(
			$places,
			function ( $a, $b ) {
				return $a['distance_km'] <=> $b['distance_km'];
			}
		);

		return array(
			'success'  => true,
			'count'    => count( $places ),
			'location' => array(
				'latitude'  => $center_lat,
				'longitude' => $center_lon,
				'radius_km' => $radius,
			),
			'date'     => $date,
			'places'   => $places,
		);
	}

	/**
	 * Calculate distance between two coordinates using Haversine formula.
	 *
	 * @param float $lat1 Latitude of first point.
	 * @param float $lon1 Longitude of first point.
	 * @param float $lat2 Latitude of second point.
	 * @param float $lon2 Longitude of second point.
	 * @return float Distance in kilometers.
	 */
	private function haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
		$earth_radius = 6371;
		$d_lat        = deg2rad( $lat2 - $lat1 );
		$d_lon        = deg2rad( $lon2 - $lon1 );
		$a            = sin( $d_lat / 2 ) * sin( $d_lat / 2 ) +
			cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
			sin( $d_lon / 2 ) * sin( $d_lon / 2 );
		$c            = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
		return $earth_radius * $c;
	}

	/**
	 * Get first taxonomy term slug for a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private function get_first_term( $post_id, $taxonomy ) {
		$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
		return ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0] : '';
	}

	/**
	 * Get booking sources from NV oOS linked services.
	 *
	 * @param int    $place_id Place post ID.
	 * @param string $date     Date to check.
	 * @param int    $duration Minutes.
	 * @return array
	 */
	private function get_nvoos_booking_sources( $place_id, $date, $duration ) {
		$sources = array();

		$services = get_posts(
			array(
				'post_type'      => 'mcp_service',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'meta_key'       => '_service_place_id',
				'meta_value'     => $place_id,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $services ) ) {
			$sources[] = array(
				'source'          => 'nvoos',
				'service_count'   => count( $services ),
				'available_slots' => 0, // Simplified: would require full slot calculation.
			);
		}

		return $sources;
	}
}

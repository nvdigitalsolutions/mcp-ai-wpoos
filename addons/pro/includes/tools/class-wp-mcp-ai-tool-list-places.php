<?php
/**
 * Tool for listing places.
 *
 * Allows AI assistants to list and filter places with geospatial queries.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists places with filtering options including location-based radius search.
 */
class WP_MCP_AI_Tool_List_Places implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_places';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Places', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists saved places with optional filtering by type, location, radius, rating, and tags. Supports geospatial queries to find places near a specific location.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'place_type'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by place type (e.g., restaurant, hotel, attraction)', 'wp-mcp-ai' ),
				),
				'tags'           => array(
					'type'        => 'array',
					'description' => __( 'Filter by tags (optional)', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'latitude'       => array(
					'type'        => 'number',
					'description' => __( 'Center latitude for radius search', 'wp-mcp-ai' ),
					'minimum'     => -90,
					'maximum'     => 90,
				),
				'longitude'      => array(
					'type'        => 'number',
					'description' => __( 'Center longitude for radius search', 'wp-mcp-ai' ),
					'minimum'     => -180,
					'maximum'     => 180,
				),
				'radius'         => array(
					'type'        => 'number',
					'description' => __( 'Search radius in kilometers (default: 10)', 'wp-mcp-ai' ),
					'default'     => 10,
					'minimum'     => 0.1,
					'maximum'     => 100,
				),
				'min_rating'     => array(
					'type'        => 'number',
					'description' => __( 'Minimum rating (0-5)', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 5,
				),
				'price_level'    => array(
					'type'        => 'integer',
					'description' => __( 'Filter by price level (1-4)', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 4,
				),
				'has_amenity'    => array(
					'type'        => 'string',
					'description' => __( 'Filter places that have a specific amenity', 'wp-mcp-ai' ),
				),
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Search term to match place names or descriptions', 'wp-mcp-ai' ),
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of places to return (default: 20, max: 100)', 'wp-mcp-ai' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'include_closed' => array(
					'type'        => 'boolean',
					'description' => __( 'Include places that are currently closed (default: false)', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Places management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_places_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list places.', 'wp-mcp-ai' ) );
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_place',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 100 ) : 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Filter by place type taxonomy.
		$tax_query = array();

		if ( ! empty( $arguments['place_type'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'mcp_ai_place_type',
				'field'    => 'slug',
				'terms'    => sanitize_text_field( $arguments['place_type'] ),
			);
		}

		// Filter by tags.
		if ( ! empty( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'mcp_ai_place_tag',
				'field'    => 'slug',
				'terms'    => array_map( 'sanitize_text_field', $arguments['tags'] ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query;
		}

		// Build meta query for filters.
		$meta_query = array();

		// Filter by rating.
		if ( isset( $arguments['min_rating'] ) ) {
			$meta_query[] = array(
				'key'     => '_place_rating',
				'value'   => floatval( $arguments['min_rating'] ),
				'compare' => '>=',
				'type'    => 'DECIMAL(3,2)',
			);
		}

		// Filter by price level.
		if ( isset( $arguments['price_level'] ) ) {
			$meta_query[] = array(
				'key'     => '_place_price_level',
				'value'   => absint( $arguments['price_level'] ),
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		// Filter by amenity.
		if ( ! empty( $arguments['has_amenity'] ) ) {
			$meta_query[] = array(
				'key'     => '_place_amenities',
				'value'   => sprintf( '"%s"', sanitize_text_field( $arguments['has_amenity'] ) ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Search by name or description.
		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		// Execute query.
		$query  = new WP_Query( $query_args );
		$places = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$place_id = get_the_ID();

				$place_data = $this->get_place_data( $place_id );

				// Apply location-based filtering if coordinates provided.
				if ( isset( $arguments['latitude'], $arguments['longitude'] ) ) {
					$center_lat = floatval( $arguments['latitude'] );
					$center_lon = floatval( $arguments['longitude'] );

					if ( isset( $place_data['latitude'], $place_data['longitude'] ) ) {
						$distance = $this->calculate_distance(
							$center_lat,
							$center_lon,
							$place_data['latitude'],
							$place_data['longitude']
						);

						$radius = isset( $arguments['radius'] ) ? floatval( $arguments['radius'] ) : 10;

						if ( $distance > $radius ) {
							continue;
						}

						$place_data['distance_km'] = round( $distance, 2 );
					}
				}

				$places[] = $place_data;
			}
			wp_reset_postdata();

			// Sort by distance if location-based search.
			if ( isset( $arguments['latitude'], $arguments['longitude'] ) ) {
				usort( $places, function( $a, $b ) {
					$a_dist = isset( $a['distance_km'] ) ? $a['distance_km'] : PHP_FLOAT_MAX;
					$b_dist = isset( $b['distance_km'] ) ? $b['distance_km'] : PHP_FLOAT_MAX;
					return $a_dist <=> $b_dist;
				});
			}
		}

		return array(
			'success' => true,
			'count'   => count( $places ),
			'total'   => $query->found_posts,
			'places'  => $places,
		);
	}

	/**
	 * Get comprehensive place data.
	 *
	 * @param int $place_id Place post ID.
	 * @return array Place data.
	 */
	private function get_place_data( $place_id ) {
		$types = wp_get_object_terms( $place_id, 'mcp_ai_place_type', array( 'fields' => 'slugs' ) );
		$tags  = wp_get_object_terms( $place_id, 'mcp_ai_place_tag', array( 'fields' => 'names' ) );

		return array(
			'id'                => $place_id,
			'name'              => get_the_title(),
			'description'       => get_the_content(),
			'type'              => ! empty( $types ) ? $types[0] : '',
			'tags'              => $tags,
			'address'           => get_post_meta( $place_id, '_place_address', true ) ?: '',
			'latitude'          => (float) get_post_meta( $place_id, '_place_latitude', true ),
			'longitude'         => (float) get_post_meta( $place_id, '_place_longitude', true ),
			'phone'             => get_post_meta( $place_id, '_place_phone', true ) ?: '',
			'email'             => get_post_meta( $place_id, '_place_email', true ) ?: '',
			'website'           => get_post_meta( $place_id, '_place_website', true ) ?: '',
			'rating'            => (float) get_post_meta( $place_id, '_place_rating', true ),
			'price_level'       => (int) get_post_meta( $place_id, '_place_price_level', true ),
			'business_hours'    => get_post_meta( $place_id, '_place_business_hours', true ) ?: array(),
			'amenities'         => get_post_meta( $place_id, '_place_amenities', true ) ?: array(),
			'google_place_id'   => get_post_meta( $place_id, '_place_google_place_id', true ) ?: '',
			'address_components' => get_post_meta( $place_id, '_place_address_components', true ) ?: array(),
			'created_at'        => get_the_date( 'c' ),
			'updated_at'        => get_the_modified_date( 'c' ),
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
	private function calculate_distance( $lat1, $lon1, $lat2, $lon2 ) {
		$earth_radius = 6371; // Kilometers.

		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lon = deg2rad( $lon2 - $lon1 );

		$a = sin( $d_lat / 2 ) * sin( $d_lat / 2 ) +
			cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
			sin( $d_lon / 2 ) * sin( $d_lon / 2 );

		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth_radius * $c;
	}
}

<?php
/**
 * Tool for geospatial analysis using Turf.js.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Perform geospatial analysis using Turf.js.
 *
 * This tool leverages Turf.js to provide:
 * - Distance calculations between locations
 * - Point-in-polygon queries
 * - Area measurements
 * - Proximity analysis and buffering
 * - Geographic route calculations
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Analyze_Geospatial implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_geospatial';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Geospatial Data', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Perform geospatial analysis using Turf.js. Calculate distances between locations, find places within radius, measure areas, determine point-in-polygon relationships, and analyze geographic data. Perfect for location-based services and proximity searches.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation'          => array(
					'type'        => 'string',
					'enum'        => array( 'distance', 'buffer', 'within', 'area', 'nearest', 'bearing' ),
					'description' => __( 'Geospatial operation: distance (between points), buffer (radius), within (point in polygon), area (polygon area), nearest (closest point), bearing (direction)', 'mcp-ai-wpoos-pro' ),
				),
				'place_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Primary place ID for analysis', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'coordinates'        => array(
					'type'        => 'object',
					'properties'  => array(
						'latitude'  => array(
							'type'    => 'number',
							'minimum' => -90,
							'maximum' => 90,
						),
						'longitude' => array(
							'type'    => 'number',
							'minimum' => -180,
							'maximum' => 180,
						),
					),
					'description' => __( 'Geographic coordinates (alternative to place_id)', 'mcp-ai-wpoos-pro' ),
				),
				'target_place_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Target place ID (for distance and bearing operations)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'target_coordinates' => array(
					'type'        => 'object',
					'properties'  => array(
						'latitude'  => array(
							'type'    => 'number',
							'minimum' => -90,
							'maximum' => 90,
						),
						'longitude' => array(
							'type'    => 'number',
							'minimum' => -180,
							'maximum' => 180,
						),
					),
					'description' => __( 'Target coordinates (alternative to target_place_id)', 'mcp-ai-wpoos-pro' ),
				),
				'radius'             => array(
					'type'        => 'number',
					'description' => __( 'Radius in specified units (for buffer and within operations)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'units'              => array(
					'type'        => 'string',
					'enum'        => array( 'miles', 'kilometers', 'meters', 'feet' ),
					'description' => __( 'Distance unit for measurements', 'mcp-ai-wpoos-pro' ),
					'default'     => 'miles',
				),
				'find_places'        => array(
					'type'        => 'boolean',
					'description' => __( 'Find places within buffer/radius (for buffer and within operations)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'place_types'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Filter places by type (e.g., restaurant, hospital, school)', 'mcp-ai-wpoos-pro' ),
				),
				'limit'              => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of places to return (for find_places)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
				),
			),
			'required'   => array( 'operation' ),
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
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Primarily read operation.
			'requires-capability',  // Requires read capability.
			'external-dependency',  // Requires Turf.js.
			'cacheable',            // Results can be cached.
			'idempotent',           // Same input produces same output.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if places management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_places_management'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Places Management is not enabled. Please enable it in settings.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check if Turf.js is available.
		$turf_available = $this->check_turf_availability();
		if ( ! $turf_available ) {
			return array(
				'success' => false,
				'error'   => __( 'Turf.js is not available. Please ensure the package is installed. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get operation.
		$operation = sanitize_text_field( $arguments['operation'] );

		// Get source coordinates.
		$source_coords = $this->get_coordinates_from_arguments( $arguments, 'place_id', 'coordinates' );
		if ( ! $source_coords ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid or missing source location. Provide either place_id or coordinates.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Execute operation.
		switch ( $operation ) {
			case 'distance':
				$result = $this->calculate_distance( $source_coords, $arguments );
				break;

			case 'buffer':
				$result = $this->create_buffer( $source_coords, $arguments );
				break;

			case 'within':
				$result = $this->find_within( $source_coords, $arguments );
				break;

			case 'area':
				$result = $this->calculate_area( $source_coords, $arguments );
				break;

			case 'nearest':
				$result = $this->find_nearest( $source_coords, $arguments );
				break;

			case 'bearing':
				$result = $this->calculate_bearing( $source_coords, $arguments );
				break;

			default:
				return array(
					'success' => false,
					'error'   => __( 'Unsupported geospatial operation.', 'mcp-ai-wpoos-pro' ),
				);
		}

		if ( isset( $result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $result['error'],
			);
		}

		return array_merge(
			array(
				'success'   => true,
				'message'   => __( 'Geospatial analysis completed successfully.', 'mcp-ai-wpoos-pro' ),
				'operation' => $operation,
			),
			$result
		);
	}

	/**
	 * Check if Turf.js is available.
	 *
	 * @return bool True if Turf.js is available.
	 */
	private function check_turf_availability() {
		// Check if package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/turf/dist/esm/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/@turf/turf/dist/esm/index.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get coordinates from arguments.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $place_key Place ID parameter name.
	 * @param string $coords_key Coordinates parameter name.
	 * @return array|false Coordinates array or false on failure.
	 */
	private function get_coordinates_from_arguments( $arguments, $place_key, $coords_key ) {
		// Try place ID first.
		if ( isset( $arguments[ $place_key ] ) && absint( $arguments[ $place_key ] ) > 0 ) {
			$place_id = absint( $arguments[ $place_key ] );
			if ( get_post_type( $place_id ) === 'place' ) {
				$lat = get_post_meta( $place_id, '_place_latitude', true );
				$lng = get_post_meta( $place_id, '_place_longitude', true );

				if ( $lat && $lng ) {
					return array(
						'latitude'  => floatval( $lat ),
						'longitude' => floatval( $lng ),
						'place_id'  => $place_id,
					);
				}
			}
		}

		// Try direct coordinates.
		if ( isset( $arguments[ $coords_key ] ) && is_array( $arguments[ $coords_key ] ) ) {
			$coords = $arguments[ $coords_key ];
			if ( isset( $coords['latitude'] ) && isset( $coords['longitude'] ) ) {
				return array(
					'latitude'  => floatval( $coords['latitude'] ),
					'longitude' => floatval( $coords['longitude'] ),
				);
			}
		}

		return false;
	}

	/**
	 * Calculate distance between two points.
	 *
	 * @param array $source Source coordinates.
	 * @param array $arguments Tool arguments.
	 * @return array Result with distance.
	 */
	private function calculate_distance( $source, $arguments ) {
		$target = $this->get_coordinates_from_arguments( $arguments, 'target_place_id', 'target_coordinates' );

		if ( ! $target ) {
			return array( 'error' => __( 'Target location required for distance calculation.', 'mcp-ai-wpoos-pro' ) );
		}

		$units = isset( $arguments['units'] ) ? sanitize_text_field( $arguments['units'] ) : 'miles';

		// Use Turf.js for distance calculation.
		$result = $this->execute_turf_operation(
			'distance',
			array(
				'from'  => array( $source['longitude'], $source['latitude'] ),
				'to'    => array( $target['longitude'], $target['latitude'] ),
				'units' => $units,
			)
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		return array(
			'distance' => $result['distance'],
			'units'    => $units,
			'source'   => $source,
			'target'   => $target,
		);
	}

	/**
	 * Create buffer around point.
	 *
	 * @param array $source Source coordinates.
	 * @param array $arguments Tool arguments.
	 * @return array Result with buffer GeoJSON.
	 */
	private function create_buffer( $source, $arguments ) {
		$radius = isset( $arguments['radius'] ) ? floatval( $arguments['radius'] ) : 1;
		$units  = isset( $arguments['units'] ) ? sanitize_text_field( $arguments['units'] ) : 'miles';

		$result = $this->execute_turf_operation(
			'buffer',
			array(
				'point'  => array( $source['longitude'], $source['latitude'] ),
				'radius' => $radius,
				'units'  => $units,
			)
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		// Find places within buffer if requested.
		$places = array();
		if ( isset( $arguments['find_places'] ) && $arguments['find_places'] ) {
			$places = $this->find_places_in_buffer( $source, $radius, $units, $arguments );
		}

		return array(
			'buffer_geojson' => $result['geojson'],
			'center'         => $source,
			'radius'         => $radius,
			'units'          => $units,
			'places'         => $places,
			'place_count'    => count( $places ),
		);
	}

	/**
	 * Find places within radius.
	 *
	 * @param array $source Source coordinates.
	 * @param array $arguments Tool arguments.
	 * @return array Result with places.
	 */
	private function find_within( $source, $arguments ) {
		$radius = isset( $arguments['radius'] ) ? floatval( $arguments['radius'] ) : 1;
		$units  = isset( $arguments['units'] ) ? sanitize_text_field( $arguments['units'] ) : 'miles';

		$places = $this->find_places_in_buffer( $source, $radius, $units, $arguments );

		return array(
			'center'      => $source,
			'radius'      => $radius,
			'units'       => $units,
			'places'      => $places,
			'place_count' => count( $places ),
		);
	}

	/**
	 * Calculate area of polygon.
	 *
	 * @param array $source Source coordinates.
	 * @param array $arguments Tool arguments.
	 * @return array Result with area.
	 */
	private function calculate_area( $source, $arguments ) {
		return array( 'error' => __( 'Area calculation not yet implemented.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Find nearest place.
	 *
	 * @param array $source Source coordinates.
	 * @param array $arguments Tool arguments.
	 * @return array Result with nearest place.
	 */
	private function find_nearest( $source, $arguments ) {
		$places = $this->find_places_in_buffer( $source, 100, 'miles', $arguments );

		if ( empty( $places ) ) {
			return array( 'error' => __( 'No places found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sort by distance and get nearest.
		usort(
			$places,
			function ( $a, $b ) {
				return $a['distance'] <=> $b['distance'];
			}
		);

		return array(
			'source'        => $source,
			'nearest_place' => $places[0],
		);
	}

	/**
	 * Calculate bearing between two points.
	 *
	 * @param array $source Source coordinates.
	 * @param array $arguments Tool arguments.
	 * @return array Result with bearing.
	 */
	private function calculate_bearing( $source, $arguments ) {
		$target = $this->get_coordinates_from_arguments( $arguments, 'target_place_id', 'target_coordinates' );

		if ( ! $target ) {
			return array( 'error' => __( 'Target location required for bearing calculation.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $this->execute_turf_operation(
			'bearing',
			array(
				'from' => array( $source['longitude'], $source['latitude'] ),
				'to'   => array( $target['longitude'], $target['latitude'] ),
			)
		);

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		return array(
			'bearing'  => $result['bearing'],
			'cardinal' => $this->bearing_to_cardinal( $result['bearing'] ),
			'source'   => $source,
			'target'   => $target,
		);
	}

	/**
	 * Find places within buffer.
	 *
	 * @param array  $center Center coordinates.
	 * @param float  $radius Radius.
	 * @param string $units Distance units.
	 * @param array  $arguments Tool arguments.
	 * @return array Array of places.
	 */
	private function find_places_in_buffer( $center, $radius, $units, $arguments ) {
		// Query places.
		$query_args = array(
			'post_type'      => 'place',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10,
		);

		// Add meta query for place types if specified.
		if ( isset( $arguments['place_types'] ) && is_array( $arguments['place_types'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => '_place_type',
					'value'   => $arguments['place_types'],
					'compare' => 'IN',
				),
			);
		}

		$places_query = new WP_Query( $query_args );
		$places       = array();

		foreach ( $places_query->posts as $place ) {
			$lat = get_post_meta( $place->ID, '_place_latitude', true );
			$lng = get_post_meta( $place->ID, '_place_longitude', true );

			if ( $lat && $lng ) {
				// Calculate distance using Turf.js.
				$distance_result = $this->execute_turf_operation(
					'distance',
					array(
						'from'  => array( $center['longitude'], $center['latitude'] ),
						'to'    => array( floatval( $lng ), floatval( $lat ) ),
						'units' => $units,
					)
				);

				if ( ! isset( $distance_result['error'] ) && $distance_result['distance'] <= $radius ) {
					$places[] = array(
						'id'        => $place->ID,
						'title'     => $place->post_title,
						'latitude'  => floatval( $lat ),
						'longitude' => floatval( $lng ),
						'distance'  => $distance_result['distance'],
						'units'     => $units,
					);
				}
			}
		}

		return $places;
	}

	/**
	 * Execute Turf.js operation.
	 *
	 * @param string $operation Operation name.
	 * @param array  $params Operation parameters.
	 * @return array|false Operation result or false on failure.
	 */
	private function execute_turf_operation( $operation, $params ) {
		/**
		 * Filter to allow custom Turf.js operation implementation.
		 *
		 * @param array|false $result Operation result or false.
		 * @param string      $operation Operation name.
		 * @param array       $params Operation parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_turf_execute_operation', false, $operation, $params );

		if ( false === $result ) {
			return array(
				'error' => __( 'Turf.js operations require client-side JavaScript or Node.js service. Please implement the wp_mcp_ai_turf_execute_operation filter. See docs/INTEGRATION_BEST_PRACTICES.md for client-side geospatial analysis patterns.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $result;
	}

	/**
	 * Convert bearing degrees to cardinal direction.
	 *
	 * @param float $bearing Bearing in degrees.
	 * @return string Cardinal direction (N, NE, E, SE, S, SW, W, NW).
	 */
	private function bearing_to_cardinal( $bearing ) {
		$directions = array( 'N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW' );
		$index      = round( $bearing / 45 ) % 8;
		return $directions[ $index ];
	}
}

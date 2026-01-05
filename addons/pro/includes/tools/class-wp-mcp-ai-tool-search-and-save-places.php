<?php
/**
 * Tool for searching and saving places from Google Maps.
 *
 * Enhances the existing search_places tool by optionally saving results to database.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search places via Google Maps API with option to save results.
 */
class WP_MCP_AI_Tool_Search_And_Save_Places implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_and_save_places';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search and Save Places', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches for places using Google Places API and optionally saves the results to the database for future reference. This enhances geospatial capabilities by building a local database of places.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'          => array(
					'type'        => 'string',
					'description' => __( 'Search query text or keywords', 'wp-mcp-ai' ),
				),
				'latitude'       => array(
					'type'        => 'number',
					'description' => __( 'Center latitude for search', 'wp-mcp-ai' ),
				),
				'longitude'      => array(
					'type'        => 'number',
					'description' => __( 'Center longitude for search', 'wp-mcp-ai' ),
				),
				'radius'         => array(
					'type'        => 'integer',
					'description' => __( 'Search radius in meters (max 50000)', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50000,
					'default'     => 1500,
				),
				'type'           => array(
					'type'        => 'string',
					'description' => __( 'Place type (e.g., restaurant, cafe, hotel)', 'wp-mcp-ai' ),
				),
				'save_results'   => array(
					'type'        => 'boolean',
					'description' => __( 'Save search results to database (default: true)', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'skip_existing'  => array(
					'type'        => 'boolean',
					'description' => __( 'Skip places that already exist in database (default: true)', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Update existing places with fresh data (default: false)', 'wp-mcp-ai' ),
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
		return array(
			'pro', 'external-api', 'database-write', 'requires-capability' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search and save places.', 'wp-mcp-ai' ) );
		}

		// Use Google Maps client to search.
		if ( ! class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_class', __( 'Google Maps client not available.', 'wp-mcp-ai' ) );
		}

		$client  = new WP_MCP_AI_Google_Maps_Client();
		$options = array();

		if ( isset( $arguments['radius'] ) ) {
			$options['radius'] = absint( $arguments['radius'] );
		}

		if ( isset( $arguments['type'] ) ) {
			$options['type'] = sanitize_text_field( $arguments['type'] );
		}

		$query     = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$latitude  = isset( $arguments['latitude'] ) ? floatval( $arguments['latitude'] ) : null;
		$longitude = isset( $arguments['longitude'] ) ? floatval( $arguments['longitude'] ) : null;

		// Execute search.
		if ( ! empty( $query ) ) {
			if ( null !== $latitude && null !== $longitude ) {
				$options['location'] = $latitude . ',' . $longitude;
			}
			$result = $client->text_search( $query, $options );
		} elseif ( null !== $latitude && null !== $longitude ) {
			$result = $client->nearby_search( $latitude, $longitude, $options );
		} else {
			return new WP_Error(
				'wp_mcp_ai_missing_parameters',
				__( 'Either query or both latitude and longitude must be provided.', 'wp-mcp-ai' )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$save_results    = isset( $arguments['save_results'] ) ? (bool) $arguments['save_results'] : true;
		$skip_existing   = isset( $arguments['skip_existing'] ) ? (bool) $arguments['skip_existing'] : true;
		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : false;

		$saved_count   = 0;
		$skipped_count = 0;
		$updated_count = 0;
		$saved_ids     = array();

		if ( $save_results && ! empty( $result['results'] ) ) {
			foreach ( $result['results'] as $place_data ) {
				$google_place_id = isset( $place_data['place_id'] ) ? $place_data['place_id'] : '';

				if ( empty( $google_place_id ) ) {
					continue;
				}

				// Check if place already exists.
				$existing = $this->find_existing_place( $google_place_id );

				if ( $existing && $skip_existing && ! $update_existing ) {
					++$skipped_count;
					continue;
				}

				if ( $existing && $update_existing ) {
					// Update existing place.
					$this->update_place_from_api_data( $existing, $place_data );
					++$updated_count;
					$saved_ids[] = $existing;
				} else {
					// Create new place.
					$new_place_id = $this->create_place_from_api_data( $place_data, $current_user_id );
					if ( $new_place_id ) {
						++$saved_count;
						$saved_ids[] = $new_place_id;
					}
				}
			}
		}

		return array(
			'success'        => true,
			'found_count'    => isset( $result['results'] ) ? count( $result['results'] ) : 0,
			'saved_count'    => $saved_count,
			'updated_count'  => $updated_count,
			'skipped_count'  => $skipped_count,
			'saved_place_ids' => $saved_ids,
			'results'        => $result['results'],
		);
	}

	/**
	 * Find existing place by Google Place ID.
	 *
	 * @param string $google_place_id Google Place ID.
	 * @return int|null Place post ID or null.
	 */
	private function find_existing_place( $google_place_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_place',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_place_google_place_id',
						'value' => $google_place_id,
					),
				),
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	/**
	 * Create place from Google API data.
	 *
	 * @param array $place_data API place data.
	 * @param int   $user_id    User ID.
	 * @return int|false Place ID or false on failure.
	 */
	private function create_place_from_api_data( $place_data, $user_id ) {
		$name        = isset( $place_data['name'] ) ? sanitize_text_field( $place_data['name'] ) : '';
		$address     = isset( $place_data['formatted_address'] ) ? sanitize_text_field( $place_data['formatted_address'] ) : '';
		$latitude    = isset( $place_data['geometry']['location']['lat'] ) ? floatval( $place_data['geometry']['location']['lat'] ) : null;
		$longitude   = isset( $place_data['geometry']['location']['lng'] ) ? floatval( $place_data['geometry']['location']['lng'] ) : null;
		$types       = isset( $place_data['types'] ) && is_array( $place_data['types'] ) ? $place_data['types'] : array();
		$place_type  = ! empty( $types ) ? sanitize_text_field( $types[0] ) : '';

		$post_data = array(
			'post_type'   => 'mcp_ai_place',
			'post_title'  => $name,
			'post_status' => 'publish',
			'post_author' => $user_id,
		);

		$place_id = wp_insert_post( $post_data );

		if ( is_wp_error( $place_id ) || ! $place_id ) {
			return false;
		}

		// Set taxonomy.
		if ( $place_type ) {
			wp_set_object_terms( $place_id, $place_type, 'mcp_ai_place_type', false );
		}

		// Save metadata.
		update_post_meta( $place_id, '_place_address', $address );
		update_post_meta( $place_id, '_place_latitude', $latitude );
		update_post_meta( $place_id, '_place_longitude', $longitude );
		update_post_meta( $place_id, '_place_google_place_id', sanitize_text_field( $place_data['place_id'] ) );

		if ( isset( $place_data['rating'] ) ) {
			update_post_meta( $place_id, '_place_rating', floatval( $place_data['rating'] ) );
		}

		if ( isset( $place_data['price_level'] ) ) {
			update_post_meta( $place_id, '_place_price_level', absint( $place_data['price_level'] ) );
		}

		return $place_id;
	}

	/**
	 * Update existing place with fresh API data.
	 *
	 * @param int   $place_id   Place post ID.
	 * @param array $place_data API place data.
	 */
	private function update_place_from_api_data( $place_id, $place_data ) {
		if ( isset( $place_data['rating'] ) ) {
			update_post_meta( $place_id, '_place_rating', floatval( $place_data['rating'] ) );
		}

		if ( isset( $place_data['price_level'] ) ) {
			update_post_meta( $place_id, '_place_price_level', absint( $place_data['price_level'] ) );
		}

		// Update modified time.
		wp_update_post(
			array(
				'ID'            => $place_id,
				'post_modified' => current_time( 'mysql' ),
			)
		);
	}
}

<?php
/**
 * Tool for creating places.
 *
 * Allows AI assistants to create new places with location data, contact information,
 * and integration with Google Maps/Places API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-content-media.php';

/**
 * Creates a new place with comprehensive location and business data.
 */
class WP_MCP_AI_Tool_Create_Place implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Content_Media;
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_place';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Place', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new place (attraction, business, location) or updates an existing one if place_id is provided. Includes address, coordinates, contact info, hours, and other details. Supports auto-geocoding and Google Places API integration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'place_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Optional place ID. If provided, updates the existing place instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'Place name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'         => array(
					'type'        => 'string',
					'description' => __( 'Place description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'place_type'          => array(
					'type'        => 'string',
					'description' => __( 'Type of place (e.g., restaurant, hotel, attraction, museum, park, business)', 'mcp-ai-wpoos-pro' ),
				),
				'address'             => array(
					'type'        => 'string',
					'description' => __( 'Full street address', 'mcp-ai-wpoos-pro' ),
				),
				'latitude'            => array(
					'type'        => 'number',
					'description' => __( 'Latitude coordinate (-90 to 90)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => -90,
					'maximum'     => 90,
				),
				'longitude'           => array(
					'type'        => 'number',
					'description' => __( 'Longitude coordinate (-180 to 180)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => -180,
					'maximum'     => 180,
				),
				'auto_geocode'        => array(
					'type'        => 'boolean',
					'description' => __( 'If true and latitude/longitude not provided, automatically geocode the address', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'phone'               => array(
					'type'        => 'string',
					'description' => __( 'Phone number', 'mcp-ai-wpoos-pro' ),
				),
				'email'               => array(
					'type'        => 'string',
					'description' => __( 'Email address', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'website'             => array(
					'type'        => 'string',
					'description' => __( 'Website URL', 'mcp-ai-wpoos-pro' ),
					'format'      => 'uri',
				),
				'rating'              => array(
					'type'        => 'number',
					'description' => __( 'Rating (0-5)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
				),
				'price_level'         => array(
					'type'        => 'integer',
					'description' => __( 'Price level (1-4, where 1 is least expensive)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 4,
				),
				'business_hours'      => array(
					'type'        => 'object',
					'description' => __( 'Business hours by day of week', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'monday'    => array( 'type' => 'string' ),
						'tuesday'   => array( 'type' => 'string' ),
						'wednesday' => array( 'type' => 'string' ),
						'thursday'  => array( 'type' => 'string' ),
						'friday'    => array( 'type' => 'string' ),
						'saturday'  => array( 'type' => 'string' ),
						'sunday'    => array( 'type' => 'string' ),
					),
				),
				'amenities'           => array(
					'type'        => 'array',
					'description' => __( 'List of amenities/features (e.g., "wifi", "parking", "wheelchair_accessible")', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'tags'                => array(
					'type'        => 'array',
					'description' => __( 'Custom tags for categorization', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'google_place_id'     => array(
					'type'        => 'string',
					'description' => __( 'Google Place ID for API integration', 'mcp-ai-wpoos-pro' ),
				),
				'street'              => array(
					'type'        => 'string',
					'description' => __( 'Street address component', 'mcp-ai-wpoos-pro' ),
				),
				'city'                => array(
					'type'        => 'string',
					'description' => __( 'City', 'mcp-ai-wpoos-pro' ),
				),
				'state'               => array(
					'type'        => 'string',
					'description' => __( 'State/Province', 'mcp-ai-wpoos-pro' ),
				),
				'country'             => array(
					'type'        => 'string',
					'description' => __( 'Country', 'mcp-ai-wpoos-pro' ),
				),
				'postal_code'         => array(
					'type'        => 'string',
					'description' => __( 'Postal/ZIP code', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);

		// Merge content media parameters.
		$schema['properties'] = array_merge( $schema['properties'], $this->get_content_media_parameters() );

		return $schema;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro', 'database-write', 'requires-capability' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create places.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if this is an update operation.
		$place_id       = isset( $arguments['place_id'] ) ? absint( $arguments['place_id'] ) : 0;
		$is_update      = false;
		$existing_place = null;

		if ( $place_id ) {
			// Verify place exists and user has permission to update it.
			$existing_place = get_post( $place_id );

			if ( ! $existing_place || 'mcp_ai_place' !== $existing_place->post_type ) {
				return new WP_Error( 'wp_mcp_ai_place_not_found', __( 'Place not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author = absint( $existing_place->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this place.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate and sanitize inputs.
		$name        = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$place_type  = isset( $arguments['place_type'] ) ? sanitize_text_field( $arguments['place_type'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Place name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Handle geocoding if needed.
		$latitude  = isset( $arguments['latitude'] ) ? floatval( $arguments['latitude'] ) : null;
		$longitude = isset( $arguments['longitude'] ) ? floatval( $arguments['longitude'] ) : null;
		$address   = isset( $arguments['address'] ) ? sanitize_text_field( $arguments['address'] ) : '';

		if ( null === $latitude && null === $longitude && ! empty( $address ) ) {
			$auto_geocode = isset( $arguments['auto_geocode'] ) ? (bool) $arguments['auto_geocode'] : true;

			if ( $auto_geocode ) {
				// Attempt to geocode the address.
				if ( class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
					$maps_client = new WP_MCP_AI_Google_Maps_Client();
					$geocode_result = $maps_client->geocode( $address );

					if ( ! is_wp_error( $geocode_result ) && isset( $geocode_result['latitude'], $geocode_result['longitude'] ) ) {
						$latitude  = $geocode_result['latitude'];
						$longitude = $geocode_result['longitude'];

						// Extract address components if not provided.
						if ( isset( $geocode_result['address_components'] ) ) {
							$components = $geocode_result['address_components'];
							if ( ! isset( $arguments['street'] ) && ! empty( $components['street'] ) ) {
								$arguments['street'] = $components['street'];
							}
							if ( ! isset( $arguments['city'] ) && ! empty( $components['city'] ) ) {
								$arguments['city'] = $components['city'];
							}
							if ( ! isset( $arguments['state'] ) && ! empty( $components['state'] ) ) {
								$arguments['state'] = $components['state'];
							}
							if ( ! isset( $arguments['country'] ) && ! empty( $components['country'] ) ) {
								$arguments['country'] = $components['country'];
							}
							if ( ! isset( $arguments['postal_code'] ) && ! empty( $components['postal_code'] ) ) {
								$arguments['postal_code'] = $components['postal_code'];
							}
						}
					}
				}
			}
		}

		if ( $is_update ) {
			// Update existing place.
			$post_data = array(
				'ID'           => $place_id,
				'post_title'   => $name,
				'post_content' => $this->embed_content_media( $description, $arguments ),
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Set place type taxonomy.
			if ( ! empty( $place_type ) ) {
				wp_set_object_terms( $place_id, $place_type, 'mcp_ai_place_type', false );
			}

			// Set tags taxonomy.
			if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
				$tags = array_map( 'sanitize_text_field', $arguments['tags'] );
				wp_set_object_terms( $place_id, $tags, 'mcp_ai_place_tag', false );
			}

			// Save location metadata.
			if ( ! empty( $address ) ) {
				update_post_meta( $place_id, '_place_address', $address );
			}

			if ( null !== $latitude ) {
				update_post_meta( $place_id, '_place_latitude', $latitude );
			}

			if ( null !== $longitude ) {
				update_post_meta( $place_id, '_place_longitude', $longitude );
			}

			// Save address components.
			$address_components = array();
			if ( isset( $arguments['street'] ) ) {
				$address_components['street'] = sanitize_text_field( $arguments['street'] );
			}
			if ( isset( $arguments['city'] ) ) {
				$address_components['city'] = sanitize_text_field( $arguments['city'] );
			}
			if ( isset( $arguments['state'] ) ) {
				$address_components['state'] = sanitize_text_field( $arguments['state'] );
			}
			if ( isset( $arguments['country'] ) ) {
				$address_components['country'] = sanitize_text_field( $arguments['country'] );
			}
			if ( isset( $arguments['postal_code'] ) ) {
				$address_components['postal_code'] = sanitize_text_field( $arguments['postal_code'] );
			}
			if ( ! empty( $address_components ) ) {
				update_post_meta( $place_id, '_place_address_components', $address_components );
			}

			// Save contact information.
			if ( isset( $arguments['phone'] ) ) {
				update_post_meta( $place_id, '_place_phone', sanitize_text_field( $arguments['phone'] ) );
			}

			if ( isset( $arguments['email'] ) ) {
				update_post_meta( $place_id, '_place_email', sanitize_email( $arguments['email'] ) );
			}

			if ( isset( $arguments['website'] ) ) {
				update_post_meta( $place_id, '_place_website', esc_url_raw( $arguments['website'] ) );
			}

			// Save rating and price level.
			if ( isset( $arguments['rating'] ) ) {
				update_post_meta( $place_id, '_place_rating', floatval( $arguments['rating'] ) );
			}

			if ( isset( $arguments['price_level'] ) ) {
				update_post_meta( $place_id, '_place_price_level', absint( $arguments['price_level'] ) );
			}

			// Save business hours.
			if ( isset( $arguments['business_hours'] ) && is_array( $arguments['business_hours'] ) ) {
				$sanitized_hours = array();
				foreach ( $arguments['business_hours'] as $day => $hours ) {
					$sanitized_hours[ sanitize_key( $day ) ] = sanitize_text_field( $hours );
				}
				update_post_meta( $place_id, '_place_business_hours', $sanitized_hours );
			}

			// Save amenities.
			if ( isset( $arguments['amenities'] ) && is_array( $arguments['amenities'] ) ) {
				$amenities = array_map( 'sanitize_text_field', $arguments['amenities'] );
				update_post_meta( $place_id, '_place_amenities', $amenities );
			}

			// Save Google Place ID.
			if ( isset( $arguments['google_place_id'] ) ) {
				update_post_meta( $place_id, '_place_google_place_id', sanitize_text_field( $arguments['google_place_id'] ) );
			}

			$place = get_post( $place_id );

			// Build response.
			$response = array(
				'success'  => true,
				'message'  => sprintf(
					/* translators: %s: place name */
					__( 'Place updated: %s', 'mcp-ai-wpoos-pro' ),
					$name
				),
				'place_id' => $place_id,
				'place'    => array(
					'id'          => $place_id,
					'name'        => $name,
					'description' => $description,
					'type'        => $place_type,
					'address'     => $address,
					'latitude'    => $latitude,
					'longitude'   => $longitude,
					'updated_at'  => $place->post_modified,
				),
				'updated'  => true,
			);

			return $response;
		} else {
			// Create place post.
			$post_data = array(
				'post_type'    => 'mcp_ai_place',
				'post_title'   => $name,
				'post_content' => $this->embed_content_media( $description, $arguments ),
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			);

			$place_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $place_id ) ) {
				return $place_id;
			}

			// Set place type taxonomy.
			if ( ! empty( $place_type ) ) {
				wp_set_object_terms( $place_id, $place_type, 'mcp_ai_place_type', false );
			}

			// Set tags taxonomy.
			if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
				$tags = array_map( 'sanitize_text_field', $arguments['tags'] );
				wp_set_object_terms( $place_id, $tags, 'mcp_ai_place_tag', false );
			}

			// Save location metadata.
			if ( ! empty( $address ) ) {
				update_post_meta( $place_id, '_place_address', $address );
			}

			if ( null !== $latitude ) {
				update_post_meta( $place_id, '_place_latitude', $latitude );
			}

			if ( null !== $longitude ) {
				update_post_meta( $place_id, '_place_longitude', $longitude );
			}

			// Save address components.
			$address_components = array();
			if ( isset( $arguments['street'] ) ) {
				$address_components['street'] = sanitize_text_field( $arguments['street'] );
			}
			if ( isset( $arguments['city'] ) ) {
				$address_components['city'] = sanitize_text_field( $arguments['city'] );
			}
			if ( isset( $arguments['state'] ) ) {
				$address_components['state'] = sanitize_text_field( $arguments['state'] );
			}
			if ( isset( $arguments['country'] ) ) {
				$address_components['country'] = sanitize_text_field( $arguments['country'] );
			}
			if ( isset( $arguments['postal_code'] ) ) {
				$address_components['postal_code'] = sanitize_text_field( $arguments['postal_code'] );
			}
			if ( ! empty( $address_components ) ) {
				update_post_meta( $place_id, '_place_address_components', $address_components );
			}

			// Save contact information.
			if ( isset( $arguments['phone'] ) ) {
				update_post_meta( $place_id, '_place_phone', sanitize_text_field( $arguments['phone'] ) );
			}

			if ( isset( $arguments['email'] ) ) {
				update_post_meta( $place_id, '_place_email', sanitize_email( $arguments['email'] ) );
			}

			if ( isset( $arguments['website'] ) ) {
				update_post_meta( $place_id, '_place_website', esc_url_raw( $arguments['website'] ) );
			}

			// Save rating and price level.
			if ( isset( $arguments['rating'] ) ) {
				update_post_meta( $place_id, '_place_rating', floatval( $arguments['rating'] ) );
			}

			if ( isset( $arguments['price_level'] ) ) {
				update_post_meta( $place_id, '_place_price_level', absint( $arguments['price_level'] ) );
			}

			// Save business hours.
			if ( isset( $arguments['business_hours'] ) && is_array( $arguments['business_hours'] ) ) {
				$sanitized_hours = array();
				foreach ( $arguments['business_hours'] as $day => $hours ) {
					$sanitized_hours[ sanitize_key( $day ) ] = sanitize_text_field( $hours );
				}
				update_post_meta( $place_id, '_place_business_hours', $sanitized_hours );
			}

			// Save amenities.
			if ( isset( $arguments['amenities'] ) && is_array( $arguments['amenities'] ) ) {
				$amenities = array_map( 'sanitize_text_field', $arguments['amenities'] );
				update_post_meta( $place_id, '_place_amenities', $amenities );
			}

			// Save Google Place ID.
			if ( isset( $arguments['google_place_id'] ) ) {
				update_post_meta( $place_id, '_place_google_place_id', sanitize_text_field( $arguments['google_place_id'] ) );
			}

			// Build response.
			$response = array(
				'success'  => true,
				'message'  => __( 'Place created successfully.', 'mcp-ai-wpoos-pro' ),
				'place_id' => $place_id,
				'place'    => array(
					'id'          => $place_id,
					'name'        => $name,
					'description' => $description,
					'type'        => $place_type,
					'address'     => $address,
					'latitude'    => $latitude,
					'longitude'   => $longitude,
					'created_at'  => current_time( 'mysql' ),
				),
				'updated'  => false,
			);

			return $response;
		}
	}
}

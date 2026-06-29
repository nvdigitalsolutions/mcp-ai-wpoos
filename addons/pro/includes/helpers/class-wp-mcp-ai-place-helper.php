<?php
/**
 * Place Helper — shared place data persistence logic.
 *
 * Extracted from class-wp-mcp-ai-tool-create-place.php so that import tools,
 * the CLI, and the inline assistant can all persist place records through a
 * single code path without duplicating the meta-saving logic.
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

/**
 * Static helper for creating, updating, and deduplicating Place CPT records.
 *
 * All methods are static so they can be called from tools, CLI commands, and
 * import handlers without instantiation overhead.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Place_Helper {

	/**
	 * Place CPT post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_place';

	// -------------------------------------------------------------------------
	// Deduplication
	// -------------------------------------------------------------------------

	/**
	 * Find an existing place by dedup strategy.
	 *
	 * @since 1.4.0
	 *
	 * @param string $strategy Dedup strategy: 'google_place_id', 'source_url',
	 *                         'name_and_city', 'name_and_lat_lng', 'name'.
	 * @param array  $item     Item data (must contain fields relevant to the strategy).
	 * @return int|null Place post ID or null if no match.
	 */
	public static function find_existing( $strategy, array $item ) {
		switch ( $strategy ) {
			case 'google_place_id':
				return self::find_by_google_place_id(
					isset( $item['google_place_id'] ) ? $item['google_place_id'] : ''
				);

			case 'source_url':
				return self::find_by_source_url(
					isset( $item['source_url'] ) ? $item['source_url'] : ''
				);

			case 'name_and_city':
				return self::find_by_name_and_city(
					isset( $item['name'] ) ? $item['name'] : '',
					isset( $item['city'] ) ? $item['city'] : ''
				);

			case 'name_and_lat_lng':
				return self::find_by_name_and_coords(
					isset( $item['name'] ) ? $item['name'] : '',
					isset( $item['latitude'] ) ? $item['latitude'] : null,
					isset( $item['longitude'] ) ? $item['longitude'] : null
				);

			case 'name':
			default:
				return self::find_by_name( isset( $item['name'] ) ? $item['name'] : '' );
		}
	}

	/**
	 * Find by Google Place ID.
	 *
	 * @param string $google_place_id Google Place ID.
	 * @return int|null
	 */
	public static function find_by_google_place_id( $google_place_id ) {
		if ( empty( $google_place_id ) ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
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
	 * Find by source URL (for import deduplication).
	 *
	 * @param string $source_url Source URL.
	 * @return int|null
	 */
	public static function find_by_source_url( $source_url ) {
		if ( empty( $source_url ) ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_place_source_url',
						'value' => $source_url,
					),
				),
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	/**
	 * Find by place name and city.
	 *
	 * @param string $name Place name.
	 * @param string $city City.
	 * @return int|null
	 */
	public static function find_by_name_and_city( $name, $city ) {
		if ( empty( $name ) || empty( $city ) ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'title'          => $name,
				'meta_query'     => array(
					array(
						'key'     => '_place_city',
						'value'   => $city,
						'compare' => '=',
					),
				),
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	/**
	 * Find by name (simple title match).
	 *
	 * @param string $name Place name.
	 * @return int|null
	 */
	public static function find_by_name( $name ) {
		if ( empty( $name ) ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'title'          => $name,
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	/**
	 * Find by name and approximate coordinates (within ~0.001 degrees ≈ 111m).
	 *
	 * @param string $name      Place name.
	 * @param float  $latitude  Latitude.
	 * @param float  $longitude Longitude.
	 * @return int|null
	 */
	public static function find_by_name_and_coords( $name, $latitude, $longitude ) {
		if ( empty( $name ) || null === $latitude || null === $longitude ) {
			return null;
		}

		$tolerance = 0.001;

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'title'          => $name,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_place_latitude',
						'value'   => array( $latitude - $tolerance, $latitude + $tolerance ),
						'type'    => 'NUMERIC',
						'compare' => 'BETWEEN',
					),
					array(
						'key'     => '_place_longitude',
						'value'   => array( $longitude - $tolerance, $longitude + $tolerance ),
						'type'    => 'NUMERIC',
						'compare' => 'BETWEEN',
					),
				),
			)
		);

		return ! empty( $query->posts ) ? $query->posts[0] : null;
	}

	// -------------------------------------------------------------------------
	// Place persistence (create & update)
	// -------------------------------------------------------------------------

	/**
	 * Create a new place post with all metadata.
	 *
	 * @since 1.4.0
	 *
	 * @param array $arguments Sanitised place data (same shape as create_place tool).
	 * @param int   $user_id   WordPress user ID for post_author.
	 * @param bool  $embed_media Whether to embed content media (requires the trait).
	 * @return int|WP_Error Place post ID on success, WP_Error on failure.
	 */
	public static function create_place( array $arguments, $user_id, $embed_media = false ) {
		$name        = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';

		$post_data = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $name,
			'post_content' => $description,
			'post_status'  => 'publish',
			'post_author'  => $user_id,
		);

		// Set parent if provided.
		if ( ! empty( $arguments['parent_place_id'] ) ) {
			$post_data['post_parent'] = absint( $arguments['parent_place_id'] );
		}

		$place_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $place_id ) ) {
			return $place_id;
		}

		self::save_place_meta( $place_id, $arguments );

		return $place_id;
	}

	/**
	 * Update an existing place post with all metadata.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $place_id Existing place post ID.
	 * @param array $arguments Sanitised place data.
	 * @return int|WP_Error Place post ID on success, WP_Error on failure.
	 */
	public static function update_place( $place_id, array $arguments ) {
		$name        = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';

		$post_data = array(
			'ID'           => $place_id,
			'post_title'   => $name,
			'post_content' => $description,
		);

		// Set parent if provided.
		if ( isset( $arguments['parent_place_id'] ) ) {
			$post_data['post_parent'] = absint( $arguments['parent_place_id'] );
		}

		$result = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::save_place_meta( $place_id, $arguments );

		return $place_id;
	}

	/**
	 * Save all place meta fields to the database.
	 *
	 * This is the single authority for all place meta keys. Any tool that
	 * creates or updates a place should call this (or go through
	 * create_place / update_place which call it internally).
	 *
	 * @since 1.4.0
	 *
	 * @param int   $place_id  Place post ID.
	 * @param array $arguments Sanitised place data (all optional keys).
	 * @return void
	 */
	public static function save_place_meta( $place_id, array $arguments ) {
		// Taxonomy: place type.
		if ( ! empty( $arguments['place_type'] ) ) {
			wp_set_object_terms( $place_id, sanitize_text_field( $arguments['place_type'] ), 'mcp_ai_place_type', false );
		}

		// Taxonomy: tags.
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$tags = array_map( 'sanitize_text_field', $arguments['tags'] );
			wp_set_object_terms( $place_id, $tags, 'mcp_ai_place_tag', false );
		}

		// Location.
		if ( isset( $arguments['address'] ) && '' !== $arguments['address'] ) {
			update_post_meta( $place_id, '_place_address', sanitize_text_field( $arguments['address'] ) );
		}

		if ( isset( $arguments['latitude'] ) && null !== $arguments['latitude'] ) {
			update_post_meta( $place_id, '_place_latitude', floatval( $arguments['latitude'] ) );
		}

		if ( isset( $arguments['longitude'] ) && null !== $arguments['longitude'] ) {
			update_post_meta( $place_id, '_place_longitude', floatval( $arguments['longitude'] ) );
		}

		// Address components.
		$address_components = array();
		$component_keys     = array( 'street', 'city', 'state', 'country', 'postal_code' );
		foreach ( $component_keys as $key ) {
			if ( isset( $arguments[ $key ] ) && '' !== $arguments[ $key ] ) {
				$address_components[ $key ] = sanitize_text_field( $arguments[ $key ] );
			}
		}
		if ( ! empty( $address_components ) ) {
			update_post_meta( $place_id, '_place_address_components', $address_components );
		}

		// Contact.
		if ( isset( $arguments['phone'] ) ) {
			update_post_meta( $place_id, '_place_phone', sanitize_text_field( $arguments['phone'] ) );
		}
		if ( isset( $arguments['email'] ) ) {
			update_post_meta( $place_id, '_place_email', sanitize_email( $arguments['email'] ) );
		}
		if ( isset( $arguments['website'] ) ) {
			update_post_meta( $place_id, '_place_website', esc_url_raw( $arguments['website'] ) );
		}

		// Rating / price.
		if ( isset( $arguments['rating'] ) ) {
			update_post_meta( $place_id, '_place_rating', floatval( $arguments['rating'] ) );
		}
		if ( isset( $arguments['price_level'] ) ) {
			update_post_meta( $place_id, '_place_price_level', absint( $arguments['price_level'] ) );
		}

		// Business hours.
		if ( isset( $arguments['business_hours'] ) && is_array( $arguments['business_hours'] ) ) {
			$sanitized_hours = array();
			foreach ( $arguments['business_hours'] as $day => $hours ) {
				$sanitized_hours[ sanitize_key( $day ) ] = sanitize_text_field( $hours );
			}
			update_post_meta( $place_id, '_place_business_hours', $sanitized_hours );
		}

		// Amenities.
		if ( isset( $arguments['amenities'] ) && is_array( $arguments['amenities'] ) ) {
			update_post_meta( $place_id, '_place_amenities', array_map( 'sanitize_text_field', $arguments['amenities'] ) );
		}

		// Google Place ID.
		if ( isset( $arguments['google_place_id'] ) ) {
			update_post_meta( $place_id, '_place_google_place_id', sanitize_text_field( $arguments['google_place_id'] ) );
		}

		// Source URL (for import deduplication).
		if ( isset( $arguments['source_url'] ) ) {
			update_post_meta( $place_id, '_place_source_url', esc_url_raw( $arguments['source_url'] ) );
		}

		// Parent relationship meta (complementing post_parent).
		if ( isset( $arguments['relationship_type'] ) ) {
			update_post_meta( $place_id, '_place_relationship_type', sanitize_text_field( $arguments['relationship_type'] ) );
		}
	}

	// -------------------------------------------------------------------------
	// Geocoding
	// -------------------------------------------------------------------------

	/**
	 * Attempt to geocode an address and enrich arguments with coordinates
	 * and address components.
	 *
	 * @since 1.4.0
	 *
	 * @param array $arguments Place arguments (passed by reference).
	 * @return void
	 */
	public static function maybe_geocode( array &$arguments ) {
		$latitude  = isset( $arguments['latitude'] ) ? floatval( $arguments['latitude'] ) : null;
		$longitude = isset( $arguments['longitude'] ) ? floatval( $arguments['longitude'] ) : null;
		$address   = isset( $arguments['address'] ) ? $arguments['address'] : '';

		if ( null !== $latitude && null !== $longitude ) {
			return; // Already have coordinates.
		}

		if ( empty( $address ) ) {
			return; // Nothing to geocode.
		}

		$auto_geocode = isset( $arguments['auto_geocode'] ) ? (bool) $arguments['auto_geocode'] : true;
		if ( ! $auto_geocode ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
			return;
		}

		$maps_client    = new WP_MCP_AI_Google_Maps_Client();
		$geocode_result = $maps_client->geocode( $address );

		if ( is_wp_error( $geocode_result ) ) {
			return;
		}

		if ( isset( $geocode_result['latitude'], $geocode_result['longitude'] ) ) {
			$arguments['latitude']  = $geocode_result['latitude'];
			$arguments['longitude'] = $geocode_result['longitude'];

			// Enrich address components if not already provided.
			if ( isset( $geocode_result['address_components'] ) ) {
				$components = $geocode_result['address_components'];
				$map        = array(
					'street'      => 'street',
					'city'        => 'city',
					'state'       => 'state',
					'country'     => 'country',
					'postal_code' => 'postal_code',
				);
				foreach ( $map as $comp_key => $arg_key ) {
					if ( ! isset( $arguments[ $arg_key ] ) && ! empty( $components[ $comp_key ] ) ) {
						$arguments[ $arg_key ] = $components[ $comp_key ];
					}
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Image sideloading
	// -------------------------------------------------------------------------

	/**
	 * Sideload images from URLs into the Media Library and attach to a place.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $place_id       Place post ID.
	 * @param array  $image_urls     Array of image URLs.
	 * @param string $featured_from  'first_image', 'last_image', 'none'.
	 * @return array{attached: int, errors: array} Count of attached images and errors.
	 */
	public static function sideload_images( $place_id, array $image_urls, $featured_from = 'first_image' ) {
		$result = array(
			'attached' => 0,
			'errors'   => array(),
		);

		if ( empty( $image_urls ) ) {
			return $result;
		}

		// Require WordPress media handling.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_ids = array();

		foreach ( $image_urls as $index => $url ) {
			// Skip empty URLs.
			if ( empty( $url ) ) {
				continue;
			}

			// Download and attach.
			$attachment_id = media_sideload_image( $url, $place_id, null, 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				$result['errors'][] = array(
					'url'   => $url,
					'error' => $attachment_id->get_error_message(),
				);
				continue;
			}

			$attachment_ids[] = $attachment_id;
			++$result['attached'];

			// Set first image as featured.
			if ( 'first_image' === $featured_from && 0 === $index && $attachment_id ) {
				set_post_thumbnail( $place_id, $attachment_id );
			}
		}

		// Set last image as featured if configured.
		if ( 'last_image' === $featured_from && ! empty( $attachment_ids ) ) {
			set_post_thumbnail( $place_id, end( $attachment_ids ) );
		}

		// Store gallery.
		if ( ! empty( $attachment_ids ) ) {
			update_post_meta( $place_id, '_place_gallery', $attachment_ids );
		}

		return $result;
	}
}

<?php
/**
 * Tool for creating places.
 *
 * Allows AI assistants to create new places with location data, contact information,
 * and integration with Google Maps/Places API.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-content-media.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/helpers/class-wp-mcp-ai-place-helper.php';

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
				'place_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Optional place ID. If provided, updates the existing place instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'Place name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'Place description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'place_type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of place (e.g., restaurant, hotel, attraction, museum, park, business)', 'mcp-ai-wpoos-pro' ),
				),
				'address'           => array(
					'type'        => 'string',
					'description' => __( 'Full street address', 'mcp-ai-wpoos-pro' ),
				),
				'latitude'          => array(
					'type'        => 'number',
					'description' => __( 'Latitude coordinate (-90 to 90)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => -90,
					'maximum'     => 90,
				),
				'longitude'         => array(
					'type'        => 'number',
					'description' => __( 'Longitude coordinate (-180 to 180)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => -180,
					'maximum'     => 180,
				),
				'auto_geocode'      => array(
					'type'        => 'boolean',
					'description' => __( 'If true and latitude/longitude not provided, automatically geocode the address', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'phone'             => array(
					'type'        => 'string',
					'description' => __( 'Phone number', 'mcp-ai-wpoos-pro' ),
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'Email address', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'website'           => array(
					'type'        => 'string',
					'description' => __( 'Website URL', 'mcp-ai-wpoos-pro' ),
					'format'      => 'uri',
				),
				'rating'            => array(
					'type'        => 'number',
					'description' => __( 'Rating (0-5)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
				),
				'price_level'       => array(
					'type'        => 'integer',
					'description' => __( 'Price level (1-4, where 1 is least expensive)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 4,
				),
				'business_hours'    => array(
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
				'amenities'         => array(
					'type'        => 'array',
					'description' => __( 'List of amenities/features (e.g., "wifi", "parking", "wheelchair_accessible")', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => __( 'Custom tags for categorization', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'google_place_id'   => array(
					'type'        => 'string',
					'description' => __( 'Google Place ID for API integration', 'mcp-ai-wpoos-pro' ),
				),
				'street'            => array(
					'type'        => 'string',
					'description' => __( 'Street address component', 'mcp-ai-wpoos-pro' ),
				),
				'city'              => array(
					'type'        => 'string',
					'description' => __( 'City', 'mcp-ai-wpoos-pro' ),
				),
				'state'             => array(
					'type'        => 'string',
					'description' => __( 'State/Province', 'mcp-ai-wpoos-pro' ),
				),
				'country'           => array(
					'type'        => 'string',
					'description' => __( 'Country', 'mcp-ai-wpoos-pro' ),
				),
				'postal_code'       => array(
					'type'        => 'string',
					'description' => __( 'Postal/ZIP code', 'mcp-ai-wpoos-pro' ),
				),
				'parent_place_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Parent place ID for hierarchical organisation (e.g., an attraction under a city)', 'mcp-ai-wpoos-pro' ),
				),
				'relationship_type' => array(
					'type'        => 'string',
					'description' => __( 'Relationship to parent place', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'contains', 'near', 'part_of', 'recommended_for' ),
					'default'     => 'contains',
				),
				'source_url'        => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Source URL for import deduplication tracking', 'mcp-ai-wpoos-pro' ),
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
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'places',
			'post_type'             => 'mcp_ai_place',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'travel_agent', 'content_creator', 'researcher' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-capability',
		);
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

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
			$existing_place = get_post( $place_id );

			if ( ! $existing_place || WP_MCP_AI_Place_Helper::POST_TYPE !== $existing_place->post_type ) {
				return new WP_Error( 'wp_mcp_ai_place_not_found', __( 'Place not found.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_author       = absint( $existing_place->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this place.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate required fields.
		$name = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Place name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Attempt geocoding if coordinates are missing.
		WP_MCP_AI_Place_Helper::maybe_geocode( $arguments );

		// Embed content media in description (from trait).
		$description              = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$arguments['description'] = $this->embed_content_media( $description, $arguments );

		if ( $is_update ) {
			$result = WP_MCP_AI_Place_Helper::update_place( $place_id, $arguments );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$place = get_post( $place_id );
			return array(
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
					'type'        => isset( $arguments['place_type'] ) ? $arguments['place_type'] : '',
					'address'     => isset( $arguments['address'] ) ? $arguments['address'] : '',
					'latitude'    => isset( $arguments['latitude'] ) ? $arguments['latitude'] : null,
					'longitude'   => isset( $arguments['longitude'] ) ? $arguments['longitude'] : null,
					'updated_at'  => $place->post_modified,
				),
				'updated'  => true,
			);
		}

		// Create new place.
		$place_id = WP_MCP_AI_Place_Helper::create_place( $arguments, $current_user_id );
		if ( is_wp_error( $place_id ) ) {
			return $place_id;
		}

		return array(
			'success'  => true,
			'message'  => __( 'Place created successfully.', 'mcp-ai-wpoos-pro' ),
			'place_id' => $place_id,
			'place'    => array(
				'id'          => $place_id,
				'name'        => $name,
				'description' => $description,
				'type'        => isset( $arguments['place_type'] ) ? $arguments['place_type'] : '',
				'address'     => isset( $arguments['address'] ) ? $arguments['address'] : '',
				'latitude'    => isset( $arguments['latitude'] ) ? $arguments['latitude'] : null,
				'longitude'   => isset( $arguments['longitude'] ) ? $arguments['longitude'] : null,
				'created_at'  => current_time( 'mysql' ),
			),
			'updated'  => false,
		);
	}
}

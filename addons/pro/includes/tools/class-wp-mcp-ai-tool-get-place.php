<?php
/**
 * Tool for getting a single place.
 *
 * Allows AI assistants to retrieve details of a specific place.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets a single place with all details.
 */
class WP_MCP_AI_Tool_Get_Place implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_place';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Place', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific place.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'place_id' => array(
					'type'        => 'integer',
					'description' => __( 'Place ID to retrieve (required)', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'place_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro', 'read-only' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view places.', 'wp-mcp-ai' ) );
		}

		$place_id = isset( $arguments['place_id'] ) ? absint( $arguments['place_id'] ) : 0;

		if ( ! $place_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Place ID is required.', 'wp-mcp-ai' ) );
		}

		$place = get_post( $place_id );

		if ( ! $place || 'mcp_ai_place' !== $place->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Place not found.', 'wp-mcp-ai' ) );
		}

		$types = wp_get_object_terms( $place_id, 'mcp_ai_place_type', array( 'fields' => 'slugs' ) );
		$tags  = wp_get_object_terms( $place_id, 'mcp_ai_place_tag', array( 'fields' => 'names' ) );

		$place_data = array(
			'success'            => true,
			'place'              => array(
				'id'                 => $place_id,
				'name'               => get_the_title( $place ),
				'description'        => $place->post_content,
				'type'               => ! empty( $types ) ? $types[0] : '',
				'tags'               => $tags,
				'address'            => get_post_meta( $place_id, '_place_address', true ) ?: '',
				'latitude'           => (float) get_post_meta( $place_id, '_place_latitude', true ),
				'longitude'          => (float) get_post_meta( $place_id, '_place_longitude', true ),
				'address_components' => get_post_meta( $place_id, '_place_address_components', true ) ?: array(),
				'phone'              => get_post_meta( $place_id, '_place_phone', true ) ?: '',
				'email'              => get_post_meta( $place_id, '_place_email', true ) ?: '',
				'website'            => get_post_meta( $place_id, '_place_website', true ) ?: '',
				'rating'             => (float) get_post_meta( $place_id, '_place_rating', true ),
				'price_level'        => (int) get_post_meta( $place_id, '_place_price_level', true ),
				'business_hours'     => get_post_meta( $place_id, '_place_business_hours', true ) ?: array(),
				'amenities'          => get_post_meta( $place_id, '_place_amenities', true ) ?: array(),
				'google_place_id'    => get_post_meta( $place_id, '_place_google_place_id', true ) ?: '',
				'created_at'         => $place->post_date,
				'updated_at'         => $place->post_modified,
			),
		);

		return $place_data;
	}
}

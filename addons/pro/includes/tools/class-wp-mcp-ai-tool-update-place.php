<?php
/**
 * Tool for updating places.
 *
 * Allows AI assistants to update existing place information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing place.
 */
class WP_MCP_AI_Tool_Update_Place implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_place';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Place', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing place with new information. Only provided fields will be updated.', 'wp-mcp-ai' );
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
					'description' => __( 'Place ID to update (required)', 'wp-mcp-ai' ),
				),
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'New place name', 'wp-mcp-ai' ),
				),
				'description'         => array(
					'type'        => 'string',
					'description' => __( 'New description', 'wp-mcp-ai' ),
				),
				'place_type'          => array(
					'type'        => 'string',
					'description' => __( 'New place type', 'wp-mcp-ai' ),
				),
				'address'             => array(
					'type'        => 'string',
					'description' => __( 'New address', 'wp-mcp-ai' ),
				),
				'latitude'            => array(
					'type'        => 'number',
					'description' => __( 'New latitude', 'wp-mcp-ai' ),
				),
				'longitude'           => array(
					'type'        => 'number',
					'description' => __( 'New longitude', 'wp-mcp-ai' ),
				),
				'phone'               => array(
					'type'        => 'string',
					'description' => __( 'New phone number', 'wp-mcp-ai' ),
				),
				'email'               => array(
					'type'        => 'string',
					'description' => __( 'New email', 'wp-mcp-ai' ),
				),
				'website'             => array(
					'type'        => 'string',
					'description' => __( 'New website', 'wp-mcp-ai' ),
				),
				'rating'              => array(
					'type'        => 'number',
					'description' => __( 'New rating', 'wp-mcp-ai' ),
				),
				'price_level'         => array(
					'type'        => 'integer',
					'description' => __( 'New price level', 'wp-mcp-ai' ),
				),
				'business_hours'      => array(
					'type'        => 'object',
					'description' => __( 'New business hours', 'wp-mcp-ai' ),
				),
				'amenities'           => array(
					'type'        => 'array',
					'description' => __( 'New amenities list', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'tags'                => array(
					'type'        => 'array',
					'description' => __( 'New tags', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'string' ),
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
			'pro', 'database-write', 'requires-capability' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update places.', 'wp-mcp-ai' ) );
		}

		$place_id = isset( $arguments['place_id'] ) ? absint( $arguments['place_id'] ) : 0;

		if ( ! $place_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Place ID is required.', 'wp-mcp-ai' ) );
		}

		$place = get_post( $place_id );

		if ( ! $place || 'mcp_ai_place' !== $place->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Place not found.', 'wp-mcp-ai' ) );
		}

		// Update post fields if provided.
		$update_data = array( 'ID' => $place_id );

		if ( isset( $arguments['name'] ) ) {
			$update_data['post_title'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$update_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		if ( count( $update_data ) > 1 ) {
			wp_update_post( $update_data );
		}

		// Update taxonomy terms.
		if ( isset( $arguments['place_type'] ) ) {
			wp_set_object_terms( $place_id, sanitize_text_field( $arguments['place_type'] ), 'mcp_ai_place_type', false );
		}

		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			wp_set_object_terms( $place_id, array_map( 'sanitize_text_field', $arguments['tags'] ), 'mcp_ai_place_tag', false );
		}

		// Update metadata.
		$meta_fields = array(
			'address'        => '_place_address',
			'latitude'       => '_place_latitude',
			'longitude'      => '_place_longitude',
			'phone'          => '_place_phone',
			'email'          => '_place_email',
			'website'        => '_place_website',
			'rating'         => '_place_rating',
			'price_level'    => '_place_price_level',
			'business_hours' => '_place_business_hours',
			'amenities'      => '_place_amenities',
		);

		foreach ( $meta_fields as $arg_key => $meta_key ) {
			if ( isset( $arguments[ $arg_key ] ) ) {
				$value = $arguments[ $arg_key ];

				// Sanitize based on field type.
				if ( 'email' === $arg_key ) {
					$value = sanitize_email( $value );
				} elseif ( 'website' === $arg_key ) {
					$value = esc_url_raw( $value );
				} elseif ( in_array( $arg_key, array( 'latitude', 'longitude', 'rating' ), true ) ) {
					$value = floatval( $value );
				} elseif ( 'price_level' === $arg_key ) {
					$value = absint( $value );
				} elseif ( is_array( $value ) ) {
					$value = array_map( 'sanitize_text_field', $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				update_post_meta( $place_id, $meta_key, $value );
			}
		}

		return array(
			'success'  => true,
			'message'  => __( 'Place updated successfully.', 'wp-mcp-ai' ),
			'place_id' => $place_id,
		);
	}
}

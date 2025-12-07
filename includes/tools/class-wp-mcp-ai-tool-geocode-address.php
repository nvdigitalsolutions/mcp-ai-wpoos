<?php
/**
 * Tool that geocodes addresses using Google Maps Platform Geocoding API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-google-maps-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for geocoding addresses (address to coordinates) and reverse geocoding.
 * Follows separation of concerns: handles WordPress integration while delegating API calls to client.
 */
class WP_MCP_AI_Tool_Geocode_Address implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'geocode_address';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Geocode Address', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert addresses to geographic coordinates (latitude/longitude) or coordinates to addresses using Google Maps Geocoding API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'address'     => array(
					'type'        => 'string',
					'description' => __( 'The address to geocode (e.g., "1600 Amphitheatre Parkway, Mountain View, CA"). Required for forward geocoding.', 'wp-mcp-ai' ),
				),
				'latitude'    => array(
					'type'        => 'number',
					'description' => __( 'Latitude coordinate for reverse geocoding. Required with longitude for reverse geocoding.', 'wp-mcp-ai' ),
				),
				'longitude'   => array(
					'type'        => 'number',
					'description' => __( 'Longitude coordinate for reverse geocoding. Required with latitude for reverse geocoding.', 'wp-mcp-ai' ),
				),
				'language'    => array(
					'type'        => 'string',
					'description' => __( 'Language code for results (e.g., "en", "es", "fr").', 'wp-mcp-ai' ),
				),
				'region'      => array(
					'type'        => 'string',
					'description' => __( 'Region code for geocoding bias (e.g., "us", "uk").', 'wp-mcp-ai' ),
				),
				'result_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter results by type for reverse geocoding (e.g., "street_address", "locality").', 'wp-mcp-ai' ),
				),
				'timeout'     => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 60,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to use geocoding.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to use geocoding.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		$address   = isset( $arguments['address'] ) ? sanitize_text_field( $arguments['address'] ) : '';
		$latitude  = isset( $arguments['latitude'] ) ? floatval( $arguments['latitude'] ) : null;
		$longitude = isset( $arguments['longitude'] ) ? floatval( $arguments['longitude'] ) : null;

		$client  = new WP_MCP_AI_Google_Maps_Client();
		$options = array();

		if ( isset( $arguments['language'] ) ) {
			$options['language'] = sanitize_text_field( $arguments['language'] );
		}

		if ( isset( $arguments['region'] ) ) {
			$options['region'] = sanitize_text_field( $arguments['region'] );
		}

		if ( isset( $arguments['result_type'] ) ) {
			$options['result_type'] = sanitize_text_field( $arguments['result_type'] );
		}

		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		// Determine if this is forward or reverse geocoding.
		if ( ! empty( $address ) ) {
			// Forward geocoding: address to coordinates.
			$result = $client->geocode( $address, $options );
		} elseif ( null !== $latitude && null !== $longitude ) {
			// Reverse geocoding: coordinates to address.
			$result = $client->reverse_geocode( $latitude, $longitude, $options );
		} else {
			return new WP_Error(
				'wp_mcp_ai_missing_parameters',
				__( 'Either "address" or both "latitude" and "longitude" must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		if ( ! empty( $address ) ) {
			$summary = sprintf(
				/* translators: %s: address */
				__( 'Geocoded address: %s', 'wp-mcp-ai' ),
				$address
			);
		} else {
			$summary = sprintf(
				/* translators: 1: latitude, 2: longitude */
				__( 'Reverse geocoded coordinates: %1$s, %2$s', 'wp-mcp-ai' ),
				$latitude,
				$longitude
			);
		}

		$result = array_merge(
			array( 'summary' => $summary ),
			$result
		);

		/**
		 * Allow third parties to filter the geocoding result before it is returned.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_geocode_address_result', $result, $arguments, $context );

		return $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',        // Makes external API calls.
			'requires-capability', // Requires user capabilities.
		);
	}
}

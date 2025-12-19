<?php
/**
 * Tool that searches for places using Google Maps Platform Places API.
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
 * Provides a tool for searching places using Google Maps Places API (nearby and text search).
 * Follows separation of concerns: handles WordPress integration while delegating API calls to client.
 */
class WP_MCP_AI_Tool_Search_Places implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_places';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Places', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search for businesses, landmarks, and points of interest using Google Maps Places API. Supports nearby search and text search with AI-powered contextual results.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'     => array(
					'type'        => 'string',
					'description' => __( 'Search query text (e.g., "coffee shops", "restaurants in downtown"). Required for text search.', 'wp-mcp-ai' ),
				),
				'latitude'  => array(
					'type'        => 'number',
					'description' => __( 'Latitude coordinate for the search center. Required for nearby search.', 'wp-mcp-ai' ),
				),
				'longitude' => array(
					'type'        => 'number',
					'description' => __( 'Longitude coordinate for the search center. Required for nearby search.', 'wp-mcp-ai' ),
				),
				'radius'    => array(
					'type'        => 'integer',
					'description' => __( 'Search radius in meters (max 50000). Default is 1500 meters.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50000,
					'default'     => 1500,
				),
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'Place type to filter results (e.g., "restaurant", "cafe", "hotel", "museum", "park"). See Google Places API documentation for full list.', 'wp-mcp-ai' ),
				),
				'keyword'   => array(
					'type'        => 'string',
					'description' => __( 'Additional keyword to match against place names and types.', 'wp-mcp-ai' ),
				),
				'timeout'   => array(
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
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'label'   => __( 'Find nearby restaurants', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `search_places` tool to find restaurants near a location. Ask for the location, get coordinates if needed using geocoding, then search for type "restaurant" within a reasonable radius.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Find coffee shops', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `search_places` tool with type "cafe" to find coffee shops near a specified location.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Search for points of interest', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `search_places` tool with a text query to find specific places, landmarks, or businesses. This is useful for trip planning and local discovery.', 'wp-mcp-ai' ),
			),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to search places.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search places.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		$query     = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$latitude  = isset( $arguments['latitude'] ) ? floatval( $arguments['latitude'] ) : null;
		$longitude = isset( $arguments['longitude'] ) ? floatval( $arguments['longitude'] ) : null;

		$client  = new WP_MCP_AI_Google_Maps_Client();
		$options = array();

		if ( isset( $arguments['radius'] ) ) {
			$options['radius'] = max( 1, min( 50000, absint( $arguments['radius'] ) ) );
		}

		if ( isset( $arguments['type'] ) ) {
			$options['type'] = sanitize_text_field( $arguments['type'] );
		}

		if ( isset( $arguments['keyword'] ) ) {
			$options['keyword'] = sanitize_text_field( $arguments['keyword'] );
		}

		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		// Determine if this is text search or nearby search.
		if ( ! empty( $query ) ) {
			// Text search mode.
			if ( null !== $latitude && null !== $longitude ) {
				// Include location for biased results.
				$options['location'] = $latitude . ',' . $longitude;
			}

			$result = $client->text_search( $query, $options );
		} elseif ( null !== $latitude && null !== $longitude ) {
			// Nearby search mode.
			$result = $client->nearby_search( $latitude, $longitude, $options );
		} else {
			return new WP_Error(
				'wp_mcp_ai_missing_parameters',
				__( 'Either "query" or both "latitude" and "longitude" must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$place_count = isset( $result['results'] ) && is_array( $result['results'] ) ? count( $result['results'] ) : 0;

		if ( ! empty( $query ) ) {
			$summary = sprintf(
				/* translators: 1: number of places, 2: search query */
				__( 'Found %1$d place(s) for "%2$s"', 'wp-mcp-ai' ),
				$place_count,
				$query
			);
		} else {
			$summary = sprintf(
				/* translators: %d: number of places */
				__( 'Found %d nearby place(s)', 'wp-mcp-ai' ),
				$place_count
			);
		}

		$result = array_merge(
			array( 'summary' => $summary ),
			$result
		);

		/**
		 * Allow third parties to filter the places search result before it is returned.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_search_places_result', $result, $arguments, $context );

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

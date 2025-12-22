<?php
/**
 * Tool that performs geospatial queries using Gemini AI with Google Maps grounding.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for geospatial queries with Gemini and Google Maps grounding.
 * Enables AI-powered location-based queries with map context and rich insights.
 */
class WP_MCP_AI_Tool_Gemini_Geospatial_Query implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gemini_geospatial_query';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Gemini Geospatial Query', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Ask location-based questions using Gemini AI with Google Maps grounding. Returns AI-generated answers about places, directions, and local information with map context tokens for visualization.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'       => array(
					'type'        => 'string',
					'description' => __( 'Natural language query about locations, places, directions, or local information (e.g., "What are the best coffee shops near Central Park?", "Tell me about restaurants with outdoor seating in downtown Seattle").', 'wp-mcp-ai' ),
				),
				'latitude'    => array(
					'type'        => 'number',
					'description' => __( 'Optional latitude to provide location context for more relevant results.', 'wp-mcp-ai' ),
				),
				'longitude'   => array(
					'type'        => 'number',
					'description' => __( 'Optional longitude to provide location context for more relevant results.', 'wp-mcp-ai' ),
				),
				'model'       => array(
					'type'        => 'string',
					'description' => __( 'Gemini model to use (defaults to configured model).', 'wp-mcp-ai' ),
				),
				'temperature' => array(
					'type'        => 'number',
					'description' => __( 'Creativity level (0.0-2.0). Lower values are more focused, higher values more creative.', 'wp-mcp-ai' ),
					'minimum'     => 0.0,
					'maximum'     => 2.0,
				),
				'timeout'     => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-120).', 'wp-mcp-ai' ),
					'minimum'     => 5,
					'maximum'     => 120,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'label'   => __( 'Find local attractions', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to ask about tourist attractions, landmarks, or points of interest in a specific area. The AI will provide detailed information with map context.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Get dining recommendations', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to find restaurants, cafes, or food options with specific features (e.g., "dog friendly", "outdoor seating", "vegan options") in any location.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Plan a route', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to ask about routes, directions, or travel planning between locations. The AI provides detailed guidance with real-time map data.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Explore an area', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to learn about a neighborhood, city, or region. Get AI-powered summaries of area highlights, demographics, and local culture.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to use geospatial queries.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to use geospatial queries.', 'wp-mcp-ai' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
			}
		}

		$query = isset( $arguments['query'] ) ? sanitize_textarea_field( $arguments['query'] ) : '';

		if ( empty( $query ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'A query is required for geospatial search.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$client  = new WP_MCP_AI_Gemini_Client();
		$options = array();

		// Add optional location context.
		if ( isset( $arguments['latitude'] ) && isset( $arguments['longitude'] ) ) {
			$options['location'] = array(
				'latitude'  => floatval( $arguments['latitude'] ),
				'longitude' => floatval( $arguments['longitude'] ),
			);
		}

		// Add optional model.
		if ( isset( $arguments['model'] ) && ! empty( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}

		// Add optional temperature.
		if ( isset( $arguments['temperature'] ) ) {
			$temperature = floatval( $arguments['temperature'] );
			if ( $temperature >= 0.0 && $temperature <= 2.0 ) {
				$options['temperature'] = $temperature;
			}
		}

		// Add optional timeout.
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 120, absint( $arguments['timeout'] ) ) );
		}

		$result = $client->create_geospatial_query( $query, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract relevant information for the response.
		$response = array(
			'query'   => $query,
			'content' => isset( $result['content'] ) ? $result['content'] : '',
			'model'   => isset( $result['model'] ) ? $result['model'] : '',
		);

		// Include Google Maps context token if available.
		if ( isset( $result['google_maps_context_token'] ) ) {
			$response['google_maps_context_token'] = $result['google_maps_context_token'];
			$response['has_map_context']           = true;
		} else {
			$response['has_map_context'] = false;
		}

		// Include usage information if available.
		if ( isset( $result['usage'] ) ) {
			$response['usage'] = $result['usage'];
		}

		// Add summary for display.
		if ( ! empty( $response['content'] ) ) {
			$summary = sprintf(
				/* translators: %s: query text */
				__( 'Geospatial query completed for: %s', 'wp-mcp-ai' ),
				$query
			);
		} else {
			$summary = __( 'Geospatial query completed, but no content was returned.', 'wp-mcp-ai' );
		}

		$response = array_merge(
			array( 'summary' => $summary ),
			$response
		);

		/**
		 * Allow third parties to filter the geospatial query result before it is returned.
		 *
		 * @param array $response  Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$response = apply_filters( 'wp_mcp_ai_gemini_geospatial_query_result', $response, $arguments, $context );

		return $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',        // Makes external API calls to Gemini and Google Maps.
			'requires-capability', // Requires user capabilities.
			'ai-powered',          // Uses AI to generate responses.
		);
	}
}

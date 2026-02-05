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
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Gemini Geospatial Query', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Ask location-based questions using Gemini AI with Google Maps grounding. Returns AI-generated answers about places, directions, and local information with map context tokens for visualization.', 'mcp-ai-wpoos' );
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
					'description' => __( 'Natural language query about locations, places, directions, or local information (e.g., "What are the best coffee shops near Central Park?", "Tell me about restaurants with outdoor seating in downtown Seattle").', 'mcp-ai-wpoos' ),
				),
				'latitude'    => array(
					'type'        => 'number',
					'description' => __( 'Optional latitude to provide location context for more relevant results.', 'mcp-ai-wpoos' ),
				),
				'longitude'   => array(
					'type'        => 'number',
					'description' => __( 'Optional longitude to provide location context for more relevant results.', 'mcp-ai-wpoos' ),
				),
				'model'       => array(
					'type'        => 'string',
					'description' => __( 'Gemini model to use (defaults to configured model).', 'mcp-ai-wpoos' ),
				),
				'temperature' => array(
					'type'        => 'number',
					'description' => __( 'Creativity level (0.0-2.0). Lower values are more focused, higher values more creative.', 'mcp-ai-wpoos' ),
					'minimum'     => 0.0,
					'maximum'     => 2.0,
				),
				'timeout'     => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-120).', 'mcp-ai-wpoos' ),
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
				'label'   => __( 'Find local attractions', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to ask about tourist attractions, landmarks, or points of interest in a specific area. The AI will provide detailed information with map context.', 'mcp-ai-wpoos' ),
			),
			array(
				'label'   => __( 'Get dining recommendations', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to find restaurants, cafes, or food options with specific features (e.g., "dog friendly", "outdoor seating", "vegan options") in any location.', 'mcp-ai-wpoos' ),
			),
			array(
				'label'   => __( 'Plan a route', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to ask about routes, directions, or travel planning between locations. The AI provides detailed guidance with real-time map data.', 'mcp-ai-wpoos' ),
			),
			array(
				'label'   => __( 'Explore an area', 'mcp-ai-wpoos' ),
				'payload' => __( 'Use the `gemini_geospatial_query` tool to learn about a neighborhood, city, or region. Get AI-powered summaries of area highlights, demographics, and local culture.', 'mcp-ai-wpoos' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to use geospatial queries.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to use geospatial queries.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		$query = isset( $arguments['query'] ) ? sanitize_textarea_field( $arguments['query'] ) : '';

		if ( empty( $query ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'A query is required for geospatial search.', 'mcp-ai-wpoos' ),
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
				__( 'Geospatial query completed for: %s', 'mcp-ai-wpoos' ),
				$query
			);
		} else {
			$summary = __( 'Geospatial query completed, but no content was returned.', 'mcp-ai-wpoos' );
		}

		$response = array_merge(
			array(
				'message' => $summary, // Chat client.
				'summary' => $summary, // Backward compatibility.
			),
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

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'geospatial_location',

			'pattern_compatibility' => array( 'event_driven' ),

			'profession_tags'       => array( 'urban_planner', 'geographer' ),

			'risk_level'            => 'info',

		);
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

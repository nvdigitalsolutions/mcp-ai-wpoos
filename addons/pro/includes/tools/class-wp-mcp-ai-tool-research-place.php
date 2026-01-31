<?php
/**
 * Tool for researching places/attractions using AI and web search.
 *
 * Provides comprehensive research about a place, attraction, or business
 * including location data, contact information, hours, and other details.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Research Place Tool
 *
 * Uses AI and web search to research comprehensive information about
 * places, attractions, businesses, and locations.
 */
class WP_MCP_AI_Tool_Research_Place implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'research_place';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Research Place', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Research comprehensive information about a place, attraction, or business using AI and web search. Returns name, description, address, coordinates, contact info, hours, amenities, and other details ready for creating a place entry.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'             => array(
					'type'        => 'string',
					'description' => __( 'The place to research (e.g., "Eiffel Tower Paris", "Central Park New York", "Tokyo Tower")', 'mcp-ai-wpoos-pro' ),
				),
				'include_details'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include detailed information like hours, amenities, and ratings', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'use_google_places' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to use Google Places API for accurate data (if configured)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-credentials',
			'consumes-tokens',
			'external-api',
			'network-dependent',
			'may-timeout',
			'cacheable',
			'read-only',
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions - requires read capability.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to research places.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate required arguments.
		if ( empty( $arguments['query'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'Search query is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$query             = sanitize_text_field( $arguments['query'] );
		$include_details   = isset( $arguments['include_details'] ) ? (bool) $arguments['include_details'] : true;
		$use_google_places = isset( $arguments['use_google_places'] ) ? (bool) $arguments['use_google_places'] : true;

		// Check cache first.
		$cache_key = 'place_research_' . md5( $query );
		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_place_research' );

		if ( false !== $cached && is_array( $cached ) ) {
			$cached['_from_cache'] = true;
			return $cached;
		}

		// Log research start.
		WP_MCP_AI_Logger::log_event(
			'place_research_started',
			'Starting place research',
			array(
				'query'   => $query,
				'user_id' => $user_id,
			)
		);

		// Try Google Places API first if enabled and configured.
		$google_data = null;
		if ( $use_google_places && class_exists( 'WP_MCP_AI_Google_Maps_Client' ) ) {
			$google_data = $this->search_google_places( $query );
		}

		// Build research prompt.
		$prompt = $this->build_research_prompt( $query, $google_data, $include_details );

		// Use AI to research the place.
		$research_result = $this->perform_ai_research( $prompt, $context );

		if ( is_wp_error( $research_result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Place research failed: ' . $research_result->get_error_message(),
				array(
					'query' => $query,
					'error' => $research_result->get_error_code(),
				)
			);
			return $research_result;
		}

		// Parse and validate the research results.
		$place_data = $this->parse_research_results( $research_result, $query, $google_data );

		if ( is_wp_error( $place_data ) ) {
			WP_MCP_AI_Logger::log_error(
				'Failed to parse place research results: ' . $place_data->get_error_message(),
				array(
					'query' => $query,
				)
			);
			return $place_data;
		}

		// Cache the results for 24 hours.
		wp_cache_set( $cache_key, $place_data, 'wp_mcp_ai_place_research', DAY_IN_SECONDS );

		// Log success.
		WP_MCP_AI_Logger::log_event(
			'place_research_completed',
			'Place research completed successfully',
			array(
				'query'      => $query,
				'place_name' => isset( $place_data['name'] ) ? $place_data['name'] : '',
			)
		);

		return $place_data;
	}

	/**
	 * Search Google Places API.
	 *
	 * @param string $query Search query.
	 * @return array|null Google Places data or null if not found.
	 */
	protected function search_google_places( $query ) {
		try {
			$maps_client = new WP_MCP_AI_Google_Maps_Client();
			$result      = $maps_client->search_places( $query );

			if ( ! is_wp_error( $result ) && ! empty( $result['results'][0] ) ) {
				return $result['results'][0];
			}
		} catch ( Exception $e ) {
			// Silently fail - we'll use AI research instead.
			WP_MCP_AI_Logger::log_error(
				'Google Places search failed: ' . $e->getMessage(),
				array( 'query' => $query )
			);
		}

		return null;
	}

	/**
	 * Build the research prompt for AI.
	 *
	 * @param string     $query          Search query.
	 * @param array|null $google_data    Optional Google Places data.
	 * @param bool       $include_details Whether to include detailed info.
	 * @return string Research prompt.
	 */
	protected function build_research_prompt( $query, $google_data, $include_details ) {
		$prompt = sprintf(
			"Research comprehensive information about the following place, attraction, or business:\n\n**Query:** %s\n\n",
			$query
		);

		if ( $google_data ) {
			$prompt .= "**Reference Data from Google Places:**\n";
			$prompt .= wp_json_encode( $google_data, JSON_PRETTY_PRINT ) . "\n\n";
			$prompt .= "Use this as a reference but verify and expand upon it.\n\n";
		}

		$prompt .= "Extract and research the following information:\n\n";
		$prompt .= "1. **Name**: Official name of the place\n";
		$prompt .= "2. **Description**: Comprehensive description (200-500 words) including history, significance, and visitor information\n";
		$prompt .= "3. **Place Type**: Category (e.g., attraction, museum, restaurant, hotel, park, business)\n";
		$prompt .= "4. **Address**: Full street address\n";
		$prompt .= "5. **Location Components**: street, city, state, country, postal_code\n";
		$prompt .= "6. **Coordinates**: latitude and longitude\n";
		$prompt .= "7. **Contact Information**: phone, email, website\n";

		if ( $include_details ) {
			$prompt .= "8. **Business Hours**: Operating hours by day of week\n";
			$prompt .= "9. **Rating**: Average rating (0-5) if available\n";
			$prompt .= "10. **Price Level**: Price level (1-4) if applicable\n";
			$prompt .= "11. **Amenities**: List of features/amenities (e.g., wifi, parking, wheelchair_accessible)\n";
			$prompt .= "12. **Google Place ID**: If available from reference data\n";
		}

		$prompt .= "\n**IMPORTANT**: Return the information in the following JSON format:\n\n";
		$prompt .= "```json\n";
		$prompt .= "{\n";
		$prompt .= '  "name": "Place Name",';
		$prompt .= "\n";
		$prompt .= '  "description": "Detailed description...",';
		$prompt .= "\n";
		$prompt .= '  "place_type": "attraction",';
		$prompt .= "\n";
		$prompt .= '  "address": "Full address",';
		$prompt .= "\n";
		$prompt .= '  "street": "Street address",';
		$prompt .= "\n";
		$prompt .= '  "city": "City",';
		$prompt .= "\n";
		$prompt .= '  "state": "State/Province",';
		$prompt .= "\n";
		$prompt .= '  "country": "Country",';
		$prompt .= "\n";
		$prompt .= '  "postal_code": "ZIP/Postal",';
		$prompt .= "\n";
		$prompt .= '  "latitude": 48.8584,';
		$prompt .= "\n";
		$prompt .= '  "longitude": 2.2945,';
		$prompt .= "\n";
		$prompt .= '  "phone": "+1234567890",';
		$prompt .= "\n";
		$prompt .= '  "email": "email@example.com",';
		$prompt .= "\n";
		$prompt .= '  "website": "https://example.com",';
		$prompt .= "\n";
		$prompt .= '  "rating": 4.5,';
		$prompt .= "\n";
		$prompt .= '  "price_level": 2,';
		$prompt .= "\n";
		$prompt .= '  "business_hours": { "monday": "9:00-17:00", ... },';
		$prompt .= "\n";
		$prompt .= '  "amenities": ["wifi", "parking", "wheelchair_accessible"],';
		$prompt .= "\n";
		$prompt .= '  "google_place_id": "ChIJ...",';
		$prompt .= "\n";
		$prompt .= '  "sources": ["URL1", "URL2"]';
		$prompt .= "\n";
		$prompt .= "}\n";
		$prompt .= "```\n\n";

		$prompt .= 'Use web search to find official and authoritative sources. ';
		$prompt .= "Include source URLs in the 'sources' array. ";
		$prompt .= "If information is not available, use null for that field.\n";

		return $prompt;
	}

	/**
	 * Perform AI research using the plugin's AI capabilities.
	 *
	 * @param string $prompt  Research prompt.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Research results or error.
	 */
	protected function perform_ai_research( $prompt, $context ) {
		// Get a suitable AI model for research.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$provider = $this->get_research_provider( $settings );
		$model    = $this->get_research_model( $provider, $settings );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		if ( is_wp_error( $model ) ) {
			return $model;
		}

		// Build messages array.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful AI assistant that researches places, attractions, and businesses. Always respond with valid JSON matching the requested format. Use web search when available to find accurate and up-to-date information.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);

		// Call the appropriate AI client.
		$client = $this->get_ai_client( $provider, $settings );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Make the API call.
		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.2, // Low temperature for factual information.
				'max_tokens'  => 2000,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract the content from the response.
		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from AI provider.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'content'  => $result['choices'][0]['message']['content'],
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Get the best available provider for research.
	 *
	 * @param array $settings Plugin settings.
	 * @return string|WP_Error Provider name or error.
	 */
	protected function get_research_provider( $settings ) {
		// Prefer OpenAI or Gemini for research tasks (best web search access).
		if ( ! empty( $settings['openai_api_key'] ) ) {
			return 'openai';
		}

		if ( ! empty( $settings['gemini_api_key'] ) ) {
			return 'gemini';
		}

		if ( ! empty( $settings['anthropic_api_key'] ) ) {
			return 'anthropic';
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure OpenAI, Gemini, or Anthropic API keys in plugin settings.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get the best model for research from a provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return string|WP_Error Model identifier or error.
	 */
	protected function get_research_model( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				return ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4o';

			case 'gemini':
				return ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-2.5-flash';

			case 'anthropic':
				return 'claude-sonnet-4-5-20250929';

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'Provider not supported for research: %s', 'mcp-ai-wpoos-pro' ),
						$provider
					)
				);
		}
	}

	/**
	 * Get the appropriate AI client for a provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return object|WP_Error AI client instance or error.
	 */
	protected function get_ai_client( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'OpenAI client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_OpenAI_Client();

			case 'gemini':
				if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Gemini client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Gemini_Client();

			case 'anthropic':
				if ( ! class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_Error(
						'wp_mcp_ai_client_unavailable',
						__( 'Anthropic client not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				return new WP_MCP_AI_Anthropic_Client();

			default:
				return new WP_Error(
					'wp_mcp_ai_unsupported_provider',
					sprintf(
						/* translators: %s: provider name */
						__( 'AI client not available for provider: %s', 'mcp-ai-wpoos-pro' ),
						$provider
					)
				);
		}
	}

	/**
	 * Parse the AI research results into place data format.
	 *
	 * @param array      $research_result AI research results.
	 * @param string     $query           Original search query.
	 * @param array|null $google_data     Optional Google Places data.
	 * @return array|WP_Error Parsed place data or error.
	 */
	protected function parse_research_results( $research_result, $query, $google_data ) {
		$content = $research_result['content'];

		// Extract JSON from markdown code blocks if present.
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} elseif ( preg_match( '/```\s*(.*?)\s*```/s', $content, $matches ) ) {
			$json = $matches[1];
		} else {
			$json = $content;
		}

		// Parse JSON.
		$data = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_parse_error',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse AI response as JSON: %s', 'mcp-ai-wpoos-pro' ),
					json_last_error_msg()
				)
			);
		}

		// Ensure minimum required fields.
		if ( empty( $data['name'] ) ) {
			$data['name'] = $query;
		}

		// Build place data structure.
		$place_data = array(
			'success'           => true,
			'query'             => $query,
			'name'              => sanitize_text_field( $data['name'] ),
			'description'       => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'place_type'        => isset( $data['place_type'] ) ? sanitize_text_field( $data['place_type'] ) : '',
			'address'           => isset( $data['address'] ) ? sanitize_text_field( $data['address'] ) : '',
			'street'            => isset( $data['street'] ) ? sanitize_text_field( $data['street'] ) : '',
			'city'              => isset( $data['city'] ) ? sanitize_text_field( $data['city'] ) : '',
			'state'             => isset( $data['state'] ) ? sanitize_text_field( $data['state'] ) : '',
			'country'           => isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '',
			'postal_code'       => isset( $data['postal_code'] ) ? sanitize_text_field( $data['postal_code'] ) : '',
			'latitude'          => isset( $data['latitude'] ) ? floatval( $data['latitude'] ) : null,
			'longitude'         => isset( $data['longitude'] ) ? floatval( $data['longitude'] ) : null,
			'phone'             => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'email'             => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
			'website'           => isset( $data['website'] ) ? esc_url_raw( $data['website'] ) : '',
			'rating'            => isset( $data['rating'] ) ? floatval( $data['rating'] ) : null,
			'price_level'       => isset( $data['price_level'] ) ? absint( $data['price_level'] ) : null,
			'business_hours'    => isset( $data['business_hours'] ) && is_array( $data['business_hours'] ) ? $data['business_hours'] : array(),
			'amenities'         => isset( $data['amenities'] ) && is_array( $data['amenities'] ) ? array_map( 'sanitize_text_field', $data['amenities'] ) : array(),
			'google_place_id'   => isset( $data['google_place_id'] ) ? sanitize_text_field( $data['google_place_id'] ) : '',
			'sources'           => isset( $data['sources'] ) && is_array( $data['sources'] ) ? array_map( 'esc_url_raw', $data['sources'] ) : array(),
			'researched_at'     => current_time( 'mysql' ),
			'research_model'    => $research_result['model'],
			'research_provider' => $research_result['provider'],
		);

		return $place_data;
	}
}

<?php
/**
 * Google Maps Platform API client wrapper.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-google-maps-client.php` (behaviour-preserving;
 * base copy is retained permanently — ecosystem port plan D-NOBASE).
 *
 * Endpoints, query building, validation order, error codes, and response
 * normalization are byte-identical. Decoupling (documented, additive):
 *
 * - `get_api_key()` — monolith installs read the base plugin's settings
 *   (`google_maps_api_key` in `wp_mcp_ai_settings`, falling back to the
 *   base credential resolver); standalone installs read the content-graph
 *   settings store (`google_maps_api_key` in
 *   `nvoos_content_graph_settings`, falling back to the CG-AI
 *   `CredentialResolver` for env/constant support).
 * - Logging — monolith installs forward to the base `WP_MCP_AI_Logger`
 *   (byte-identical events); standalone installs skip logging until the
 *   CG-AI audit-logger port (Wave D4).
 * - Transport errors — monolith installs delegate to
 *   `WP_MCP_AI_HTTP::prepare_transport_error()` (byte-identical,
 *   including connection-refused/timeout enrichment); standalone installs
 *   replicate the default branch (original error preserved in
 *   `data.error`). The full enrichment joins when the HTTP utility is
 *   ported (tracked).
 *
 * @package NvoosContentGraphAi\Provider
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Provider;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper around Google Maps Platform APIs (Places, Geocoding, etc.).
 *
 * Maintains separation of concerns: this class handles ONLY API
 * communication. WordPress integration and capability checks are handled
 * by tool classes.
 *
 * @since 1.1.0
 */
class GoogleMapsClient {

	const GEOCODING_API_ENDPOINT      = 'https://maps.googleapis.com/maps/api/geocode/json';
	const PLACES_API_ENDPOINT         = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
	const PLACES_DETAILS_ENDPOINT     = 'https://maps.googleapis.com/maps/api/place/details/json';
	const PLACES_TEXT_SEARCH_ENDPOINT = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
	const PLACES_AUTOCOMPLETE_ENDPOINT = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';

	/**
	 * Retrieve the configured API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			$key      = isset( $settings['google_maps_api_key'] ) ? $settings['google_maps_api_key'] : '';

			if ( empty( $key ) && class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
				$key = \WP_MCP_AI_Credential_Resolver::get_api_key( 'google_maps' ) ?? '';
			}

			return $key;
		}

		$key = (string) CoreBridge::instance()->settings->get( 'google_maps_api_key', '' );

		if ( '' === $key ) {
			$resolved = CredentialResolver::getApiKey( 'google_maps' );
			if ( null !== $resolved ) {
				$key = $resolved;
			}
		}

		return $key;
	}

	/**
	 * Geocode an address to latitude/longitude coordinates.
	 *
	 * @param string $address Address to geocode.
	 * @param array  $options Additional options (timeout, language, region).
	 * @return array|\WP_Error Geocoding result or error.
	 */
	public function geocode( $address, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_google_maps_api_key',
				__( 'No Google Maps API key has been configured.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_google_maps_api_key' => __( 'Add a Google Maps API key in the NV oOS settings.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$address = sanitize_text_field( $address );

		if ( empty( $address ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_address',
				__( 'An address must be supplied for geocoding.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		$query_args = array(
			'address' => $address,
			'key'     => $api_key,
		);

		if ( isset( $options['language'] ) ) {
			$query_args['language'] = sanitize_text_field( $options['language'] );
		}

		if ( isset( $options['region'] ) ) {
			$query_args['region'] = sanitize_text_field( $options['region'] );
		}

		$url = add_query_arg( $query_args, self::GEOCODING_API_ENDPOINT );

		$request_args = array(
			'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
		);

		static::log( 'google_maps_geocode_request', 'Sending geocoding request to Google Maps.', array( 'address' => $address ) );

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			static::log_error( 'Google Maps geocoding request failed.', array( 'error' => $response->get_error_message() ) );

			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Google Maps API request failed to complete.', 'nvoos-content-graph-ai' ),
				__( 'Google Maps', 'nvoos-content-graph-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			static::log_error( 'Failed to decode Google Maps geocoding response.', array( 'body' => $body ) );

			return new \WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Google Maps API returned malformed JSON.', 'nvoos-content-graph-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error_message'] ) ? $decoded['error_message'] : __( 'Unexpected response from Google Maps.', 'nvoos-content-graph-ai' );

			static::log_error(
				'Google Maps returned an error response for geocoding.',
				array(
					'code'   => $code,
					'status' => isset( $decoded['status'] ) ? $decoded['status'] : 'UNKNOWN',
					'body'   => $decoded,
				)
			);

			return new \WP_Error(
				'wp_mcp_ai_api_error',
				$error_message,
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		if ( isset( $decoded['status'] ) && 'OK' !== $decoded['status'] ) {
			/* translators: %s: Google Maps API status code */
			$error_message = isset( $decoded['error_message'] ) ? $decoded['error_message'] : sprintf( __( 'Google Maps status: %s', 'nvoos-content-graph-ai' ), $decoded['status'] );

			return new \WP_Error(
				'wp_mcp_ai_geocoding_failed',
				$error_message,
				array(
					'status' => $decoded['status'],
					'body'   => $decoded,
				)
			);
		}

		static::log( 'google_maps_geocode_response', 'Google Maps geocoding completed successfully.' );

		return $this->normalize_geocoding_response( $decoded );
	}

	/**
	 * Reverse geocode coordinates to an address.
	 *
	 * @param float $latitude  Latitude coordinate.
	 * @param float $longitude Longitude coordinate.
	 * @param array $options   Additional options (timeout, language, result_type).
	 * @return array|\WP_Error Reverse geocoding result or error.
	 */
	public function reverse_geocode( $latitude, $longitude, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_google_maps_api_key',
				__( 'No Google Maps API key has been configured.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_google_maps_api_key' => __( 'Add a Google Maps API key in the NV oOS settings.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$latitude  = floatval( $latitude );
		$longitude = floatval( $longitude );

		$latlng = $latitude . ',' . $longitude;

		$query_args = array(
			'latlng' => $latlng,
			'key'    => $api_key,
		);

		if ( isset( $options['language'] ) ) {
			$query_args['language'] = sanitize_text_field( $options['language'] );
		}

		if ( isset( $options['result_type'] ) ) {
			$query_args['result_type'] = sanitize_text_field( $options['result_type'] );
		}

		$url = add_query_arg( $query_args, self::GEOCODING_API_ENDPOINT );

		$request_args = array(
			'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
		);

		static::log(
			'google_maps_reverse_geocode_request',
			'Sending reverse geocoding request to Google Maps.',
			array(
				'latitude'  => $latitude,
				'longitude' => $longitude,
			)
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			static::log_error( 'Google Maps reverse geocoding request failed.', array( 'error' => $response->get_error_message() ) );

			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Google Maps API request failed to complete.', 'nvoos-content-graph-ai' ),
				__( 'Google Maps', 'nvoos-content-graph-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			static::log_error( 'Failed to decode Google Maps reverse geocoding response.', array( 'body' => $body ) );

			return new \WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Google Maps API returned malformed JSON.', 'nvoos-content-graph-ai' ) );
		}

		if ( $code < 200 || $code >= 300 || ( isset( $decoded['status'] ) && 'OK' !== $decoded['status'] ) ) {
			$error_message = isset( $decoded['error_message'] ) ? $decoded['error_message'] : __( 'Reverse geocoding failed.', 'nvoos-content-graph-ai' );

			return new \WP_Error(
				'wp_mcp_ai_reverse_geocoding_failed',
				$error_message,
				array(
					'status' => isset( $decoded['status'] ) ? $decoded['status'] : 'UNKNOWN',
					'body'   => $decoded,
				)
			);
		}

		static::log( 'google_maps_reverse_geocode_response', 'Google Maps reverse geocoding completed successfully.' );

		return $this->normalize_geocoding_response( $decoded );
	}

	/**
	 * Search for nearby places.
	 *
	 * @param float $latitude  Latitude coordinate.
	 * @param float $longitude Longitude coordinate.
	 * @param array $options   Additional options (radius, type, keyword, timeout).
	 * @return array|\WP_Error Places search result or error.
	 */
	public function nearby_search( $latitude, $longitude, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_google_maps_api_key',
				__( 'No Google Maps API key has been configured.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_google_maps_api_key' => __( 'Add a Google Maps API key in the NV oOS settings.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$latitude  = floatval( $latitude );
		$longitude = floatval( $longitude );

		$location = $latitude . ',' . $longitude;
		$radius   = isset( $options['radius'] ) ? absint( $options['radius'] ) : 1500; // Default 1.5km radius.

		$query_args = array(
			'location' => $location,
			'radius'   => $radius,
			'key'      => $api_key,
		);

		if ( isset( $options['type'] ) ) {
			$query_args['type'] = sanitize_text_field( $options['type'] );
		}

		if ( isset( $options['keyword'] ) ) {
			$query_args['keyword'] = sanitize_text_field( $options['keyword'] );
		}

		$url = add_query_arg( $query_args, self::PLACES_API_ENDPOINT );

		$request_args = array(
			'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
		);

		static::log(
			'google_maps_nearby_search_request',
			'Sending nearby search request to Google Maps.',
			array(
				'location' => $location,
				'radius'   => $radius,
			)
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			static::log_error( 'Google Maps nearby search request failed.', array( 'error' => $response->get_error_message() ) );

			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Google Maps API request failed to complete.', 'nvoos-content-graph-ai' ),
				__( 'Google Maps', 'nvoos-content-graph-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			static::log_error( 'Failed to decode Google Maps nearby search response.', array( 'body' => $body ) );

			return new \WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Google Maps API returned malformed JSON.', 'nvoos-content-graph-ai' ) );
		}

		if ( $code < 200 || $code >= 300 || ( isset( $decoded['status'] ) && 'OK' !== $decoded['status'] && 'ZERO_RESULTS' !== $decoded['status'] ) ) {
			$error_message = isset( $decoded['error_message'] ) ? $decoded['error_message'] : __( 'Nearby search failed.', 'nvoos-content-graph-ai' );

			return new \WP_Error(
				'wp_mcp_ai_places_search_failed',
				$error_message,
				array(
					'status' => isset( $decoded['status'] ) ? $decoded['status'] : 'UNKNOWN',
					'body'   => $decoded,
				)
			);
		}

		static::log( 'google_maps_nearby_search_response', 'Google Maps nearby search completed successfully.' );

		return $this->normalize_places_response( $decoded );
	}

	/**
	 * Search for places using text query.
	 *
	 * @param string $query   Search query text.
	 * @param array  $options Additional options (location, radius, type, timeout).
	 * @return array|\WP_Error Places search result or error.
	 */
	public function text_search( $query, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_google_maps_api_key',
				__( 'No Google Maps API key has been configured.', 'nvoos-content-graph-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_google_maps_api_key' => __( 'Add a Google Maps API key in the NV oOS settings.', 'nvoos-content-graph-ai' ),
					),
				)
			);
		}

		$query = sanitize_text_field( $query );

		if ( empty( $query ) ) {
			return new \WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'A search query must be supplied for text search.', 'nvoos-content-graph-ai' ),
				array( 'status' => 400 )
			);
		}

		$query_args = array(
			'query' => $query,
			'key'   => $api_key,
		);

		if ( isset( $options['location'] ) ) {
			$query_args['location'] = sanitize_text_field( $options['location'] );
		}

		if ( isset( $options['radius'] ) ) {
			$query_args['radius'] = absint( $options['radius'] );
		}

		if ( isset( $options['type'] ) ) {
			$query_args['type'] = sanitize_text_field( $options['type'] );
		}

		$url = add_query_arg( $query_args, self::PLACES_TEXT_SEARCH_ENDPOINT );

		$request_args = array(
			'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30,
		);

		static::log(
			'google_maps_text_search_request',
			'Sending text search request to Google Maps.',
			array( 'query' => $query )
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			static::log_error( 'Google Maps text search request failed.', array( 'error' => $response->get_error_message() ) );

			return static::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Google Maps API request failed to complete.', 'nvoos-content-graph-ai' ),
				__( 'Google Maps', 'nvoos-content-graph-ai' )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			static::log_error( 'Failed to decode Google Maps text search response.', array( 'body' => $body ) );

			return new \WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Google Maps API returned malformed JSON.', 'nvoos-content-graph-ai' ) );
		}

		if ( $code < 200 || $code >= 300 || ( isset( $decoded['status'] ) && 'OK' !== $decoded['status'] && 'ZERO_RESULTS' !== $decoded['status'] ) ) {
			$error_message = isset( $decoded['error_message'] ) ? $decoded['error_message'] : __( 'Text search failed.', 'nvoos-content-graph-ai' );

			return new \WP_Error(
				'wp_mcp_ai_places_text_search_failed',
				$error_message,
				array(
					'status' => isset( $decoded['status'] ) ? $decoded['status'] : 'UNKNOWN',
					'body'   => $decoded,
				)
			);
		}

		static::log( 'google_maps_text_search_response', 'Google Maps text search completed successfully.' );

		return $this->normalize_places_response( $decoded );
	}

	/**
	 * Normalize geocoding API response.
	 *
	 * @param array $decoded Raw decoded API response.
	 * @return array Normalized response.
	 */
	protected function normalize_geocoding_response( array $decoded ) {
		$results = isset( $decoded['results'] ) && is_array( $decoded['results'] ) ? $decoded['results'] : array();

		$normalized_results = array();

		foreach ( $results as $result ) {
			$normalized_results[] = array(
				'formatted_address'  => isset( $result['formatted_address'] ) ? $result['formatted_address'] : '',
				'place_id'           => isset( $result['place_id'] ) ? $result['place_id'] : '',
				'latitude'           => isset( $result['geometry']['location']['lat'] ) ? floatval( $result['geometry']['location']['lat'] ) : 0,
				'longitude'          => isset( $result['geometry']['location']['lng'] ) ? floatval( $result['geometry']['location']['lng'] ) : 0,
				'types'              => isset( $result['types'] ) && is_array( $result['types'] ) ? $result['types'] : array(),
				'address_components' => isset( $result['address_components'] ) && is_array( $result['address_components'] ) ? $result['address_components'] : array(),
			);
		}

		return array(
			'status'  => isset( $decoded['status'] ) ? $decoded['status'] : 'UNKNOWN',
			'results' => $normalized_results,
		);
	}

	/**
	 * Normalize places API response.
	 *
	 * @param array $decoded Raw decoded API response.
	 * @return array Normalized response.
	 */
	protected function normalize_places_response( array $decoded ) {
		$results = isset( $decoded['results'] ) && is_array( $decoded['results'] ) ? $decoded['results'] : array();

		$normalized_results = array();

		foreach ( $results as $place ) {
			$normalized_results[] = array(
				'name'               => isset( $place['name'] ) ? $place['name'] : '',
				'place_id'           => isset( $place['place_id'] ) ? $place['place_id'] : '',
				'formatted_address'  => isset( $place['formatted_address'] ) ? $place['formatted_address'] : ( isset( $place['vicinity'] ) ? $place['vicinity'] : '' ),
				'latitude'           => isset( $place['geometry']['location']['lat'] ) ? floatval( $place['geometry']['location']['lat'] ) : 0,
				'longitude'          => isset( $place['geometry']['location']['lng'] ) ? floatval( $place['geometry']['location']['lng'] ) : 0,
				'rating'             => isset( $place['rating'] ) ? floatval( $place['rating'] ) : null,
				'user_ratings_total' => isset( $place['user_ratings_total'] ) ? absint( $place['user_ratings_total'] ) : 0,
				'types'              => isset( $place['types'] ) && is_array( $place['types'] ) ? $place['types'] : array(),
				'price_level'        => isset( $place['price_level'] ) ? absint( $place['price_level'] ) : null,
				'business_status'    => isset( $place['business_status'] ) ? $place['business_status'] : '',
			);
		}

		return array(
			'status'          => isset( $decoded['status'] ) ? $decoded['status'] : 'UNKNOWN',
			'results'         => $normalized_results,
			'next_page_token' => isset( $decoded['next_page_token'] ) ? $decoded['next_page_token'] : null,
		);
	}

	/**
	 * Log an event through the base plugin's logger (monolith only).
	 *
	 * Standalone installs skip logging until the CG-AI audit logger lands
	 * (Wave D4).
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional event data.
	 * @return void
	 */
	protected static function log( $event, $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $event, $message, $data );
		}
	}

	/**
	 * Log an error through the base plugin's logger (monolith only).
	 *
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional error data.
	 * @return void
	 */
	protected static function log_error( $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $data );
		}
	}

	/**
	 * Prepare a transport error for return.
	 *
	 * Monolith installs delegate to the base plugin's `WP_MCP_AI_HTTP`
	 * (byte-identical, including connection-refused/timeout enrichment);
	 * standalone installs replicate the default branch — the original
	 * error is preserved under `data.error`. The full enrichment joins
	 * when the HTTP utility is ported (tracked).
	 *
	 * @param \WP_Error $transport_error Transport-level error.
	 * @param string    $default_code    Fallback error code.
	 * @param string    $default_message Fallback error message.
	 * @param string    $service_label   Human-readable service name.
	 * @return \WP_Error
	 */
	protected static function prepare_transport_error( $transport_error, $default_code, $default_message, $service_label = '' ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_HTTP' ) ) {
			return \WP_MCP_AI_HTTP::prepare_transport_error(
				$transport_error,
				$default_code,
				$default_message,
				$service_label
			);
		}

		if ( ! $transport_error instanceof \WP_Error ) {
			return new \WP_Error( $default_code, $default_message );
		}

		return new \WP_Error(
			$default_code,
			$default_message,
			array( 'error' => $transport_error )
		);
	}
}

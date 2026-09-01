<?php
/**
 * Google Maps client port tests (Wave D2b).
 *
 * Characterization suite for `NvoosContentGraphAi\Provider\GoogleMapsClient`.
 * Assertions mirror the base plugin's
 * `tests/test-google-maps-client.php` behaviour: endpoint constants,
 * missing-key and missing-input errors, query-string building, transport
 * error shape, JSON decode failures, API status handling, and response
 * normalization. Requests are intercepted with the standard
 * `pre_http_request` filter so no real network I/O occurs.
 *
 * Matrix note: `get_api_key()` reads the base settings option in monolith
 * runs and the content-graph settings store in standalone runs; tests
 * seed the store appropriate to the active matrix (detected via
 * `defined( 'WP_MCP_AI_PATH' )`).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Provider\GoogleMapsClient;

/**
 * Test double pinning the API key in both matrices.
 */
class Testable_Google_Maps_Client extends GoogleMapsClient {

	public function get_api_key() {
		return 'gmaps-test-key';
	}
}

/**
 * @group provider
 */
class Test_Google_Maps_Client extends \WP_UnitTestCase {

	private GoogleMapsClient $client;

	public function setUp(): void {
		parent::setUp();

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );
		\NvoosContentGraphAi\Adapter\CredentialResolver::clearCache();

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$this->client = new GoogleMapsClient();
	}

	public function tearDown(): void {
		$this->remove_http_intercept();

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );
		\NvoosContentGraphAi\Adapter\CredentialResolver::clearCache();

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		parent::tearDown();
	}

	/**
	 * Intercept the next wp_remote_get() call with a fixed response.
	 *
	 * @param mixed $response Response array or WP_Error to return.
	 */
	private function intercept_http( $response ): void {
		\add_filter(
			'pre_http_request',
			static function () use ( $response ) {
				return $response;
			},
			10
		);
	}

	private function remove_http_intercept(): void {
		\remove_all_filters( 'pre_http_request' );
	}

	public function test_endpoint_constants_byte_identical(): void {
		$this->assertSame( 'https://maps.googleapis.com/maps/api/geocode/json', GoogleMapsClient::GEOCODING_API_ENDPOINT );
		$this->assertSame( 'https://maps.googleapis.com/maps/api/place/nearbysearch/json', GoogleMapsClient::PLACES_API_ENDPOINT );
		$this->assertSame( 'https://maps.googleapis.com/maps/api/place/details/json', GoogleMapsClient::PLACES_DETAILS_ENDPOINT );
		$this->assertSame( 'https://maps.googleapis.com/maps/api/place/textsearch/json', GoogleMapsClient::PLACES_TEXT_SEARCH_ENDPOINT );
		$this->assertSame( 'https://maps.googleapis.com/maps/api/place/autocomplete/json', GoogleMapsClient::PLACES_AUTOCOMPLETE_ENDPOINT );
	}

	public function test_missing_api_key_errors_on_all_endpoints(): void {
		// No key seeded anywhere → every public method returns the same error.
		$expected = 'wp_mcp_ai_missing_google_maps_api_key';

		$result = $this->client->geocode( '1600 Amphitheatre Parkway' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$result = $this->client->reverse_geocode( 37.42, -122.08 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );

		$result = $this->client->nearby_search( 37.42, -122.08 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );

		$result = $this->client->text_search( 'coffee' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected, $result->get_error_code() );
	}

	public function test_get_api_key_reads_active_store(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: base settings option.
			\update_option( 'wp_mcp_ai_settings', array( 'google_maps_api_key' => 'base-key' ) );
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		} else {
			// Standalone: content-graph settings store.
			\NvoosContentGraphAi\CoreBridge::instance()->settings->set( 'google_maps_api_key', 'cg-key' );
		}

		$expected = defined( 'WP_MCP_AI_PATH' ) ? 'base-key' : 'cg-key';

		$this->assertSame( $expected, $this->client->get_api_key() );
	}

	public function test_geocode_success_normalizes_results(): void {
		$this->intercept_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => \wp_json_encode(
					array(
						'status'  => 'OK',
						'results' => array(
							array(
								'formatted_address'  => '1600 Amphitheatre Parkway, Mountain View, CA',
								'place_id'           => 'ChIJabc',
								'geometry'           => array(
									'location' => array(
										'lat' => 37.4223878,
										'lng' => -122.0841877,
									),
								),
								'types'              => array( 'street_address' ),
								'address_components' => array( array( 'long_name' => '1600' ) ),
							),
						),
					)
				),
			)
		);

		$client = new Testable_Google_Maps_Client();
		$result = $client->geocode(
			'1600 Amphitheatre Parkway',
			array(
				'language' => 'en',
				'region'   => 'us',
				'timeout'  => 45,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'OK', $result['status'] );
		$this->assertCount( 1, $result['results'] );

		$first = $result['results'][0];
		$this->assertSame( '1600 Amphitheatre Parkway, Mountain View, CA', $first['formatted_address'] );
		$this->assertSame( 'ChIJabc', $first['place_id'] );
		$this->assertSame( 37.4223878, $first['latitude'] );
		$this->assertSame( -122.0841877, $first['longitude'] );
		$this->assertSame( array( 'street_address' ), $first['types'] );
		$this->assertCount( 1, $first['address_components'] );
	}

	public function test_geocode_query_args_and_endpoint(): void {
		$captured = array();
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = array(
					'url'      => $url,
					'timeout'  => $args['timeout'] ?? null,
				);

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => \wp_json_encode( array( 'status' => 'OK', 'results' => array() ) ),
				);
			},
			10,
			3
		);

		$client = new Testable_Google_Maps_Client();
		$client->geocode( 'Paris', array( 'language' => 'fr', 'region' => 'fr', 'timeout' => 45 ) );

		$parsed = \wp_parse_url( $captured['url'] );
		\parse_str( (string) ( $parsed['query'] ?? '' ), $query );

		$this->assertSame( GoogleMapsClient::GEOCODING_API_ENDPOINT, $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'] );
		$this->assertSame( 'Paris', $query['address'] );
		$this->assertSame( 'gmaps-test-key', $query['key'] );
		$this->assertSame( 'fr', $query['language'] );
		$this->assertSame( 'fr', $query['region'] );
		$this->assertSame( 45, $captured['timeout'] );
	}

	public function test_geocode_missing_address(): void {
		$client = new Testable_Google_Maps_Client();

		$result = $client->geocode( '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_address', $result->get_error_code() );
	}

	public function test_geocode_malformed_json(): void {
		$this->intercept_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html>not json</html>',
			)
		);

		$client = new Testable_Google_Maps_Client();
		$result = $client->geocode( 'Paris' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_invalid_response', $result->get_error_code() );
	}

	public function test_geocode_status_error(): void {
		$this->intercept_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => \wp_json_encode(
					array(
						'status'         => 'ZERO_RESULTS',
						'error_message'  => 'Nothing found.',
						'results'        => array(),
					)
				),
			)
		);

		$client = new Testable_Google_Maps_Client();
		$result = $client->geocode( 'zzzzzz' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_geocoding_failed', $result->get_error_code() );
		$this->assertSame( 'ZERO_RESULTS', $result->get_error_data()['status'] );
	}

	public function test_reverse_geocode_builds_latlng_query(): void {
		$captured = '';
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => \wp_json_encode( array( 'status' => 'OK', 'results' => array() ) ),
				);
			},
			10,
			3
		);

		$client = new Testable_Google_Maps_Client();
		$client->reverse_geocode( '37.4223878', '-122.0841877', array( 'result_type' => 'street_address' ) );

		$parsed = \wp_parse_url( $captured );
		\parse_str( (string) ( $parsed['query'] ?? '' ), $query );

		$this->assertSame( '37.4223878,-122.0841877', $query['latlng'] );
		$this->assertSame( 'street_address', $query['result_type'] );
		$this->assertSame( 'gmaps-test-key', $query['key'] );
	}

	public function test_nearby_search_defaults_radius_and_accepts_zero_results(): void {
		$captured = '';
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => \wp_json_encode( array( 'status' => 'ZERO_RESULTS', 'results' => array() ) ),
				);
			},
			10,
			3
		);

		$client = new Testable_Google_Maps_Client();
		$result = $client->nearby_search( 37.42, -122.08, array( 'type' => 'cafe', 'keyword' => 'coffee' ) );

		$parsed = \wp_parse_url( $captured );
		\parse_str( (string) ( $parsed['query'] ?? '' ), $query );

		$this->assertSame( '37.42,-122.08', $query['location'] );
		$this->assertSame( '1500', $query['radius'] ); // Default 1.5km.
		$this->assertSame( 'cafe', $query['type'] );
		$this->assertSame( 'coffee', $query['keyword'] );

		// ZERO_RESULTS is a valid outcome for nearby search.
		$this->assertIsArray( $result );
		$this->assertSame( 'ZERO_RESULTS', $result['status'] );
		$this->assertSame( array(), $result['results'] );
	}

	public function test_text_search_query_and_places_normalization(): void {
		$captured = '';
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => \wp_json_encode(
						array(
							'status'          => 'OK',
							'next_page_token' => 'token-1',
							'results'         => array(
								array(
									'name'               => 'Blue Bottle Coffee',
									'place_id'           => 'ChIJxyz',
									'vicinity'           => 'Mint Plaza',
									'geometry'           => array(
										'location' => array(
											'lat' => 37.7833,
											'lng' => -122.4089,
										),
									),
									'rating'             => '4.5',
									'user_ratings_total' => '1200',
									'types'              => array( 'cafe' ),
									'price_level'        => '2',
									'business_status'    => 'OPERATIONAL',
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$client = new Testable_Google_Maps_Client();
		$result = $client->text_search( 'coffee near me', array( 'radius' => 800 ) );

		$parsed = \wp_parse_url( $captured );
		\parse_str( (string) ( $parsed['query'] ?? '' ), $query );

		$this->assertSame( 'coffee near me', $query['query'] );
		$this->assertSame( '800', $query['radius'] );

		$this->assertSame( 'token-1', $result['next_page_token'] );

		$place = $result['results'][0];
		$this->assertSame( 'Blue Bottle Coffee', $place['name'] );
		$this->assertSame( 'Mint Plaza', $place['formatted_address'] ); // vicinity fallback.
		$this->assertSame( 37.7833, $place['latitude'] );
		$this->assertSame( 4.5, $place['rating'] );
		$this->assertSame( 1200, $place['user_ratings_total'] );
		$this->assertSame( 2, $place['price_level'] );
		$this->assertSame( 'OPERATIONAL', $place['business_status'] );
	}

	public function test_transport_error_preserves_original_error(): void {
		$this->intercept_http( new \WP_Error( 'http_request_failed', 'network down' ) );

		$client = new Testable_Google_Maps_Client();
		$result = $client->geocode( 'Paris' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_http_error', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertInstanceOf( \WP_Error::class, $data['error'] );
	}
}

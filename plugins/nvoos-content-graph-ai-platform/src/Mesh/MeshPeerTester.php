<?php
/**
 * Mesh Peer Connection Tester
 *
 * Tests connectivity to mesh peer sites to verify configuration is working.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Mesh;

use NvoosContentGraphAiPlatform\Federation\PeerCpt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles testing connectivity to mesh peer sites.
 */
class MeshPeerTester {

	/**
	 * Test connection to a mesh peer.
	 *
	 * @param array $peer Peer configuration with name, url, api_key.
	 * @return array|\WP_Error Test results or error.
	 */
	public static function test_connection( $peer ) {
		// Validate peer data.
		if ( ! is_array( $peer ) || empty( $peer['url'] ) ) {
			return new \WP_Error(
				'invalid_peer',
				__( 'Invalid peer configuration.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$url     = esc_url_raw( $peer['url'] );
		$api_key = isset( $peer['api_key'] ) ? sanitize_text_field( $peer['api_key'] ) : '';

		// Validate URL format.
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error(
				'invalid_url',
				__( 'Invalid peer URL.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Test 1: Check if site is reachable.
		$reachable = self::test_reachability( $url );
		if ( is_wp_error( $reachable ) ) {
			return $reachable;
		}

		// Test 2: Check well-known endpoint (if federation is enabled).
		$wellknown = self::test_wellknown_endpoint( $url );

		// Test 3: Test MCP endpoint authentication (if API key provided).
		$mcp_auth = null;
		if ( ! empty( $api_key ) ) {
			$mcp_auth = self::test_mcp_authentication( $url, $api_key );
		}

		// Compile results.
		$results = array(
			'success'       => true,
			'url'           => $url,
			'reachable'     => true,
			'wellknown'     => ! is_wp_error( $wellknown ),
			'authenticated' => ! empty( $api_key ) && ! is_wp_error( $mcp_auth ),
			'site_name'     => ! is_wp_error( $wellknown ) && isset( $wellknown['site_name'] ) ? $wellknown['site_name'] : '',
			'capabilities'  => ! is_wp_error( $wellknown ) && isset( $wellknown['capabilities'] ) ? $wellknown['capabilities'] : array(),
			'message'       => __( 'Connection test successful.', 'nvoos-content-graph-ai-platform' ),
			'details'       => array(),
		);

		// Add details about each test.
		$results['details']['reachability'] = array(
			'status'  => 'success',
			'message' => __( 'Site is reachable.', 'nvoos-content-graph-ai-platform' ),
		);

		if ( ! is_wp_error( $wellknown ) ) {
			$results['details']['wellknown'] = array(
				'status'  => 'success',
				'message' => __( 'Federation well-known endpoint accessible.', 'nvoos-content-graph-ai-platform' ),
			);
		} else {
			$results['details']['wellknown'] = array(
				'status'  => 'warning',
				'message' => $wellknown->get_error_message(),
			);
		}

		if ( ! empty( $api_key ) ) {
			if ( ! is_wp_error( $mcp_auth ) ) {
				$results['details']['authentication'] = array(
					'status'  => 'success',
					'message' => __( 'API key authentication successful.', 'nvoos-content-graph-ai-platform' ),
				);
			} else {
				$results['details']['authentication'] = array(
					'status'  => 'error',
					'message' => $mcp_auth->get_error_message(),
				);
				$results['authenticated']             = false;
			}
		} else {
			$results['details']['authentication'] = array(
				'status'  => 'skipped',
				'message' => __( 'No API key provided.', 'nvoos-content-graph-ai-platform' ),
			);
		}

		return $results;
	}

	/**
	 * Test if the peer site is reachable.
	 *
	 * @param string $url Peer URL.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	protected static function test_reachability( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WP-MCP-AI-Mesh-Test/1.0',
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'unreachable',
				sprintf(
					/* translators: %s: error message */
					__( 'Site is not reachable: %s', 'nvoos-content-graph-ai-platform' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 400 ) {
			return new \WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Site returned HTTP status %d', 'nvoos-content-graph-ai-platform' ),
					$status_code
				)
			);
		}

		return true;
	}

	/**
	 * Test the well-known endpoint for federation discovery.
	 *
	 * @param string $url Peer URL.
	 * @return array|\WP_Error Well-known data or error.
	 */
	protected static function test_wellknown_endpoint( $url ) {
		$wellknown_url = trailingslashit( $url ) . '.well-known/ai-peer';

		$response = wp_remote_get(
			$wellknown_url,
			array(
				'timeout'    => 5,
				'user-agent' => 'WP-MCP-AI-Mesh-Test/1.0',
				'sslverify'  => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'wellknown_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Well-known endpoint not accessible: %s', 'nvoos-content-graph-ai-platform' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error(
				'wellknown_not_found',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Well-known endpoint returned status %d', 'nvoos-content-graph-ai-platform' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'wellknown_invalid',
				__( 'Well-known endpoint returned invalid JSON.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $data;
	}

	/**
	 * Test MCP endpoint authentication with API key.
	 *
	 * @param string $url     Peer URL.
	 * @param string $api_key API key for authentication.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	protected static function test_mcp_authentication( $url, $api_key ) {
		// Try to access the MCP assistants endpoint with authentication.
		$mcp_url = trailingslashit( $url ) . 'wp-json/mcp-ai/v1/assistants';

		$response = wp_remote_get(
			$mcp_url,
			array(
				'timeout'    => 10,
				'user-agent' => 'WP-MCP-AI-Mesh-Test/1.0',
				'sslverify'  => true,
				'headers'    => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'mcp_auth_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'MCP authentication failed: %s', 'nvoos-content-graph-ai-platform' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		// 200 = success, 401 = unauthorized (but endpoint exists).
		if ( 200 === $status_code ) {
			return true;
		}

		if ( 401 === $status_code || 403 === $status_code ) {
			$error_message = __( 'API key authentication failed. Please verify the API key is correct.', 'nvoos-content-graph-ai-platform' );

			// Try to get more specific error details from the response body.
			$remote_error = self::extract_error_message( $response );
			if ( ! empty( $remote_error ) ) {
				$error_message = $remote_error;
			}

			return new \WP_Error(
				'mcp_auth_invalid',
				$error_message
			);
		}

		if ( 404 === $status_code ) {
			return new \WP_Error(
				'mcp_not_found',
				__( 'MCP endpoint not found. The remote site may not have the plugin installed.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// For any other error status codes, try to extract details from the response.
		$error_message = sprintf(
			/* translators: %d: HTTP status code */
			__( 'MCP endpoint returned status %d', 'nvoos-content-graph-ai-platform' ),
			$status_code
		);

		// Try to get more specific error details from the response body.
		$remote_error = self::extract_error_message( $response );
		if ( ! empty( $remote_error ) ) {
			$error_message .= ': ' . $remote_error;
		}

		return new \WP_Error(
			'mcp_error',
			$error_message
		);
	}

	/**
	 * Extract and sanitize error message from remote API response.
	 *
	 * @param array|\WP_Error $response HTTP response from wp_remote_get.
	 * @return string Sanitized error message or empty string.
	 */
	protected static function extract_error_message( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return '';
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['message'] ) ) {
			return '';
		}

		// Sanitize the error message to prevent XSS attacks.
		return sanitize_text_field( $data['message'] );
	}

	/**
	 * Update mesh peer CPT with test results.
	 *
	 * @param string $mesh_peer_id Mesh peer ID.
	 * @param array  $test_results Test results.
	 */
	public static function update_peer_test_status( $mesh_peer_id, $test_results ) {
		// Find the ai_peer CPT post for this mesh peer.
		$query = new \WP_Query(
			array(
				'post_type'      => PeerCpt::POST_TYPE,
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary for finding mesh peer.
				'meta_query'     => array(
					array(
						'key'   => '_wp_mcp_ai_mesh_peer_id',
						'value' => $mesh_peer_id,
					),
				),
				'fields'         => 'ids',
			)
		);

		if ( ! $query->have_posts() ) {
			return;
		}

		$post_id = $query->posts[0];

		// Update health status based on test results.
		if ( isset( $test_results['success'] ) && $test_results['success'] ) {
			if ( isset( $test_results['authenticated'] ) && $test_results['authenticated'] ) {
				update_post_meta( $post_id, PeerCpt::META_HEALTH_STATUS, 'healthy' );
			} else {
				update_post_meta( $post_id, PeerCpt::META_HEALTH_STATUS, 'degraded' );
			}
		} else {
			update_post_meta( $post_id, PeerCpt::META_HEALTH_STATUS, 'down' );
		}

		// Store last test timestamp.
		update_post_meta( $post_id, PeerCpt::META_LAST_VERIFIED, current_time( 'mysql', true ) );

		// Store test result details.
		update_post_meta( $post_id, '_wp_mcp_ai_last_test_result', wp_json_encode( $test_results ) );
	}
}

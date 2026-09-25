<?php
/**
 * Higgsfield API client — shared lifecycle for all Higgsfield provider tools.
 *
 * Higgsfield (https://docs.higgsfield.ai) exposes one authenticated,
 * asynchronous API for its curated catalog of generative video and image
 * models. Every workflow follows the same lifecycle (industry-standard
 * submit-and-poll, as used by fal.ai / Replicate):
 *
 *   1. POST a model endpoint  → { status: "queued", request_id, status_url, cancel_url }
 *   2. GET  /requests/{id}/status until a terminal state
 *      (completed | failed | nsfw | canceled; queued/in_progress are active)
 *   3. POST /requests/{id}/cancel for queued work (202 = canceled, 400 = started)
 *   4. Download outputs immediately — provider retention is ~7 days.
 *
 * Authentication: `Authorization: Key {KEY_ID}:{KEY_SECRET}` (two-part
 * credential). Credentials resolve from NV oOS settings
 * (higgsfield_api_key_id / higgsfield_api_key_secret), then env vars /
 * PHP constants (HIGGSFIELD_API_KEY_ID / HIGGSFIELD_API_KEY_SECRET, or a
 * combined HIGGSFIELD_API_KEY with `ID:SECRET`).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for the Higgsfield generation API.
 */
class WP_MCP_AI_Higgsfield_Client {

	const API_BASE = 'https://api.higgsfield.ai';

	/**
	 * Terminal request states per the Higgsfield OpenAPI spec.
	 */
	const TERMINAL_STATUSES = array( 'completed', 'failed', 'nsfw', 'canceled' );

	/**
	 * Active (non-terminal) request states.
	 */
	const ACTIVE_STATUSES = array( 'queued', 'in_progress' );

	/**
	 * Resolve Higgsfield credentials from settings, environment, or constants.
	 *
	 * Priority: NV oOS settings → environment variables → PHP constants.
	 * Also accepts a combined `HIGGSFIELD_API_KEY` env/constant in
	 * `KEY_ID:KEY_SECRET` form.
	 *
	 * @return array{key_id: string, secret: string}
	 */
	public function get_credentials() {
		$key_id = '';
		$secret = '';

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( ! empty( $settings['higgsfield_api_key_id'] ) ) {
				$key_id = sanitize_text_field( $settings['higgsfield_api_key_id'] );
			}
			if ( ! empty( $settings['higgsfield_api_key_secret'] ) ) {
				$secret = sanitize_text_field( $settings['higgsfield_api_key_secret'] );
			}
		}

		// Environment variable fallbacks.
		if ( '' === $key_id ) {
			$key_id = (string) getenv( 'HIGGSFIELD_API_KEY_ID' );
		}
		if ( '' === $secret ) {
			$secret = (string) getenv( 'HIGGSFIELD_API_KEY_SECRET' );
		}

		// PHP constant fallbacks.
		if ( '' === $key_id && defined( 'HIGGSFIELD_API_KEY_ID' ) ) {
			$key_id = (string) constant( 'HIGGSFIELD_API_KEY_ID' );
		}
		if ( '' === $secret && defined( 'HIGGSFIELD_API_KEY_SECRET' ) ) {
			$secret = (string) constant( 'HIGGSFIELD_API_KEY_SECRET' );
		}

		// Combined credential (KEY_ID:KEY_SECRET) via env or constant.
		if ( '' === $key_id || '' === $secret ) {
			$combined = '';
			if ( getenv( 'HIGGSFIELD_API_KEY' ) ) {
				$combined = (string) getenv( 'HIGGSFIELD_API_KEY' );
			} elseif ( defined( 'HIGGSFIELD_API_KEY' ) ) {
				$combined = (string) constant( 'HIGGSFIELD_API_KEY' );
			}

			if ( '' !== $combined && false !== strpos( $combined, ':' ) ) {
				$parts  = explode( ':', $combined, 2 );
				$key_id = trim( $parts[0] );
				$secret = trim( $parts[1] );
			}
		}

		/**
		 * Filter the resolved Higgsfield API credentials.
		 *
		 * @param array $credentials Associative array with 'key_id' and 'secret'.
		 */
		return apply_filters(
			'wp_mcp_ai_higgsfield_credentials',
			array(
				'key_id' => $key_id,
				'secret' => $secret,
			)
		);
	}

	/**
	 * Build the Authorization and Content-Type headers for the API.
	 *
	 * @return array Headers, or empty array when credentials are incomplete.
	 */
	public function get_auth_headers() {
		$credentials = $this->get_credentials();
		$key_id      = isset( $credentials['key_id'] ) ? (string) $credentials['key_id'] : '';
		$secret      = isset( $credentials['secret'] ) ? (string) $credentials['secret'] : '';

		if ( '' === $key_id || '' === $secret ) {
			return array();
		}

		return array(
			'Authorization' => 'Key ' . $key_id . ':' . $secret,
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Build the status URL for a request.
	 *
	 * @param string $request_id Higgsfield request ID (UUID).
	 * @return string Status endpoint URL.
	 */
	public function build_status_url( $request_id ) {
		return self::API_BASE . '/requests/' . rawurlencode( $request_id ) . '/status';
	}

	/**
	 * Build the cancel URL for a request.
	 *
	 * @param string $request_id Higgsfield request ID (UUID).
	 * @return string Cancel endpoint URL.
	 */
	public function build_cancel_url( $request_id ) {
		return self::API_BASE . '/requests/' . rawurlencode( $request_id ) . '/cancel';
	}

	/**
	 * Submit a generation request to a model endpoint.
	 *
	 * @param string $endpoint_path Endpoint path relative to the API base
	 *                              (e.g. '/higgsfield/cinema-studio/4.0').
	 * @param array  $payload       JSON-serialisable request body.
	 * @param int    $timeout       HTTP timeout in seconds.
	 * @return array|WP_Error Submission handle {status, request_id, status_url, cancel_url} or error.
	 */
	public function submit_request( $endpoint_path, array $payload, $timeout = 60 ) {
		$headers = $this->get_auth_headers();
		if ( empty( $headers ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'No Higgsfield API credentials have been configured. Add your Key ID and Secret in the NV oOS provider settings or define HIGGSFIELD_API_KEY_ID and HIGGSFIELD_API_KEY_SECRET.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_post(
			self::API_BASE . $endpoint_path,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['detail'] ) ? $data['detail'] : __( 'Higgsfield API request failed.', 'mcp-ai-wpoos' );

			return new WP_Error(
				'wp_mcp_ai_higgsfield_error',
				$message,
				array(
					'status'   => $code,
					'response' => $data,
				)
			);
		}

		if ( empty( $data['request_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_invalid_response',
				__( 'Higgsfield returned an unexpected response format. Expected a request_id.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'status'     => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'queued',
			'request_id' => sanitize_text_field( $data['request_id'] ),
			'status_url' => isset( $data['status_url'] ) ? esc_url_raw( $data['status_url'], array( 'https' ) ) : $this->build_status_url( $data['request_id'] ),
			'cancel_url' => isset( $data['cancel_url'] ) ? esc_url_raw( $data['cancel_url'], array( 'https' ) ) : $this->build_cancel_url( $data['request_id'] ),
		);
	}

	/**
	 * Retrieve the current state of a generation request.
	 *
	 * @param string $request_id Higgsfield request ID (UUID).
	 * @return array|WP_Error Status data (status, request_id, error, outputs) or error.
	 */
	public function get_request_status( $request_id ) {
		$headers = $this->get_auth_headers();
		if ( empty( $headers ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'No Higgsfield API credentials have been configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_get(
			$this->build_status_url( $request_id ),
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['detail'] ) ? $data['detail'] : __( 'Failed to check request status.', 'mcp-ai-wpoos' );

			return new WP_Error(
				'wp_mcp_ai_higgsfield_status_error',
				$message,
				array(
					'status'     => $code,
					'request_id' => $request_id,
				)
			);
		}

		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_invalid_response',
				__( 'Higgsfield returned an unexpected status format.', 'mcp-ai-wpoos' ),
				array(
					'status'     => 500,
					'request_id' => $request_id,
				)
			);
		}

		$data['request_id'] = isset( $data['request_id'] ) ? sanitize_text_field( $data['request_id'] ) : $request_id;
		$data['status']     = sanitize_key( $data['status'] );

		return $data;
	}

	/**
	 * Cancel a queued request that has not started processing.
	 *
	 * @param string $request_id Higgsfield request ID (UUID).
	 * @return true|WP_Error True when canceled (202); error otherwise.
	 */
	public function cancel_request( $request_id ) {
		$headers = $this->get_auth_headers();
		if ( empty( $headers ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'No Higgsfield API credentials have been configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_post(
			$this->build_cancel_url( $request_id ),
			array(
				'headers' => $headers,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 202 === $code ) {
			return true;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( 400 === $code ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_cancel_started',
				isset( $data['detail'] ) ? $data['detail'] : __( 'The request has already started processing and can no longer be canceled.', 'mcp-ai-wpoos' ),
				array(
					'status'     => 400,
					'request_id' => $request_id,
				)
			);
		}

		return new WP_Error(
			'wp_mcp_ai_higgsfield_cancel_failed',
			isset( $data['detail'] ) ? $data['detail'] : __( 'Failed to cancel the request.', 'mcp-ai-wpoos' ),
			array(
				'status'     => $code,
				'request_id' => $request_id,
			)
		);
	}

	/**
	 * Poll a request until it reaches a terminal state or the deadline passes.
	 *
	 * Industry-standard backoff: start at $initial_delay seconds, grow ×1.5 to
	 * a 10-second cap, with 0-1s jitter. Transient failures (network, 5xx)
	 * continue polling; credential (401) and lookup (404) failures abort.
	 *
	 * @param string $request_id    Higgsfield request ID (UUID).
	 * @param int    $timeout       Maximum total wait in seconds.
	 * @param int    $initial_delay Seconds before the first poll (0 in tests).
	 * @return array|WP_Error Outputs {status, request_id, video_url, image_urls, audio_urls} or error.
	 */
	public function wait_for_completion( $request_id, $timeout = 300, $initial_delay = 2 ) {
		$deadline    = time() + $timeout;
		$status_data = array();

		/**
		 * Filter the initial poll delay for Higgsfield request polling.
		 *
		 * @param int    $delay      Seconds before the first status check.
		 * @param string $request_id Higgsfield request ID.
		 */
		$delay = (int) apply_filters( 'wp_mcp_ai_higgsfield_poll_initial_delay', max( 0, (int) $initial_delay ), $request_id );

		while ( time() < $deadline ) {
			if ( $delay > 0 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions -- Expected polling backoff; mirrors the plugin's Sora tool sleep loop.
				sleep( min( $delay, 10 ) );
			}

			$status_data = $this->get_request_status( $request_id );

			if ( is_wp_error( $status_data ) ) {
				$error_data = $status_data->get_error_data();
				$http_code  = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;

				// Credential and lookup errors are permanent — abort polling.
				if ( in_array( $http_code, array( 400, 401, 404 ), true ) ) {
					return $status_data;
				}

				// Transient failure — retry with backoff.
				$delay = min( (int) round( $delay * 1.5 ), 10 ) + 1;
				continue;
			}

			$state = isset( $status_data['status'] ) ? $status_data['status'] : '';

			if ( in_array( $state, self::TERMINAL_STATUSES, true ) ) {
				if ( 'completed' === $state ) {
					return $this->extract_outputs( $status_data );
				}

				return $this->error_for_terminal_state( $status_data );
			}

			// Exponential backoff with 0-1s jitter per Higgsfield polling guidance.
			$delay = min( (int) round( $delay * 1.5 ), 10 ) + wp_rand( 0, 1 );
		}

		return new WP_Error(
			'wp_mcp_ai_higgsfield_timeout',
			__( 'Video generation timed out. The video may still be processing; poll the status endpoint with the request_id.', 'mcp-ai-wpoos' ),
			array(
				'status'      => 504,
				'request_id'  => $request_id,
				'status_url'  => $this->build_status_url( $request_id ),
				'last_status' => isset( $status_data['status'] ) ? $status_data['status'] : '',
			)
		);
	}

	/**
	 * Map a terminal failure state to a WP_Error.
	 *
	 * @param array $status_data Raw status response.
	 * @return WP_Error Provider-appropriate error.
	 */
	private function error_for_terminal_state( array $status_data ) {
		$state      = isset( $status_data['status'] ) ? $status_data['status'] : 'failed';
		$request_id = isset( $status_data['request_id'] ) ? $status_data['request_id'] : '';

		if ( 'nsfw' === $state ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_nsfw',
				__( 'The request was rejected by Higgsfield content moderation.', 'mcp-ai-wpoos' ),
				array(
					'status'     => 422,
					'request_id' => $request_id,
				)
			);
		}

		if ( 'canceled' === $state ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_canceled',
				__( 'The generation request was canceled.', 'mcp-ai-wpoos' ),
				array(
					'status'     => 410,
					'request_id' => $request_id,
				)
			);
		}

		$message = isset( $status_data['error'] ) && is_string( $status_data['error'] ) && '' !== $status_data['error']
			? $status_data['error']
			: __( 'Generation failed.', 'mcp-ai-wpoos' );

		return new WP_Error(
			'wp_mcp_ai_higgsfield_generation_failed',
			$message,
			array(
				'status'     => 500,
				'request_id' => $request_id,
			)
		);
	}

	/**
	 * Extract output media URLs from a completed status response.
	 *
	 * @param array $status_data Raw completed status response.
	 * @return array Normalised outputs.
	 */
	public function extract_outputs( array $status_data ) {
		$video_url  = '';
		$image_urls = array();
		$audio_urls = array();

		if ( isset( $status_data['video'] ) && is_array( $status_data['video'] ) && ! empty( $status_data['video']['url'] ) ) {
			$video_url = esc_url_raw( $status_data['video']['url'], array( 'https' ) );
		}

		if ( isset( $status_data['images'] ) && is_array( $status_data['images'] ) ) {
			foreach ( $status_data['images'] as $image ) {
				if ( is_array( $image ) && ! empty( $image['url'] ) ) {
					$image_urls[] = esc_url_raw( $image['url'], array( 'https' ) );
				}
			}
		}

		if ( isset( $status_data['audios'] ) && is_array( $status_data['audios'] ) ) {
			foreach ( $status_data['audios'] as $audio ) {
				if ( is_array( $audio ) && ! empty( $audio['url'] ) ) {
					$audio_urls[] = esc_url_raw( $audio['url'], array( 'https' ) );
				}
			}
		} elseif ( isset( $status_data['audio'] ) && is_array( $status_data['audio'] ) && ! empty( $status_data['audio']['url'] ) ) {
			$audio_urls[] = esc_url_raw( $status_data['audio']['url'], array( 'https' ) );
		}

		return array(
			'status'     => 'completed',
			'request_id' => isset( $status_data['request_id'] ) ? $status_data['request_id'] : '',
			'video_url'  => $video_url,
			'image_urls' => array_values( array_filter( $image_urls ) ),
			'audio_urls' => array_values( array_filter( $audio_urls ) ),
		);
	}

	/**
	 * Download a media file from a validated https URL.
	 *
	 * @param string $url     Public https URL.
	 * @param int    $timeout HTTP timeout in seconds.
	 * @return string|WP_Error Binary content or error.
	 */
	public function download_file( $url, $timeout = 300 ) {
		$url = esc_url_raw( $url, array( 'https' ) );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_invalid_media_url',
				__( 'Invalid media URL received from API.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_download_failed',
				__( 'Failed to download generated media.', 'mcp-ai-wpoos' ),
				array( 'status' => $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error(
				'wp_mcp_ai_higgsfield_download_empty',
				__( 'Downloaded media is empty.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		return $body;
	}
}

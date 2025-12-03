<?php
/**
 * Tool that publishes TikTok videos using the Open API.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Provides a tool for publishing TikTok videos via the Open API share endpoint.
 */
class WP_MCP_AI_Pro_Tool_Post_Tiktok_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * TikTok Open API endpoint used for uploads.
	 */
	const API_ENDPOINT = 'https://open-api.tiktok.com/share/video/upload/';

	/**
	 * Default timeout for TikTok requests.
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'post_tiktok_video';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Publish TikTok Video', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Publishes a video to TikTok using the official Open API share endpoint.', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'TikTok Open API access token with the video.share scope.', 'wp-mcp-ai-pro' ),
				),
				'open_id'      => array(
					'type'        => 'string',
					'description' => __( 'The Open ID of the TikTok user or business account receiving the video.', 'wp-mcp-ai-pro' ),
				),
				'video_url'    => array(
					'type'        => 'string',
					'description' => __( 'Publicly accessible URL for the video asset.', 'wp-mcp-ai-pro' ),
				),
				'caption'      => array(
					'type'        => 'string',
					'description' => __( 'Optional caption text applied to the published video.', 'wp-mcp-ai-pro' ),
				),
			),
			'required'             => array( 'access_token', 'open_id', 'video_url' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_post_tiktok_video_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to publish TikTok videos.', 'wp-mcp-ai-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai-pro' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';
		$open_id      = isset( $arguments['open_id'] ) ? $this->sanitize_open_id( $arguments['open_id'] ) : '';
		$video_url    = isset( $arguments['video_url'] ) ? $this->sanitize_url( $arguments['video_url'] ) : '';
		$caption      = isset( $arguments['caption'] ) ? $this->sanitize_caption( $arguments['caption'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_tiktok_token', __( 'A valid TikTok access token is required.', 'wp-mcp-ai-pro' ) );
		}

		if ( '' === $open_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_tiktok_open_id', __( 'A valid TikTok Open ID is required.', 'wp-mcp-ai-pro' ) );
		}

		if ( '' === $video_url ) {
			return new WP_Error( 'wp_mcp_ai_missing_tiktok_video_url', __( 'A publicly accessible video URL is required.', 'wp-mcp-ai-pro' ) );
		}

		$payload = array(
			'access_token' => $access_token,
			'open_id'      => $open_id,
			'video_url'    => $video_url,
		);

		if ( '' !== $caption ) {
			$payload['text'] = $caption;
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_tiktok_encoding_error', __( 'Failed to encode the TikTok request payload.', 'wp-mcp-ai-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'tiktok_publish_request',
			'Sending TikTok video publish request.',
			array(
				'open_id'     => $open_id,
				'has_caption' => '' !== $caption,
			)
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_post_tiktok_video_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'TikTok publish request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_tiktok_http_error',
				__( 'The TikTok API request failed to send.', 'wp-mcp-ai-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		$data        = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		$error_code  = isset( $data['error_code'] ) ? (int) $data['error_code'] : 0;
		$description = isset( $data['description'] ) ? $data['description'] : '';

		if ( 200 !== $code || $error_code ) {
			$message = $description ? $description : __( 'TikTok API returned an error.', 'wp-mcp-ai-pro' );

			WP_MCP_AI_Logger::log_error(
				'TikTok publish request was not successful.',
				array(
					'http_code'  => $code,
					'open_id'    => $open_id,
					'error_code' => $error_code,
					'response'   => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_tiktok_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'video_id'   => isset( $data['video_id'] ) ? $data['video_id'] : '',
			'publish_id' => isset( $data['publish_id'] ) ? $data['publish_id'] : '',
			'status'     => isset( $data['status'] ) ? $data['status'] : 'submitted',
		);
	}

	/**
	 * Sanitize the TikTok access token.
	 *
	 * @param string $token Raw access token.
	 * @return string
	 */
	protected function sanitize_access_token( $token ) {
		if ( ! is_string( $token ) ) {
			return '';
		}

		$token = trim( $token );

		if ( '' === $token ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9._\-]/', '', $token );
	}

	/**
	 * Sanitize the TikTok Open ID value.
	 *
	 * @param string $open_id Raw Open ID value.
	 * @return string
	 */
	protected function sanitize_open_id( $open_id ) {
		if ( ! is_string( $open_id ) ) {
			return '';
		}

		$open_id = trim( $open_id );

		if ( '' === $open_id ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9._-]/', '', $open_id );
	}

	/**
	 * Sanitize a caption string.
	 *
	 * @param string $caption Raw caption text.
	 * @return string
	 */
	protected function sanitize_caption( $caption ) {
		if ( ! is_string( $caption ) ) {
			return '';
		}

		$caption = trim( $caption );

		if ( '' === $caption ) {
			return '';
		}

		return sanitize_textarea_field( $caption );
	}

	/**
	 * Sanitize a URL value.
	 *
	 * @param string $url Raw URL value.
	 * @return string
	 */
	protected function sanitize_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}

		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );

		return $sanitized ? $sanitized : '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

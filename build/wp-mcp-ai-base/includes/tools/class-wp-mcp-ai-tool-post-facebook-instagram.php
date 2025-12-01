<?php
/**
 * Tool that publishes Facebook Page and Instagram posts using the Graph API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for publishing content to Facebook Pages and Instagram business accounts.
 */
class WP_MCP_AI_Tool_Post_Facebook_Instagram implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Graph API version used for requests.
	 */
	const GRAPH_VERSION = 'v18.0';

	/**
	 * Default timeout for Graph API calls.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'post_facebook_instagram';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Publish Meta Social Post', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a post on a Facebook Page or Instagram business account using the Meta Graph API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'platform'     => array(
					'type'        => 'string',
					'enum'        => array( 'facebook', 'instagram' ),
					'description' => __( 'Target platform for the post.', 'wp-mcp-ai' ),
				),
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'Meta Graph API access token with publishing permissions.', 'wp-mcp-ai' ),
				),
				'target_id'    => array(
					'type'        => 'string',
					'description' => __( 'Facebook Page ID or Instagram business account ID.', 'wp-mcp-ai' ),
				),
				'message'      => array(
					'type'        => 'string',
					'description' => __( 'Post message used when publishing to Facebook.', 'wp-mcp-ai' ),
				),
				'link'         => array(
					'type'        => 'string',
					'description' => __( 'Optional link to attach to a Facebook post.', 'wp-mcp-ai' ),
				),
				'caption'      => array(
					'type'        => 'string',
					'description' => __( 'Caption for Instagram posts.', 'wp-mcp-ai' ),
				),
				'image_url'    => array(
					'type'        => 'string',
					'description' => __( 'Publicly accessible image URL used for Instagram posts.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'platform', 'access_token', 'target_id' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_post_facebook_instagram_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to publish social posts.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$platform = isset( $arguments['platform'] ) ? sanitize_key( $arguments['platform'] ) : '';

		if ( ! in_array( $platform, array( 'facebook', 'instagram' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_platform', __( 'A valid target platform must be provided.', 'wp-mcp-ai' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_access_token', __( 'An access token is required to publish posts.', 'wp-mcp-ai' ) );
		}

		$target_id = isset( $arguments['target_id'] ) ? $this->sanitize_target_id( $arguments['target_id'] ) : '';

		if ( '' === $target_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_target_id', __( 'A valid target identifier is required.', 'wp-mcp-ai' ) );
		}

		if ( 'facebook' === $platform ) {
			return $this->publish_facebook_post( $target_id, $access_token, $arguments, $context );
		}

		return $this->publish_instagram_post( $target_id, $access_token, $arguments, $context );
	}

	/**
	 * Publish a Facebook Page post using the Graph API.
	 *
	 * @param string $page_id      Facebook Page identifier.
	 * @param string $access_token Access token with publishing permissions.
	 * @param array  $arguments    Tool arguments.
	 * @param array  $context      Request context.
	 * @return array|WP_Error
	 */
	protected function publish_facebook_post( $page_id, $access_token, array $arguments, array $context ) {
		$message = isset( $arguments['message'] ) ? $this->sanitize_message( $arguments['message'] ) : '';
		$link    = isset( $arguments['link'] ) ? $this->sanitize_url( $arguments['link'] ) : '';

		if ( '' === $message && '' === $link ) {
			return new WP_Error( 'wp_mcp_ai_missing_facebook_content', __( 'A message or link must be supplied for Facebook posts.', 'wp-mcp-ai' ) );
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%1$s/%2$s/feed', self::GRAPH_VERSION, rawurlencode( $page_id ) );

		$payload = array(
			'access_token' => $access_token,
		);

		if ( '' !== $message ) {
			$payload['message'] = $message;
		}

		if ( '' !== $link ) {
			$payload['link'] = $link;
		}

		WP_MCP_AI_Logger::log_event(
			'meta_social_publish_request',
			'Sending Facebook Page publish request.',
			array(
				'platform'  => 'facebook',
				'target_id' => $page_id,
				'has_link'  => '' !== $link,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_post_facebook_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Facebook publish request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_facebook_http_error',
				__( 'The Facebook API request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['id'] ) ) {
			$message = __( 'Facebook API returned an error.', 'wp-mcp-ai' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'Facebook publish request was not successful.',
				array(
					'http_code' => $code,
					'target_id' => $page_id,
					'api_error' => isset( $decoded['error'] ) ? $decoded['error'] : array(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_facebook_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'summary'  => sprintf( __( 'Published to %s', 'wp-mcp-ai' ), 'Facebook' ),
			'platform' => 'facebook',
			'post_id'  => $decoded['id'],
		);
	}

	/**
	 * Publish an Instagram post using the Graph API media endpoints.
	 *
	 * @param string $ig_user_id   Instagram business account identifier.
	 * @param string $access_token Access token with publishing permissions.
	 * @param array  $arguments    Tool arguments.
	 * @param array  $context      Request context.
	 * @return array|WP_Error
	 */
	protected function publish_instagram_post( $ig_user_id, $access_token, array $arguments, array $context ) {
		$caption   = isset( $arguments['caption'] ) ? $this->sanitize_message( $arguments['caption'] ) : '';
		$image_url = isset( $arguments['image_url'] ) ? $this->sanitize_url( $arguments['image_url'] ) : '';

		if ( '' === $caption || '' === $image_url ) {
			return new WP_Error( 'wp_mcp_ai_missing_instagram_content', __( 'Instagram posts require both a caption and an image URL.', 'wp-mcp-ai' ) );
		}

		$media_endpoint = sprintf( 'https://graph.facebook.com/%1$s/%2$s/media', self::GRAPH_VERSION, rawurlencode( $ig_user_id ) );

		$media_payload = array(
			'access_token' => $access_token,
			'caption'      => $caption,
			'image_url'    => $image_url,
		);

		WP_MCP_AI_Logger::log_event(
			'meta_social_publish_request',
			'Creating Instagram media container.',
			array(
				'platform'  => 'instagram',
				'target_id' => $ig_user_id,
			)
		);

		$media_response = wp_remote_post(
			$media_endpoint,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_post_instagram_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $media_payload,
			)
		);

		if ( is_wp_error( $media_response ) ) {
			WP_MCP_AI_Logger::log_error( 'Instagram media request failed to send.', array( 'error' => $media_response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_instagram_http_error',
				__( 'The Instagram media request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $media_response )
			);
		}

		$media_code    = wp_remote_retrieve_response_code( $media_response );
		$media_body    = wp_remote_retrieve_body( $media_response );
		$media_decoded = json_decode( $media_body, true );

		if ( null === $media_decoded ) {
			$media_decoded = array();
		}

		if ( 200 !== $media_code || empty( $media_decoded['id'] ) ) {
			$message = __( 'Instagram API returned an error while creating media.', 'wp-mcp-ai' );

			if ( ! empty( $media_decoded['error']['message'] ) ) {
				$message = $media_decoded['error']['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'Instagram media creation failed.',
				array(
					'http_code' => $media_code,
					'target_id' => $ig_user_id,
					'api_error' => isset( $media_decoded['error'] ) ? $media_decoded['error'] : array(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_instagram_api_error',
				esc_html( $message ),
				array(
					'code'     => $media_code,
					'response' => $media_decoded,
				)
			);
		}

		$publish_endpoint = sprintf( 'https://graph.facebook.com/%1$s/%2$s/media_publish', self::GRAPH_VERSION, rawurlencode( $ig_user_id ) );

		$publish_payload = array(
			'access_token' => $access_token,
			'creation_id'  => $media_decoded['id'],
		);

		WP_MCP_AI_Logger::log_event(
			'meta_social_publish_request',
			'Publishing Instagram media container.',
			array(
				'platform'    => 'instagram',
				'target_id'   => $ig_user_id,
				'creation_id' => $media_decoded['id'],
			)
		);

		$publish_response = wp_remote_post(
			$publish_endpoint,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_publish_instagram_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $publish_payload,
			)
		);

		if ( is_wp_error( $publish_response ) ) {
			WP_MCP_AI_Logger::log_error( 'Instagram publish request failed to send.', array( 'error' => $publish_response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_instagram_publish_http_error',
				__( 'The Instagram publish request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $publish_response )
			);
		}

		$publish_code    = wp_remote_retrieve_response_code( $publish_response );
		$publish_body    = wp_remote_retrieve_body( $publish_response );
		$publish_decoded = json_decode( $publish_body, true );

		if ( null === $publish_decoded ) {
			$publish_decoded = array();
		}

		if ( 200 !== $publish_code || empty( $publish_decoded['id'] ) ) {
			$message = __( 'Instagram API returned an error while publishing media.', 'wp-mcp-ai' );

			if ( ! empty( $publish_decoded['error']['message'] ) ) {
				$message = $publish_decoded['error']['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'Instagram media publish failed.',
				array(
					'http_code' => $publish_code,
					'target_id' => $ig_user_id,
					'api_error' => isset( $publish_decoded['error'] ) ? $publish_decoded['error'] : array(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_instagram_publish_error',
				esc_html( $message ),
				array(
					'code'     => $publish_code,
					'response' => $publish_decoded,
				)
			);
		}

		return array(
			'summary'  => sprintf( __( 'Published to %s', 'wp-mcp-ai' ), 'Instagram' ),
			'platform' => 'instagram',
			'post_id'  => $publish_decoded['id'],
		);
	}

	/**
	 * Sanitize the Graph API access token.
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

		return preg_replace( '/[^A-Za-z0-9:_\-|\.]/', '', $token );
	}

	/**
	 * Sanitize a Facebook Page or Instagram business account identifier.
	 *
	 * @param string $target_id Raw identifier value.
	 * @return string
	 */
	protected function sanitize_target_id( $target_id ) {
		if ( ! is_string( $target_id ) ) {
			return '';
		}

		$target_id = trim( $target_id );

		if ( '' === $target_id ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9._-]/', '', $target_id );
	}

	/**
	 * Sanitize a freeform message.
	 *
	 * @param string $message Raw message value.
	 * @return string
	 */
	protected function sanitize_message( $message ) {
		if ( ! is_string( $message ) ) {
			return '';
		}

		$message = trim( $message );

		if ( '' === $message ) {
			return '';
		}

		return sanitize_textarea_field( $message );
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
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

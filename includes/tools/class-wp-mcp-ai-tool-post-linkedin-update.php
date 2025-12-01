<?php
/**
 * Tool that publishes updates to LinkedIn organisations or members.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for publishing LinkedIn UGC posts via the v2 API.
 */
class WP_MCP_AI_Tool_Post_Linkedin_Update implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * LinkedIn API endpoint for creating UGC posts.
	 */
	const API_ENDPOINT = 'https://api.linkedin.com/v2/ugcPosts';

	/**
	 * Default timeout for LinkedIn requests.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'post_linkedin_update';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Publish LinkedIn Update', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a LinkedIn post for a member or organisation via the UGC API.', 'wp-mcp-ai' );
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
					'description' => __( 'OAuth access token authorised for the LinkedIn UGC API.', 'wp-mcp-ai' ),
				),
				'author'       => array(
					'type'        => 'string',
					'description' => __( 'LinkedIn author URN (e.g. urn:li:organization:123456).', 'wp-mcp-ai' ),
				),
				'text'         => array(
					'type'        => 'string',
					'description' => __( 'Main text body for the LinkedIn update.', 'wp-mcp-ai' ),
				),
				'share_url'    => array(
					'type'        => 'string',
					'description' => __( 'Optional URL to attach as share media.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'access_token', 'author', 'text' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_post_linkedin_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to publish LinkedIn updates.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';
		$author       = isset( $arguments['author'] ) ? $this->sanitize_author( $arguments['author'] ) : '';
		$text         = isset( $arguments['text'] ) ? $this->sanitize_text( $arguments['text'] ) : '';
		$share_url    = isset( $arguments['share_url'] ) ? $this->sanitize_url( $arguments['share_url'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_linkedin_token', __( 'A valid LinkedIn OAuth access token is required.', 'wp-mcp-ai' ) );
		}

		if ( '' === $author ) {
			return new WP_Error( 'wp_mcp_ai_missing_linkedin_author', __( 'A valid LinkedIn author URN is required.', 'wp-mcp-ai' ) );
		}

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_linkedin_text', __( 'Post text must be provided for LinkedIn updates.', 'wp-mcp-ai' ) );
		}

		$payload = array(
			'author'          => $author,
			'lifecycleState'  => 'PUBLISHED',
			'specificContent' => array(
				'com.linkedin.ugc.ShareContent' => array(
					'shareCommentary'    => array(
						'text' => $text,
					),
					'shareMediaCategory' => $share_url ? 'ARTICLE' : 'NONE',
				),
			),
			'visibility'      => array(
				'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
			),
		);

		if ( $share_url ) {
			$payload['specificContent']['com.linkedin.ugc.ShareContent']['media'] = array(
				array(
					'status'      => 'READY',
					'originalUrl' => $share_url,
				),
			);
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_linkedin_encoding_error', __( 'Failed to encode the LinkedIn request payload.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'linkedin_publish_request',
			'Sending LinkedIn UGC post request.',
			array(
				'author'    => $author,
				'has_media' => (bool) $share_url,
			)
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'headers' => array(
					'Authorization'             => 'Bearer ' . $access_token,
					'Content-Type'              => 'application/json',
					'X-Restli-Protocol-Version' => '2.0.0',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_post_linkedin_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LinkedIn publish request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_linkedin_http_error',
				__( 'The LinkedIn API request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code && 201 !== $code ) {
			$message = __( 'LinkedIn API returned an error.', 'wp-mcp-ai' );

			if ( ! empty( $decoded['message'] ) ) {
				$message = $decoded['message'];
			} elseif ( ! empty( $decoded['serviceErrorCode'] ) ) {
				/* translators: %d: LinkedIn service error code */
				$message = sprintf( __( 'LinkedIn error code %d returned.', 'wp-mcp-ai' ), (int) $decoded['serviceErrorCode'] );
			}

			WP_MCP_AI_Logger::log_error(
				'LinkedIn publish request was not successful.',
				array(
					'http_code' => $code,
					'author'    => $author,
					'response'  => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_linkedin_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'urn'    => isset( $decoded['id'] ) ? $decoded['id'] : '',
			'status' => isset( $decoded['lifecycleState'] ) ? $decoded['lifecycleState'] : 'PUBLISHED',
		);
	}

	/**
	 * Sanitise the LinkedIn access token.
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
	 * Sanitise the LinkedIn author URN.
	 *
	 * @param string $author Raw author URN.
	 * @return string
	 */
	protected function sanitize_author( $author ) {
		if ( ! is_string( $author ) ) {
			return '';
		}

		$author = trim( $author );

		if ( '' === $author ) {
			return '';
		}

		$author = preg_replace( '/[^A-Za-z0-9:._-]/', '', $author );

		if ( 0 !== strpos( $author, 'urn:li:' ) ) {
			return '';
		}

		return $author;
	}

	/**
	 * Sanitise the update text content.
	 *
	 * @param string $text Raw text content.
	 * @return string
	 */
	protected function sanitize_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		return sanitize_textarea_field( $text );
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

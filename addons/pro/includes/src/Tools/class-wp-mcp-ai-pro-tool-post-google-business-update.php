<?php
/**
 * Tool that publishes updates to Google Business Profile locations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Provides a tool for creating Google Business Profile posts via the My Business API.
 */
class WP_MCP_AI_Pro_Tool_Post_Google_Business_Update implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
	 * Default timeout for Google Business requests.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'post_google_business_update';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Publish Google Business Update', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a local post on a Google Business Profile location via the My Business API.', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token'   => array(
					'type'        => 'string',
					'description' => __( 'OAuth access token authorised for the Business Profile API.', 'wp-mcp-ai-pro' ),
				),
				'location'       => array(
					'type'        => 'string',
					'description' => __( 'Resource name for the location (e.g. accounts/123/locations/456).', 'wp-mcp-ai-pro' ),
				),
				'summary'        => array(
					'type'        => 'string',
					'description' => __( 'Primary text content for the business update.', 'wp-mcp-ai-pro' ),
				),
				'language_code'  => array(
					'type'        => 'string',
					'description' => __( 'BCP 47 language code for the post content.', 'wp-mcp-ai-pro' ),
				),
				'call_to_action' => array(
					'type'        => 'string',
					'description' => __( 'Optional call to action type (e.g. LEARN_MORE, BOOK, ORDER).', 'wp-mcp-ai-pro' ),
				),
				'action_url'     => array(
					'type'        => 'string',
					'description' => __( 'Destination URL for the call to action.', 'wp-mcp-ai-pro' ),
				),
			),
			'required'             => array( 'access_token', 'location', 'summary' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_post_google_business_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to publish Google Business updates.', 'wp-mcp-ai-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai-pro' ) );
		}

		$access_token  = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';
		$location      = isset( $arguments['location'] ) ? $this->sanitize_location( $arguments['location'] ) : '';
		$summary       = isset( $arguments['summary'] ) ? $this->sanitize_summary( $arguments['summary'] ) : '';
		$language_code = isset( $arguments['language_code'] ) ? $this->sanitize_language_code( $arguments['language_code'] ) : 'en';
		$cta           = isset( $arguments['call_to_action'] ) ? $this->sanitize_call_to_action( $arguments['call_to_action'] ) : '';
		$action_url    = isset( $arguments['action_url'] ) ? $this->sanitize_url( $arguments['action_url'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_google_token', __( 'A valid Google OAuth access token is required.', 'wp-mcp-ai-pro' ) );
		}

		if ( '' === $location ) {
			return new WP_Error( 'wp_mcp_ai_missing_google_location', __( 'A valid Business Profile location resource name is required.', 'wp-mcp-ai-pro' ) );
		}

		if ( '' === $summary ) {
			return new WP_Error( 'wp_mcp_ai_missing_google_summary', __( 'A summary must be provided for the business update.', 'wp-mcp-ai-pro' ) );
		}

		$endpoint = sprintf( 'https://mybusiness.googleapis.com/v4/%s/localPosts', $location );

		$payload = array(
			'languageCode' => $language_code,
			'summary'      => $summary,
			'topicType'    => 'STANDARD',
		);

		if ( '' !== $cta && '' !== $action_url ) {
			$payload['callToAction'] = array(
				'actionType' => $cta,
				'url'        => $action_url,
			);
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_google_business_encoding_error', __( 'Failed to encode the Google Business request payload.', 'wp-mcp-ai-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'google_business_publish_request',
			'Sending Google Business Profile post request.',
			array(
				'location'      => $location,
				'has_cta'       => '' !== $cta && '' !== $action_url,
				'language_code' => $language_code,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_post_google_business_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Business publish request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_google_business_http_error',
				__( 'The Google Business API request failed to send.', 'wp-mcp-ai-pro' ),
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
			$message = __( 'Google Business API returned an error.', 'wp-mcp-ai-pro' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'Google Business publish request was not successful.',
				array(
					'http_code' => $code,
					'location'  => $location,
					'api_error' => isset( $decoded['error'] ) ? $decoded['error'] : array(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_google_business_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'name'  => isset( $decoded['name'] ) ? $decoded['name'] : '',
			'state' => isset( $decoded['state'] ) ? $decoded['state'] : '',
		);
	}

	/**
	 * Sanitize the OAuth access token.
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
	 * Sanitise the Business Profile location resource name.
	 *
	 * @param string $location Raw location string.
	 * @return string
	 */
	protected function sanitize_location( $location ) {
		if ( ! is_string( $location ) ) {
			return '';
		}

		$location = trim( $location );

		if ( '' === $location ) {
			return '';
		}

		$location = preg_replace( '/[^A-Za-z0-9\/_-]/', '', $location );
		$location = trim( $location, '/' );

		if ( '' === $location ) {
			return '';
		}

		// Ensure the expected accounts/{accountId}/locations/{locationId} pattern.
		if ( ! preg_match( '#^accounts\/[A-Za-z0-9_-]+\/locations\/[A-Za-z0-9_-]+$#', $location ) ) {
			return '';
		}

		return $location;
	}

	/**
	 * Sanitise the summary text.
	 *
	 * @param string $summary Raw summary.
	 * @return string
	 */
	protected function sanitize_summary( $summary ) {
		if ( ! is_string( $summary ) ) {
			return '';
		}

		$summary = trim( $summary );

		if ( '' === $summary ) {
			return '';
		}

		return sanitize_textarea_field( $summary );
	}

	/**
	 * Sanitise the language code.
	 *
	 * @param string $language_code Raw language code.
	 * @return string
	 */
	protected function sanitize_language_code( $language_code ) {
		if ( ! is_string( $language_code ) ) {
			return 'en';
		}

		$language_code = trim( $language_code );

		if ( '' === $language_code ) {
			return 'en';
		}

		$language_code = preg_replace( '/[^A-Za-z0-9-]/', '', $language_code );

		return $language_code ? strtolower( $language_code ) : 'en';
	}

	/**
	 * Sanitise the call to action type.
	 *
	 * @param string $call_to_action Raw call to action value.
	 * @return string
	 */
	protected function sanitize_call_to_action( $call_to_action ) {
		if ( ! is_string( $call_to_action ) ) {
			return '';
		}

		$call_to_action = trim( $call_to_action );

		if ( '' === $call_to_action ) {
			return '';
		}

		$call_to_action = strtoupper( preg_replace( '/[^A-Z_]/i', '', $call_to_action ) );

		return $call_to_action;
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

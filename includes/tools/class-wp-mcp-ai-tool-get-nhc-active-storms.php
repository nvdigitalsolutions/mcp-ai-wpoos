<?php
/**
 * Tool that retrieves the National Hurricane Center active storms feed.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the JSON summary of active storms from the National Hurricane Center.
 */
class WP_MCP_AI_Tool_Get_NHC_Active_Storms implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const ENDPOINT = 'https://www.nhc.noaa.gov/CurrentStorms.json';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_nhc_active_storms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get NHC Active Storms', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves the National Hurricane Center JSON summary for current active storms.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
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

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view active storm data.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$request_args = apply_filters(
			'wp_mcp_ai_nhc_active_storms_request_args',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => $this->build_user_agent(),
				),
			)
		);

		$response = wp_remote_get( self::ENDPOINT, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_nhc_request_failed',
				__( 'The request to the National Hurricane Center failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_nhc_unexpected_status',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The National Hurricane Center returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					(int) $status_code
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_nhc_invalid_json', __( 'The National Hurricane Center response could not be decoded.', 'wp-mcp-ai' ) );
		}

		$sanitized = $this->sanitize_payload( $decoded );

		return array(
			'summary' => sprintf(
				/* translators: %d: number of active storms */
				__( 'Found %d active storm(s)', 'wp-mcp-ai' ),
				is_countable( $sanitized ) ? count( $sanitized ) : 0
			),
			'storms'  => $sanitized,
		);
	}

	/**
	 * Build a descriptive User-Agent header for the remote request.
	 *
	 * @return string
	 */
	protected function build_user_agent() {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();

		$site_name = is_string( $site_name ) ? sanitize_text_field( $site_name ) : '';
		$site_url  = is_string( $site_url ) ? esc_url_raw( $site_url ) : '';

		if ( '' === $site_name && '' === $site_url ) {
			return 'WP-MCP-AI/1.0 (+https://wordpress.org/)';
		}

		if ( '' === $site_name ) {
			return sprintf( 'WP-MCP-AI/1.0 (+%s)', $site_url );
		}

		if ( '' === $site_url ) {
			return sprintf( 'WP-MCP-AI/1.0 (%s)', $site_name );
		}

		return sprintf( 'WP-MCP-AI/1.0 (%s; +%s)', $site_name, $site_url );
	}

	/**
	 * Recursively sanitize the decoded JSON payload.
	 *
	 * @param mixed $data Decoded JSON payload.
	 *
	 * @return mixed
	 */
	protected function sanitize_payload( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();

			if ( wp_is_numeric_array( $data ) ) {
				foreach ( $data as $value ) {
					$sanitized[] = $this->sanitize_payload( $value );
				}

				return $sanitized;
			}

			foreach ( $data as $key => $value ) {
				$sanitized_key               = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				$sanitized[ $sanitized_key ] = $this->sanitize_payload( $value );
			}

			return $sanitized;
		}

		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}

		if ( is_bool( $data ) ) {
			return (bool) $data;
		}

		if ( is_int( $data ) || is_float( $data ) ) {
			return $data;
		}

		return $data;
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

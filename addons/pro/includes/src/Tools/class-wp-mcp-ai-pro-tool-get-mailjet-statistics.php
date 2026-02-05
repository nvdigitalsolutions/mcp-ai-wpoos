<?php
/**
 * Tool that retrieves Mailjet statistics and metrics.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving Mailjet email statistics.
 */
class WP_MCP_AI_Pro_Tool_Get_Mailjet_Statistics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_ENDPOINT = 'https://api.mailjet.com/v3/REST/statcounters';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_mailjet_statistics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Mailjet Statistics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves email sending statistics and metrics from Mailjet.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'counter_source' => array(
					'type'        => 'string',
					'description' => __( 'Source type to query (APIKey, Campaign, ContactsList, User). Default is APIKey for account-wide stats.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'APIKey', 'Campaign', 'ContactsList', 'User' ),
					'default'     => 'APIKey',
				),
				'counter_timing' => array(
					'type'        => 'string',
					'description' => __( 'Timing period for statistics (Event, Message). Default is Message.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Event', 'Message' ),
					'default'     => 'Message',
				),
				'from_ts'        => array(
					'type'        => 'string',
					'description' => __( 'Start timestamp (UNIX timestamp or ISO 8601 date). Optional.', 'mcp-ai-wpoos-pro' ),
				),
				'to_ts'          => array(
					'type'        => 'string',
					'description' => __( 'End timestamp (UNIX timestamp or ISO 8601 date). Optional.', 'mcp-ai-wpoos-pro' ),
				),
			),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_mailjet_statistics_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view Mailjet statistics.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_key    = isset( $settings['mailjet_api_key'] ) ? trim( $settings['mailjet_api_key'] ) : '';
		$api_secret = isset( $settings['mailjet_api_secret'] ) ? trim( $settings['mailjet_api_secret'] ) : '';

		if ( '' === $api_key || '' === $api_secret ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_missing_credentials',
				__( 'Mailjet API credentials have not been configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build query parameters.
		$params = array(
			'CounterSource' => isset( $arguments['counter_source'] ) ? sanitize_text_field( $arguments['counter_source'] ) : 'APIKey',
			'CounterTiming' => isset( $arguments['counter_timing'] ) ? sanitize_text_field( $arguments['counter_timing'] ) : 'Message',
		);

		if ( ! empty( $arguments['from_ts'] ) ) {
			$params['FromTS'] = sanitize_text_field( $arguments['from_ts'] );
		}

		if ( ! empty( $arguments['to_ts'] ) ) {
			$params['ToTS'] = sanitize_text_field( $arguments['to_ts'] );
		}

		$url = add_query_arg( $params, self::API_ENDPOINT );

		$timeout = $this->resolve_timeout( $settings );

		$request_args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => $timeout,
			'method'  => 'GET',
		);

		$request_args = apply_filters( 'wp_mcp_ai_mailjet_statistics_request_args', $request_args, $params, $arguments, $context, $this );

		WP_MCP_AI_Logger::log_event(
			'mailjet_statistics_request',
			'Retrieving statistics from Mailjet.',
			array(
				'params' => $params,
			)
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Mailjet statistics request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_mailjet_http_error',
				__( 'The Mailjet API request failed to complete.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 200 !== (int) $status_code ) {
			$message_text = __( 'The Mailjet API returned an unexpected status code.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['ErrorMessage'] ) ) {
				$message_text .= ' ' . $decoded['ErrorMessage'];
			}

			return new WP_Error(
				'wp_mcp_ai_mailjet_http_status',
				$message_text,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_invalid_response',
				__( 'Mailjet returned an invalid response payload.', 'mcp-ai-wpoos-pro' ),
				array( 'body' => $body )
			);
		}

		return array(
			'success'    => true,
			'statistics' => isset( $decoded['Data'] ) ? $decoded['Data'] : $decoded,
			'count'      => isset( $decoded['Count'] ) ? absint( $decoded['Count'] ) : 0,
		);
	}

	/**
	 * Resolve the HTTP timeout for Mailjet requests.
	 *
	 * @param array $settings Plugin settings.
	 * @return int
	 */
	protected function resolve_timeout( $settings ) {
		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		if ( $timeout <= 0 ) {
			$timeout = 30;
		}

		return $timeout;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Read-only operation.
			'external-api',         // Calls Mailjet API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

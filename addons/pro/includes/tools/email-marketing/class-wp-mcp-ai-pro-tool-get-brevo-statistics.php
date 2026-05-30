<?php
/**
 * Tool that retrieves email campaign statistics from the Brevo (Sendinblue) platform.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving Brevo email campaign statistics.
 *
 * Brevo (formerly Sendinblue) API docs: https://developers.brevo.com/docs/getting-started
 */
class WP_MCP_AI_Pro_Tool_Get_Brevo_Statistics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_BASE = 'https://api.brevo.com/v3';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_brevo_statistics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Brevo Statistics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves email campaign statistics and transactional email metrics from Brevo.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'Statistics type: campaigns (email campaigns list and stats) or transactional (SMTP email aggregated stats).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'campaigns', 'transactional' ),
					'default'     => 'campaigns',
				),
				'campaign_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional specific campaign ID to fetch detailed statistics for. Only used when type is "campaigns".', 'mcp-ai-wpoos-pro' ),
				),
				'start_date'  => array(
					'type'        => 'string',
					'description' => __( 'Start date filter in YYYY-MM-DD format. Used with transactional type.', 'mcp-ai-wpoos-pro' ),
				),
				'end_date'    => array(
					'type'        => 'string',
					'description' => __( 'End date filter in YYYY-MM-DD format. Used with transactional type.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of campaign results to return (default: 10, max: 500). Only used when type is "campaigns".', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_brevo_statistics_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view Brevo statistics.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_key = isset( $settings['brevo_api_key'] ) ? trim( $settings['brevo_api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_missing_credentials',
				__( 'Brevo API key has not been configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : 'campaigns';

		if ( 'transactional' === $type ) {
			return $this->get_transactional_stats( $arguments, $api_key, $settings );
		}

		// Default: campaigns.
		if ( ! empty( $arguments['campaign_id'] ) ) {
			return $this->get_campaign_report( absint( $arguments['campaign_id'] ), $api_key, $settings );
		}

		return $this->get_campaigns_list( $arguments, $api_key, $settings );
	}

	/**
	 * Fetch the list of email campaigns with basic stats.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function get_campaigns_list( $arguments, $api_key, $settings ) {
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit = min( max( $limit, 1 ), 500 );

		$params = array(
			'type'  => 'classic',
			'limit' => $limit,
		);

		$url = add_query_arg( $params, self::API_BASE . '/emailCampaigns' );

		$timeout = $this->resolve_timeout( $settings );

		$request_args = array(
			'headers' => array(
				'api-key' => $api_key,
				'Accept'  => 'application/json',
			),
			'timeout' => $timeout,
			'method'  => 'GET',
		);

		WP_MCP_AI_Logger::log_event(
			'brevo_campaigns_request',
			'Retrieving email campaigns from Brevo.',
			array( 'limit' => $limit )
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Brevo campaigns request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_brevo_http_error',
				__( 'The Brevo API request failed to complete.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 200 !== (int) $status_code ) {
			$message_text = __( 'The Brevo API returned an unexpected status code.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
				$message_text .= ' ' . $decoded['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_brevo_http_status',
				$message_text,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_invalid_response',
				__( 'Brevo returned an invalid response payload.', 'mcp-ai-wpoos-pro' ),
				array( 'body' => $body )
			);
		}

		return array(
			'success'   => true,
			'campaigns' => isset( $decoded['campaigns'] ) ? $decoded['campaigns'] : array(),
			'count'     => isset( $decoded['count'] ) ? absint( $decoded['count'] ) : 0,
		);
	}

	/**
	 * Fetch detailed report for a single campaign.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $api_key     API key.
	 * @param array  $settings    Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function get_campaign_report( $campaign_id, $api_key, $settings ) {
		$url     = self::API_BASE . '/emailCampaigns/' . $campaign_id . '/sendReport';
		$timeout = $this->resolve_timeout( $settings );

		$request_args = array(
			'headers' => array(
				'api-key' => $api_key,
				'Accept'  => 'application/json',
			),
			'timeout' => $timeout,
			'method'  => 'GET',
		);

		WP_MCP_AI_Logger::log_event(
			'brevo_campaign_report_request',
			'Retrieving campaign report from Brevo.',
			array( 'campaign_id' => $campaign_id )
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Brevo campaign report request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_brevo_http_error',
				__( 'The Brevo API request failed to complete.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 200 !== (int) $status_code ) {
			$message_text = __( 'The Brevo API returned an unexpected status code.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
				$message_text .= ' ' . $decoded['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_brevo_http_status',
				$message_text,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_invalid_response',
				__( 'Brevo returned an invalid response payload.', 'mcp-ai-wpoos-pro' ),
				array( 'body' => $body )
			);
		}

		return array(
			'success'     => true,
			'campaign_id' => $campaign_id,
			'report'      => $decoded,
		);
	}

	/**
	 * Fetch aggregated transactional email statistics.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function get_transactional_stats( $arguments, $api_key, $settings ) {
		$params = array();

		if ( ! empty( $arguments['start_date'] ) ) {
			$params['startDate'] = sanitize_text_field( $arguments['start_date'] );
		}

		if ( ! empty( $arguments['end_date'] ) ) {
			$params['endDate'] = sanitize_text_field( $arguments['end_date'] );
		}

		$url     = add_query_arg( $params, self::API_BASE . '/smtp/statistics/aggregatedReport' );
		$timeout = $this->resolve_timeout( $settings );

		$request_args = array(
			'headers' => array(
				'api-key' => $api_key,
				'Accept'  => 'application/json',
			),
			'timeout' => $timeout,
			'method'  => 'GET',
		);

		WP_MCP_AI_Logger::log_event(
			'brevo_transactional_stats_request',
			'Retrieving transactional stats from Brevo.',
			array( 'params' => $params )
		);

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Brevo transactional stats request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_brevo_http_error',
				__( 'The Brevo API request failed to complete.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( 200 !== (int) $status_code ) {
			$message_text = __( 'The Brevo API returned an unexpected status code.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
				$message_text .= ' ' . $decoded['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_brevo_http_status',
				$message_text,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_invalid_response',
				__( 'Brevo returned an invalid response payload.', 'mcp-ai-wpoos-pro' ),
				array( 'body' => $body )
			);
		}

		return array(
			'success'    => true,
			'statistics' => $decoded,
		);
	}

	/**
	 * Resolve the HTTP timeout for Brevo requests.
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
			'external-api',         // Calls Brevo API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

<?php
/**
 * Social media Pro tool (ecosystem port — Wave F2, social tools batch A).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/social-media/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Provides a tool for querying LinkedIn organizational insights.
 */
class WP_MCP_AI_Pro_Tool_Get_Linkedin_Insights implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
	 * Default timeout for LinkedIn API calls.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Endpoint for share statistics.
	 */
	const SHARE_STATS_ENDPOINT = 'https://api.linkedin.com/v2/organizationalEntityShareStatistics';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_linkedin_insights';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Retrieve LinkedIn Insights', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Fetches share statistics for a LinkedIn organization using the LinkedIn Marketing API.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token'          => array(
					'type'        => 'string',
					'description' => __( 'LinkedIn OAuth access token with organizational read permissions.', 'nvoos-content-graph-pro' ),
				),
				'organization'          => array(
					'type'        => 'string',
					'description' => __( 'The organization URN, for example urn:li:organization:123.', 'nvoos-content-graph-pro' ),
				),
				'timeframe_start'       => array(
					'type'        => 'string',
					'description' => __( 'Optional Unix epoch start boundary in milliseconds.', 'nvoos-content-graph-pro' ),
				),
				'timeframe_end'         => array(
					'type'        => 'string',
					'description' => __( 'Optional Unix epoch end boundary in milliseconds.', 'nvoos-content-graph-pro' ),
				),
				'time_granularity_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional granularity such as DAY, WEEK or MONTH.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'access_token', 'organization' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_get_linkedin_insights_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_linkedin_insights_forbidden', __( 'You do not have permission to request LinkedIn insights.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_linkedin_insights_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-pro' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_linkedin_insights_missing_token', __( 'An OAuth access token is required.', 'nvoos-content-graph-pro' ) );
		}

		$organization = isset( $arguments['organization'] ) ? $this->sanitize_organization( $arguments['organization'] ) : '';

		if ( '' === $organization ) {
			return new WP_Error( 'wp_mcp_ai_linkedin_insights_missing_organization', __( 'A valid organization URN is required.', 'nvoos-content-graph-pro' ) );
		}

		$timeframe_start       = isset( $arguments['timeframe_start'] ) ? $this->sanitize_timestamp( $arguments['timeframe_start'] ) : '';
		$timeframe_end         = isset( $arguments['timeframe_end'] ) ? $this->sanitize_timestamp( $arguments['timeframe_end'] ) : '';
		$time_granularity_type = isset( $arguments['time_granularity_type'] ) ? $this->sanitize_granularity( $arguments['time_granularity_type'] ) : '';

		$query_args = array(
			'q'                    => 'organizationalEntity',
			'organizationalEntity' => $organization,
		);

		$time_interval = array();

		if ( '' !== $timeframe_start || '' !== $timeframe_end ) {
			$time_interval['timeRange'] = array();

			if ( '' !== $timeframe_start ) {
				$time_interval['timeRange']['start'] = (int) $timeframe_start;
			}

			if ( '' !== $timeframe_end ) {
				$time_interval['timeRange']['end'] = (int) $timeframe_end;
			}
		}

		if ( '' !== $time_granularity_type ) {
			$time_interval['timeGranularity'] = $time_granularity_type;
		}

		if ( ! empty( $time_interval ) ) {
			$query_args['timeIntervals'] = array( $time_interval );
		}

		$request_url = add_query_arg( $query_args, self::SHARE_STATS_ENDPOINT );

		WP_MCP_AI_Logger::log_event(
			'linkedin_insights_request',
			'Sending LinkedIn insights request.',
			array(
				'organization' => $organization,
				'has_time'     => ! empty( $time_interval ),
			)
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => apply_filters( 'wp_mcp_ai_get_linkedin_insights_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LinkedIn insights request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_linkedin_insights_http_error',
				__( 'The LinkedIn insights request failed to send.', 'nvoos-content-graph-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			$decoded = array();
		}

		if ( 200 !== $code ) {
			$message = __( 'LinkedIn insights API returned an error.', 'nvoos-content-graph-pro' );

			if ( ! empty( $decoded['message'] ) ) {
				$message = $decoded['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'LinkedIn insights request returned an error.',
				array(
					'http_code'    => $code,
					'organization' => $organization,
					'api_error'    => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_linkedin_insights_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return array(
			'organization' => $organization,
			'statistics'   => isset( $decoded['elements'] ) ? $decoded['elements'] : array(),
		);
	}

	/**
	 * Sanitize the LinkedIn OAuth token.
	 *
	 * @param string $token Raw token.
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
	 * Sanitize an organization URN.
	 *
	 * @param string $organization Raw organization URN.
	 * @return string
	 */
	protected function sanitize_organization( $organization ) {
		if ( ! is_string( $organization ) ) {
			return '';
		}

		$organization = trim( $organization );

		if ( '' === $organization ) {
			return '';
		}

		return preg_replace( '/[^A-Za-z0-9:\-_.]/', '', $organization );
	}

	/**
	 * Sanitize Unix epoch milliseconds.
	 *
	 * @param string $value Raw timestamp value.
	 * @return string
	 */
	protected function sanitize_timestamp( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value = preg_replace( '/[^0-9]/', '', $value );

		return $value;
	}

	/**
	 * Sanitize the requested time granularity.
	 *
	 * @param string $granularity Raw granularity value.
	 * @return string
	 */
	protected function sanitize_granularity( $granularity ) {
		if ( ! is_string( $granularity ) ) {
			return '';
		}

		$granularity = strtoupper( trim( $granularity ) );
		$granularity = preg_replace( '/[^A-Z_]/', '', $granularity );

		return $granularity;
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

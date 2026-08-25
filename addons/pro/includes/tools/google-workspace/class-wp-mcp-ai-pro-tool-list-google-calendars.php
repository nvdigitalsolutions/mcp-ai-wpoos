<?php
/**
 * Tool that lists the Google Calendars the connected account is subscribed to.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 * @since     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/google/class-wp-mcp-ai-google-calendar-credentials.php';

/**
 * Provides an assistant tool that enumerates the calendars on the authorised
 * account's calendar list, so later tools can be pointed at a concrete
 * calendar ID instead of guessing at `primary`.
 */
class WP_MCP_AI_Pro_Tool_List_Google_Calendars implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Capability required before the tool talks to the Calendar API.
	 *
	 * @var string
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Page size used when the caller omits `max_results`.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_RESULTS = 100;

	/**
	 * Upper bound this tool accepts for `max_results`.
	 *
	 * Google's own ceiling for `calendarList.list` is 250 entries per page.
	 *
	 * @var int
	 */
	const MAX_RESULTS_LIMIT = 250;

	/**
	 * Default HTTP timeout for the Calendar request, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_google_calendars';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Google Calendars', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists the Google Calendars the connected account can see, including each calendar ID, display name, time zone, and access role. Use this first to discover the calendar_id required by the other Google Calendar tools instead of assuming "primary".', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Google Calendar connection ID from Remote Sites. When omitted, the connection configured under Tools → Connections → Google Calendar is used.', 'mcp-ai-wpoos-pro' ),
				),
				'max_results'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of calendars to return (1-250). Defaults to 100.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => self::MAX_RESULTS_LIMIT,
					'default'     => self::DEFAULT_MAX_RESULTS,
				),
			),
			'required'             => array(),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_list_google_calendars_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_forbidden', __( 'You do not have permission to list Google Calendars.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// Gate 1: sanitise every caller-supplied value before any logic runs.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$max_results   = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : self::DEFAULT_MAX_RESULTS;

		if ( $max_results < 1 ) {
			$max_results = self::DEFAULT_MAX_RESULTS;
		}

		$max_results = min( $max_results, self::MAX_RESULTS_LIMIT );

		// Clamp through the client so the tool and the transport agree on the
		// bounds Google actually enforces.
		$max_results = WP_MCP_AI_Google_Calendar_Client::clamp_max_results( $max_results );

		$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id, $context, $arguments );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Reading the calendar list needs its own scope: granular consent means
		// an account holding only the events scope cannot enumerate calendars.
		$scope_check = WP_MCP_AI_Google_Calendar_Credentials::require_scope(
			$credentials,
			WP_MCP_AI_Google_Calendar_Scopes::SCOPE_CALENDARLIST_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$timeout = (int) apply_filters( 'wp_mcp_ai_google_calendar_request_timeout', self::DEFAULT_TIMEOUT, $context, $arguments, $this );

		if ( $timeout <= 0 ) {
			$timeout = self::DEFAULT_TIMEOUT;
		}

		$client = WP_MCP_AI_Google_Calendar_Credentials::make_client(
			$credentials,
			array( 'timeout' => $timeout )
		);

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$decoded = $client->list_calendars( array( 'maxResults' => $max_results ) );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$items     = isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : array();
		$calendars = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$calendars[] = $this->format_calendar( $item );
		}

		return array(
			'success'           => true,
			'count'             => count( $calendars ),
			'calendars'         => $calendars,
			'credential_source' => isset( $credentials['source'] ) ? (string) $credentials['source'] : '',
		);
	}

	/**
	 * Reduce a Google calendarList entry to the fields assistants need.
	 *
	 * Google omits `primary` and `selected` entirely when they are false, so
	 * both are normalised to real booleans here rather than passed through.
	 *
	 * @param array $item Raw calendarList entry.
	 * @return array Normalised calendar summary.
	 */
	protected function format_calendar( array $item ) {
		return array(
			'id'               => isset( $item['id'] ) ? sanitize_text_field( (string) $item['id'] ) : '',
			'summary'          => isset( $item['summary'] ) ? sanitize_text_field( (string) $item['summary'] ) : '',
			'description'      => isset( $item['description'] ) ? sanitize_textarea_field( (string) $item['description'] ) : '',
			'time_zone'        => isset( $item['timeZone'] ) ? sanitize_text_field( (string) $item['timeZone'] ) : '',
			'access_role'      => isset( $item['accessRole'] ) ? sanitize_text_field( (string) $item['accessRole'] ) : '',
			'primary'          => ! empty( $item['primary'] ),
			'selected'         => ! empty( $item['selected'] ),
			'background_color' => isset( $item['backgroundColor'] ) ? sanitize_text_field( (string) $item['backgroundColor'] ) : '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Calls Google Calendar API.
			'network-dependent',    // Requires internet connectivity.
			'cacheable',            // Calendar lists change rarely.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

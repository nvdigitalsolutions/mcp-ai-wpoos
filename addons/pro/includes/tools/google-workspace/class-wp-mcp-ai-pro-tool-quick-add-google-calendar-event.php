<?php
/**
 * Tool that creates a Google Calendar event from a natural-language phrase.
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
 * Provides an assistant tool that delegates event parsing to Google's quickAdd
 * endpoint.
 *
 * `events.quickAdd` lets Google interpret a phrase such as
 * "Lunch with Alice Friday at noon" against the calendar's own timezone and
 * locale. That is more reliable than parsing relative dates locally, but the
 * interpretation is opaque: the resolved `start` and `end` are echoed back so
 * the caller can confirm Google understood the phrase before relying on it.
 */
class WP_MCP_AI_Pro_Tool_Quick_Add_Google_Calendar_Event implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Capability required before the tool talks to the Calendar API.
	 *
	 * @var string
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Default HTTP timeout for the Calendar request, in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Values Google accepts for the `sendUpdates` query parameter.
	 *
	 * @var array<string>
	 */
	const ALLOWED_SEND_UPDATES = array( 'all', 'externalOnly', 'none' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'quick_add_google_calendar_event';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Quick Add Google Calendar Event', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a Google Calendar event from a plain-language phrase such as "Lunch with Alice Friday at noon" or "Team standup tomorrow 9am for 30 minutes", letting Google resolve the date and time against the calendar\'s timezone. The resolved start and end are returned so you can verify the interpretation. Use create_google_calendar_event when you already have exact timestamps, attendees, or reminders.', 'mcp-ai-wpoos-pro' );
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
				'calendar_id'   => array(
					'type'        => 'string',
					'description' => __( 'Calendar identifier to add the event to. Defaults to the configured calendar, or "primary".', 'mcp-ai-wpoos-pro' ),
				),
				'text'          => array(
					'type'        => 'string',
					'description' => __( 'Natural-language description of the event, e.g. "Lunch with Alice Friday at noon". Relative dates are resolved by Google against the calendar timezone.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'send_updates'  => array(
					'type'        => 'string',
					'description' => __( 'Controls whether attendees are emailed about the new event: "all", "externalOnly", or "none".', 'mcp-ai-wpoos-pro' ),
					'enum'        => self::ALLOWED_SEND_UPDATES,
				),
			),
			'required'             => array( 'text' ),
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
			'wp_mcp_ai_quick_add_google_calendar_event_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_forbidden', __( 'You do not have permission to create Google Calendar events.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_calendar_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ), array( 'status' => 403 ) );
		}

		// Gate 1: sanitise every caller-supplied value before any logic runs.
		$connection_id     = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$calendar_override = isset( $arguments['calendar_id'] ) ? sanitize_text_field( $arguments['calendar_id'] ) : '';
		$text              = isset( $arguments['text'] ) ? trim( sanitize_text_field( $arguments['text'] ) ) : '';
		$send_updates      = isset( $arguments['send_updates'] ) ? sanitize_text_field( $arguments['send_updates'] ) : '';

		if ( '' === $text ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_missing_text',
				__( 'A natural-language event description is required, for example "Lunch with Alice Friday at noon".', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $send_updates && ! in_array( $send_updates, self::ALLOWED_SEND_UPDATES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_calendar_invalid_send_updates',
				sprintf(
					/* translators: %s: comma-separated list of accepted sendUpdates values. */
					__( 'The send_updates value must be one of: %s.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', self::ALLOWED_SEND_UPDATES )
				),
				array( 'status' => 400 )
			);
		}

		$credentials = WP_MCP_AI_Google_Calendar_Credentials::resolve( $connection_id, $context, $arguments );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Creating events requires the writable events scope.
		$scope_check = WP_MCP_AI_Google_Calendar_Credentials::require_scope(
			$credentials,
			WP_MCP_AI_Google_Calendar_Scopes::SCOPE_EVENTS
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$calendar_id = WP_MCP_AI_Google_Calendar_Credentials::resolve_calendar_id( $credentials, $calendar_override );

		if ( '' === $calendar_id ) {
			return new WP_Error( 'wp_mcp_ai_calendar_missing_calendar', __( 'A target Google Calendar identifier is required.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
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

		$query = array();

		if ( '' !== $send_updates ) {
			$query['sendUpdates'] = $send_updates;
		}

		$decoded = $client->quick_add_event( $calendar_id, $text, $query );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return array(
			'success'           => true,
			'event_id'          => isset( $decoded['id'] ) ? sanitize_text_field( (string) $decoded['id'] ) : '',
			'calendar_id'       => $calendar_id,
			'html_link'         => isset( $decoded['htmlLink'] ) ? esc_url_raw( (string) $decoded['htmlLink'] ) : '',
			'summary'           => isset( $decoded['summary'] ) ? sanitize_text_field( (string) $decoded['summary'] ) : '',
			'start'             => isset( $decoded['start'] ) && is_array( $decoded['start'] ) ? $this->format_time_field( $decoded['start'] ) : array(),
			'end'               => isset( $decoded['end'] ) && is_array( $decoded['end'] ) ? $this->format_time_field( $decoded['end'] ) : array(),
			'interpreted_from'  => $text,
			'credential_source' => isset( $credentials['source'] ) ? (string) $credentials['source'] : '',
		);
	}

	/**
	 * Normalise a Google `start`/`end` object, preserving its native shape.
	 *
	 * Google's quickAdd may resolve to either an all-day event (`date`) or a
	 * timed one (`dateTime` plus `timeZone`), so both shapes are passed through
	 * verbatim and the caller can tell them apart by which key is present.
	 *
	 * @param array $value Raw time object.
	 * @return array Normalised time object.
	 */
	protected function format_time_field( array $value ) {
		$formatted = array();

		if ( isset( $value['date'] ) ) {
			$formatted['date'] = sanitize_text_field( (string) $value['date'] );
		}

		if ( isset( $value['dateTime'] ) ) {
			$formatted['dateTime'] = sanitize_text_field( (string) $value['dateTime'] );
		}

		if ( isset( $value['timeZone'] ) ) {
			$formatted['timeZone'] = sanitize_text_field( (string) $value['timeZone'] );
		}

		return $formatted;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Creates calendar events.
			'state-changing',       // Mutates remote calendar state.
			'external-api',         // Calls Google Calendar API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

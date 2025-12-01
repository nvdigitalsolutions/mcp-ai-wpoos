<?php
/**
 * Tool that retrieves event data from GDACS.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches tropical cyclone and flood events from GDACS.
 */
class WP_MCP_AI_Tool_Get_GDACS_Events implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * GDACS endpoint for tropical cyclone and flood events.
	 */
	const EVENTS_ENDPOINT = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/MAP';

	/**
	 * Recommended chat completion model for assistants consuming the payload.
	 */
	const DEFAULT_MODEL = 'gpt-5';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_gdacs_events';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get GDACS Events', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves recent tropical cyclone and flood alerts from the Global Disaster Alert and Coordination System (GDACS).', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'from_date' => array(
					'type'        => 'string',
					'description' => __( 'Optional start date (YYYY-MM-DD) used to filter the GDACS events.', 'wp-mcp-ai' ),
				),
				'to_date'   => array(
					'type'        => 'string',
					'description' => __( 'Optional end date (YYYY-MM-DD) used to filter the GDACS events.', 'wp-mcp-ai' ),
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

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_gdacs_forbidden', __( 'You do not have permission to view GDACS events.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_gdacs_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$query_args = array(
			'eventtypes' => 'TC,FL',
		);

		if ( ! empty( $arguments['from_date'] ) ) {
			$from_date = sanitize_text_field( $arguments['from_date'] );

			if ( ! $this->is_valid_date( $from_date ) ) {
				return new WP_Error( 'wp_mcp_ai_gdacs_invalid_from_date', __( 'The provided start date must use the YYYY-MM-DD format.', 'wp-mcp-ai' ) );
			}

			$query_args['fromdate'] = $from_date;
		}

		if ( ! empty( $arguments['to_date'] ) ) {
			$to_date = sanitize_text_field( $arguments['to_date'] );

			if ( ! $this->is_valid_date( $to_date ) ) {
				return new WP_Error( 'wp_mcp_ai_gdacs_invalid_to_date', __( 'The provided end date must use the YYYY-MM-DD format.', 'wp-mcp-ai' ) );
			}

			$query_args['todate'] = $to_date;
		}

		$request_url = self::EVENTS_ENDPOINT;

		if ( ! empty( $query_args ) ) {
			$request_url = add_query_arg( $query_args, $request_url );
		}

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_gdacs_request_failed',
				__( 'The GDACS request failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_gdacs_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GDACS returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				),
				array( 'status' => $status_code )
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'wp_mcp_ai_gdacs_bad_json', __( 'The GDACS response could not be decoded.', 'wp-mcp-ai' ) );
		}

		$sanitised_events = $this->sanitize_payload( $decoded );
		$event_count      = is_array( $sanitised_events ) ? count( $sanitised_events ) : 0;

		return array(
			'summary'   => sprintf( __( 'Found %d GDACS events', 'wp-mcp-ai' ), $event_count ),
			'model'     => self::DEFAULT_MODEL,
			'from_date' => isset( $query_args['fromdate'] ) ? $query_args['fromdate'] : null,
			'to_date'   => isset( $query_args['todate'] ) ? $query_args['todate'] : null,
			'events'    => $sanitised_events,
		);
	}

	/**
	 * Validate a YYYY-MM-DD formatted date string.
	 *
	 * @param string $value Potential date string.
	 * @return bool
	 */
	protected function is_valid_date( $value ) {
		$date = DateTime::createFromFormat( 'Y-m-d', $value );

		return $date && $value === $date->format( 'Y-m-d' );
	}

	/**
	 * Recursively sanitise the GDACS response payload.
	 *
	 * @param mixed $value Response payload.
	 * @return mixed
	 */
	protected function sanitize_payload( $value ) {
		if ( is_array( $value ) ) {
			$sanitised = array();

			foreach ( $value as $key => $item ) {
				$clean_key               = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				$sanitised[ $clean_key ] = $this->sanitize_payload( $item );
			}

			return $sanitised;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		return wp_json_encode( $value );
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

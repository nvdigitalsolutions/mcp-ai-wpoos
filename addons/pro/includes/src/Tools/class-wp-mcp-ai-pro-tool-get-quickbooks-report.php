<?php
/**
 * Tool that fetches reporting data from QuickBooks Online.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves QuickBooks Online reports using the configured credentials.
 */
class WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Base URL for the QuickBooks Online reports API.
	 */
	const REPORTS_ENDPOINT = 'https://quickbooks.api.intuit.com/v3/company/%1$s/reports/%2$s';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'quickbooks_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'QuickBooks Online Report', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves reporting data from QuickBooks Online using the configured company ID.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'report'            => array(
					'type'        => 'string',
					'description' => __( 'Name of the QuickBooks report to request, such as ProfitAndLoss.', 'wp-mcp-ai' ),
				),
				'start_date'        => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO-8601 start date (YYYY-MM-DD).', 'wp-mcp-ai' ),
				),
				'end_date'          => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO-8601 end date (YYYY-MM-DD).', 'wp-mcp-ai' ),
				),
				'accounting_method' => array(
					'type'        => 'string',
					'enum'        => array( 'Accrual', 'Cash' ),
					'description' => __( 'Limit the report to a specific accounting method.', 'wp-mcp-ai' ),
				),
				'minor_version'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Optional QuickBooks API minor version to target.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'report' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_quickbooks_required_capability', 'manage_options', $context );

		if ( ! $user_id || ! user_can( $user_id, $required_capability ) ) {
			return new WP_Error( 'wp_mcp_ai_quickbooks_forbidden', __( 'You do not have permission to access QuickBooks reports.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_quickbooks_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$settings    = WP_MCP_AI_Admin_Settings::get_settings();
		$company_id  = isset( $settings['quickbooks_company_id'] ) ? trim( (string) $settings['quickbooks_company_id'] ) : '';
		$api_key     = isset( $settings['quickbooks_api_key'] ) ? trim( (string) $settings['quickbooks_api_key'] ) : '';
		$report_name = isset( $arguments['report'] ) ? trim( sanitize_text_field( $arguments['report'] ) ) : '';

		if ( '' === $company_id || '' === $api_key ) {
			return new WP_Error(
				'wp_mcp_ai_quickbooks_missing_credentials',
				__( 'QuickBooks credentials are not configured. Add the company ID and API key in the WP oOS settings.', 'wp-mcp-ai' )
			);
		}

		if ( '' === $report_name ) {
			return new WP_Error( 'wp_mcp_ai_quickbooks_missing_report', __( 'A QuickBooks report name is required.', 'wp-mcp-ai' ) );
		}

		$query_args = array();

		if ( ! empty( $arguments['start_date'] ) ) {
			$start_date = sanitize_text_field( $arguments['start_date'] );

			if ( ! $this->is_valid_date( $start_date ) ) {
				return new WP_Error( 'wp_mcp_ai_quickbooks_invalid_start', __( 'The provided start date must use the YYYY-MM-DD format.', 'wp-mcp-ai' ) );
			}

			$query_args['start_date'] = $start_date;
		}

		if ( ! empty( $arguments['end_date'] ) ) {
			$end_date = sanitize_text_field( $arguments['end_date'] );

			if ( ! $this->is_valid_date( $end_date ) ) {
				return new WP_Error( 'wp_mcp_ai_quickbooks_invalid_end', __( 'The provided end date must use the YYYY-MM-DD format.', 'wp-mcp-ai' ) );
			}

			$query_args['end_date'] = $end_date;
		}

		if ( ! empty( $arguments['accounting_method'] ) ) {
			$accounting_method = ucfirst( strtolower( sanitize_text_field( $arguments['accounting_method'] ) ) );

			if ( ! in_array( $accounting_method, array( 'Accrual', 'Cash' ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_quickbooks_invalid_method', __( 'The accounting method must be Accrual or Cash.', 'wp-mcp-ai' ) );
			}

			$query_args['accounting_method'] = $accounting_method;
		}

		if ( isset( $arguments['minor_version'] ) ) {
			$minor_version = absint( $arguments['minor_version'] );

			if ( $minor_version > 0 ) {
				$query_args['minorversion'] = $minor_version;
			}
		}

		$endpoint = sprintf( self::REPORTS_ENDPOINT, rawurlencode( $company_id ), rawurlencode( $report_name ) );

		if ( ! empty( $query_args ) ) {
			$endpoint = add_query_arg( $query_args, $endpoint );
		}

		$timeout = isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log( 'QuickBooks report request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_quickbooks_http_error',
				__( 'The request to QuickBooks Online failed.', 'wp-mcp-ai' ),
				$response
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log( 'QuickBooks report returned unexpected status.', array( 'status' => $status_code ) );

			return new WP_Error(
				'wp_mcp_ai_quickbooks_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'QuickBooks Online returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body       = wp_remote_retrieve_body( $response );
		$decoded    = json_decode( $body, true );
		$json_error = json_last_error();

		if ( JSON_ERROR_NONE !== $json_error ) {
			WP_MCP_AI_Admin_Settings::log( 'QuickBooks report returned invalid JSON.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_quickbooks_invalid_json', __( 'QuickBooks Online returned an invalid JSON response.', 'wp-mcp-ai' ) );
		}

		if ( isset( $decoded['Fault'] ) ) {
			WP_MCP_AI_Admin_Settings::log( 'QuickBooks report fault encountered.', array( 'fault' => $decoded['Fault'] ) );

			return new WP_Error(
				'wp_mcp_ai_quickbooks_fault',
				__( 'QuickBooks Online reported an error for this request.', 'wp-mcp-ai' ),
				$decoded['Fault']
			);
		}

		return array(
			'report'       => $report_name,
			'company_id'   => $company_id,
			'parameters'   => $query_args,
			'raw_response' => $this->sanitize_report_payload( $decoded ),
			'requested_at' => current_time( 'mysql' ),
			'http_status'  => $status_code,
		);
	}

	/**
	 * Validate an ISO date string in YYYY-MM-DD format.
	 *
	 * @param string $value Date string to validate.
	 * @return bool
	 */
	protected function is_valid_date( $value ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$parts = explode( '-', $value );

		if ( count( $parts ) !== 3 ) {
			return false;
		}

		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
	}

	/**
	 * Recursively sanitise the QuickBooks payload so it is safe for AI consumption.
	 *
	 * @param mixed $data Response data to sanitise.
	 * @return mixed
	 */
	protected function sanitize_report_payload( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();

			foreach ( $data as $key => $value ) {
				$sanitized_key               = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				$sanitized[ $sanitized_key ] = $this->sanitize_report_payload( $value );
			}

			return $sanitized;
		}

		if ( is_scalar( $data ) ) {
			if ( is_string( $data ) ) {
				return sanitize_text_field( $data );
			}

			return $data;
		}

		return null;
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

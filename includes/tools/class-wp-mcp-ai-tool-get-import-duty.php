<?php
/**
 * Tool that retrieves import duty information for supported countries.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
}

/**
 * Retrieves import duty rates from the ITA Tariff Rates API.
 */
class WP_MCP_AI_Tool_Get_Import_Duty implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_ENDPOINT = 'https://api.trade.gov/v1/tariff_rates/search';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_import_duty';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Lookup Import Duty', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Finds the most recent import duty rate for an HS code or product description when importing into the United States, Jamaica, or Sri Lanka.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country'     => array(
					'type'        => 'string',
					'description' => __( 'Destination country the goods are being imported into.', 'wp-mcp-ai' ),
					'enum'        => array( 'united_states', 'jamaica', 'sri_lanka' ),
				),
				'hs_code'     => array(
					'type'        => 'string',
					'description' => __( 'HS or HTS code (6-10 digits) for the product.', 'wp-mcp-ai' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Free-form description of the goods to help locate a tariff line when an HS code is not available.', 'wp-mcp-ai' ),
				),
				'max_results' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of duty records to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 5,
				),
			),
			'required'             => array( 'country' ),
			'additionalProperties' => false,
			'allOf'                => array(
				array(
					'anyOf' => array(
						array(
							'required' => array( 'hs_code' ),
						),
						array(
							'required' => array( 'description' ),
						),
					),
				),
			),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to look up import duties.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$country_key = isset( $arguments['country'] ) ? sanitize_key( $arguments['country'] ) : '';
		$country_map = $this->get_supported_countries();

		if ( empty( $country_key ) || ! isset( $country_map[ $country_key ] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_country', __( 'Please choose a supported destination country (United States, Jamaica, or Sri Lanka).', 'wp-mcp-ai' ) );
		}

		$hs_code     = isset( $arguments['hs_code'] ) ? $this->sanitize_hs_code( $arguments['hs_code'] ) : '';
		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';

		if ( '' === $hs_code && '' === $description ) {
			return new WP_Error( 'wp_mcp_ai_missing_identifier', __( 'Provide an HS code or a description of the item to look up duty rates.', 'wp-mcp-ai' ) );
		}

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 5;
		$max_results = $max_results > 0 ? min( $max_results, 10 ) : 5;

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$api_key  = isset( $settings['ita_tariff_api_key'] ) ? trim( (string) $settings['ita_tariff_api_key'] ) : '';

		$query_args = array(
			'reporter' => $country_map[ $country_key ],
			'size'     => $max_results,
		);

		if ( $hs_code ) {
			$query_args['hs6']     = substr( $hs_code, 0, 6 );
			$query_args['hs_full'] = $hs_code;
		}

		if ( $description ) {
			$query_args['search_text'] = $description;
			$query_args['q']           = $description;
		}

		if ( $api_key ) {
			$query_args['api_key'] = $api_key;
		}

		$request_url = add_query_arg( $query_args, self::API_ENDPOINT );

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Accept'        => 'application/json',
					'User-Agent'    => 'wp-mcp-ai-duty-tool/1.0',
					'Cache-Control' => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_duty_lookup_failed',
				__( 'The import duty lookup request failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 301 === $status_code ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( false !== strpos( $location, 'developer.trade.gov' ) ) {
				return new WP_Error(
					'wp_mcp_ai_duty_api_redirect',
					__( 'The tariff service redirected the request. Verify that your Trade.gov API key is valid and stored in the settings.', 'wp-mcp-ai' )
				);
			}
		}

		if ( $status_code >= 400 || 0 === $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_duty_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The tariff service returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new WP_Error( 'wp_mcp_ai_duty_bad_json', __( 'The tariff service response could not be decoded.', 'wp-mcp-ai' ) );
		}

		$results = array();

		if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
			$results = $data['results'];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$results = $data['data'];
		}

		if ( empty( $results ) ) {
			return new WP_Error( 'wp_mcp_ai_duty_no_results', __( 'No duty entries were returned for the supplied criteria.', 'wp-mcp-ai' ) );
		}

		$normalized = array();

		foreach ( $results as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized[] = $this->normalize_result( $item );

			if ( count( $normalized ) >= $max_results ) {
				break;
			}
		}

		if ( empty( $normalized ) ) {
			return new WP_Error( 'wp_mcp_ai_duty_unusable_results', __( 'The tariff service returned data in an unexpected format.', 'wp-mcp-ai' ) );
		}

		return array(
			'summary' => sprintf( __( 'Found %d import duty results', 'wp-mcp-ai' ), count( $normalized ) ),
			'query'   => array(
				'country'     => $country_map[ $country_key ],
				'hs_code'     => $hs_code,
				'description' => $description,
				'endpoint'    => esc_url_raw( $request_url ),
			),
			'results' => $normalized,
		);
	}

	/**
	 * Retrieve the supported destination countries.
	 *
	 * @return array<string, string>
	 */
	protected function get_supported_countries() {
		return array(
			'united_states' => 'United States',
			'jamaica'       => 'Jamaica',
			'sri_lanka'     => 'Sri Lanka',
		);
	}

	/**
	 * Sanitise an HS/HTS code value.
	 *
	 * @param string $value Raw HS code string.
	 * @return string
	 */
	protected function sanitize_hs_code( $value ) {
		$digits = preg_replace( '/[^0-9]/', '', (string) $value );

		if ( strlen( $digits ) > 10 ) {
			$digits = substr( $digits, 0, 10 );
		}

		return $digits;
	}

	/**
	 * Normalise a tariff record into a predictable structure.
	 *
	 * @param array $item Raw item from the API.
	 * @return array<string, mixed>
	 */
	protected function normalize_result( array $item ) {
		$hs_code       = $this->extract_first_scalar( $item, array( 'hs_full', 'hs_code', 'htsno', 'hs10', 'hs8', 'hs6' ) );
		$description   = $this->extract_first_scalar( $item, array( 'product_description', 'description', 'product', 'goods_description', 'hs_description', 'long_description' ) );
		$rate_value    = $this->extract_first_scalar( $item, array( 'duty_rate', 'tariff_rate', 'applied_rate', 'applied_tariff_rate', 'simple_average', 'mfn_duty_rate', 'rate', 'value' ) );
		$rate_unit     = $this->extract_first_scalar( $item, array( 'tariff_rate_measure', 'unit', 'measurement', 'duty_type', 'rate_type' ) );
		$latest_period = $this->extract_first_scalar( $item, array( 'most_recent_year', 'year', 'reporting_year', 'latest_year', 'period', 'effective_year', 'effective_date' ) );
		$preferential  = $this->extract_first_scalar( $item, array( 'preferential_rate', 'fta_rate', 'special_rate' ) );
		$additional    = $this->extract_first_scalar( $item, array( 'notes', 'requirements', 'conditions' ) );
		$source_url    = $this->extract_first_scalar( $item, array( 'tariff_link', 'source', 'url', 'reference' ) );

		return array(
			'hs_code'                 => $hs_code ? sanitize_text_field( $hs_code ) : '',
			'description'             => $description ? sanitize_text_field( $description ) : '',
			'duty_rate'               => $this->format_rate( $rate_value ),
			'measure'                 => $rate_unit ? sanitize_text_field( $rate_unit ) : '',
			'period'                  => $latest_period ? sanitize_text_field( (string) $latest_period ) : '',
			'preferential_rate'       => $this->format_rate( $preferential ),
			'additional_requirements' => $additional ? sanitize_text_field( $additional ) : '',
			'source'                  => $source_url ? esc_url_raw( $source_url ) : '',
			'raw_fields'              => $this->filter_scalar_fields( $item ),
		);
	}

	/**
	 * Extract the first non-empty scalar value for a list of keys.
	 *
	 * @param array $item Data array.
	 * @param array $keys Keys to inspect in order.
	 * @return string
	 */
	protected function extract_first_scalar( array $item, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $item[ $key ] ) && ! is_array( $item[ $key ] ) && '' !== $item[ $key ] ) {
				return (string) $item[ $key ];
			}
		}

		return '';
	}

	/**
	 * Format a duty rate value into a human readable string.
	 *
	 * @param string $value Raw rate value.
	 * @return string
	 */
	protected function format_rate( $value ) {
		if ( '' === $value || null === $value ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			return rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' ) . '%';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^[0-9]+(\.[0-9]+)?$/', $value ) ) {
			return rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' ) . '%';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Keep only scalar fields from the raw payload so assistants can inspect additional metadata if needed.
	 *
	 * @param array $item API result item.
	 * @return array<string, string>
	 */
	protected function filter_scalar_fields( array $item ) {
		$filtered = array();

		foreach ( $item as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$filtered[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
		}

		return $filtered;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Tool is part of the Pro tier.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls to ITA Tariff Rates API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

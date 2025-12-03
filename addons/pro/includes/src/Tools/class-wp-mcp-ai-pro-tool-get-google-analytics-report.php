<?php
/**
 * Tool that fetches reporting data from Google Analytics 4.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Runs Analytics Data API reports for GA4 properties.
 */
class WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_SCOPE      = 'https://www.googleapis.com/auth/analytics.readonly';
	const DEFAULT_TOKEN_URI  = 'https://oauth2.googleapis.com/token';
	const TOKEN_CACHE_PREFIX = 'wp_mcp_ai_ga_token_';
	const MAX_REPORT_LIMIT   = 100000;
	const DEFAULT_START_DATE = '7daysAgo';
	const DEFAULT_END_DATE   = 'today';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'google_analytics_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Google Analytics Report', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves GA4 reporting data using the Google Analytics Data API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'property_id'         => array(
					'type'        => 'string',
					'description' => __( 'Override the Google Analytics property ID. Falls back to the default configured in settings.', 'wp-mcp-ai' ),
				),
				'metrics'             => array(
					'description' => __( 'One or more metric names (for example, activeUsers). Comma separated strings are accepted.', 'wp-mcp-ai' ),
					'anyOf'       => array(
						array(
							'type' => 'string',
						),
						array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
							),
						),
					),
				),
				'dimensions'          => array(
					'description' => __( 'Optional list of dimensions (for example, country or pagePath).', 'wp-mcp-ai' ),
					'anyOf'       => array(
						array(
							'type' => 'string',
						),
						array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
							),
						),
					),
				),
				'start_date'          => array(
					'type'        => 'string',
					'description' => __( 'Report start date in YYYY-MM-DD format or a relative keyword such as 7daysAgo.', 'wp-mcp-ai' ),
				),
				'end_date'            => array(
					'type'        => 'string',
					'description' => __( 'Report end date in YYYY-MM-DD format or a relative keyword such as today.', 'wp-mcp-ai' ),
				),
				'limit'               => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_REPORT_LIMIT,
					'description' => __( 'Maximum number of rows to return (defaults to the Analytics API default).', 'wp-mcp-ai' ),
				),
				'offset'              => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Row offset to apply when paginating through a large report.', 'wp-mcp-ai' ),
				),
				'metric_aggregations' => array(
					'description' => __( 'Optional metric aggregations such as TOTAL or MAXIMUM.', 'wp-mcp-ai' ),
					'anyOf'       => array(
						array(
							'type' => 'string',
						),
						array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
							),
						),
					),
				),
				'dimension_filter'    => array(
					'type'                 => 'object',
					'description'          => __( 'Raw Analytics API dimension filter definition.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
				'metric_filter'       => array(
					'type'                 => 'object',
					'description'          => __( 'Raw Analytics API metric filter definition.', 'wp-mcp-ai' ),
					'additionalProperties' => true,
				),
				'order_bys'           => array(
					'type'        => 'array',
					'description' => __( 'Ordering instructions. Provide objects with dimension or metric keys and optional desc boolean.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'dimension' => array(
								'type' => 'string',
							),
							'metric'    => array(
								'type' => 'string',
							),
							'desc'      => array(
								'type' => 'boolean',
							),
						),
						'additionalProperties' => true,
					),
				),
				'keep_empty_rows'     => array(
					'type'        => 'boolean',
					'description' => __( 'When true, rows with zero metrics are retained.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'metrics' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_google_analytics_required_capability', 'manage_options', $context, $arguments, $this );
		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_forbidden', __( 'You do not have permission to request Google Analytics reports.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
		}

		$settings    = WP_MCP_AI_Admin_Settings::get_settings();
		$property_id = '';

		if ( ! empty( $arguments['property_id'] ) ) {
			$property_id = $this->sanitize_name( $arguments['property_id'] );
		}

		if ( '' === $property_id && ! empty( $settings['google_analytics_property_id'] ) ) {
			$property_id = $this->sanitize_name( $settings['google_analytics_property_id'] );
		}

		if ( '' === $property_id ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_missing_property', __( 'A Google Analytics property ID is required.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$metrics = $this->normalise_names_list( isset( $arguments['metrics'] ) ? $arguments['metrics'] : array() );
		if ( empty( $metrics ) ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_missing_metrics', __( 'At least one metric must be requested.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$dimensions = $this->normalise_names_list( isset( $arguments['dimensions'] ) ? $arguments['dimensions'] : array() );

		$start_date = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : self::DEFAULT_START_DATE;
		$end_date   = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : self::DEFAULT_END_DATE;

		if ( '' === $start_date ) {
			$start_date = self::DEFAULT_START_DATE;
		}

		if ( '' === $end_date ) {
			$end_date = self::DEFAULT_END_DATE;
		}

		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 0;
		$offset = isset( $arguments['offset'] ) ? max( 0, absint( $arguments['offset'] ) ) : 0;

		if ( $limit > self::MAX_REPORT_LIMIT ) {
			$limit = self::MAX_REPORT_LIMIT;
		}

		$metric_aggregations = $this->normalise_metric_aggregations( isset( $arguments['metric_aggregations'] ) ? $arguments['metric_aggregations'] : array() );

		$dimension_filter = array();
		if ( isset( $arguments['dimension_filter'] ) ) {
			if ( ! is_array( $arguments['dimension_filter'] ) ) {
				return new WP_Error( 'wp_mcp_ai_google_analytics_invalid_dimension_filter', __( 'The dimension filter must be provided as an object.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$dimension_filter = $this->sanitize_filter_tree( $arguments['dimension_filter'] );
		}

		$metric_filter = array();
		if ( isset( $arguments['metric_filter'] ) ) {
			if ( ! is_array( $arguments['metric_filter'] ) ) {
				return new WP_Error( 'wp_mcp_ai_google_analytics_invalid_metric_filter', __( 'The metric filter must be provided as an object.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$metric_filter = $this->sanitize_filter_tree( $arguments['metric_filter'] );
		}

		$order_bys = $this->normalise_order_bys( isset( $arguments['order_bys'] ) ? $arguments['order_bys'] : array() );
		if ( is_wp_error( $order_bys ) ) {
			return $order_bys;
		}

		$keep_empty_rows = ! empty( $arguments['keep_empty_rows'] );

		$credentials = array();
		if ( ! empty( $settings['google_analytics_credentials_json'] ) ) {
			$decoded = json_decode( $settings['google_analytics_credentials_json'], true );
			if ( is_array( $decoded ) ) {
				$credentials = $decoded;
			}
		}

		$credentials = apply_filters( 'wp_mcp_ai_google_analytics_service_account_credentials', $credentials, $context, $arguments, $this );
		if ( empty( $credentials ) || ! is_array( $credentials ) ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_missing_credentials', __( 'Google Analytics credentials are not configured.', 'wp-mcp-ai' ), array( 'status' => 500 ) );
		}

		$access_token = $this->resolve_access_token( $credentials, $arguments, $context );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$request_body = array(
			'dateRanges' => array(
				array(
					'startDate' => $start_date,
					'endDate'   => $end_date,
				),
			),
			'metrics'    => array_map(
				function ( $metric ) {
					return array( 'name' => $metric );
				},
				$metrics
			),
		);

		if ( ! empty( $dimensions ) ) {
			$request_body['dimensions'] = array_map(
				function ( $dimension ) {
					return array( 'name' => $dimension );
				},
				$dimensions
			);
		}

		if ( $limit > 0 ) {
			$request_body['limit'] = $limit;
		}

		if ( $offset > 0 ) {
			$request_body['offset'] = $offset;
		}

		if ( ! empty( $metric_aggregations ) ) {
			$request_body['metricAggregations'] = $metric_aggregations;
		}

		if ( ! empty( $dimension_filter ) ) {
			$request_body['dimensionFilter'] = $dimension_filter;
		}

		if ( ! empty( $metric_filter ) ) {
			$request_body['metricFilter'] = $metric_filter;
		}

		if ( ! empty( $order_bys ) ) {
			$request_body['orderBys'] = $order_bys;
		}

		if ( $keep_empty_rows ) {
			$request_body['keepEmptyRows'] = true;
		}

		$endpoint = sprintf( 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport', rawurlencode( $property_id ) );

		$timeout = isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;
		$timeout = (int) apply_filters( 'wp_mcp_ai_google_analytics_request_timeout', $timeout, $context, $arguments, $this );
		if ( $timeout <= 0 ) {
			$timeout = 15;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'timeout' => $timeout,
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Analytics report request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_google_analytics_http_error',
				__( 'The request to the Google Analytics Data API failed.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'error'  => $response,
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( null === $data ) {
			$data = array();
		}

		if ( $status < 200 || $status >= 300 ) {
			WP_MCP_AI_Admin_Settings::log(
				'Google Analytics report returned unexpected status.',
				array(
					'status' => $status,
					'body'   => $body,
				)
			);

			$message = __( 'Google Analytics rejected the report request.', 'wp-mcp-ai' );
			if ( isset( $data['error']['message'] ) ) {
				$message = sprintf( '%s %s', $message, $data['error']['message'] );
			}

			return new WP_Error(
				'wp_mcp_ai_google_analytics_error',
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		return array(
			'property_id'  => $property_id,
			'date_range'   => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			'metrics'      => $metrics,
			'dimensions'   => $dimensions,
			'row_count'    => isset( $data['rowCount'] ) ? absint( $data['rowCount'] ) : 0,
			'raw_response' => $this->sanitize_report_payload( $data ),
			'requested_at' => current_time( 'mysql' ),
			'http_status'  => $status,
		);
	}

	/**
	 * Resolve a Google API access token either from filters or service account credentials.
	 *
	 * @param array $credentials Service account configuration.
	 * @param array $arguments   Tool arguments.
	 * @param array $context     Request context.
	 * @return string|WP_Error
	 */
	protected function resolve_access_token( array $credentials, array $arguments, array $context ) {
		$token = apply_filters( 'wp_mcp_ai_google_analytics_access_token', '', $context, $arguments, $this );
		if ( is_string( $token ) ) {
			$token = trim( $token );
		}

		if ( ! empty( $token ) ) {
			return $token;
		}

		$cache_key = '';
		$scope     = $this->determine_scope_string( $credentials );

		if ( isset( $credentials['client_email'] ) ) {
			$cache_key = self::TOKEN_CACHE_PREFIX . md5( strtolower( $credentials['client_email'] ) . '|' . $scope );
			$cached    = get_transient( $cache_key );

			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$exchange = $this->exchange_service_account_token( $credentials, $scope, $arguments, $context );
		if ( is_wp_error( $exchange ) ) {
			return $exchange;
		}

		$token_value = isset( $exchange['access_token'] ) ? (string) $exchange['access_token'] : '';
		$expires_in  = isset( $exchange['expires_in'] ) ? (int) $exchange['expires_in'] : 3600;
		$cache_ttl   = max( 60, $expires_in - 60 );

		if ( $cache_key && '' !== $token_value ) {
			set_transient( $cache_key, $token_value, $cache_ttl );
		}

		return $token_value;
	}

	/**
	 * Exchange a service account credential for an access token.
	 *
	 * @param array  $credentials Service account configuration.
	 * @param string $scope       OAuth scope string.
	 * @param array  $arguments   Tool arguments.
	 * @param array  $context     Request context.
	 * @return array|WP_Error
	 */
	protected function exchange_service_account_token( array $credentials, $scope, array $arguments, array $context ) {
		$client_email = isset( $credentials['client_email'] ) ? sanitize_email( $credentials['client_email'] ) : '';
		$private_key  = isset( $credentials['private_key'] ) ? trim( (string) $credentials['private_key'] ) : '';
		$token_uri    = isset( $credentials['token_uri'] ) ? esc_url_raw( $credentials['token_uri'] ) : '';
		$subject      = isset( $credentials['delegated_email'] ) ? sanitize_email( $credentials['delegated_email'] ) : '';

		if ( '' === $client_email || '' === $private_key ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_invalid_credentials', __( 'Incomplete Google Analytics service account credentials.', 'wp-mcp-ai' ), array( 'status' => 500 ) );
		}

		if ( '' === $token_uri ) {
			$token_uri = self::DEFAULT_TOKEN_URI;
		}

		$now    = time();
		$claims = array(
			'iss'   => $client_email,
			'scope' => $scope,
			'aud'   => $token_uri,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		if ( '' !== $subject ) {
			$claims['sub'] = $subject;
		}

		$assertion = $this->build_jwt_assertion( $claims, $private_key );
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$timeout = (int) apply_filters( 'wp_mcp_ai_google_analytics_token_request_timeout', 15, $context, $arguments, $this );
		if ( $timeout <= 0 ) {
			$timeout = 15;
		}

		$response = wp_remote_post(
			$token_uri,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'timeout' => $timeout,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_google_analytics_token_error',
				__( 'Unable to obtain a Google Analytics access token.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'error'  => $response,
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 || ! isset( $data['access_token'] ) ) {
			$message = __( 'Google rejected the service account token request.', 'wp-mcp-ai' );
			if ( isset( $data['error_description'] ) ) {
				$message = sprintf( '%s %s', $message, $data['error_description'] );
			}

			return new WP_Error(
				'wp_mcp_ai_google_analytics_token_error',
				$message,
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		return array(
			'access_token' => (string) $data['access_token'],
			'expires_in'   => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600,
		);
	}

	/**
	 * Build a signed JWT assertion using the provided claims and private key.
	 *
	 * @param array  $claims      Assertion claims.
	 * @param string $private_key RSA private key.
	 * @return string|WP_Error
	 */
	protected function build_jwt_assertion( array $claims, $private_key ) {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$segments = array(
			$this->base64url_encode( wp_json_encode( $header ) ),
			$this->base64url_encode( wp_json_encode( $claims ) ),
		);

		$input     = implode( '.', $segments );
		$signature = '';

		$success = openssl_sign( $input, $signature, $private_key, 'sha256' );
		if ( ! $success ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_signing_failed', __( 'Unable to sign the Google Analytics service account assertion.', 'wp-mcp-ai' ), array( 'status' => 500 ) );
		}

		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Base64 URL-safe encode helper.
	 *
	 * @param string $value Raw value to encode.
	 * @return string
	 */
	protected function base64url_encode( $value ) {
		$encoded = base64_encode( $value );
		$encoded = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), $encoded );

		return $encoded;
	}

	/**
	 * Normalise a metric or dimension name list into an array of safe identifiers.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	protected function normalise_names_list( $value ) {
		if ( is_string( $value ) ) {
			$value = array_map( 'trim', explode( ',', $value ) );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$names = array();
		foreach ( $value as $entry ) {
			if ( is_string( $entry ) ) {
				$name = $this->sanitize_name( $entry );
				if ( '' !== $name ) {
					$names[ $name ] = $name;
				}
			}
		}

		return array_values( $names );
	}

	/**
	 * Sanitise a metric or dimension identifier.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	protected function sanitize_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		$name = preg_replace( '/[^A-Za-z0-9_:\.]/', '', $name );

		return trim( $name );
	}

	/**
	 * Normalise metric aggregations.
	 *
	 * @param mixed $value Raw aggregation definitions.
	 * @return array
	 */
	protected function normalise_metric_aggregations( $value ) {
		$allowed = array( 'TOTAL', 'MINIMUM', 'MAXIMUM', 'COUNT', 'SUM', 'AVERAGE' );

		$aggregations = $this->normalise_names_list( $value );
		$aggregations = array_map( 'strtoupper', $aggregations );

		return array_values( array_intersect( $allowed, $aggregations ) );
	}

	/**
	 * Normalise Analytics order by definitions.
	 *
	 * @param mixed $value Raw order definitions.
	 * @return array|WP_Error
	 */
	protected function normalise_order_bys( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( isset( $value['metric'] ) || isset( $value['dimension'] ) ) {
			$value = array( $value );
		}

		if ( ! is_array( $value ) ) {
			return new WP_Error( 'wp_mcp_ai_google_analytics_invalid_order', __( 'Order instructions must be provided as an array.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$normalised = array();

		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$order = array();

			if ( isset( $entry['metric'] ) ) {
				$metric_name = $this->sanitize_name( $entry['metric'] );
				if ( '' !== $metric_name ) {
					$order['metric'] = array( 'metricName' => $metric_name );
				}
			}

			if ( isset( $entry['dimension'] ) ) {
				$dimension_name = $this->sanitize_name( $entry['dimension'] );
				if ( '' !== $dimension_name ) {
					$order['dimension'] = array( 'dimensionName' => $dimension_name );
				}
			}

			if ( empty( $order ) ) {
				continue;
			}

			if ( isset( $entry['desc'] ) ) {
				$order['desc'] = (bool) $entry['desc'];
			}

			$normalised[] = $order;
		}

		return $normalised;
	}

	/**
	 * Sanitise a filter tree so it can be safely encoded.
	 *
	 * @param mixed $value Raw filter value.
	 * @return mixed
	 */
	protected function sanitize_filter_tree( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = array();

			foreach ( $value as $key => $child ) {
				$sanitized_key = $key;
				if ( is_string( $key ) ) {
					$sanitized_key = preg_replace( '/[^A-Za-z0-9_]/', '', $key );
					if ( '' === $sanitized_key ) {
						continue;
					}
				}

				if ( in_array( $sanitized_key, array( 'fieldName', 'dimensionName', 'metricName' ), true ) ) {
					$sanitized[ $sanitized_key ] = $this->sanitize_name( $child );
				} else {
					$sanitized[ $sanitized_key ] = $this->sanitize_filter_tree( $child );
				}
			}

			return $sanitized;
		}

		if ( is_scalar( $value ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}

		return null;
	}

	/**
	 * Recursively sanitise the Analytics payload before returning it to the assistant.
	 *
	 * @param mixed $data Raw response data.
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
			return is_string( $data ) ? sanitize_text_field( $data ) : $data;
		}

		return null;
	}

	/**
	 * Determine the OAuth scope string from the provided credentials.
	 *
	 * @param array $credentials Credential array.
	 * @return string
	 */
	protected function determine_scope_string( array $credentials ) {
		if ( isset( $credentials['scopes'] ) ) {
			$scopes = $credentials['scopes'];
		} elseif ( isset( $credentials['scope'] ) ) {
			$scopes = $credentials['scope'];
		} else {
			$scopes = self::DEFAULT_SCOPE;
		}

		if ( is_array( $scopes ) ) {
			$scopes = implode( ' ', array_filter( array_map( 'trim', $scopes ) ) );
		} else {
			$scopes = trim( (string) $scopes );
		}

		if ( '' === $scopes ) {
			$scopes = self::DEFAULT_SCOPE;
		}

		return $scopes;
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

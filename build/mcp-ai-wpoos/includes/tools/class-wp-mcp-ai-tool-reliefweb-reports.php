<?php
/**
 * Tool that retrieves ReliefWeb reports filtered by country or disaster type.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries the ReliefWeb API for recent humanitarian reports.
 */
class WP_MCP_AI_Tool_ReliefWeb_Reports implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Base endpoint for ReliefWeb report searches.
	 */
	const ENDPOINT = 'https://api.reliefweb.int/v1/reports';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'reliefweb_reports';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'ReliefWeb Reports', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches the ReliefWeb humanitarian dataset for recent reports filtered by country or disaster type.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country'       => array(
					'type'        => 'string',
					'description' => __( 'Optional country name to filter by (for example, Jamaica).', 'wp-mcp-ai' ),
				),
				'disaster_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional disaster type to filter by (for example, Storm).', 'wp-mcp-ai' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Optional keyword search applied across report titles and body content.', 'wp-mcp-ai' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => __( 'Maximum number of reports to return (1-50).', 'wp-mcp-ai' ),
				),
				'sort'          => array(
					'type'        => 'string',
					'enum'        => array( 'date:desc', 'date:asc' ),
					'default'     => 'date:desc',
					'description' => __( 'Sort order for the ReliefWeb API (newest first by default).', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_reliefweb_forbidden', __( 'You do not have permission to search ReliefWeb reports.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_reliefweb_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$country       = isset( $arguments['country'] ) ? sanitize_text_field( $arguments['country'] ) : '';
		$disaster_type = isset( $arguments['disaster_type'] ) ? sanitize_text_field( $arguments['disaster_type'] ) : '';
		$search_term   = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';

		if ( '' === $country && '' === $disaster_type ) {
			return new WP_Error( 'wp_mcp_ai_reliefweb_missing_filter', __( 'Provide at least a country or disaster type to search ReliefWeb reports.', 'wp-mcp-ai' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;

		if ( $limit <= 0 ) {
			$limit = 10;
		}

		$limit = min( $limit, 50 );

		$sort = isset( $arguments['sort'] ) ? sanitize_text_field( $arguments['sort'] ) : 'date:desc';
		if ( ! in_array( $sort, array( 'date:desc', 'date:asc' ), true ) ) {
			$sort = 'date:desc';
		}

		$payload = array(
			'limit'  => $limit,
			'sort'   => array( $sort ),
			'fields' => array(
				'include' => array( 'title', 'url', 'url_alias', 'date', 'country', 'disaster_type', 'source' ),
			),
		);

		$conditions = array();

		if ( '' !== $country ) {
			$conditions[] = array(
				'field' => 'country.name',
				'value' => $country,
			);
		}

		if ( '' !== $disaster_type ) {
			$conditions[] = array(
				'field' => 'disaster_type',
				'value' => $disaster_type,
			);
		}

		if ( ! empty( $conditions ) ) {
			$payload['filter'] = array(
				'conditions' => $conditions,
			);

			if ( count( $conditions ) > 1 ) {
				$payload['filter']['operator'] = 'OR';
			}
		}

		if ( '' !== $search_term ) {
			$payload['query'] = array(
				'value'  => $search_term,
				'fields' => array( 'title', 'body', 'country.name' ),
			);
		}

		$payload = apply_filters( 'wp_mcp_ai_reliefweb_request_payload', $payload, $arguments, $context );

		$appname = apply_filters( 'wp_mcp_ai_reliefweb_appname', 'wp-mcp-ai', $arguments, $context );
		$appname = sanitize_text_field( $appname );

		if ( '' === $appname ) {
			$appname = 'wp-mcp-ai';
		}

		$query_args = array(
			'appname' => $appname,
			'profile' => 'full',
		);

		$request_url = add_query_arg( $query_args, self::ENDPOINT );

		$timeout = (int) apply_filters( 'wp_mcp_ai_reliefweb_timeout', 15, $arguments, $context );
		if ( $timeout < 5 ) {
			$timeout = 5;
		}

		$response = wp_remote_post(
			$request_url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_reliefweb_http_error',
				__( 'The ReliefWeb request failed.', 'wp-mcp-ai' ),
				$response
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_reliefweb_bad_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'ReliefWeb returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded || ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_reliefweb_bad_json', __( 'The ReliefWeb response could not be decoded.', 'wp-mcp-ai' ) );
		}

		$results = array();

		if ( ! empty( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			foreach ( $decoded['data'] as $item ) {
				if ( empty( $item['fields'] ) || ! is_array( $item['fields'] ) ) {
					continue;
				}

				$fields = $item['fields'];

				$countries = array();
				if ( ! empty( $fields['country'] ) && is_array( $fields['country'] ) ) {
					foreach ( $fields['country'] as $country_item ) {
						if ( ! empty( $country_item['name'] ) ) {
							$countries[] = sanitize_text_field( $country_item['name'] );
						}
					}
				}

				$disasters = array();
				if ( ! empty( $fields['disaster_type'] ) && is_array( $fields['disaster_type'] ) ) {
					foreach ( $fields['disaster_type'] as $disaster_item ) {
						if ( ! empty( $disaster_item['name'] ) ) {
							$disasters[] = sanitize_text_field( $disaster_item['name'] );
						}
					}
				}

				$sources = array();
				if ( ! empty( $fields['source'] ) && is_array( $fields['source'] ) ) {
					foreach ( $fields['source'] as $source_item ) {
						if ( ! empty( $source_item['name'] ) ) {
							$sources[] = sanitize_text_field( $source_item['name'] );
						}
					}
				}

				$report_url = '';
				if ( ! empty( $fields['url_alias'] ) ) {
					$report_url = esc_url_raw( $fields['url_alias'] );
				} elseif ( ! empty( $fields['url'] ) ) {
					$report_url = esc_url_raw( $fields['url'] );
				} elseif ( ! empty( $item['href'] ) ) {
					$report_url = esc_url_raw( $item['href'] );
				}

				$date_original = '';
				if ( ! empty( $fields['date']['original'] ) ) {
					$date_original = sanitize_text_field( $fields['date']['original'] );
				} elseif ( ! empty( $fields['date']['created'] ) ) {
					$date_original = sanitize_text_field( $fields['date']['created'] );
				}

				$results[] = array_filter(
					array(
						'id'             => isset( $item['id'] ) ? sanitize_text_field( $item['id'] ) : '',
						'title'          => isset( $fields['title'] ) ? sanitize_text_field( $fields['title'] ) : '',
						'url'            => $report_url,
						'countries'      => $countries,
						'disaster_types' => $disasters,
						'sources'        => $sources,
						'published_at'   => $date_original,
					)
				);
			}
		}

		return array(
			'filters'  => array_filter(
				array(
					'country'       => $country,
					'disaster_type' => $disaster_type,
					'search'        => $search_term,
					'sort'          => $sort,
					'limit'         => $limit,
				)
			),
			'total'    => isset( $decoded['totalCount'] ) ? (int) $decoded['totalCount'] : null,
			'returned' => isset( $decoded['count'] ) ? (int) $decoded['count'] : count( $results ),
			'results'  => $results,
		);
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

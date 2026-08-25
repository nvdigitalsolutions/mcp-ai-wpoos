<?php
/**
 * Tool listing Elementor Pro form submissions.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide a condensed view of Elementor Pro form submissions.
 *
 * Queries the e_submissions / e_submissions_values tables introduced
 * in Elementor Pro 3.2+ when "Collect Submissions" is enabled on a
 * Form widget.
 */
class WP_MCP_AI_Tool_Get_Elementor_Form_Submissions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine whether Elementor Pro with submissions support is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		global $wpdb;

		$has_pro = defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin', false );
		if ( ! $has_pro ) {
			return false;
		}

		// Verify the submissions tables exist (Elementor Pro 3.2+).
		$submissions_table = $wpdb->prefix . 'e_submissions';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions_table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (bool) $table_exists;
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) && ! class_exists( '\\ElementorPro\\Plugin', false ) ) {
			return __( 'The Elementor form submissions tool is disabled because Elementor Pro is not active.', 'mcp-ai-wpoos' );
		}

		return __( 'The Elementor form submissions tool is disabled because the submissions database tables were not found. Ensure "Collect Submissions" is enabled on at least one Elementor form and Elementor Pro 3.2+ is installed.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'get_elementor_form_submissions';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Get Elementor Form Submissions', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Retrieves Elementor Pro form submissions for a given page (post_id). Only works with Elementor Pro 3.2+ forms where "Collect Submissions" is enabled. The form_post_id parameter is the WordPress post ID of the page containing the Elementor Form widget. Use get_elementor_templates first to discover available Elementor pages. Returns a form_found flag indicating whether the given post_id actually had any form submissions — false means the form may not exist at that location.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'form_post_id'  => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Post ID of the page containing the Elementor form widget. Use get_elementor_templates to find the correct ID.', 'mcp-ai-wpoos' ),
				),
				'element_id'    => array(
					'type'        => 'string',
					'description' => __( 'Optional Elementor widget ID (e.g. "abc1234") to filter submissions by a specific form widget on the page.', 'mcp-ai-wpoos' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Optional submission status filter (e.g. success, failed).', 'mcp-ai-wpoos' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of submissions to return (1-50).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'transport'     => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'rest', 'http' ),
					'description' => __( 'Optional transport hint. Use "rest" for local REST API calls, "http" for remote. Default "auto" prefers local when available.', 'mcp-ai-wpoos' ),
					'default'     => 'auto',
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional remote connection ID from the Remote Site Manager for fetching submissions from another WordPress site.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'form_post_id' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_elementor_submissions_missing', self::get_unavailable_reason() );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view Elementor form submissions.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $this->user_can_view_submissions( $user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view Elementor form submissions.', 'mcp-ai-wpoos' ) );
		}

		$form_post_id = absint( isset( $arguments['form_post_id'] ) ? $arguments['form_post_id'] : 0 );
		if ( ! $form_post_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_form_id', __( 'A form post ID must be provided.', 'mcp-ai-wpoos' ) );
		}

		$limit         = $this->sanitize_limit( isset( $arguments['limit'] ) ? $arguments['limit'] : null, 10 );
		$status        = $this->sanitize_status( isset( $arguments['status'] ) ? $arguments['status'] : '' );
		$element_id    = $this->sanitize_element_id( isset( $arguments['element_id'] ) ? $arguments['element_id'] : '' );
		$transport     = $this->sanitize_transport( isset( $arguments['transport'] ) ? $arguments['transport'] : 'auto' );
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		// If a connection_id is supplied, delegate to the remote data dispatcher.
		if ( $connection_id && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return $this->execute_remote( $form_post_id, $limit, $status, $element_id, $connection_id, $user_id );
		}

		// Local execution: query the Elementor submissions tables directly.
		return $this->execute_local( $form_post_id, $limit, $status, $element_id );
	}

	/**
	 * Execute locally by querying the e_submissions tables.
	 *
	 * @param int    $form_post_id Post ID of the page containing the form.
	 * @param int    $limit        Maximum number of submissions.
	 * @param string $status       Optional status filter.
	 * @param string $element_id   Optional element/widget ID filter.
	 * @return array
	 */
	protected function execute_local( $form_post_id, $limit, $status, $element_id ) {
		global $wpdb;

		$submissions_table = $wpdb->prefix . 'e_submissions';
		$values_table      = $wpdb->prefix . 'e_submissions_values';

		$where_clauses   = array( $wpdb->prepare( 's.post_id = %d', $form_post_id ) );
		$where_clauses[] = $wpdb->prepare( 's.type = %s', 'form' );

		if ( $status ) {
			$where_clauses[] = $wpdb->prepare( 's.status = %s', $status );
		}

		if ( $element_id ) {
			$where_clauses[] = $wpdb->prepare( 's.element_id = %s', $element_id );
		}

		$where = implode( ' AND ', $where_clauses );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$submissions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.form_name, s.element_id, s.status, s.user_id, s.user_ip,
				        s.created_at_gmt, s.updated_at_gmt, s.referer
				 FROM {$submissions_table} s
				 WHERE {$where}
				 ORDER BY s.created_at_gmt DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $submissions ) ) {
			// Check whether the form actually exists by looking for ANY
			// submissions row for this post_id (any type, not just 'form').
			// If there's never been a submission for this post_id, the form
			// may not exist at this location.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$form_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$submissions_table} WHERE post_id = %d LIMIT 1",
					$form_post_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			return array(
				'transport'    => 'local',
				'form_post_id' => $form_post_id,
				'submissions'  => array(),
				'total'        => 0,
				'form_found'   => $form_exists,
			);
		}

		// Batch-load field values for all retrieved submissions.
		$submission_ids = array();
		foreach ( $submissions as $sub ) {
			$submission_ids[] = absint( $sub['id'] );
		}

		$fields_by_submission = $this->load_submission_fields( $submission_ids );

		// Build the normalized output.
		$records = array();
		foreach ( $submissions as $sub ) {
			$sid = absint( $sub['id'] );

			$records[] = array(
				'id'         => $sid,
				'form_name'  => isset( $sub['form_name'] ) ? sanitize_text_field( $sub['form_name'] ) : '',
				'element_id' => isset( $sub['element_id'] ) ? sanitize_key( $sub['element_id'] ) : '',
				'status'     => isset( $sub['status'] ) ? sanitize_key( $sub['status'] ) : '',
				'user_id'    => isset( $sub['user_id'] ) ? absint( $sub['user_id'] ) : 0,
				'user_ip'    => isset( $sub['user_ip'] ) ? sanitize_text_field( $sub['user_ip'] ) : '',
				'referer'    => isset( $sub['referer'] ) ? esc_url_raw( $sub['referer'] ) : '',
				'created_at' => isset( $sub['created_at_gmt'] ) ? $this->format_datetime( $sub['created_at_gmt'] ) : '',
				'updated_at' => isset( $sub['updated_at_gmt'] ) ? $this->format_datetime( $sub['updated_at_gmt'] ) : '',
				'fields'     => isset( $fields_by_submission[ $sid ] ) ? $fields_by_submission[ $sid ] : array(),
			);
		}

		// Get total count for pagination reference. `$where` is already built from
		// prepared fragments and the query has no remaining placeholders, so it must
		// not be passed through prepare() again — that is flagged as incorrect usage
		// and would re-process any literal percent signs in the prepared values.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$submissions_table} s WHERE {$where}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'transport'    => 'local',
			'form_post_id' => $form_post_id,
			'submissions'  => $records,
			'total'        => $total,
			'form_found'   => true,
		);
	}

	/**
	 * Execute remotely via a saved Remote Site Manager connection.
	 *
	 * @param int    $form_post_id  Post ID of the page containing the form.
	 * @param int    $limit         Maximum number of submissions.
	 * @param string $status        Optional status filter.
	 * @param string $element_id    Optional element/widget ID filter.
	 * @param string $connection_id Remote connection ID.
	 * @param int    $user_id       Requesting user ID.
	 * @return array|WP_Error
	 */
	protected function execute_remote( $form_post_id, $limit, $status, $element_id, $connection_id, $user_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_unavailable',
				__( 'Remote data source connections require the Pro addon.', 'mcp-ai-wpoos' )
			);
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			return new WP_Error(
				'wp_mcp_ai_connection_not_found',
				__( 'The specified remote connection was not found.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_connection_disabled',
				__( 'The specified remote connection is disabled.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $connection['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_connection_no_url',
				__( 'The remote connection has no URL configured.', 'mcp-ai-wpoos' )
			);
		}

		// Build the remote REST API request.
		$rest_url = rtrim( $connection['url'], '/' ) . '/wp-json/mcp-ai/v1/tools/execute';
		$args     = array(
			'tool'         => 'get_elementor_form_submissions',
			'form_post_id' => $form_post_id,
			'limit'        => $limit,
		);

		if ( $status ) {
			$args['status'] = $status;
		}
		if ( $element_id ) {
			$args['element_id'] = $element_id;
		}

		$request_args = array(
			'method'  => 'POST',
			'body'    => wp_json_encode( $args ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'timeout' => 30,
		);

		// Attach authentication based on connection type.
		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';
		switch ( $auth_type ) {
			case 'application_password':
				if ( ! empty( $connection['username'] ) && ! empty( $connection['password'] ) ) {
					$password = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['password'] );
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth per RFC 7617.
						$encoded                                  = base64_encode( $connection['username'] . ':' . $password );
						$request_args['headers']['Authorization'] = 'Basic ' . $encoded;
				}
				break;

			case 'basic_auth':
				if ( ! empty( $connection['username'] ) && ! empty( $connection['password'] ) ) {
					$password = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['password'] );
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth per RFC 7617.
					$encoded                                  = base64_encode( $connection['username'] . ':' . $password );
					$request_args['headers']['Authorization'] = 'Basic ' . $encoded;
				}
				break;

			case 'custom_header':
				if ( ! empty( $connection['api_key'] ) ) {
					$api_key                              = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
					$request_args['headers']['X-API-Key'] = $api_key;
				}
				break;

			case 'jwt':
				if ( ! empty( $connection['token'] ) ) {
					$token                                    = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['token'] );
					$request_args['headers']['Authorization'] = 'Bearer ' . $token;
				}
				break;

			case 'none':
			default:
				break;
		}

		$response = wp_remote_request( $rest_url, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Remote request failed: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_invalid_response',
				__( 'The remote site returned an invalid response.', 'mcp-ai-wpoos' )
			);
		}

		// Wrap the remote result with connection metadata.
		$data['transport']       = 'remote';
		$data['connection_id']   = $connection_id;
		$data['connection_name'] = isset( $connection['name'] ) ? $connection['name'] : $connection_id;

		return $data;
	}

	/**
	 * Batch-load submission field values.
	 *
	 * @param int[] $submission_ids Submission IDs.
	 * @return array<string, array<int, array{name: string, label: string, value: string}>>
	 */
	protected function load_submission_fields( array $submission_ids ) {
		global $wpdb;

		if ( empty( $submission_ids ) ) {
			return array();
		}

		$values_table = $wpdb->prefix . 'e_submissions_values';
		$placeholders = implode( ',', array_fill( 0, count( $submission_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT submission_id, `key`, `value`
						 FROM {$values_table}
						 WHERE submission_id IN ({$placeholders})
						 ORDER BY id ASC",
						...$submission_ids
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$fields_by_submission = array();
		$field_count_by_sub   = array();

		foreach ( $rows as $row ) {
			$sid = absint( $row['submission_id'] );
			if ( ! isset( $field_count_by_sub[ $sid ] ) ) {
				$field_count_by_sub[ $sid ] = 0;
			}

			// Limit to 8 fields per submission to keep responses compact.
			if ( $field_count_by_sub[ $sid ] >= 8 ) {
				continue;
			}

			++$field_count_by_sub[ $sid ];

			$name  = sanitize_key( $row['key'] );
			$label = str_replace( array( '_', '-' ), ' ', $name );
			$label = ucwords( $label );

			$value = $this->normalise_field_value( $row['value'] );

			if ( ! isset( $fields_by_submission[ $sid ] ) ) {
				$fields_by_submission[ $sid ] = array();
			}

			$fields_by_submission[ $sid ][] = array(
				'name'  => $name,
				'label' => $label,
				'value' => $value,
			);
		}

		return $fields_by_submission;
	}

	/**
	 * Produce a safe textual summary of a field value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected function normalise_field_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );

		if ( function_exists( 'wp_html_excerpt' ) ) {
			$value = wp_html_excerpt( $value, 200, '…' );
		} elseif ( strlen( $value ) > 200 ) {
			$value = substr( $value, 0, 200 ) . '…';
		}

		return $value;
	}

	/**
	 * Format a datetime string into ISO 8601.
	 *
	 * @param string $datetime Raw datetime string.
	 * @return string
	 */
	protected function format_datetime( $datetime ) {
		if ( empty( $datetime ) ) {
			return '';
		}

		if ( false !== strpos( $datetime, 'T' ) ) {
			return $datetime;
		}

		$formatted = mysql2date( DATE_W3C, $datetime, false );
		return $formatted ? $formatted : $datetime;
	}

	/**
	 * Determine whether the user can view Elementor submissions.
	 *
	 * @param int $user_id User identifier.
	 * @return bool
	 */
	protected function user_can_view_submissions( $user_id ) {
		$capabilities = array();

		// Elementor Pro's own capability checks.
		if ( class_exists( '\\ElementorPro\\Modules\\Forms\\Submissions\\Database\\Query' ) ) {
			$capabilities[] = 'manage_options';
		}

		$capabilities[] = 'edit_posts';
		$capabilities[] = 'edit_pages';

		/**
		 * Filter the capability checks used before listing Elementor form submissions.
		 *
		 * @param array $capabilities Capability strings that should grant access.
		 */
		$capabilities = apply_filters( 'wp_mcp_ai_elementor_submissions_capabilities', array_filter( array_unique( $capabilities ) ) );

		foreach ( (array) $capabilities as $capability ) {
			$capability = sanitize_key( $capability );
			if ( $capability && user_can( $user_id, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize the maximum number of submissions to return.
	 *
	 * @param mixed $value   Raw value from the assistant.
	 * @param int   $default_value Default value when input is missing.
	 * @return int
	 */
	protected function sanitize_limit( $value, $default_value ) {
		$limit = absint( $value );
		if ( $limit < 1 ) {
			$limit = $default_value;
		}

		return (int) min( 50, $limit );
	}

	/**
	 * Sanitize a submission status filter.
	 *
	 * @param mixed $status Raw status value.
	 * @return string
	 */
	protected function sanitize_status( $status ) {
		if ( ! is_string( $status ) ) {
			return '';
		}

		return sanitize_key( $status );
	}

	/**
	 * Sanitize an Elementor widget element ID.
	 *
	 * @param mixed $element_id Raw element ID.
	 * @return string
	 */
	protected function sanitize_element_id( $element_id ) {
		if ( ! is_string( $element_id ) ) {
			return '';
		}

		return sanitize_key( $element_id );
	}

	/**
	 * Sanitize the transport parameter.
	 *
	 * @param string $transport Raw transport value.
	 * @return string
	 */
	protected function sanitize_transport( $transport ) {
		$transport = is_string( $transport ) ? sanitize_key( $transport ) : 'auto';
		if ( ! in_array( $transport, array( 'auto', 'rest', 'http' ), true ) ) {
			return 'auto';
		}

		return $transport;
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'integration_external',

			'pattern_compatibility' => array( 'skill_router' ),

			'profession_tags'       => array( 'web_developer', 'data_analyst', 'marketing_manager' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'requires-capability',
			'requires-plugin',
		);
	}
}

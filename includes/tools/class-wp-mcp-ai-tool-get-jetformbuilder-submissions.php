<?php
/**
 * Tool listing JetFormBuilder submissions for a specific form.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide a condensed view of JetFormBuilder form submissions.
 */
class WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether JetFormBuilder appears to be available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_JetFormBuilder_Tool_Handlers::is_available();
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The JetFormBuilder submissions tool is disabled because JetFormBuilder is not active.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'get_jetformbuilder_submissions';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Get JetFormBuilder Submissions', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Retrieves recent JetFormBuilder submissions for a given form, including key field snapshots.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'form_id'   => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Identifier of the JetFormBuilder form whose submissions should be listed.', 'wp-mcp-ai' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'Optional submission status filter (for example success or failed).', 'wp-mcp-ai' ),
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of submissions to return (1-50).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'transport' => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'rest', 'http' ),
					'description' => __( 'Optional transport hint for the JetFormBuilder request.', 'wp-mcp-ai' ),
					'default'     => 'auto',
				),
			),
			'required'             => array( 'form_id' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_jetformbuilder_missing', __( 'JetFormBuilder is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view JetFormBuilder submissions.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! $this->user_can_view_records( $user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view JetFormBuilder submissions.', 'wp-mcp-ai' ) );
		}

		$form_id = $this->sanitize_form_id( isset( $arguments['form_id'] ) ? $arguments['form_id'] : '' );
		if ( '' === $form_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_form_id', __( 'A JetFormBuilder form identifier must be provided.', 'wp-mcp-ai' ) );
		}

		$limit     = $this->sanitize_limit( isset( $arguments['limit'] ) ? $arguments['limit'] : null, 10 );
		$status    = $this->sanitize_status( isset( $arguments['status'] ) ? $arguments['status'] : '' );
		$transport = isset( $arguments['transport'] ) ? sanitize_key( $arguments['transport'] ) : 'auto';
		if ( ! in_array( $transport, array( 'auto', 'rest', 'http' ), true ) ) {
			$transport = 'auto';
		}

		$params = array(
			'per_page' => $limit,
		);

		if ( $status ) {
			$params['status'] = $status;
		}

		$result = WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch(
			'fetch_submissions',
			array(
				'id'        => $form_id,
				'params'    => $params,
				'transport' => $transport,
			),
			array( 'user_id' => $user_id )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['success'] ) ) {
			return $this->transform_handler_error( $result );
		}

		$submissions = $this->prepare_submissions( isset( $result['data'] ) ? $result['data'] : array() );
		$output      = array(
			'transport'   => isset( $result['transport'] ) ? $result['transport'] : 'rest',
			'status'      => isset( $result['status'] ) ? (int) $result['status'] : 200,
			'form_id'     => $form_id,
			'submissions' => $submissions,
		);

		$total = $this->extract_total( $result );
		if ( null === $total ) {
			$total = count( $submissions );
		}

		$output['total'] = (int) $total;

		return $output;
	}

	/**
	 * Determine whether the user can view JetFormBuilder records.
	 *
	 * @param int $user_id User identifier.
	 * @return bool
	 */
	protected function user_can_view_records( $user_id ) {
		$capabilities = array();

		if ( class_exists( '\\Jet_Form_Builder\\Admin\\Tabs_Handlers\\Tab_Handler_Manager' ) ) {
			$capability = \Jet_Form_Builder\Admin\Tabs_Handlers\Tab_Handler_Manager::get_form_records_access_capability();
			if ( is_string( $capability ) && '' !== $capability ) {
				$capabilities[] = $capability;
			}
		}

		$capabilities[] = 'manage_options';
		$capabilities[] = 'edit_jet_fb_forms';

		/**
		 * Filter the capability checks used before listing JetFormBuilder submissions.
		 *
		 * @param array $capabilities Capability strings that should grant access.
		 */
		$capabilities = apply_filters( 'wp_mcp_ai_jetformbuilder_records_capabilities', array_filter( array_unique( $capabilities ) ) );

		foreach ( (array) $capabilities as $capability ) {
			$capability = sanitize_key( $capability );
			if ( $capability && user_can( $user_id, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize a JetFormBuilder form identifier.
	 *
	 * @param mixed $form_id Raw identifier.
	 * @return string
	 */
	protected function sanitize_form_id( $form_id ) {
		if ( is_numeric( $form_id ) ) {
			$form_id = absint( $form_id );
			return $form_id > 0 ? (string) $form_id : '';
		}

		if ( ! is_string( $form_id ) ) {
			return '';
		}

		$form_id = trim( wp_strip_all_tags( $form_id ) );
		$form_id = sanitize_text_field( $form_id );

		if ( '' === $form_id ) {
			return '';
		}

		return $form_id;
	}

	/**
	 * Sanitize the maximum number of submissions to return.
	 *
	 * @param mixed $value   Raw value from the assistant.
	 * @param int   $default Default value when input is missing.
	 * @return int
	 */
	protected function sanitize_limit( $value, $default ) {
		$limit = absint( $value );
		if ( $limit < 1 ) {
			$limit = $default;
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

		$status = sanitize_key( $status );

		return $status;
	}

	/**
	 * Prepare submissions returned by JetFormBuilder.
	 *
	 * @param mixed $payload Raw handler payload.
	 * @return array
	 */
	protected function prepare_submissions( $payload ) {
		$records = array();
		$source  = $this->coerce_list_from_payload( $payload );

		foreach ( $source as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			if ( ! $id ) {
				continue;
			}

			$status = '';
			if ( isset( $entry['status'] ) ) {
				$status = sanitize_key( (string) $entry['status'] );
			}

			$created    = '';
			$candidates = array(
				isset( $entry['created_at'] ) ? $entry['created_at'] : '',
				isset( $entry['created'] ) ? $entry['created'] : '',
				isset( $entry['date'] ) ? $entry['date'] : '',
			);

			foreach ( $candidates as $candidate ) {
				$candidate = is_string( $candidate ) ? trim( $candidate ) : '';
				if ( '' === $candidate ) {
					continue;
				}

				if ( false !== strpos( $candidate, 'T' ) ) {
					$created = $candidate;
					break;
				}

				$formatted = mysql2date( DATE_W3C, $candidate, false );
				if ( $formatted ) {
					$created = $formatted;
					break;
				}
			}

			$records[] = array(
				'id'         => $id,
				'status'     => $status,
				'created_at' => $created,
				'fields'     => $this->prepare_submission_fields( $entry ),
			);
		}

		return $records;
	}

	/**
	 * Reduce the handler payload to a sequential list.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array
	 */
	protected function coerce_list_from_payload( $payload ) {
		if ( is_array( $payload ) ) {
			if ( $this->is_sequential_array( $payload ) ) {
				return $payload;
			}

			if ( isset( $payload['list'] ) && is_array( $payload['list'] ) ) {
				return $this->coerce_list_from_payload( $payload['list'] );
			}

			if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
				return $this->coerce_list_from_payload( $payload['data'] );
			}
		}

		return array();
	}

	/**
	 * Determine whether an array uses sequential keys.
	 *
	 * @param array $array Array to inspect.
	 * @return bool
	 */
	protected function is_sequential_array( array $array ) {
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * Prepare submission field snapshots.
	 *
	 * @param array $record Raw record payload.
	 * @return array
	 */
	protected function prepare_submission_fields( array $record ) {
		$fields = array();

		$field_sets = array();
		if ( isset( $record['fields'] ) && is_array( $record['fields'] ) ) {
			$field_sets[] = $record['fields'];
		}
		if ( isset( $record['field_data'] ) && is_array( $record['field_data'] ) ) {
			$field_sets[] = $record['field_data'];
		}
		if ( isset( $record['meta'] ) && is_array( $record['meta'] ) && isset( $record['meta']['fields'] ) && is_array( $record['meta']['fields'] ) ) {
			$field_sets[] = $record['meta']['fields'];
		}

		foreach ( $field_sets as $set ) {
			foreach ( $set as $key => $field ) {
				$fields[] = $this->normalise_field_entry( $field, $key );
				if ( count( $fields ) >= 8 ) {
					break 2;
				}
			}
		}

		return array_values( array_filter( $fields ) );
	}

	/**
	 * Normalise a single submission field entry.
	 *
	 * @param mixed $field    Raw field payload.
	 * @param mixed $fallback Optional fallback key.
	 * @return array|null
	 */
	protected function normalise_field_entry( $field, $fallback ) {
		$name      = '';
		$label     = '';
		$raw_value = '';

		if ( is_array( $field ) ) {
			if ( isset( $field['name'] ) && is_string( $field['name'] ) ) {
				$name = $field['name'];
			} elseif ( isset( $field['field_name'] ) && is_string( $field['field_name'] ) ) {
				$name = $field['field_name'];
			}

			if ( isset( $field['label'] ) && is_string( $field['label'] ) ) {
				$label = $field['label'];
			} elseif ( isset( $field['title'] ) && is_string( $field['title'] ) ) {
				$label = $field['title'];
			}

			if ( array_key_exists( 'value', $field ) ) {
				$raw_value = $field['value'];
			} elseif ( array_key_exists( 'raw', $field ) ) {
				$raw_value = $field['raw'];
			} elseif ( array_key_exists( 'content', $field ) ) {
				$raw_value = $field['content'];
			}
		} else {
			$raw_value = $field;
		}

		if ( '' === $name && is_string( $fallback ) && '' !== $fallback ) {
			$name = $fallback;
		}

		$name  = sanitize_key( $name );
		$label = $this->normalise_field_label( $label, $name );
		$value = $this->normalise_field_value( $raw_value );

		if ( '' === $name && '' === $label && '' === $value ) {
			return null;
		}

		return array(
			'name'  => $name,
			'label' => $label,
			'value' => $value,
		);
	}

	/**
	 * Normalise a field label using the field name when no label exists.
	 *
	 * @param string $label Provided label.
	 * @param string $name  Field name.
	 * @return string
	 */
	protected function normalise_field_label( $label, $name ) {
		$label = is_string( $label ) ? trim( wp_strip_all_tags( $label ) ) : '';

		if ( '' !== $label ) {
			return $label;
		}

		if ( '' === $name ) {
			return '';
		}

		$generated = str_replace( array( '_', '-' ), ' ', $name );
		$generated = ucwords( $generated );

		return $generated;
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
	 * Convert a handler error payload into WP_Error.
	 *
	 * @param array $result Handler response.
	 * @return WP_Error
	 */
	protected function transform_handler_error( array $result ) {
		$error   = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : array();
		$message = isset( $error['message'] ) ? (string) $error['message'] : __( 'JetFormBuilder request failed.', 'wp-mcp-ai' );
		$code    = isset( $error['code'] ) ? sanitize_key( $error['code'] ) : 'jetformbuilder_error';
		$status  = isset( $result['status'] ) ? (int) $result['status'] : 500;

		if ( '' === $code ) {
			$code = 'jetformbuilder_error';
		}

		$code = 'wp_mcp_ai_' . $code;

		$data = array( 'status' => $status );
		if ( isset( $result['transport'] ) ) {
			$data['transport'] = $result['transport'];
		}
		if ( isset( $error['data'] ) ) {
			$data['error_data'] = $error['data'];
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Extract total counts from handler metadata.
	 *
	 * @param array $result Handler payload.
	 * @return int|null
	 */
	protected function extract_total( array $result ) {
		if ( isset( $result['headers'] ) && is_array( $result['headers'] ) ) {
			foreach ( $result['headers'] as $key => $value ) {
				if ( is_string( $key ) && 0 === strcasecmp( $key, 'X-WP-Total' ) ) {
					return (int) $value;
				}
			}
		}

		$data = isset( $result['data'] ) ? $result['data'] : null;
		if ( is_array( $data ) ) {
			if ( isset( $data['total'] ) ) {
				return (int) $data['total'];
			}

			if ( isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data']['total'] ) ) {
				return (int) $data['data']['total'];
			}
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

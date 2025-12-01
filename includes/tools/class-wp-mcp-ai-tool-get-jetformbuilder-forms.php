<?php
/**
 * Tool listing JetFormBuilder forms.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide a concise view of JetFormBuilder forms for the assistant.
 */
class WP_MCP_AI_Tool_Get_JetFormBuilder_Forms implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'The JetFormBuilder forms tool is disabled because JetFormBuilder is not active.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'get_jetformbuilder_forms';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Get JetFormBuilder Forms', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Lists JetFormBuilder forms with concise metadata for the assistant.', 'wp-mcp-ai' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'    => array(
					'type'        => 'string',
					'description' => __( 'Optional search term to match form titles.', 'wp-mcp-ai' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'Optional post status filter such as publish, draft, or any.', 'wp-mcp-ai' ),
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of forms to return (1-50).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
				),
				'transport' => array(
					'type'        => 'string',
					'enum'        => array( 'auto', 'rest', 'http' ),
					'description' => __( 'Optional transport hint for the JetFormBuilder request.', 'wp-mcp-ai' ),
					'default'     => 'auto',
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_jetformbuilder_missing', __( 'JetFormBuilder is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view JetFormBuilder forms.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! $this->user_can_manage_forms( $user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view JetFormBuilder forms.', 'wp-mcp-ai' ) );
		}

		$limit     = $this->sanitize_limit( isset( $arguments['limit'] ) ? $arguments['limit'] : null, 20 );
		$status    = $this->sanitize_status( isset( $arguments['status'] ) ? $arguments['status'] : '' );
		$search    = $this->sanitize_search( isset( $arguments['search'] ) ? $arguments['search'] : '' );
		$transport = isset( $arguments['transport'] ) ? sanitize_key( $arguments['transport'] ) : 'auto';
		if ( ! in_array( $transport, array( 'auto', 'rest', 'http' ), true ) ) {
			$transport = 'auto';
		}

		$params = array(
			'per_page' => $limit,
			'orderby'  => 'modified',
			'order'    => 'desc',
			'context'  => 'edit',
		);

		if ( $status && 'any' !== $status ) {
			$params['status'] = $status;
		}

		if ( $search ) {
			$params['search'] = $search;
		}

		$result = WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch(
			'list_forms',
			array(
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

		$forms  = $this->prepare_forms( isset( $result['data'] ) ? $result['data'] : array() );
		$output = array(
			'transport' => isset( $result['transport'] ) ? $result['transport'] : 'rest',
			'status'    => isset( $result['status'] ) ? (int) $result['status'] : 200,
			'forms'     => $forms,
		);

		$total = $this->extract_total( $result );
		if ( null === $total ) {
			$total = count( $forms );
		}

		$output['total'] = (int) $total;

		return $output;
	}

	/**
	 * Determine whether the current user can manage JetFormBuilder forms.
	 *
	 * @param int $user_id User identifier.
	 * @return bool
	 */
	protected function user_can_manage_forms( $user_id ) {
		$capabilities = array();

		$post_type_object = get_post_type_object( 'jet-form-builder' );
		if ( $post_type_object && isset( $post_type_object->cap ) ) {
			if ( ! empty( $post_type_object->cap->edit_posts ) ) {
				$capabilities[] = $post_type_object->cap->edit_posts;
			}
			if ( ! empty( $post_type_object->cap->edit_others_posts ) ) {
				$capabilities[] = $post_type_object->cap->edit_others_posts;
			}
			if ( ! empty( $post_type_object->cap->publish_posts ) ) {
				$capabilities[] = $post_type_object->cap->publish_posts;
			}
		}

		$capabilities[] = 'manage_options';
		$capabilities[] = 'edit_jet_fb_forms';

		/**
		 * Filter the capability checks used before listing JetFormBuilder forms.
		 *
		 * @param array $capabilities Capability strings that should grant access.
		 */
		$capabilities = apply_filters( 'wp_mcp_ai_jetformbuilder_forms_capabilities', array_filter( array_unique( $capabilities ) ) );

		foreach ( (array) $capabilities as $capability ) {
			$capability = sanitize_key( $capability );
			if ( $capability && user_can( $user_id, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize the maximum number of results to return.
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
	 * Sanitize a status filter.
	 *
	 * @param mixed $status Raw status.
	 * @return string
	 */
	protected function sanitize_status( $status ) {
		$status = is_string( $status ) ? strtolower( trim( $status ) ) : '';

		if ( '' === $status ) {
			return '';
		}

		if ( 'any' === $status || 'all' === $status ) {
			return 'any';
		}

		$allowed_statuses = array(
			'publish',
			'draft',
			'pending',
			'future',
			'private',
			'trash',
		);

		$status = sanitize_key( $status );
		if ( in_array( $status, $allowed_statuses, true ) ) {
			return $status;
		}

		return '';
	}

	/**
	 * Sanitize a search term.
	 *
	 * @param mixed $search Raw search string.
	 * @return string
	 */
	protected function sanitize_search( $search ) {
		if ( ! is_string( $search ) ) {
			return '';
		}

		$search = wp_strip_all_tags( $search );
		$search = trim( $search );

		return $search;
	}

	/**
	 * Prepare the list of forms returned by JetFormBuilder.
	 *
	 * @param mixed $payload Raw handler payload.
	 * @return array
	 */
	protected function prepare_forms( $payload ) {
		$forms  = array();
		$source = $this->coerce_list_from_payload( $payload );

		foreach ( $source as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			if ( ! $id ) {
				continue;
			}

			$label = '';
			if ( isset( $entry['title'] ) ) {
				if ( is_array( $entry['title'] ) && isset( $entry['title']['rendered'] ) ) {
					$label = $entry['title']['rendered'];
				} elseif ( is_scalar( $entry['title'] ) ) {
					$label = $entry['title'];
				}
			}

			$label = $label ? trim( wp_strip_all_tags( (string) $label ) ) : '';
			if ( '' === $label ) {
				/* translators: %d: JetFormBuilder form ID. */
				$label = sprintf( __( 'Form #%d', 'wp-mcp-ai' ), $id );
			}

			$slug = '';
			if ( isset( $entry['slug'] ) ) {
				$slug = sanitize_title( (string) $entry['slug'] );
			}

			$status = '';
			if ( isset( $entry['status'] ) ) {
				$status = sanitize_key( (string) $entry['status'] );
			}

			$updated    = '';
			$candidates = array(
				isset( $entry['modified_gmt'] ) ? $entry['modified_gmt'] : '',
				isset( $entry['modified'] ) ? $entry['modified'] : '',
				isset( $entry['date_gmt'] ) ? $entry['date_gmt'] : '',
				isset( $entry['date'] ) ? $entry['date'] : '',
			);

			foreach ( $candidates as $candidate ) {
				$candidate = is_string( $candidate ) ? trim( $candidate ) : '';
				if ( '' === $candidate ) {
					continue;
				}

				if ( false !== strpos( $candidate, 'T' ) ) {
					$updated = $candidate;
					break;
				}

				$formatted = mysql2date( DATE_W3C, $candidate, false );
				if ( $formatted ) {
					$updated = $formatted;
					break;
				}
			}

			$forms[] = array(
				'id'         => $id,
				'label'      => $label,
				'slug'       => $slug,
				'status'     => $status,
				'updated_at' => $updated,
				'shortcode'  => sprintf( '[jet_form_builder id="%d"]', $id ),
			);
		}

		return $forms;
	}

	/**
	 * Extract a sequential list of entries from the handler payload.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array
	 */
	protected function coerce_list_from_payload( $payload ) {
		if ( is_array( $payload ) ) {
			if ( $this->is_sequential_array( $payload ) ) {
				return $payload;
			}

			if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
				return $this->coerce_list_from_payload( $payload['data'] );
			}

			if ( isset( $payload['forms'] ) && is_array( $payload['forms'] ) ) {
				return $this->coerce_list_from_payload( $payload['forms'] );
			}
		}

		return array();
	}

	/**
	 * Determine whether the provided array uses sequential integer keys.
	 *
	 * @param array $array Array to test.
	 * @return bool
	 */
	protected function is_sequential_array( array $array ) {
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}

	/**
	 * Convert a normalised handler error payload into WP_Error.
	 *
	 * @param array $result Handler payload.
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
	 * Extract the total count from handler headers or payload when present.
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

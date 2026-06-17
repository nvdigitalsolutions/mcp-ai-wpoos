<?php
/**
 * Tool aggregating form submissions from all configured form plugins.
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
 * Provide a unified view of form submissions across JetFormBuilder,
 * Elementor Pro, and any configured remote data sources.
 *
 * This is the cross-plugin bridging tool that lets AI assistants
 * reason about "all form submissions" regardless of which form
 * builder generated them.
 */
class WP_MCP_AI_Tool_Get_All_Form_Submissions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Determine if any form submission source is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Available if at least one source is present.
		if ( class_exists( 'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions' ) ) {
			$jfb = new WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions();
			if ( $jfb->is_available() ) {
				return true;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Tool_Get_Elementor_Form_Submissions' ) ) {
			$elementor = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
			if ( $elementor->is_available() ) {
				return true;
			}
		}

		// Also available if any remote connections are configured.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			foreach ( $connections as $conn ) {
				if ( ! empty( $conn['enabled'] ) && ! empty( $conn['url'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The unified form submissions tool is disabled because no form plugin (JetFormBuilder, Elementor Pro) is active and no remote data source connections are configured.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'get_all_form_submissions';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'Get All Form Submissions', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Retrieves recent form submissions from all available sources (JetFormBuilder, Elementor Pro, and configured remote data connections) in a unified format. IMPORTANT: When looking up a specific form ID, call get_jetformbuilder_forms or get_elementor_templates FIRST to discover which forms exist and what type they are. This tool auto-detects form types when a form_id is provided, but discovery tools provide richer context about available forms and their field structures.', 'mcp-ai-wpoos' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sources'       => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'jetformbuilder', 'elementor', 'remote' ),
					),
					'description' => __( 'Optional list of sources to query. Omit to query all available sources. Valid values: jetformbuilder, elementor, remote.', 'mcp-ai-wpoos' ),
				),
				'form_id'       => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Optional form ID to filter results. For JetFormBuilder this is the form post ID; for Elementor this is the page post ID.', 'mcp-ai-wpoos' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Optional submission status filter (e.g. success, failed).', 'mcp-ai-wpoos' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of submissions per source (1-50).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional remote connection ID from the Remote Site Manager. When provided, only queries the specified remote connection.', 'mcp-ai-wpoos' ),
				),
			),
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
			return new WP_Error( 'wp_mcp_ai_no_form_sources', self::get_unavailable_reason() );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view form submissions.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$limit         = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit         = min( 50, max( 1, $limit ) );
		$status        = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$form_id       = isset( $arguments['form_id'] ) ? $arguments['form_id'] : '';
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		$user_requested_sources = isset( $arguments['sources'] ) && is_array( $arguments['sources'] )
			? array_map( 'sanitize_key', $arguments['sources'] )
			: array();

		$form_id_int = $form_id ? absint( $form_id ) : 0;

		// When a specific form_id is provided, auto-detect its type to avoid
		// querying incompatible sources and misleading the AI about which
		// form builder owns the form.
		$detected_form_type = '';
		if ( $form_id_int && empty( $user_requested_sources ) ) {
			$detected_form_type = $this->detect_form_type( $form_id_int, $context );
		}

		if ( $form_id_int && empty( $user_requested_sources ) && $detected_form_type ) {
			// Auto-scope to the detected source only.
			if ( 'jetformbuilder' === $detected_form_type ) {
				$requested_sources = array( 'jetformbuilder' );
			} elseif ( 'elementor' === $detected_form_type ) {
				$requested_sources = array( 'elementor' );
			} else {
				$requested_sources = array( 'jetformbuilder', 'elementor' );
			}
		} elseif ( empty( $user_requested_sources ) ) {
			$requested_sources = array( 'jetformbuilder', 'elementor', 'remote' );
		} else {
			$requested_sources = $user_requested_sources;
		}

		$all_submissions = array();
		$totals          = array();
		$errors          = array();

		// Query JetFormBuilder.
		if ( in_array( 'jetformbuilder', $requested_sources, true )
		&& class_exists( 'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions' )
		) {
			$jfb_tool = new WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions();
			if ( $jfb_tool->is_available() ) {
				// If no specific form_id is given, discover all JetFormBuilder
				// forms so the unified tool can aggregate across them.
				$jfb_form_ids = $form_id ? array( $form_id ) : array();
				if ( empty( $jfb_form_ids ) ) {
					// Always query the records table directly for form IDs
					// that actually have submissions. This is the authoritative
					// source: it finds every form that has stored record data,
					// including forms that may no longer exist as CPT posts.
					$jfb_form_ids = $this->discover_jfb_forms_local();

					// Supplement with forms discovered via the REST API
					// (which may include forms that have no records yet
					// but exist as CPT posts).
					if ( class_exists( 'WP_MCP_AI_Tool_Get_JetFormBuilder_Forms' ) ) {
						$forms_tool   = new WP_MCP_AI_Tool_Get_JetFormBuilder_Forms();
						$forms_result = $forms_tool->execute(
							array( 'limit' => 50 ),
							$context
						);
						if ( ! is_wp_error( $forms_result ) && ! empty( $forms_result['forms'] ) ) {
							foreach ( $forms_result['forms'] as $form ) {
								if ( ! empty( $form['id'] ) ) {
									$jfb_form_ids[] = $form['id'];
								}
							}
						} elseif ( is_wp_error( $forms_result ) ) {
							$errors['jetformbuilder_forms'] = $forms_result->get_error_message();
						}
					}

					// Deduplicate form IDs.
					$jfb_form_ids = array_values( array_unique( $jfb_form_ids ) );
				}

				$jfb_running_total = 0;
				foreach ( $jfb_form_ids as $current_form_id ) {
					$jfb_args = array(
						'limit'     => $limit,
						'form_id'   => $current_form_id,
						'transport' => $connection_id ? 'http' : 'auto',
					);
					if ( $status ) {
						$jfb_args['status'] = $status;
					}
					if ( $connection_id ) {
						$jfb_args['connection_id'] = $connection_id;
					}

					$jfb_result = $jfb_tool->execute( $jfb_args, $context );

					if ( is_wp_error( $jfb_result ) ) {
						$errors['jetformbuilder'] = $jfb_result->get_error_message();
					} else {
						$subs = isset( $jfb_result['submissions'] ) ? $jfb_result['submissions'] : array();
						foreach ( $subs as &$sub ) {
							$sub['source'] = 'jetformbuilder';
						}
						unset( $sub );
						$all_submissions    = array_merge( $all_submissions, $subs );
						$jfb_running_total += isset( $jfb_result['total'] ) ? (int) $jfb_result['total'] : count( $subs );

						// Pass through form_found signal from the JFB tool.
						if ( isset( $jfb_result['form_found'] ) ) {
							$totals['jetformbuilder_form_found'] = (bool) $jfb_result['form_found'];
						}
					}
				}
				$totals['jetformbuilder'] = $jfb_running_total;
			}
		}

		// Query Elementor Pro.
		if ( in_array( 'elementor', $requested_sources, true )
			&& class_exists( 'WP_MCP_AI_Tool_Get_Elementor_Form_Submissions' )
		) {
			$elementor_tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
			if ( $elementor_tool->is_available() ) {
				// If no specific form_id is given, discover all Elementor
				// form post IDs from the submissions table.
				$el_form_ids = $form_id ? array( $form_id ) : $this->discover_elementor_forms_local();

				if ( empty( $el_form_ids ) && ! $form_id ) {
					// No Elementor forms have submissions yet.
					$totals['elementor'] = 0;
				} else {
					$el_running_total = 0;
					foreach ( $el_form_ids as $current_el_id ) {
						$el_args = array(
							'limit'        => $limit,
							'form_post_id' => $current_el_id,
						);
						if ( $status ) {
							$el_args['status'] = $status;
						}
						if ( $connection_id ) {
							$el_args['connection_id'] = $connection_id;
						}

						$el_result = $elementor_tool->execute( $el_args, $context );

						if ( is_wp_error( $el_result ) ) {
							$errors['elementor'] = $el_result->get_error_message();
						} else {
							$subs = isset( $el_result['submissions'] ) ? $el_result['submissions'] : array();
							foreach ( $subs as &$sub ) {
								$sub['source'] = 'elementor';
							}
							unset( $sub );
							$all_submissions   = array_merge( $all_submissions, $subs );
							$el_running_total += isset( $el_result['total'] ) ? (int) $el_result['total'] : count( $subs );

							// Pass through form_found signal from the Elementor tool.
							if ( isset( $el_result['form_found'] ) ) {
								$key            = 'elementor_form_found_' . $current_el_id;
								$totals[ $key ] = (bool) $el_result['form_found'];
							}
						}
					}
					$totals['elementor'] = $el_running_total;
				}
			}
		}

		// Query remote data sources.
		if ( in_array( 'remote', $requested_sources, true )
			&& class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' )
		) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

			foreach ( $connections as $conn_id => $conn ) {
				// Skip if we're targeting a specific connection and this isn't it.
				if ( $connection_id && $connection_id !== $conn_id ) {
					continue;
				}

				// Only query enabled connections with a URL.
				if ( empty( $conn['enabled'] ) || empty( $conn['url'] ) ) {
					continue;
				}

				// Check if this connection is a WordPress site (likely to have form data).
				$conn_type = isset( $conn['connection_type'] ) ? $conn['connection_type'] : '';
				if ( ! in_array( $conn_type, array( 'wordpress', 'form_data_source', 'generic' ), true ) ) {
					continue;
				}

				// Try to query both form plugin types from the remote.
				if ( class_exists( 'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions' ) ) {
					$jfb_tool = new WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions();
					if ( $jfb_tool->is_available() ) {
						$remote_args = array(
							'limit'         => $limit,
							'transport'     => 'http',
							'connection_id' => $conn_id,
						);
						if ( $status ) {
							$remote_args['status'] = $status;
						}
						if ( $form_id ) {
							$remote_args['form_id'] = $form_id;
						}

						// Pass connection_id through context for remote dispatch.
						$remote_context = array_merge( $context, array( 'remote_connection_id' => $conn_id ) );
						$remote_result  = $jfb_tool->execute( $remote_args, $remote_context );

						if ( ! is_wp_error( $remote_result ) ) {
							$subs       = isset( $remote_result['submissions'] ) ? $remote_result['submissions'] : array();
							$conn_label = isset( $conn['name'] ) ? $conn['name'] : $conn_id;
							foreach ( $subs as &$sub ) {
								$sub['source']          = 'jetformbuilder';
								$sub['connection']      = $conn_id;
								$sub['connection_name'] = $conn_label;
							}
							unset( $sub );
							$all_submissions = array_merge( $all_submissions, $subs );
							$key             = 'remote_jfb_' . $conn_id;
							$totals[ $key ]  = isset( $remote_result['total'] ) ? (int) $remote_result['total'] : count( $subs );
						}
					}
				}

				if ( class_exists( 'WP_MCP_AI_Tool_Get_Elementor_Form_Submissions' ) ) {
					$elementor_tool = new WP_MCP_AI_Tool_Get_Elementor_Form_Submissions();
					if ( $elementor_tool->is_available() && $form_id ) {
						$remote_args = array(
							'limit'         => $limit,
							'connection_id' => $conn_id,
							'form_post_id'  => $form_id,
						);
						if ( $status ) {
							$remote_args['status'] = $status;
						}

						$remote_result = $elementor_tool->execute( $remote_args, $context );

						if ( ! is_wp_error( $remote_result ) ) {
							$subs       = isset( $remote_result['submissions'] ) ? $remote_result['submissions'] : array();
							$conn_label = isset( $conn['name'] ) ? $conn['name'] : $conn_id;
							foreach ( $subs as &$sub ) {
								$sub['source']          = 'elementor';
								$sub['connection']      = $conn_id;
								$sub['connection_name'] = $conn_label;
							}
							unset( $sub );
							$all_submissions = array_merge( $all_submissions, $subs );
							$key             = 'remote_elementor_' . $conn_id;
							$totals[ $key ]  = isset( $remote_result['total'] ) ? (int) $remote_result['total'] : count( $subs );
						}
					}
				}
			}
		}

		// Sort all submissions by date descending.
		usort(
			$all_submissions,
			function ( $a, $b ) {
				$date_a = isset( $a['created_at'] ) ? $a['created_at'] : '';
				$date_b = isset( $b['created_at'] ) ? $b['created_at'] : '';
				return strcmp( $date_b, $date_a );
			}
		);

		$output = array(
			'submissions'      => $all_submissions,
			'total'            => count( $all_submissions ),
			'totals_by_source' => $totals,
		);

		if ( $errors ) {
			$output['errors'] = $errors;
		}

		// Include form-type detection when a specific form_id was queried.
		if ( $form_id_int && $detected_form_type ) {
			$output['form_type_detected'] = $detected_form_type;
		} elseif ( $form_id_int ) {
			$output['form_type_detected'] = 'unknown';
			$output['form_type_hint']     = __(
				'Could not determine which form builder owns this form ID. Use get_jetformbuilder_forms to list JetFormBuilder forms and get_elementor_templates to list Elementor templates, then query the matching submission tool directly.',
				'mcp-ai-wpoos'
			);
		}

		return $output;
	}

	/**
	 * Discover JetFormBuilder form IDs from the records table directly,
	 * bypassing the REST API when its endpoints are unavailable.
	 *
	 * This fallback queries the `jet_fb_records` table for distinct form
	 * IDs, providing the same information that `get_jetformbuilder_forms`
	 * would return through the REST API — but without depending on the
	 * REST route being registered or accessible.
	 *
	 * @since 1.2.0
	 *
	 * @return int[] Form IDs found in the records table.
	 */
	private function discover_jfb_forms_local() {
		global $wpdb;

		$records_table = $wpdb->prefix . 'jet_fb_records';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $records_table )
		);

		if ( ! $table_exists ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$form_ids = $wpdb->get_col(
			"SELECT DISTINCT form_id FROM {$records_table} ORDER BY form_id DESC LIMIT 50"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $form_ids ) ) {
			return array();
		}

		return array_map( 'absint', $form_ids );
	}

	/**
	 * Discover Elementor form post IDs from the e_submissions table.
	 *
	 * Returns distinct post_id values that have form submissions,
	 * analogous to discover_jfb_forms_local() for JetFormBuilder.
	 *
	 * @since 1.9.5
	 *
	 * @return int[] Post IDs found in the submissions table.
	 */
	private function discover_elementor_forms_local() {
		global $wpdb;

		$submissions_table = $wpdb->prefix . 'e_submissions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions_table )
		);

		if ( ! $table_exists ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$form_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$submissions_table} WHERE type = 'form' ORDER BY post_id DESC LIMIT 50"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $form_ids ) ) {
			return array();
		}

		return array_map( 'absint', $form_ids );
	}

	/**
	 * Detect which form builder a given form ID belongs to.
	 *
	 * Checks JetFormBuilder first (post type check + forms list),
	 * then Elementor (submissions table check). Returns an empty
	 * string when the form type cannot be determined.
	 *
	 * @since 1.9.5
	 *
	 * @param int   $form_id The form ID to classify.
	 * @param array $context Execution context.
	 * @return string 'jetformbuilder', 'elementor', or '' (unknown).
	 */
	private function detect_form_type( $form_id, $context ) {
		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return '';
		}

		// 1. Check if this is a JetFormBuilder form.
		$is_jfb = $this->is_jetformbuilder_form( $form_id, $context );
		if ( $is_jfb ) {
			return 'jetformbuilder';
		}

		// 2. Check if this is an Elementor form (has submissions in e_submissions).
		$is_elementor = $this->is_elementor_form( $form_id );
		if ( $is_elementor ) {
			return 'elementor';
		}

		// 3. Fallback: check if the post exists and might be a form host.
		$post = get_post( $form_id );
		if ( ! $post ) {
			return '';
		}

		// If the post has Elementor data (stored in post meta), it's likely
		// an Elementor page that may contain a form widget.
		if ( get_post_meta( $form_id, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}

		return '';
	}

	/**
	 * Check if a form ID belongs to a JetFormBuilder form.
	 *
	 * @param int   $form_id The form ID.
	 * @param array $context Execution context.
	 * @return bool
	 */
	private function is_jetformbuilder_form( $form_id, $context ) {
		// Check if the post type is a known JetFormBuilder CPT.
		$post_type = get_post_type( $form_id );
		if ( $post_type && 'jet-form-builder' === $post_type ) {
			return true;
		}

		// Query the JFB forms list to see if this ID is present.
		if ( class_exists( 'WP_MCP_AI_Tool_Get_JetFormBuilder_Forms' )
			&& WP_MCP_AI_Tool_Get_JetFormBuilder_Forms::is_available()
		) {
			$forms_tool   = new WP_MCP_AI_Tool_Get_JetFormBuilder_Forms();
			$forms_result = $forms_tool->execute(
				array( 'limit' => 50 ),
				$context
			);
			if ( ! is_wp_error( $forms_result ) && ! empty( $forms_result['forms'] ) ) {
				foreach ( $forms_result['forms'] as $form ) {
					if ( (int) $form['id'] === $form_id ) {
						return true;
					}
				}
			}
		}

		// Last resort: check the jet_fb_records table for this form_id.
		global $wpdb;
		$records_table = $wpdb->prefix . 'jet_fb_records';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $records_table )
		);
		if ( $table_exists ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$records_table} WHERE form_id = %d",
					$form_id
				)
			);
			if ( $count > 0 ) {
				return true;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false;
	}

	/**
	 * Check if a form ID has submissions in the Elementor e_submissions table.
	 *
	 * @param int $form_id The form (post) ID.
	 * @return bool
	 */
	private function is_elementor_form( $form_id ) {
		global $wpdb;

		$submissions_table = $wpdb->prefix . 'e_submissions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $submissions_table )
		);

		if ( ! $table_exists ) {
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return false;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$submissions_table} WHERE post_id = %d AND type = %s",
				$form_id,
				'form'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count > 0;
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

			'profession_tags'       => array( 'web_developer', 'data_analyst', 'marketing_manager', 'business_analyst' ),

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
			'paginated',
		);
	}
}

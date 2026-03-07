<?php
/**
 * Tool for compiling health data from multiple sources for research.
 *
 * Aggregates vital signs (from JetEngine CCT or options storage), medical
 * records, prescriptions, checkups, allergies, and any attached files or
 * vector-store context associated with the member's assistant, then returns
 * a single structured payload suitable for AI-powered health analysis.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles health data from all available sources for research and analysis.
 */
class WP_MCP_AI_Tool_Compile_Health_Research_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'compile_health_research_data';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Compile Health Research Data', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compile a member\'s complete health data from all available sources — JetEngine CCT vital signs, options-based vital signs, medical records, prescriptions, checkups, allergies, attached media files, and vector store context from the AI assistant — into a single structured research payload. Ideal for generating health summaries, trend reports, or priming an AI assistant with current patient context.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Member post ID (mcp_ai_member) to compile data for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'days_back'          => array(
					'type'        => 'integer',
					'description' => __( 'Number of days of vital-sign history to include (optional, default: 90)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 90,
				),
				'include_vitals'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include vital signs history (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_records'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include medical records, prescriptions, checkups, and allergies (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_files'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include references to files attached to the member post (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_vector_context' => array(
					'type'        => 'boolean',
					'description' => __( 'Include vector store / corpus IDs from the active AI assistant for RAG context (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'assistant_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Assistant post ID to read vector_store_id / corpus_name from (optional; auto-detected from context if omitted)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'vital_types'        => array(
					'type'        => 'array',
					'description' => __( 'Limit vital sign history to specific types, e.g. ["blood_pressure","heart_rate"]. Omit or leave empty to include all types (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'output_format'      => array(
					'type'        => 'string',
					'description' => __( 'Output format (optional, default: structured)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'structured', 'narrative', 'fhir_bundle' ),
					'default'     => 'structured',
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'pii-data', 'hipaa-relevant' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access health data.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$days_back              = isset( $arguments['days_back'] ) ? absint( $arguments['days_back'] ) : 90;
		$include_vitals         = isset( $arguments['include_vitals'] ) ? (bool) $arguments['include_vitals'] : true;
		$include_records        = isset( $arguments['include_records'] ) ? (bool) $arguments['include_records'] : true;
		$include_files          = isset( $arguments['include_files'] ) ? (bool) $arguments['include_files'] : true;
		$include_vector_context = isset( $arguments['include_vector_context'] ) ? (bool) $arguments['include_vector_context'] : true;
		$vital_types_filter     = isset( $arguments['vital_types'] ) && is_array( $arguments['vital_types'] ) ? $arguments['vital_types'] : array();
		$output_format          = isset( $arguments['output_format'] ) ? sanitize_key( $arguments['output_format'] ) : 'structured';
		$assistant_id           = isset( $arguments['assistant_id'] ) ? absint( $arguments['assistant_id'] ) : 0;

		$payload = array(
			'member'          => $this->get_member_demographics( $member ),
			'data_sources'    => array(),
			'compiled_at'     => current_time( 'c' ),
			'days_back'       => $days_back,
		);

		// ── Vital signs ───────────────────────────────────────────────────
		if ( $include_vitals ) {
			$vitals_data = $this->compile_vitals( $member_id, $days_back, $vital_types_filter );
			$payload['vital_signs']  = $vitals_data['data'];
			$payload['data_sources'] = array_merge( $payload['data_sources'], $vitals_data['sources'] );
		}

		// ── Medical records, prescriptions, checkups, allergies ───────────
		if ( $include_records ) {
			$records_data = $this->compile_health_records( $member_id );
			$payload['health_records'] = $records_data;
			$payload['data_sources'][] = 'wordpress_cpts';
		}

		// ── Attached files ────────────────────────────────────────────────
		if ( $include_files ) {
			$files_data = $this->compile_attached_files( $member_id );
			if ( ! empty( $files_data ) ) {
				$payload['attached_files'] = $files_data;
				$payload['data_sources'][] = 'media_library';
			}
		}

		// ── Vector store / corpus context ─────────────────────────────────
		if ( $include_vector_context ) {
			$vc = $this->compile_vector_context( $assistant_id, $context );
			if ( ! empty( $vc ) ) {
				$payload['vector_context'] = $vc;
				$payload['data_sources'][] = 'vector_store';
			}
		}

		$payload['data_sources'] = array_unique( $payload['data_sources'] );

		// ── Format output ─────────────────────────────────────────────────
		if ( 'narrative' === $output_format ) {
			$payload['narrative'] = $this->render_narrative( $payload, $member );
		} elseif ( 'fhir_bundle' === $output_format ) {
			$payload['fhir_bundle'] = $this->render_fhir_bundle( $payload, $member );
		}

		return array(
			'success'       => true,
			'member_id'     => $member_id,
			'output_format' => $output_format,
			'payload'       => $payload,
		);
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Gather member demographic fields.
	 *
	 * @param WP_Post $member Member post.
	 * @return array
	 */
	private function get_member_demographics( $member ) {
		$meta_keys = array(
			'member_type', 'date_of_birth', 'gender', 'blood_type',
			'email', 'phone', 'emergency_contact',
			'species', 'breed',
		);

		$demographics = array(
			'id'   => $member->ID,
			'name' => $member->post_title,
		);

		foreach ( $meta_keys as $key ) {
			$val = get_post_meta( $member->ID, $key, true );
			if ( '' !== $val && null !== $val ) {
				$demographics[ $key ] = $val;
			}
		}

		return $demographics;
	}

	/**
	 * Compile vital signs from CCT and/or options storage.
	 *
	 * @param int   $member_id Member ID.
	 * @param int   $days_back Days of history.
	 * @param array $types     Limit to specific vital types (empty = all).
	 * @return array{ data: array, sources: array }
	 */
	private function compile_vitals( $member_id, $days_back, array $types ) {
		$sources = array();
		$records = array();

		// ── CCT source ────────────────────────────────────────────────────
		if ( class_exists( 'WP_MCP_AI_JetEngine_Vitals_CCT' ) && WP_MCP_AI_JetEngine_Vitals_CCT::table_exists() ) {
			$after_date = gmdate( 'Y-m-d', time() - ( $days_back * DAY_IN_SECONDS ) );
			$rows       = WP_MCP_AI_JetEngine_Vitals_CCT::get_for_member( $member_id, $after_date );

			foreach ( $rows as $row ) {
				$row_arr = (array) $row;
				if ( ! empty( $types ) ) {
					$row_arr = $this->filter_vital_types( $row_arr, $types );
				}
				$records[] = $row_arr;
			}

			if ( ! empty( $records ) ) {
				$sources[] = 'jetengine_cct';
			}
		}

		// ── Options fallback ──────────────────────────────────────────────
		$vital_signs_key  = 'wp_mcp_ai_vital_signs_' . $member_id;
		$vital_signs      = get_option( $vital_signs_key, array() );
		$cutoff_timestamp = time() - ( $days_back * DAY_IN_SECONDS );
		$opt_records      = array();

		foreach ( $vital_signs as $entry ) {
			if ( ! isset( $entry['timestamp'] ) || $entry['timestamp'] < $cutoff_timestamp ) {
				continue;
			}
			if ( ! empty( $types ) && isset( $entry['measurements'] ) ) {
				$entry['measurements'] = array_intersect_key(
					$entry['measurements'],
					array_flip( $types )
				);
				if ( empty( $entry['measurements'] ) ) {
					continue;
				}
			}
			$opt_records[] = $entry;
		}

		if ( ! empty( $opt_records ) ) {
			$sources[] = 'options_storage';
			// Merge only if CCT didn't already supply data to avoid duplicates.
			if ( empty( $records ) ) {
				$records = $opt_records;
			}
		}

		return array(
			'data'    => $records,
			'sources' => $sources,
		);
	}

	/**
	 * Keep only columns that correspond to the requested vital types.
	 *
	 * @param array $row   CCT row as array.
	 * @param array $types Requested vital types.
	 * @return array
	 */
	private function filter_vital_types( array $row, array $types ) {
		// Map vital type names to CCT column groups.
		$type_columns = array(
			'blood_pressure'    => array( 'bp_systolic', 'bp_diastolic', 'bp_status' ),
			'heart_rate'        => array( 'heart_rate', 'heart_rate_status' ),
			'temperature'       => array( 'temperature', 'temperature_unit', 'temperature_status' ),
			'weight'            => array( 'weight', 'weight_unit', 'bmi', 'bmi_status' ),
			'blood_glucose'     => array( 'blood_glucose', 'blood_glucose_status' ),
			'oxygen_saturation' => array( 'oxygen_saturation', 'oxygen_saturation_status' ),
			'respiratory_rate'  => array( 'respiratory_rate', 'respiratory_rate_status' ),
			'egfr'              => array( 'egfr' ),
			'creatinine'        => array( 'creatinine' ),
			'bun'               => array( 'bun' ),
			'potassium'         => array( 'potassium' ),
			'sodium'            => array( 'sodium' ),
			'phosphorus'        => array( 'phosphorus' ),
			'albumin'           => array( 'albumin' ),
		);

		// Always keep metadata columns.
		$always_keep = array( '_ID', 'member_id', 'measurement_date', 'measurement_time', 'source', 'notes', 'logged_by', 'entry_id', 'cct_created', 'cct_modified' );

		$allowed = $always_keep;
		foreach ( $types as $type ) {
			$type = sanitize_key( $type );
			if ( isset( $type_columns[ $type ] ) ) {
				$allowed = array_merge( $allowed, $type_columns[ $type ] );
			}
		}

		return array_intersect_key( $row, array_flip( $allowed ) );
	}

	/**
	 * Compile health records from WordPress CPTs.
	 *
	 * @param int $member_id Member post ID.
	 * @return array
	 */
	private function compile_health_records( $member_id ) {
		$result = array();

		// Medical records.
		$records_query = new WP_Query( array(
			'post_type'      => 'mcp_ai_med_record',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'meta_value',
			'meta_key'       => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => 'member_id',
					'value'   => $member_id,
					'compare' => '=',
				),
			),
		) );
		foreach ( $records_query->posts as $post ) {
			$type = get_the_terms( $post->ID, 'mcp_ai_record_type' );
			$result['medical_records'][] = array(
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'type'        => ( $type && ! is_wp_error( $type ) ) ? $type[0]->name : '',
				'date'        => get_post_meta( $post->ID, 'date', true ),
				'provider'    => get_post_meta( $post->ID, 'provider', true ),
				'description' => wp_trim_words( $post->post_content, 50 ),
			);
		}

		// Prescriptions.
		$rx_query = new WP_Query( array(
			'post_type'      => 'mcp_ai_prescription',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'meta_query'     => array(
				array(
					'key'     => 'member_id',
					'value'   => $member_id,
					'compare' => '=',
				),
			),
		) );
		foreach ( $rx_query->posts as $post ) {
			$end_date = get_post_meta( $post->ID, 'end_date', true );
			if ( $end_date && $end_date < current_time( 'Y-m-d' ) ) {
				continue; // Skip expired prescriptions.
			}
			$result['prescriptions'][] = array(
				'id'         => $post->ID,
				'medication' => $post->post_title,
				'dosage'     => get_post_meta( $post->ID, 'dosage', true ),
				'frequency'  => get_post_meta( $post->ID, 'frequency', true ),
				'start_date' => get_post_meta( $post->ID, 'start_date', true ),
				'end_date'   => $end_date,
			);
		}

		// Upcoming checkups (next 180 days).
		$checkup_query = new WP_Query( array(
			'post_type'      => 'mcp_ai_checkup',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			'orderby'        => 'meta_value',
			'meta_key'       => 'date',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => 'member_id',
					'value'   => $member_id,
					'compare' => '=',
				),
				array(
					'key'     => 'date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
		) );
		foreach ( $checkup_query->posts as $post ) {
			$result['upcoming_checkups'][] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'date'     => get_post_meta( $post->ID, 'date', true ),
				'time'     => get_post_meta( $post->ID, 'time', true ),
				'provider' => get_post_meta( $post->ID, 'provider', true ),
				'location' => get_post_meta( $post->ID, 'location', true ),
			);
		}

		// Allergies.
		$allergy_query = new WP_Query( array(
			'post_type'      => 'mcp_ai_allergy',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => array(
				array(
					'key'     => 'member_id',
					'value'   => $member_id,
					'compare' => '=',
				),
			),
		) );
		foreach ( $allergy_query->posts as $post ) {
			$severity = get_the_terms( $post->ID, 'mcp_ai_allergy_severity' );
			$result['allergies'][] = array(
				'id'       => $post->ID,
				'allergen' => $post->post_title,
				'severity' => ( $severity && ! is_wp_error( $severity ) ) ? $severity[0]->name : '',
				'reaction' => get_post_meta( $post->ID, 'reaction', true ),
			);
		}

		return $result;
	}

	/**
	 * List files attached to the member post.
	 *
	 * @param int $member_id Member post ID.
	 * @return array
	 */
	private function compile_attached_files( $member_id ) {
		$attachments = get_posts( array(
			'post_type'      => 'attachment',
			'post_parent'    => $member_id,
			'post_status'    => 'inherit',
			'posts_per_page' => 50,
		) );

		$files = array();
		foreach ( $attachments as $att ) {
			$files[] = array(
				'attachment_id' => $att->ID,
				'filename'      => basename( get_attached_file( $att->ID ) ),
				'url'           => wp_get_attachment_url( $att->ID ),
				'mime_type'     => $att->post_mime_type,
				'title'         => $att->post_title,
				'date'          => $att->post_date,
			);
		}

		// Also check for openai_file_id stored on the member post.
		$openai_file_ids = get_post_meta( $member_id, '_wp_mcp_ai_openai_file_ids', true );
		if ( ! empty( $openai_file_ids ) ) {
			foreach ( (array) $openai_file_ids as $file_id ) {
				$files[] = array(
					'openai_file_id' => sanitize_text_field( $file_id ),
					'source'         => 'openai',
				);
			}
		}

		return $files;
	}

	/**
	 * Retrieve vector-store / corpus context from the active assistant.
	 *
	 * Reads vector_store_id (OpenAI) and corpus_name (Gemini) from the assistant
	 * post meta so downstream AI calls can inject the right retrieval context.
	 *
	 * @param int   $assistant_id Explicit assistant post ID (0 = auto-detect).
	 * @param array $context      Tool execution context.
	 * @return array
	 */
	private function compile_vector_context( $assistant_id, array $context ) {
		$vc = array();

		// Auto-detect from context if not provided.
		if ( ! $assistant_id && isset( $context['assistant_id'] ) ) {
			$assistant_id = absint( $context['assistant_id'] );
		}

		if ( ! $assistant_id ) {
			return $vc;
		}

		$assistant = get_post( $assistant_id );
		if ( ! $assistant || 'mcp_ai_assistant' !== $assistant->post_type ) {
			return $vc;
		}

		$vector_store_id = get_post_meta( $assistant_id, '_wp_mcp_ai_vector_store_id', true );
		if ( $vector_store_id ) {
			$vc['vector_store_id'] = sanitize_text_field( $vector_store_id );
			$vc['provider']        = 'openai';
		}

		$corpus_name = get_post_meta( $assistant_id, '_wp_mcp_ai_corpus_name', true );
		if ( $corpus_name ) {
			$vc['corpus_name'] = sanitize_text_field( $corpus_name );
			$vc['provider']    = isset( $vc['provider'] ) ? 'mixed' : 'gemini';
		}

		// Attached file IDs on the assistant.
		$attached_file_ids = get_post_meta( $assistant_id, '_wp_mcp_ai_attached_file_ids', true );
		if ( ! empty( $attached_file_ids ) ) {
			$vc['attached_file_ids'] = (array) $attached_file_ids;
		}

		if ( ! empty( $vc ) ) {
			$vc['assistant_id']   = $assistant_id;
			$vc['assistant_name'] = $assistant->post_title;
		}

		return $vc;
	}

	/**
	 * Render a free-text narrative summary from the compiled payload.
	 *
	 * @param array   $payload  Compiled data payload.
	 * @param WP_Post $member   Member post.
	 * @return string
	 */
	private function render_narrative( array $payload, $member ) {
		$lines = array();

		$lines[] = sprintf(
			/* translators: 1: member name, 2: compiled date */
			__( 'Health Research Compilation for %1$s — compiled %2$s', 'mcp-ai-wpoos-pro' ),
			esc_html( $member->post_title ),
			esc_html( $payload['compiled_at'] )
		);

		$demographics = $payload['member'];
		if ( ! empty( $demographics['date_of_birth'] ) ) {
			$lines[] = sprintf( __( 'Date of birth: %s', 'mcp-ai-wpoos-pro' ), esc_html( $demographics['date_of_birth'] ) );
		}
		if ( ! empty( $demographics['blood_type'] ) ) {
			$lines[] = sprintf( __( 'Blood type: %s', 'mcp-ai-wpoos-pro' ), esc_html( $demographics['blood_type'] ) );
		}

		if ( ! empty( $payload['vital_signs'] ) ) {
			$count   = count( $payload['vital_signs'] );
			$lines[] = sprintf(
				/* translators: %d: number of measurements */
				_n( '%d vital-sign measurement in the selected period.', '%d vital-sign measurements in the selected period.', $count, 'mcp-ai-wpoos-pro' ),
				$count
			);
		}

		if ( ! empty( $payload['health_records']['allergies'] ) ) {
			$allergens = wp_list_pluck( $payload['health_records']['allergies'], 'allergen' );
			$lines[]   = sprintf( __( 'Known allergies: %s', 'mcp-ai-wpoos-pro' ), implode( ', ', array_map( 'esc_html', $allergens ) ) );
		}

		if ( ! empty( $payload['health_records']['prescriptions'] ) ) {
			$meds    = wp_list_pluck( $payload['health_records']['prescriptions'], 'medication' );
			$lines[] = sprintf( __( 'Active medications: %s', 'mcp-ai-wpoos-pro' ), implode( ', ', array_map( 'esc_html', $meds ) ) );
		}

		if ( ! empty( $payload['vector_context']['vector_store_id'] ) ) {
			$lines[] = sprintf( __( 'Vector store available for RAG: %s', 'mcp-ai-wpoos-pro' ), esc_html( $payload['vector_context']['vector_store_id'] ) );
		}
		if ( ! empty( $payload['vector_context']['corpus_name'] ) ) {
			$lines[] = sprintf( __( 'Gemini corpus available for RAG: %s', 'mcp-ai-wpoos-pro' ), esc_html( $payload['vector_context']['corpus_name'] ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a minimal FHIR-style bundle array from the compiled payload.
	 *
	 * This is a simplified representation, not a spec-compliant FHIR R4 bundle.
	 * Use the dedicated export_fhir_data tool for full compliance.
	 *
	 * @param array   $payload Compiled data payload.
	 * @param WP_Post $member  Member post.
	 * @return array
	 */
	private function render_fhir_bundle( array $payload, $member ) {
		$bundle = array(
			'resourceType' => 'Bundle',
			'type'         => 'collection',
			'timestamp'    => $payload['compiled_at'],
			'entry'        => array(),
		);

		// Patient resource.
		$dem = $payload['member'];
		$bundle['entry'][] = array(
			'resource' => array(
				'resourceType' => 'Patient',
				'id'           => 'member-' . $member->ID,
				'name'         => array( array( 'text' => $member->post_title ) ),
				'birthDate'    => isset( $dem['date_of_birth'] ) ? $dem['date_of_birth'] : '',
				'gender'       => isset( $dem['gender'] ) ? $dem['gender'] : '',
			),
		);

		// Observations (vitals).
		if ( ! empty( $payload['vital_signs'] ) ) {
			foreach ( $payload['vital_signs'] as $vs ) {
				$bundle['entry'][] = array(
					'resource' => array(
						'resourceType'      => 'Observation',
						'status'            => 'final',
						'subject'           => array( 'reference' => 'Patient/member-' . $member->ID ),
						'effectiveDateTime' => isset( $vs['measurement_date'] ) ? $vs['measurement_date'] : '',
						'component'         => $this->vitals_to_fhir_components( $vs ),
					),
				);
			}
		}

		// AllergyIntolerance resources.
		if ( ! empty( $payload['health_records']['allergies'] ) ) {
			foreach ( $payload['health_records']['allergies'] as $allergy ) {
				$bundle['entry'][] = array(
					'resource' => array(
						'resourceType'         => 'AllergyIntolerance',
						'patient'              => array( 'reference' => 'Patient/member-' . $member->ID ),
						'code'                 => array( 'text' => $allergy['allergen'] ),
						'criticality'          => $allergy['severity'],
					),
				);
			}
		}

		// MedicationStatement resources.
		if ( ! empty( $payload['health_records']['prescriptions'] ) ) {
			foreach ( $payload['health_records']['prescriptions'] as $rx ) {
				$bundle['entry'][] = array(
					'resource' => array(
						'resourceType' => 'MedicationStatement',
						'subject'      => array( 'reference' => 'Patient/member-' . $member->ID ),
						'medication'   => array( 'concept' => array( 'text' => $rx['medication'] ) ),
						'dosage'       => array( array( 'text' => $rx['dosage'] . ' ' . $rx['frequency'] ) ),
					),
				);
			}
		}

		return $bundle;
	}

	/**
	 * Convert a vitals row to FHIR Observation component array.
	 *
	 * @param array $vs Vitals row (CCT or options format).
	 * @return array
	 */
	private function vitals_to_fhir_components( array $vs ) {
		$components = array();

		$mappings = array(
			'bp_systolic'       => array( 'code' => '8480-6', 'display' => 'Systolic blood pressure', 'unit' => 'mmHg' ),
			'bp_diastolic'      => array( 'code' => '8462-4', 'display' => 'Diastolic blood pressure', 'unit' => 'mmHg' ),
			'heart_rate'        => array( 'code' => '8867-4', 'display' => 'Heart rate', 'unit' => '/min' ),
			'temperature'       => array( 'code' => '8310-5', 'display' => 'Body temperature', 'unit' => 'F' ),
			'oxygen_saturation' => array( 'code' => '59408-5', 'display' => 'Oxygen saturation', 'unit' => '%' ),
			'respiratory_rate'  => array( 'code' => '9279-1', 'display' => 'Respiratory rate', 'unit' => '/min' ),
			'blood_glucose'     => array( 'code' => '2339-0', 'display' => 'Glucose [Mass/volume] in Blood', 'unit' => 'mg/dL' ),
			'egfr'              => array( 'code' => '62238-1', 'display' => 'eGFR', 'unit' => 'mL/min/1.73m2' ),
			'creatinine'        => array( 'code' => '2160-0', 'display' => 'Creatinine [Mass/volume] in Serum', 'unit' => 'mg/dL' ),
			'bun'               => array( 'code' => '3094-0', 'display' => 'BUN [Mass/volume] in Serum', 'unit' => 'mg/dL' ),
			'potassium'         => array( 'code' => '2823-3', 'display' => 'Potassium [Moles/volume] in Serum', 'unit' => 'mEq/L' ),
			'sodium'            => array( 'code' => '2951-2', 'display' => 'Sodium [Moles/volume] in Serum', 'unit' => 'mg/day' ),
			'phosphorus'        => array( 'code' => '2777-1', 'display' => 'Phosphate [Mass/volume] in Serum', 'unit' => 'mg/dL' ),
			'albumin'           => array( 'code' => '1751-7', 'display' => 'Albumin [Mass/volume] in Serum', 'unit' => 'g/dL' ),
		);

		foreach ( $mappings as $field => $loinc ) {
			if ( isset( $vs[ $field ] ) && '' !== $vs[ $field ] ) {
				$components[] = array(
					'code'        => array(
						'coding' => array(
							array(
								'system'  => 'http://loinc.org',
								'code'    => $loinc['code'],
								'display' => $loinc['display'],
							),
						),
					),
					'valueQuantity' => array(
						'value' => floatval( $vs[ $field ] ),
						'unit'  => $loinc['unit'],
					),
				);
			}
		}

		return $components;
	}
}

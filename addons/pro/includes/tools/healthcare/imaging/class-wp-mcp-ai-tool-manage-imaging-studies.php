<?php
/**
 * AI Tool: Manage Imaging Studies
 *
 * Provides the `manage_imaging_studies` tool that allows an AI assistant
 * to interact with the Medical Imaging Viewer module.  This tool is
 * intentionally limited to metadata operations (list, get, summarize) –
 * it never exposes raw pixel data or PHI to the AI model.
 *
 * Supported actions:
 *  - list    : retrieve a paginated list of studies.
 *  - get     : retrieve full metadata for a specific study (by UID or post ID).
 *  - summarize : generate a human-readable plain-English summary of a study's
 *                metadata (modality, series, PET/CT metadata, completeness).
 *  - audit   : retrieve recent audit events (requires manage_medical_imaging).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI tool for querying and summarizing DICOM imaging study metadata.
 */
class WP_MCP_AI_Tool_Manage_Imaging_Studies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_imaging_studies';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Imaging Studies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists, retrieves, and summarizes DICOM medical imaging study metadata. Supports PET/CT, MR, and other modalities. Use action "list" to browse studies, "get" to fetch a specific study by UID, "summarize" to produce a plain-English study overview, or "audit" to view recent access events.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: "list", "get", "summarize", or "audit".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'get', 'summarize', 'audit' ),
					'default'     => 'list',
				),
				'study_uid'   => array(
					'type'        => 'string',
					'description' => __( 'DICOM StudyInstanceUID. Required for actions "get" and "summarize".', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of studies per page (for action "list"). Default 20, max 100.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number (for action "list"). Default 1.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'audit_limit' => array(
					'type'        => 'integer',
					'description' => __( 'Number of audit entries to retrieve (for action "audit"). Default 50, max 500.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 50,
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'read-only',
			'local-only',
			'pii-data',
			'paginated',
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'view_medical_imaging' ) ) {
			return new WP_Error(
				'imaging_forbidden',
				__( 'You do not have permission to access medical imaging studies.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'list':
				return $this->action_list( $arguments );
			case 'get':
				return $this->action_get( $arguments );
			case 'summarize':
				return $this->action_summarize( $arguments );
			case 'audit':
				return $this->action_audit( $arguments );
			default:
				return new WP_Error( 'imaging_unknown_action', __( 'Unknown action. Use "list", "get", "summarize", or "audit".', 'mcp-ai-wpoos-pro' ) );
		}
	}

	// =========================================================================
	// Action handlers.
	// =========================================================================

	/**
	 * List studies (paginated).
	 *
	 * @param array $args Tool arguments.
	 * @return array
	 */
	private function action_list( array $args ) {
		$per_page = isset( $args['per_page'] ) ? min( absint( $args['per_page'] ), 100 ) : 20;
		$page     = isset( $args['page'] ) ? absint( $args['page'] ) : 1;

		$result  = WP_MCP_AI_Imaging_Study_CPT::get_all( $per_page, $page );
		$studies = array();

		foreach ( $result['posts'] as $post ) {
			$studies[] = $this->format_study_summary( $post );
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_list_viewed',
			array(
				'source' => 'ai_tool',
				'count'  => count( $studies ),
			)
		);

		return array(
			'studies'  => $studies,
			'total'    => $result['total'],
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Get full metadata for one study.
	 *
	 * @param array $args Tool arguments.
	 * @return array|WP_Error
	 */
	private function action_get( array $args ) {
		$study_uid = isset( $args['study_uid'] ) ? sanitize_text_field( $args['study_uid'] ) : '';
		if ( '' === $study_uid ) {
			return new WP_Error( 'imaging_missing_uid', __( 'study_uid is required for action "get".', 'mcp-ai-wpoos-pro' ) );
		}

		$post = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );
		if ( ! $post ) {
			return new WP_Error( 'imaging_not_found', __( 'Study not found.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_viewed',
			array(
				'source'   => 'ai_tool',
				'study_id' => $study_uid,
				'user_id'  => get_current_user_id(),
			)
		);

		return $this->format_study_full( $post );
	}

	/**
	 * Generate a plain-English summary of a study.
	 *
	 * This provides workflow-level context (modality, completeness, PET metadata)
	 * without exposing PHI or making clinical interpretations.
	 *
	 * @param array $args Tool arguments.
	 * @return array|WP_Error
	 */
	private function action_summarize( array $args ) {
		$study_uid = isset( $args['study_uid'] ) ? sanitize_text_field( $args['study_uid'] ) : '';
		if ( '' === $study_uid ) {
			return new WP_Error( 'imaging_missing_uid', __( 'study_uid is required for action "summarize".', 'mcp-ai-wpoos-pro' ) );
		}

		$post = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );
		if ( ! $post ) {
			return new WP_Error( 'imaging_not_found', __( 'Study not found.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Imaging_Audit_Log::log(
			'study_summarized',
			array(
				'source'   => 'ai_tool',
				'study_id' => $study_uid,
				'user_id'  => get_current_user_id(),
			)
		);

		$full    = $this->format_study_full( $post );
		$summary = $this->generate_plain_summary( $full );

		return array(
			'study_uid' => $study_uid,
			'summary'   => $summary,
		);
	}

	/**
	 * Retrieve recent audit events.
	 *
	 * @param array $args Tool arguments.
	 * @return array|WP_Error
	 */
	private function action_audit( array $args ) {
		if ( ! current_user_can( 'manage_medical_imaging' ) ) {
			return new WP_Error( 'imaging_forbidden', __( 'You do not have permission to view imaging audit logs.', 'mcp-ai-wpoos-pro' ) );
		}

		$limit     = isset( $args['audit_limit'] ) ? min( absint( $args['audit_limit'] ), 500 ) : 50;
		$study_uid = isset( $args['study_uid'] ) ? sanitize_text_field( $args['study_uid'] ) : '';

		WP_MCP_AI_Imaging_Audit_Log::log(
			'audit_log_viewed',
			array(
				'source'  => 'ai_tool',
				'user_id' => get_current_user_id(),
			)
		);

		$entries = WP_MCP_AI_Imaging_Audit_Log::get_recent( $limit, $study_uid );

		return array(
			'entries' => $entries,
			'total'   => count( $entries ),
		);
	}

	// =========================================================================
	// Formatting helpers.
	// =========================================================================

	/**
	 * Format a study for the list view (minimal, no PHI).
	 *
	 * @param WP_Post $post Study post.
	 * @return array
	 */
	private function format_study_summary( WP_Post $post ) {
		$series_json  = get_post_meta( $post->ID, '_imaging_series', true );
		$series       = json_decode( $series_json, true );
		$series_count = is_array( $series ) ? count( $series ) : 0;

		$instance_count = 0;
		if ( is_array( $series ) ) {
			foreach ( $series as $s ) {
				$instance_count += isset( $s['instances'] ) ? count( $s['instances'] ) : 0;
			}
		}

		return array(
			'study_uid'      => get_post_meta( $post->ID, '_imaging_study_instance_uid', true ),
			'modality'       => get_post_meta( $post->ID, '_imaging_modality', true ),
			'study_date'     => get_post_meta( $post->ID, '_imaging_study_date', true ),
			'description'    => get_post_meta( $post->ID, '_imaging_study_description', true ),
			'series_count'   => $series_count,
			'instance_count' => $instance_count,
			'status'         => get_post_meta( $post->ID, '_imaging_status', true ),
		);
	}

	/**
	 * Format a study with full series/instance metadata (no PHI, no file paths).
	 *
	 * @param WP_Post $post Study post.
	 * @return array
	 */
	private function format_study_full( WP_Post $post ) {
		$series_json = get_post_meta( $post->ID, '_imaging_series', true );
		$series_raw  = json_decode( $series_json, true );
		$series_safe = array();

		if ( is_array( $series_raw ) ) {
			foreach ( $series_raw as $s ) {
				$instances_safe = array();
				foreach ( isset( $s['instances'] ) ? $s['instances'] : array() as $inst ) {
					// Exclude file_path from AI output.
					$instances_safe[] = array(
						'sop_instance_uid' => isset( $inst['sop_instance_uid'] ) ? $inst['sop_instance_uid'] : '',
						'instance_number'  => isset( $inst['instance_number'] ) ? $inst['instance_number'] : '',
						'rows'             => isset( $inst['rows'] ) ? $inst['rows'] : '',
						'columns'          => isset( $inst['columns'] ) ? $inst['columns'] : '',
						'pixel_spacing'    => isset( $inst['pixel_spacing'] ) ? $inst['pixel_spacing'] : '',
					);
				}

				$series_safe[] = array(
					'series_instance_uid' => isset( $s['series_instance_uid'] ) ? $s['series_instance_uid'] : '',
					'modality'            => isset( $s['modality'] ) ? $s['modality'] : '',
					'series_description'  => isset( $s['series_description'] ) ? $s['series_description'] : '',
					'instance_count'      => count( $instances_safe ),
					'instances'           => $instances_safe,
				);
			}
		}

		return array(
			'study_uid'   => get_post_meta( $post->ID, '_imaging_study_instance_uid', true ),
			'modality'    => get_post_meta( $post->ID, '_imaging_modality', true ),
			'study_date'  => get_post_meta( $post->ID, '_imaging_study_date', true ),
			'description' => get_post_meta( $post->ID, '_imaging_study_description', true ),
			'status'      => get_post_meta( $post->ID, '_imaging_status', true ),
			'created'     => get_the_date( 'c', $post ),
			'series'      => $series_safe,
		);
	}

	/**
	 * Generate a workflow-level plain-English summary (no PHI, no clinical interpretation).
	 *
	 * @param array $study Formatted study data from format_study_full().
	 * @return string Plain-English summary.
	 */
	private function generate_plain_summary( array $study ) {
		$modality       = $study['modality'] ? $study['modality'] : __( 'Unknown modality', 'mcp-ai-wpoos-pro' );
		$study_date     = $study['study_date'] ? $study['study_date'] : __( 'Unknown date', 'mcp-ai-wpoos-pro' );
		$series_count   = count( $study['series'] );
		$instance_count = 0;

		$pet_series             = 0;
		$ct_series              = 0;
		$mr_series              = 0;
		$other_series           = 0;
		$missing_slices_warning = '';

		foreach ( $study['series'] as $s ) {
			$count           = $s['instance_count'];
			$instance_count += $count;

			$mod = strtoupper( $s['modality'] );
			if ( 'PT' === $mod ) {
				++$pet_series;
			} elseif ( 'CT' === $mod ) {
				++$ct_series;
			} elseif ( 'MR' === $mod ) {
				++$mr_series;
			} else {
				++$other_series;
			}

			// Heuristic: warn if a PET series has fewer than 10 slices.
			if ( 'PT' === $mod && $count < 10 ) {
				$missing_slices_warning = __( ' Note: The PET series appears to have fewer slices than expected – verify that all instances were uploaded.', 'mcp-ai-wpoos-pro' );
			}
		}

		// Build modality breakdown string.
		$breakdown = array();
		if ( $pet_series > 0 ) {
			// translators: %d = number of PET series.
			$breakdown[] = sprintf( _n( '%d PET series', '%d PET series', $pet_series, 'mcp-ai-wpoos-pro' ), $pet_series );
		}
		if ( $ct_series > 0 ) {
			// translators: %d = number of CT series.
			$breakdown[] = sprintf( _n( '%d CT series', '%d CT series', $ct_series, 'mcp-ai-wpoos-pro' ), $ct_series );
		}
		if ( $mr_series > 0 ) {
			// translators: %d = number of MR series.
			$breakdown[] = sprintf( _n( '%d MR series', '%d MR series', $mr_series, 'mcp-ai-wpoos-pro' ), $mr_series );
		}
		if ( $other_series > 0 ) {
			// translators: %d = number of other series.
			$breakdown[] = sprintf( _n( '%d other series', '%d other series', $other_series, 'mcp-ai-wpoos-pro' ), $other_series );
		}

		$breakdown_str = ! empty( $breakdown ) ? ' (' . implode( ', ', $breakdown ) . ')' : '';

		$summary = sprintf(
			/* translators: 1: Modality 2: Study date 3: Number of series 4: Breakdown string 5: Number of instances */
			__( 'This is a %1$s study acquired on %2$s. It contains %3$d series%4$s totalling %5$d instances.', 'mcp-ai-wpoos-pro' ),
			esc_html( $modality ),
			esc_html( $study_date ),
			$series_count,
			$breakdown_str,
			$instance_count
		);

		if ( $study['description'] ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: Study description */
				__( 'Study description: %s.', 'mcp-ai-wpoos-pro' ),
				esc_html( $study['description'] )
			);
		}

		$summary .= $missing_slices_warning;

		// Status note.
		if ( 'archived' === $study['status'] ) {
			$summary .= ' ' . __( 'This study is currently archived.', 'mcp-ai-wpoos-pro' );
		}

		$summary .= ' ' . __( 'Note: AI assistance is limited to workflow, metadata, and completeness checks. Clinical interpretation is the responsibility of a qualified clinician.', 'mcp-ai-wpoos-pro' );

		return $summary;
	}
}

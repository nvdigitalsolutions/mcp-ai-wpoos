<?php
/**
 * Tool: import_dicom_study
 *
 * Imports a DICOM study from the configured DICOMweb endpoint into the
 * `mcp_ai_imaging_study` CPT using QIDO-RS to discover the study and
 * WADO-RS to fetch its instance metadata.  Pixel data is **not**
 * downloaded — only DICOM JSON metadata is mirrored locally so the
 * existing imaging tools (`manage_imaging_studies`, `interpret_imaging_study`)
 * can operate over it.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import DICOM study tool.
 */
class WP_MCP_AI_Tool_Import_DICOM_Study implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_healthcare_imaging'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_dicom_study';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import DICOM Study', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import a DICOM study from the configured DICOMweb endpoint by StudyInstanceUID. Mirrors metadata only (no pixel data) into the local imaging study CPT.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'study_uid' => array(
					'type'        => 'string',
					'description' => __( 'DICOM StudyInstanceUID to import.', 'mcp-ai-wpoos-pro' ),
				),
				'overwrite' => array(
					'type'        => 'boolean',
					'description' => __( 'If a study with this UID already exists, update its metadata.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'study_uid' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'external-api', 'phi-data' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import imaging studies.', 'mcp-ai-wpoos-pro' ) );
		}

		$study_uid = isset( $arguments['study_uid'] ) ? sanitize_text_field( $arguments['study_uid'] ) : '';
		if ( '' === $study_uid ) {
			return new WP_Error( 'wp_mcp_ai_missing_uid', __( 'A study_uid is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$overwrite = ! empty( $arguments['overwrite'] );

		$existing = class_exists( 'WP_MCP_AI_Imaging_Study_CPT' )
			? WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid )
			: null;
		if ( $existing && ! $overwrite ) {
			return new WP_Error(
				'wp_mcp_ai_study_exists',
				__( 'A study with that UID already exists. Pass overwrite=true to refresh its metadata.', 'mcp-ai-wpoos-pro' )
			);
		}

		// QIDO to confirm the study exists on the remote.
		$qido = WP_MCP_AI_DICOMweb_Client::qido_studies( array( 'StudyInstanceUID' => $study_uid ) );
		if ( is_wp_error( $qido ) ) {
			return $qido;
		}
		if ( empty( $qido ) ) {
			return new WP_Error( 'wp_mcp_ai_study_not_found', __( 'The remote DICOMweb endpoint has no study with that UID.', 'mcp-ai-wpoos-pro' ) );
		}

		// WADO-RS metadata.
		$metadata = WP_MCP_AI_DICOMweb_Client::wado_study_metadata( $study_uid );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$summary = $this->summarize_metadata( $metadata );

		if ( ! class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			return new WP_Error( 'wp_mcp_ai_imaging_unavailable', __( 'The imaging study CPT is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $existing ) {
			$post_id = (int) $existing->ID;
			update_post_meta( $post_id, '_imaging_modality', $summary['modality'] );
			update_post_meta( $post_id, '_imaging_study_date', $summary['study_date'] );
			update_post_meta( $post_id, '_imaging_study_description', $summary['description'] );
			update_post_meta( $post_id, '_imaging_series', wp_json_encode( $summary['series'] ) );
		} else {
			$post_id = WP_MCP_AI_Imaging_Study_CPT::create(
				array(
					'study_instance_uid' => $study_uid,
					'patient_id'         => $summary['patient_id'],
					'modality'           => $summary['modality'],
					'study_date'         => $summary['study_date'],
					'study_description'  => $summary['description'],
				)
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			update_post_meta( $post_id, '_imaging_series', wp_json_encode( $summary['series'] ) );
		}

		update_post_meta( $post_id, '_imaging_source', 'dicomweb' );
		update_post_meta( $post_id, '_imaging_imported_at', gmdate( 'c' ) );

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				$existing ? 'update' : 'import',
				'imaging_study',
				$post_id,
				array(
					'user_id'   => $current_user_id,
					'tool'      => $this->get_slug(),
					'study_uid' => $study_uid,
				)
			);
		}

		/**
		 * Fires after a DICOM study is imported via DICOMweb.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $post_id   Imaging study post ID.
		 * @param string $study_uid DICOM StudyInstanceUID.
		 * @param array  $summary   Summarised metadata stored locally.
		 */
		do_action( 'wp_mcp_ai_healthcare_after_dicom_import', $post_id, $study_uid, $summary );

		return array(
			'success'   => true,
			'study_id'  => $post_id,
			'study_uid' => $study_uid,
			'summary'   => $summary,
		);
	}

	/**
	 * Reduce a DICOM JSON metadata response to the fields stored locally.
	 *
	 * @param array $metadata WADO-RS metadata array (one element per instance).
	 * @return array
	 */
	private function summarize_metadata( array $metadata ) {
		$modality   = '';
		$date       = '';
		$desc       = '';
		$patient_id = '';
		$series_map = array();

		foreach ( $metadata as $instance ) {
			if ( ! is_array( $instance ) ) {
				continue;
			}
			// DICOM JSON: keys are 8-digit hex group+element strings; each entry has 'vr' + 'Value'.
			$modality   = '' === $modality ? $this->dicom_value( $instance, '00080060' ) : $modality;
			$date       = '' === $date ? $this->dicom_value( $instance, '00080020' ) : $date;
			$desc       = '' === $desc ? $this->dicom_value( $instance, '00081030' ) : $desc;
			$patient_id = '' === $patient_id ? $this->dicom_value( $instance, '00100020' ) : $patient_id;

			$series_uid = $this->dicom_value( $instance, '0020000E' );
			if ( '' === $series_uid ) {
				continue;
			}
			if ( ! isset( $series_map[ $series_uid ] ) ) {
				$series_map[ $series_uid ] = array(
					'series_instance_uid' => $series_uid,
					'modality'            => $this->dicom_value( $instance, '00080060' ),
					'description'         => $this->dicom_value( $instance, '0008103E' ),
					'instance_count'      => 0,
				);
			}
			++$series_map[ $series_uid ]['instance_count'];
		}

		return array(
			'modality'    => $modality,
			'study_date'  => $date,
			'description' => $desc,
			'patient_id'  => $patient_id,
			'series'      => array_values( $series_map ),
		);
	}

	/**
	 * Read a single DICOM JSON tag's first Value entry.
	 *
	 * @param array  $instance Instance metadata.
	 * @param string $tag      DICOM tag (e.g. 00080060).
	 * @return string
	 */
	private function dicom_value( array $instance, $tag ) {
		if ( ! isset( $instance[ $tag ]['Value'] ) || ! is_array( $instance[ $tag ]['Value'] ) ) {
			return '';
		}
		$first = reset( $instance[ $tag ]['Value'] );
		if ( is_array( $first ) && isset( $first['Alphabetic'] ) ) {
			return (string) $first['Alphabetic'];
		}
		return is_scalar( $first ) ? (string) $first : '';
	}
}

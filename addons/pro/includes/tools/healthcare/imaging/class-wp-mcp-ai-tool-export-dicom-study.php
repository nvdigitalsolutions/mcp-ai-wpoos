<?php
/**
 * Tool: export_dicom_study
 *
 * Pushes a locally-stored imaging study's metadata to the configured
 * DICOMweb endpoint via STOW-RS.  The payload runs through the
 * `wp_mcp_ai_healthcare_before_imaging_export` filter so partner code
 * (or a paid de-identifier add-on) can scrub PHI before transmission.
 *
 * Like `import_dicom_study`, this tool is metadata-only by default;
 * partner code that needs to ship pixel data should hook
 * `wp_mcp_ai_healthcare_dicomweb_request_args` to switch the request to
 * multipart/related.
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
 * Export DICOM study tool.
 */
class WP_MCP_AI_Tool_Export_DICOM_Study implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'export_dicom_study';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export DICOM Study', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export a locally stored imaging study to the configured DICOMweb endpoint via STOW-RS. Runs through the imaging export filter so a de-identifier can scrub PHI before transmission.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'study_uid'  => array(
					'type'        => 'string',
					'description' => __( 'StudyInstanceUID of a locally stored study.', 'mcp-ai-wpoos-pro' ),
				),
				'study_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Local imaging study post ID. Either study_uid or study_id is required.', 'mcp-ai-wpoos-pro' ),
				),
				'deidentify' => array(
					'type'        => 'boolean',
					'description' => __( 'Hint to the export filter that PHI must be scrubbed.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'external-api', 'phi-data', 'destructive' );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export imaging studies.', 'mcp-ai-wpoos-pro' ) );
		}

		$study_uid = isset( $arguments['study_uid'] ) ? sanitize_text_field( $arguments['study_uid'] ) : '';
		$study_id  = isset( $arguments['study_id'] ) ? absint( $arguments['study_id'] ) : 0;
		$deident   = ! isset( $arguments['deidentify'] ) ? true : (bool) $arguments['deidentify'];

		$study = null;
		if ( $study_id > 0 ) {
			$study = get_post( $study_id );
		} elseif ( '' !== $study_uid && class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			$study = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );
		}
		if ( ! $study || 'mcp_ai_imaging_study' !== $study->post_type ) {
			return new WP_Error( 'wp_mcp_ai_study_not_found', __( 'Local imaging study not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$study_uid   = (string) get_post_meta( $study->ID, '_imaging_study_instance_uid', true );
		$modality    = (string) get_post_meta( $study->ID, '_imaging_modality', true );
		$study_date  = (string) get_post_meta( $study->ID, '_imaging_study_date', true );
		$description = (string) get_post_meta( $study->ID, '_imaging_study_description', true );
		$patient_id  = (string) get_post_meta( $study->ID, '_imaging_patient_id', true );
		$series_json = (string) get_post_meta( $study->ID, '_imaging_series', true );
		$series      = '' !== $series_json ? json_decode( $series_json, true ) : array();
		if ( ! is_array( $series ) ) {
			$series = array();
		}

		$instances = array();
		foreach ( $series as $s ) {
			$series_uid = isset( $s['series_instance_uid'] ) ? (string) $s['series_instance_uid'] : '';
			if ( '' === $series_uid ) {
				continue;
			}
			$instances[] = array(
				'00080060' => array(
					'vr'    => 'CS',
					'Value' => array( $modality ),
				),
				'00080020' => array(
					'vr'    => 'DA',
					'Value' => array( $study_date ),
				),
				'00081030' => array(
					'vr'    => 'LO',
					'Value' => array( $description ),
				),
				'00100020' => array(
					'vr'    => 'LO',
					'Value' => array( $patient_id ),
				),
				'0020000D' => array(
					'vr'    => 'UI',
					'Value' => array( $study_uid ),
				),
				'0020000E' => array(
					'vr'    => 'UI',
					'Value' => array( $series_uid ),
				),
			);
		}
		if ( empty( $instances ) ) {
			$instances[] = array(
				'00080060' => array(
					'vr'    => 'CS',
					'Value' => array( $modality ),
				),
				'00080020' => array(
					'vr'    => 'DA',
					'Value' => array( $study_date ),
				),
				'00081030' => array(
					'vr'    => 'LO',
					'Value' => array( $description ),
				),
				'00100020' => array(
					'vr'    => 'LO',
					'Value' => array( $patient_id ),
				),
				'0020000D' => array(
					'vr'    => 'UI',
					'Value' => array( $study_uid ),
				),
			);
		}

		/**
		 * Filter the DICOM JSON payload before STOW-RS transmission.
		 *
		 * Hook this to plug in a de-identifier that strips PHI from the
		 * payload (PatientName, PatientID, dates, accession numbers, etc.).
		 *
		 * @since 1.4.0
		 *
		 * @param array $instances DICOM JSON instance metadata documents.
		 * @param int   $study_id  Local imaging study post ID.
		 * @param bool  $deident   Whether the caller requested de-identification.
		 */
		$instances = apply_filters(
			'wp_mcp_ai_healthcare_before_imaging_export',
			$instances,
			(int) $study->ID,
			$deident
		);

		$response = WP_MCP_AI_DICOMweb_Client::stow_instances( $instances );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'export',
				'imaging_study',
				$study->ID,
				array(
					'user_id'    => $current_user_id,
					'tool'       => $this->get_slug(),
					'study_uid'  => $study_uid,
					'deidentify' => $deident,
				)
			);
		}

		/**
		 * Fires after a DICOM study is exported via DICOMweb.
		 *
		 * @since 1.4.0
		 *
		 * @param int    $study_id  Local imaging study post ID.
		 * @param string $study_uid DICOM StudyInstanceUID.
		 * @param array  $response  STOW-RS response payload.
		 */
		do_action( 'wp_mcp_ai_healthcare_after_imaging_export', (int) $study->ID, $study_uid, $response );

		return array(
			'success'   => true,
			'study_id'  => (int) $study->ID,
			'study_uid' => $study_uid,
			'instances' => count( $instances ),
			'response'  => $response,
		);
	}
}

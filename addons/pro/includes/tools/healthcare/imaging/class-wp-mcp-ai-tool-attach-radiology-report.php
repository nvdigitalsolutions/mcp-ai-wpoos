<?php
/**
 * Tool: attach_radiology_report
 *
 * Attaches a radiology report to a `mcp_ai_imaging_study` post.  The
 * report is stored as a child post of type `mcp_ai_radiology_report`
 * (auto-registered if absent) with a back-reference to the study and
 * the reporting clinician.  Optionally, a minimal DICOM Structured
 * Report (SR) JSON document is generated and stored as `_report_sr`
 * post-meta — this is the content type STOW-RS accepts for SR storage.
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
 * Attach radiology report tool.
 */
class WP_MCP_AI_Tool_Attach_Radiology_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
	 * Lazily register the radiology report CPT.
	 */
	public static function ensure_cpt() {
		if ( post_type_exists( 'mcp_ai_radiology_report' ) ) {
			return;
		}
		register_post_type(
			// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.TooLong
			'mcp_ai_radiology_report',
			array(
				'labels'              => array(
					'name'          => __( 'Radiology Reports', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Radiology Report', 'mcp-ai-wpoos-pro' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'supports'            => array( 'title', 'editor', 'author' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'attach_radiology_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Attach Radiology Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Attach a radiology report (findings + impression) to a stored imaging study, with optional minimal DICOM SR generation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'study_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Local imaging study post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'study_uid'        => array(
					'type'        => 'string',
					'description' => __( 'StudyInstanceUID; alternative to study_id.', 'mcp-ai-wpoos-pro' ),
				),
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Report title.', 'mcp-ai-wpoos-pro' ),
				),
				'findings'         => array(
					'type'        => 'string',
					'description' => __( 'Findings narrative.', 'mcp-ai-wpoos-pro' ),
				),
				'impression'       => array(
					'type'        => 'string',
					'description' => __( 'Impression / conclusion.', 'mcp-ai-wpoos-pro' ),
				),
				'reporting_doctor' => array(
					'type'        => 'string',
					'description' => __( 'Free-text name of the reporting clinician.', 'mcp-ai-wpoos-pro' ),
				),
				'generate_sr'      => array(
					'type'        => 'boolean',
					'description' => __( 'When true, generate a minimal DICOM Structured Report JSON document and store it as report meta.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'findings', 'impression' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'phi-data' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to attach radiology reports.', 'mcp-ai-wpoos-pro' ) );
		}

		self::ensure_cpt();

		$study_id  = isset( $arguments['study_id'] ) ? absint( $arguments['study_id'] ) : 0;
		$study_uid = isset( $arguments['study_uid'] ) ? sanitize_text_field( $arguments['study_uid'] ) : '';
		$study     = null;
		if ( $study_id > 0 ) {
			$study = get_post( $study_id );
		} elseif ( '' !== $study_uid && class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			$study = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );
		}
		if ( ! $study || 'mcp_ai_imaging_study' !== $study->post_type ) {
			return new WP_Error( 'wp_mcp_ai_study_not_found', __( 'Imaging study not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$findings   = isset( $arguments['findings'] ) ? wp_kses_post( $arguments['findings'] ) : '';
		$impression = isset( $arguments['impression'] ) ? wp_kses_post( $arguments['impression'] ) : '';
		if ( '' === $findings || '' === $impression ) {
			return new WP_Error( 'wp_mcp_ai_missing_report_body', __( 'Both findings and impression are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$title = isset( $arguments['title'] ) && '' !== $arguments['title']
			? sanitize_text_field( $arguments['title'] )
			: sprintf(
				/* translators: %s: study title */
				__( 'Report — %s', 'mcp-ai-wpoos-pro' ),
				$study->post_title
			);

		$body  = '<h3>' . esc_html__( 'Findings', 'mcp-ai-wpoos-pro' ) . "</h3>\n" . wpautop( $findings );
		$body .= '<h3>' . esc_html__( 'Impression', 'mcp-ai-wpoos-pro' ) . "</h3>\n" . wpautop( $impression );

		$report_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_radiology_report',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $body,
				'post_author'  => $current_user_id,
				'post_parent'  => (int) $study->ID,
			),
			true
		);
		if ( is_wp_error( $report_id ) ) {
			return $report_id;
		}

		$reporting = isset( $arguments['reporting_doctor'] ) ? sanitize_text_field( $arguments['reporting_doctor'] ) : '';
		update_post_meta( $report_id, '_report_study_id', (int) $study->ID );
		update_post_meta( $report_id, '_report_findings', $findings );
		update_post_meta( $report_id, '_report_impression', $impression );
		update_post_meta( $report_id, '_report_reporting_doctor', $reporting );
		update_post_meta( $report_id, '_report_authored_at', gmdate( 'c' ) );

		// Back-reference: append to the study's report list.
		$linked = (array) get_post_meta( $study->ID, '_imaging_report_ids', true );
		$linked = array_values( array_filter( array_map( 'absint', $linked ) ) );
		if ( ! in_array( (int) $report_id, $linked, true ) ) {
			$linked[] = (int) $report_id;
		}
		update_post_meta( $study->ID, '_imaging_report_ids', $linked );

		$sr = null;
		if ( ! empty( $arguments['generate_sr'] ) ) {
			$sr = $this->generate_sr_document( $study, $findings, $impression, $reporting );
			update_post_meta( $report_id, '_report_sr', wp_json_encode( $sr ) );
		}

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'create',
				'radiology_report',
				$report_id,
				array(
					'user_id'  => $current_user_id,
					'tool'     => $this->get_slug(),
					'study_id' => (int) $study->ID,
				)
			);
		}

		return array(
			'success'   => true,
			'report_id' => (int) $report_id,
			'study_id'  => (int) $study->ID,
			'sr'        => $sr,
		);
	}

	/**
	 * Build a minimal DICOM Structured Report (SR) JSON document.
	 *
	 * @param WP_Post $study      Imaging study post.
	 * @param string  $findings   Findings narrative.
	 * @param string  $impression Impression narrative.
	 * @param string  $reporting  Reporting clinician.
	 * @return array
	 */
	private function generate_sr_document( WP_Post $study, $findings, $impression, $reporting ) {
		$study_uid = (string) get_post_meta( $study->ID, '_imaging_study_instance_uid', true );
		return array(
			'00080005' => array(
				'vr'    => 'CS',
				'Value' => array( 'ISO_IR 192' ),
			),
			'00080016' => array(
				'vr'    => 'UI',
				'Value' => array( '1.2.840.10008.5.1.4.1.1.88.11' ),
			), // Basic Text SR.
			'00080060' => array(
				'vr'    => 'CS',
				'Value' => array( 'SR' ),
			),
			'00080070' => array(
				'vr'    => 'LO',
				'Value' => array( 'NV oOS' ),
			),
			'00080090' => array(
				'vr'    => 'PN',
				'Value' => array( array( 'Alphabetic' => $reporting ) ),
			),
			'00081030' => array(
				'vr'    => 'LO',
				'Value' => array( $study->post_title ),
			),
			'0020000D' => array(
				'vr'    => 'UI',
				'Value' => array( $study_uid ),
			),
			'0040A040' => array(
				'vr'    => 'CS',
				'Value' => array( 'CONTAINER' ),
			),
			'0040A730' => array(
				'vr'    => 'SQ',
				'Value' => array(
					array(
						'0040A040' => array(
							'vr'    => 'CS',
							'Value' => array( 'TEXT' ),
						),
						'0040A043' => array(
							'vr'    => 'SQ',
							'Value' => array(
								array(
									'00080100' => array(
										'vr'    => 'SH',
										'Value' => array( '121071' ),
									),
									'00080102' => array(
										'vr'    => 'SH',
										'Value' => array( 'DCM' ),
									),
									'00080104' => array(
										'vr'    => 'LO',
										'Value' => array( 'Findings' ),
									),
								),
							),
						),
						'0040A160' => array(
							'vr'    => 'UT',
							'Value' => array( wp_strip_all_tags( $findings ) ),
						),
					),
					array(
						'0040A040' => array(
							'vr'    => 'CS',
							'Value' => array( 'TEXT' ),
						),
						'0040A043' => array(
							'vr'    => 'SQ',
							'Value' => array(
								array(
									'00080100' => array(
										'vr'    => 'SH',
										'Value' => array( '121072' ),
									),
									'00080102' => array(
										'vr'    => 'SH',
										'Value' => array( 'DCM' ),
									),
									'00080104' => array(
										'vr'    => 'LO',
										'Value' => array( 'Impression' ),
									),
								),
							),
						),
						'0040A160' => array(
							'vr'    => 'UT',
							'Value' => array( wp_strip_all_tags( $impression ) ),
						),
					),
				),
			),
		);
	}
}

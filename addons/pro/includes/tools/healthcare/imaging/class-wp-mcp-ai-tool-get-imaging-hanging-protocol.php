<?php
/**
 * Tool: get_imaging_hanging_protocol
 *
 * Returns a per-modality default hanging-protocol that downstream
 * viewers (e.g. Cornerstone3D, OHIF) can apply when displaying the
 * study.  The protocols ship as opinionated defaults aligned with the
 * common radiology reading conventions for CT, MR, CR/DX, US, MG, NM,
 * PT and SR; sites can extend or override them via the
 * `wp_mcp_ai_healthcare_hanging_protocols` filter.
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
 * Get imaging hanging protocol tool.
 */
class WP_MCP_AI_Tool_Get_Imaging_Hanging_Protocol implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'get_imaging_hanging_protocol';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Imaging Hanging Protocol', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Return a default viewer hanging-protocol for a given modality (or for the modality of a stored study). Filterable so partner viewers can plug in custom layouts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'modality'  => array(
					'type'        => 'string',
					'description' => __( 'DICOM modality code (e.g. CT, MR, CR, DX, US, MG, NM, PT, SR).', 'mcp-ai-wpoos-pro' ),
				),
				'study_id'  => array(
					'type'        => 'integer',
					'description' => __( 'When supplied, the modality is read from the stored study.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'study_uid' => array(
					'type'        => 'string',
					'description' => __( 'When supplied, the modality is read from the stored study.', 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Default per-modality hanging protocols.
	 *
	 * @return array
	 */
	public static function default_protocols() {
		$base = array(
			'CT' => array(
				'name'   => 'CT default',
				'layout' => '2x2',
				'stages' => array(
					array(
						'series'        => 'axial',
						'window_preset' => 'soft-tissue',
						'sync'          => 'mpr',
					),
					array(
						'series'        => 'coronal',
						'window_preset' => 'soft-tissue',
						'sync'          => 'mpr',
					),
					array(
						'series'        => 'sagittal',
						'window_preset' => 'soft-tissue',
						'sync'          => 'mpr',
					),
					array(
						'series'        => 'axial',
						'window_preset' => 'lung',
						'sync'          => 'mpr',
					),
				),
			),
			'MR' => array(
				'name'   => 'MR default',
				'layout' => '2x2',
				'stages' => array(
					array( 'series' => 't1' ),
					array( 'series' => 't2' ),
					array( 'series' => 'flair' ),
					array( 'series' => 'dwi' ),
				),
			),
			'CR' => array(
				'name'   => 'CR / DX default',
				'layout' => '1x2',
				'stages' => array(
					array(
						'series'        => 'pa',
						'window_preset' => 'bone',
					),
					array(
						'series'        => 'lateral',
						'window_preset' => 'bone',
					),
				),
			),
			'DX' => array(
				'name'   => 'CR / DX default',
				'layout' => '1x2',
				'stages' => array(
					array(
						'series'        => 'pa',
						'window_preset' => 'bone',
					),
					array(
						'series'        => 'lateral',
						'window_preset' => 'bone',
					),
				),
			),
			'US' => array(
				'name'   => 'Ultrasound default',
				'layout' => '1x1',
				'stages' => array(
					array( 'series' => 'cine' ),
				),
			),
			'MG' => array(
				'name'   => 'Mammography default',
				'layout' => '2x2',
				'stages' => array(
					array( 'series' => 'r-cc' ),
					array( 'series' => 'l-cc' ),
					array( 'series' => 'r-mlo' ),
					array( 'series' => 'l-mlo' ),
				),
			),
			'NM' => array(
				'name'   => 'Nuclear medicine default',
				'layout' => '1x2',
				'stages' => array(
					array( 'series' => 'planar-anterior' ),
					array( 'series' => 'planar-posterior' ),
				),
			),
			'PT' => array(
				'name'   => 'PET / PET-CT default',
				'layout' => '2x2',
				'stages' => array(
					array(
						'series'        => 'pet-axial',
						'window_preset' => 'pet',
					),
					array(
						'series'        => 'ct-axial',
						'window_preset' => 'soft-tissue',
					),
					array(
						'series'        => 'fused-axial',
						'window_preset' => 'pet-ct',
					),
					array(
						'series'        => 'mip',
						'window_preset' => 'pet',
					),
				),
			),
			'SR' => array(
				'name'   => 'Structured Report',
				'layout' => '1x1',
				'stages' => array(
					array( 'series' => 'sr-text' ),
				),
			),
		);

		/**
		 * Filter the per-modality hanging protocols.
		 *
		 * @since 1.4.0
		 *
		 * @param array $base Default protocol map keyed by modality code.
		 */
		return apply_filters( 'wp_mcp_ai_healthcare_hanging_protocols', $base );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read imaging studies.', 'mcp-ai-wpoos-pro' ) );
		}

		$modality = isset( $arguments['modality'] ) ? strtoupper( sanitize_text_field( $arguments['modality'] ) ) : '';

		if ( '' === $modality ) {
			$study_id  = isset( $arguments['study_id'] ) ? absint( $arguments['study_id'] ) : 0;
			$study_uid = isset( $arguments['study_uid'] ) ? sanitize_text_field( $arguments['study_uid'] ) : '';
			$study     = null;
			if ( $study_id > 0 ) {
				$study = get_post( $study_id );
			} elseif ( '' !== $study_uid && class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
				$study = WP_MCP_AI_Imaging_Study_CPT::get_by_uid( $study_uid );
			}
			if ( $study && 'mcp_ai_imaging_study' === $study->post_type ) {
				$modality = strtoupper( (string) get_post_meta( $study->ID, '_imaging_modality', true ) );
			}
		}

		if ( '' === $modality ) {
			return new WP_Error( 'wp_mcp_ai_missing_modality', __( 'Either modality, study_id or study_uid must be supplied.', 'mcp-ai-wpoos-pro' ) );
		}

		$protocols = self::default_protocols();
		$protocol  = isset( $protocols[ $modality ] )
			? $protocols[ $modality ]
			: array(
				'name'   => 'Generic 1x1',
				'layout' => '1x1',
				'stages' => array( array( 'series' => 'first' ) ),
			);

		return array(
			'success'  => true,
			'modality' => $modality,
			'protocol' => $protocol,
		);
	}
}

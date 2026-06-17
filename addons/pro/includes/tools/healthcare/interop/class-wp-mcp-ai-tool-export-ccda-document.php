<?php
/**
 * Tool: export_ccda_document
 *
 * Emits a minimal HL7 CCDA C-CDA R2.1 Continuity of Care Document
 * (CCD; templateId 2.16.840.1.113883.10.20.22.1.2) for a member,
 * including narrative blocks for Allergies, Medications, Problems, and
 * Immunizations sourced from the local healthcare CPTs.
 *
 * The document is intentionally minimal — sites that need full CDA
 * compliance (sectional templateIds, OID-coded entries, authenticator,
 * etc.) should hook `wp_mcp_ai_healthcare_ccda_document` to enrich the
 * generated XML before it is returned.
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
 * Export CCDA document tool.
 */
class WP_MCP_AI_Tool_Export_CCDA_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_ccda_document';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export CCDA Document', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a minimal HL7 C-CDA R2.1 Continuity of Care Document (XML) for a member, with allergies, medications, problems, and immunizations narratives.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member post ID.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'   => array( 'member_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'phi-data' );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export CCDA documents.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$member    = $member_id ? get_post( $member_id ) : null;
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$first  = (string) get_post_meta( $member_id, '_member_first_name', true );
		$last   = (string) get_post_meta( $member_id, '_member_last_name', true );
		$dob    = (string) get_post_meta( $member_id, '_member_date_of_birth', true );
		$gender = (string) get_post_meta( $member_id, '_member_gender', true );
		$mrn    = (string) get_post_meta( $member_id, '_member_mrn', true );

		$allergies = $this->fetch_titles( 'mcp_ai_allergy', '_allergy_member_id', $member_id );
		$meds      = $this->fetch_titles( 'mcp_ai_prescription', '_prescription_member_id', $member_id );
		$problems  = $this->fetch_titles( 'mcp_ai_med_record', '_medical_record_member_id', $member_id );
		$immun     = $this->fetch_titles( 'mcp_ai_vaccination_record', '_record_member_id', $member_id );

		$now  = gmdate( 'YmdHis' );
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<ClinicalDocument xmlns="urn:hl7-org:v3">' . "\n";
		$xml .= '  <realmCode code="US"/>' . "\n";
		$xml .= '  <typeId root="2.16.840.1.113883.1.3" extension="POCD_HD000040"/>' . "\n";
		$xml .= '  <templateId root="2.16.840.1.113883.10.20.22.1.1" extension="2015-08-01"/>' . "\n";
		$xml .= '  <templateId root="2.16.840.1.113883.10.20.22.1.2" extension="2015-08-01"/>' . "\n";
		$xml .= '  <id root="' . esc_attr( $this->generate_oid( $member_id ) ) . '"/>' . "\n";
		$xml .= '  <code code="34133-9" codeSystem="2.16.840.1.113883.6.1" codeSystemName="LOINC" displayName="Summarization of Episode Note"/>' . "\n";
		$xml .= '  <title>' . esc_html__( 'Continuity of Care Document', 'mcp-ai-wpoos-pro' ) . '</title>' . "\n";
		$xml .= '  <effectiveTime value="' . esc_attr( $now ) . '"/>' . "\n";
		$xml .= '  <confidentialityCode code="N" codeSystem="2.16.840.1.113883.5.25"/>' . "\n";
		$xml .= '  <languageCode code="en-US"/>' . "\n";

		$xml .= '  <recordTarget><patientRole>' . "\n";
		if ( '' !== $mrn ) {
			$xml .= '    <id extension="' . esc_attr( $mrn ) . '" root="2.16.840.1.113883.4.1"/>' . "\n";
		} else {
			$xml .= '    <id root="' . esc_attr( $this->generate_oid( $member_id ) ) . '"/>' . "\n";
		}
		$xml .= '    <patient>' . "\n";
		$xml .= '      <name><given>' . esc_html( $first ) . '</given><family>' . esc_html( $last ) . '</family></name>' . "\n";
		if ( '' !== $gender ) {
			$g    = strtoupper( substr( $gender, 0, 1 ) );
			$xml .= '      <administrativeGenderCode code="' . esc_attr( $g ) . '" codeSystem="2.16.840.1.113883.5.1"/>' . "\n";
		}
		if ( '' !== $dob ) {
			$xml .= '      <birthTime value="' . esc_attr( preg_replace( '/[^0-9]/', '', $dob ) ) . '"/>' . "\n";
		}
		$xml .= '    </patient>' . "\n";
		$xml .= '  </patientRole></recordTarget>' . "\n";

		$xml .= '  <component><structuredBody>' . "\n";
		$xml .= $this->section( __( 'Allergies', 'mcp-ai-wpoos-pro' ), '48765-2', '2.16.840.1.113883.10.20.22.2.6.1', $allergies );
		$xml .= $this->section( __( 'Medications', 'mcp-ai-wpoos-pro' ), '10160-0', '2.16.840.1.113883.10.20.22.2.1.1', $meds );
		$xml .= $this->section( __( 'Problems', 'mcp-ai-wpoos-pro' ), '11450-4', '2.16.840.1.113883.10.20.22.2.5.1', $problems );
		$xml .= $this->section( __( 'Immunizations', 'mcp-ai-wpoos-pro' ), '11369-6', '2.16.840.1.113883.10.20.22.2.2.1', $immun );
		$xml .= '  </structuredBody></component>' . "\n";
		$xml .= '</ClinicalDocument>' . "\n";

		/**
		 * Filter the generated CCDA XML.
		 *
		 * @since 1.4.0
		 *
		 * @param string $xml       The CCDA XML document.
		 * @param int    $member_id Member post ID.
		 */
		$xml = (string) apply_filters( 'wp_mcp_ai_healthcare_ccda_document', $xml, $member_id );

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'export',
				'ccda_document',
				$member_id,
				array(
					'user_id' => $current_user_id,
					'tool'    => $this->get_slug(),
				)
			);
		}

		return array(
			'success'   => true,
			'member_id' => $member_id,
			'xml'       => $xml,
			'sections'  => array(
				'allergies'     => count( $allergies ),
				'medications'   => count( $meds ),
				'problems'      => count( $problems ),
				'immunizations' => count( $immun ),
			),
		);
	}

	/**
	 * Fetch titles for posts of a CPT linked to the member by meta key.
	 *
	 * @param string $cpt       CPT slug.
	 * @param string $meta_key  Member id meta key.
	 * @param int    $member_id Member post ID.
	 * @return string[]
	 */
	private function fetch_titles( $cpt, $meta_key, $member_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => $cpt,
				'posts_per_page' => 200,
				'post_status'    => 'publish',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $member_id,
					),
				),
			)
		);
		$out   = array();
		foreach ( $query->posts as $p ) {
			$out[] = (string) $p->post_title;
		}
		return $out;
	}

	/**
	 * Build a CDA section narrative block.
	 *
	 * @param string $title     Title.
	 * @param string $loinc     LOINC code.
	 * @param string $template  Template OID.
	 * @param array  $items     Item list.
	 * @return string
	 */
	private function section( $title, $loinc, $template, $items ) {
		$xml  = '    <component><section>' . "\n";
		$xml .= '      <templateId root="' . esc_attr( $template ) . '"/>' . "\n";
		$xml .= '      <code code="' . esc_attr( $loinc ) . '" codeSystem="2.16.840.1.113883.6.1"/>' . "\n";
		$xml .= '      <title>' . esc_html( $title ) . '</title>' . "\n";
		$xml .= '      <text>';
		if ( empty( $items ) ) {
			$xml .= '<paragraph>' . esc_html__( 'No information.', 'mcp-ai-wpoos-pro' ) . '</paragraph>';
		} else {
			$xml .= '<list>';
			foreach ( $items as $item ) {
				$xml .= '<item>' . esc_html( $item ) . '</item>';
			}
			$xml .= '</list>';
		}
		$xml .= '</text>' . "\n";
		$xml .= '    </section></component>' . "\n";
		return $xml;
	}

	/**
	 * Generate a deterministic pseudo-OID for a document or patient.
	 *
	 * @param int $seed Seed.
	 * @return string
	 */
	private function generate_oid( $seed ) {
		return '2.16.840.1.113883.3.9999.' . absint( $seed ) . '.' . wp_rand( 1000, 9999 );
	}
}

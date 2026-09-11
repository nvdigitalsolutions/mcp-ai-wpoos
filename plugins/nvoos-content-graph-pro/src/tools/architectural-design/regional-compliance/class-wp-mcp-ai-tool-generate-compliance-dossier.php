<?php
/**
 * Tool_Generate_Compliance_Dossier (ecosystem port - Wave F2, architectural-design tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architectural-design/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * per-file seams — the base-owned interface/Logger/media-url-utils requires gain exists-check seams
 * resolving from the addon's D8-compat `src/` copies, the response/subprocess traits are
 * wave-proof-guarded, the openai/gemini client requires stay monolith-gated, and the
 * `WP_MCP_AI_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}


/**
 * Generate per-country compliance dossier.
 */
class WP_MCP_AI_Tool_Generate_Compliance_Dossier implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Reason this tool is unavailable, if any.
	 *
	 * @since 1.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_compliance_dossier';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Compliance Dossier', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Assemble a per-country compliance dossier (LK / JM / US) bundling planning, structural (wind + seismic), accessibility, fire-safety and energy results plus the recommended supporting drawings, certifications and statutory submissions for the local authority.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code' => array(
					'type'        => 'string',
					'description' => __( 'ISO country code.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'project'      => array(
					'type'        => 'object',
					'description' => __( 'Project metadata for the dossier cover page.', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'title'     => array( 'type' => 'string' ),
						'address'   => array( 'type' => 'string' ),
						'client'    => array( 'type' => 'string' ),
						'architect' => array( 'type' => 'string' ),
						'engineer'  => array( 'type' => 'string' ),
						'date'      => array( 'type' => 'string' ),
					),
				),
				'sections'     => array(
					'type'        => 'object',
					'description' => __( 'Pre-computed result sections to bundle. Any subset is accepted; missing sections are listed as "not provided".', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'planning_compliance' => array( 'type' => 'object' ),
						'wind_loads'          => array( 'type' => 'object' ),
						'seismic_loads'       => array( 'type' => 'object' ),
						'setbacks_far'        => array( 'type' => 'object' ),
						'building_code'       => array( 'type' => 'object' ),
						'sustainability'      => array( 'type' => 'object' ),
						'natural_ventilation' => array( 'type' => 'object' ),
						'daylight_solar_gain' => array( 'type' => 'object' ),
						'thermal_comfort'     => array( 'type' => 'object' ),
					),
				),
			),
			'required'             => array( 'country_code' ),
			'additionalProperties' => false,
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
			'cacheable',
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate compliance dossiers.', 'nvoos-content-graph-pro' )
			);
		}

		$country_code = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$project      = isset( $arguments['project'] ) ? (array) $arguments['project'] : array();
		$sections     = isset( $arguments['sections'] ) ? (array) $arguments['sections'] : array();

		if ( ! in_array( $country_code, array( 'LK', 'JM', 'US' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'country_code must be LK, JM, or US.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize project metadata.
		$project_clean = array();
		foreach ( array( 'title', 'address', 'client', 'architect', 'engineer', 'date' ) as $key ) {
			$project_clean[ $key ] = isset( $project[ $key ] ) ? sanitize_text_field( $project[ $key ] ) : '';
		}

		// Recommended attachments and statutory submissions per country.
		$attachments = $this->required_attachments_for( $country_code );
		$submissions = $this->statutory_submissions_for( $country_code );

		// Aggregate per-section status.
		$section_summary = array();
		$known_sections  = array(
			'planning_compliance',
			'wind_loads',
			'seismic_loads',
			'setbacks_far',
			'building_code',
			'sustainability',
			'natural_ventilation',
			'daylight_solar_gain',
			'thermal_comfort',
		);
		$failed          = 0;
		$warned          = 0;
		$passed          = 0;
		foreach ( $known_sections as $key ) {
			$body   = isset( $sections[ $key ] ) ? $sections[ $key ] : null;
			$status = 'not_provided';
			if ( is_array( $body ) ) {
				if ( isset( $body['overall_status'] ) ) {
					$status = sanitize_text_field( (string) $body['overall_status'] );
				} elseif ( isset( $body['success'] ) && $body['success'] ) {
					$status = 'pass';
				}
				if ( 'fail' === $status ) {
					++$failed;
				} elseif ( 'conditional' === $status || 'warning' === $status ) {
					++$warned;
				} elseif ( 'pass' === $status ) {
					++$passed;
				}
			}
			$section_summary[ $key ] = array(
				'provided' => is_array( $body ),
				'status'   => $status,
			);
		}

		$overall = 'pass';
		if ( $failed > 0 ) {
			$overall = 'fail';
		} elseif ( $warned > 0 ) {
			$overall = 'conditional';
		} elseif ( 0 === $passed ) {
			$overall = 'incomplete';
		}

		$result = array(
			'success'         => true,
			'country_code'    => $country_code,
			'project'         => $project_clean,
			'generated_at'    => gmdate( 'c' ),
			'sections'        => $sections,
			'section_summary' => $section_summary,
			'attachments'     => $attachments,
			'submissions'     => $submissions,
			'overall_status'  => $overall,
			'totals'          => array(
				'sections_passed'       => $passed,
				'sections_warning'      => $warned,
				'sections_failed'       => $failed,
				'sections_not_provided' => count( $known_sections ) - $passed - $warned - $failed,
			),
			'disclaimer'      => __( 'Analytical / advisory dossier only. Final submission must be signed and stamped by the relevant registered professionals.', 'nvoos-content-graph-pro' ),
		);

		/**
		 * Filters the assembled compliance dossier before return.
		 *
		 * @since 1.3.0
		 *
		 * @param array $result   Dossier payload.
		 * @param array $arguments Original arguments.
		 */
		$result = apply_filters( 'wp_mcp_ai_arch_compliance_dossier', $result, $arguments );

		return $result;
	}

	/**
	 * Recommended supporting attachments for the dossier.
	 *
	 * @param string $country Country code.
	 * @return array<int,string>
	 */
	protected function required_attachments_for( $country ) {
		$base = array(
			__( 'Site survey plan with boundaries and dimensions.', 'nvoos-content-graph-pro' ),
			__( 'Floor plans (each level), elevations and sections.', 'nvoos-content-graph-pro' ),
			__( 'Structural drawings (foundations, framing, roof) signed by the engineer of record.', 'nvoos-content-graph-pro' ),
			__( 'MEP drawings (water, drainage, electrical, mechanical).', 'nvoos-content-graph-pro' ),
			__( 'Specifications / schedule of finishes.', 'nvoos-content-graph-pro' ),
		);
		if ( 'LK' === $country ) {
			$base[] = __( 'Form A - UDA application form.', 'nvoos-content-graph-pro' );
			$base[] = __( 'NBRO clearance (if site is in landslide zone).', 'nvoos-content-graph-pro' );
			$base[] = __( 'Drainage Board NoC if drainage is impacted.', 'nvoos-content-graph-pro' );
			$base[] = __( 'Water Board service letter / NWSDB clearance.', 'nvoos-content-graph-pro' );
			$base[] = __( 'CEA Environmental Protection Licence (for industrial categories).', 'nvoos-content-graph-pro' );
			$base[] = __( 'EIA / IEE report if dwelling units exceed UDA threshold.', 'nvoos-content-graph-pro' );
		} elseif ( 'JM' === $country ) {
			$base[] = __( 'Parish Council building application form.', 'nvoos-content-graph-pro' );
			$base[] = __( 'NEPA approval letter (if applicable).', 'nvoos-content-graph-pro' );
			$base[] = __( 'Hurricane resilience checklist (impact-rated openings, tie-down schedule).', 'nvoos-content-graph-pro' );
			$base[] = __( 'Title / proof of ownership.', 'nvoos-content-graph-pro' );
			$base[] = __( 'Surveyor identification report (SID).', 'nvoos-content-graph-pro' );
		} elseif ( 'US' === $country ) {
			$base[] = __( 'Energy compliance documentation (COMcheck or REScheck).', 'nvoos-content-graph-pro' );
			$base[] = __( 'Truss / engineered-lumber drawings.', 'nvoos-content-graph-pro' );
			$base[] = __( 'ASCE 7 wind / seismic design narrative.', 'nvoos-content-graph-pro' );
			$base[] = __( 'Geotechnical report (when required by jurisdiction).', 'nvoos-content-graph-pro' );
			$base[] = __( 'Zoning compliance affidavit / variance documentation if applicable.', 'nvoos-content-graph-pro' );
		}
		return $base;
	}

	/**
	 * Statutory submissions / authorities to engage per country.
	 *
	 * @param string $country Country code.
	 * @return array<int,array>
	 */
	protected function statutory_submissions_for( $country ) {
		$out = array();
		if ( 'LK' === $country ) {
			$out[] = array(
				'authority' => 'Urban Development Authority (UDA)',
				'purpose'   => __( 'Planning and Building approval', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'Local Pradeshiya Sabha / Municipal Council',
				'purpose'   => __( 'Building permit issuance', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'NBRO',
				'purpose'   => __( 'Landslide / hazard clearance (where required)', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'CEA',
				'purpose'   => __( 'Environmental Protection Licence (industrial uses)', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'SLIA',
				'purpose'   => __( 'Architect signoff', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'IESL',
				'purpose'   => __( 'Chartered structural engineer signoff', 'nvoos-content-graph-pro' ),
			);
		} elseif ( 'JM' === $country ) {
			$out[] = array(
				'authority' => 'Parish Council',
				'purpose'   => __( 'Building permit', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'KSAMC (if Kingston / St. Andrew)',
				'purpose'   => __( 'Local development order compliance', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'NEPA',
				'purpose'   => __( 'Environmental permit (where applicable)', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'Bureau of Standards Jamaica',
				'purpose'   => __( 'JNBC compliance', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'JIA',
				'purpose'   => __( 'Registered architect signoff', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'JIE',
				'purpose'   => __( 'Chartered engineer signoff', 'nvoos-content-graph-pro' ),
			);
		} elseif ( 'US' === $country ) {
			$out[] = array(
				'authority' => 'Local Building Department (AHJ)',
				'purpose'   => __( 'Building permit', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'Planning / Zoning Board',
				'purpose'   => __( 'Zoning approval', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'Fire Marshal',
				'purpose'   => __( 'Life-safety review', 'nvoos-content-graph-pro' ),
			);
			$out[] = array(
				'authority' => 'State Architect / PE Board',
				'purpose'   => __( 'Licensed signature & seal', 'nvoos-content-graph-pro' ),
			);
		}
		return $out;
	}
}

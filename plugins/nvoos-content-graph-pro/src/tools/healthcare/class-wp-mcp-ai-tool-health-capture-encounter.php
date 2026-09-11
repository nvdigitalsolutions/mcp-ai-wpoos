<?php
/**
 * class-wp-mcp-ai-tool-health-capture-encounter.php (ecosystem port — Wave F4, healthcare wellness breadth batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-tool-health-capture-encounter.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the capture-tool-base require resolves from the
 * addon's already-ported `src/tools/capture/` copy; the media-worker-client trait resolves via
 * the entry's `src/traits/` probe).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Capture_Tool_Base' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/capture/class-wp-mcp-ai-pro-capture-tool-base.php';
}

/**
 * MemPalace capture tool for healthcare clinical encounters.
 */
class WP_MCP_AI_Tool_Health_Capture_Encounter extends WP_MCP_AI_Pro_Capture_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'health_capture_encounter';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Healthcare — Capture Encounter', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture a clinical encounter, vitals reading, allergy note, prescription change, imaging finding, or clinical note into the MemPalace patient drawer. Records are PHI-classified and are born tier=core so they are always loaded by hierarchical recall and wake_up_context.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_prefix() {
		return 'patient';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_name() {
		return 'member_id';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_wing_key_description() {
		return __( 'Patient / member identifier (Member CPT post ID, MRN, or FHIR Patient.id). Forms the wing slug `patient/{member_id}`.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_room_enum() {
		return array( 'vitals', 'allergies', 'prescriptions', 'imaging', 'notes' );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_capture_defaults() {
		return array(
			'tier'          => WP_MCP_AI_Memory_Capture_Service::TIER_CORE,
			'importance'    => 0.85,
			'sensitivity'   => 'phi',
			'consent_basis' => 'consent',
			'verbatim'      => true,
			// 7 years matches HIPAA minimum retention; per-wing override
			// (Phase A4) can extend further per jurisdiction.
			'ttl'           => 7 * 365 * DAY_IN_SECONDS,
			'source'        => 'health_capture_encounter',
			'default_tags'  => array( 'healthcare', 'encounter', 'phi' ),
		);
	}

	/**
	 * Healthcare adds the `phi` flag to the standard capture flags.
	 *
	 * @return string[]
	 */
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array_merge(
			parent::get_capability_flags(),
			array( 'phi' )
		);
	}
}

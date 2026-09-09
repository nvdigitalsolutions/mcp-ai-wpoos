<?php
/**
 * wellness/reminders-research/class-wp-mcp-ai-tool-get-medication-schedule.php (ecosystem port — Wave F4, healthcare wellness breadth batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/reminders-research/class-wp-mcp-ai-tool-get-medication-schedule.php` for the
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

/**
 * Get daily medication schedule for a member.
 */
class WP_MCP_AI_Tool_Get_Medication_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_medication_schedule';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Medication Schedule', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieves a daily medication schedule for a member, listing all active prescriptions with dosage and frequency.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}


	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_prescription',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver', 'patient' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * Check if tool is available.
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view medication schedules.', 'nvoos-content-graph-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'nvoos-content-graph-pro' ) );
		}

		// Get all active prescriptions for the member.
		$query_args = array(
			'post_type'      => 'mcp_ai_prescription',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'get_medication_schedule', 0, 1000 ) : 1000,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => '_prescription_member_id',
					'value' => $member_id,
				),
				array(
					'key'   => '_prescription_status',
					'value' => 'active',
				),
			),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $query_args );

		$medications = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$prescription_id = get_the_ID();

				$medications[] = array(
					'id'                 => $prescription_id,
					'medication_name'    => get_the_title(),
					'dosage'             => get_post_meta( $prescription_id, '_prescription_dosage', true ),
					'frequency'          => get_post_meta( $prescription_id, '_prescription_frequency', true ),
					'prescribing_doctor' => get_post_meta( $prescription_id, '_prescription_doctor', true ),
					'start_date'         => get_post_meta( $prescription_id, '_prescription_start_date', true ),
					'end_date'           => get_post_meta( $prescription_id, '_prescription_end_date', true ),
					'refills_remaining'  => get_post_meta( $prescription_id, '_prescription_refills_remaining', true ),
					'notes'              => get_the_content(),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'      => true,
			'member_id'    => $member_id,
			'member_name'  => $member->post_title,
			'medications'  => $medications,
			'total'        => count( $medications ),
			'generated_at' => current_time( 'mysql' ),
		);
	}
}

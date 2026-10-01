<?php
/**
 * Outbound Import Leads — bulk-import ICP decision-maker lists as CRM leads.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound lead import tool.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_Tool_Outbound_Import_Leads implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_outbound_booking_toolkit'] ) && ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Outbound Booking Toolkit and CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'outbound_import_leads';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Outbound Leads', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import decision-maker rows as CRM leads for outbound sequences. Dedupes by email, applies ICP scoring, and optionally enrolls in a sequence.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Bulk-loading a target list of decision-makers (email, name, company, title, LinkedIn, Instagram) into the outbound pipeline.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Creating a single lead; use create_lead. Changing an existing lead; use update_lead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'create_lead', 'enroll_lead_in_sequence', 'outbound_get_pipeline_stats' ),
			'notes'           => __( 'Rows are deduped by email; consent is attested per import and gates auto-send email.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'rows'        => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'email'      => array( 'type' => 'string' ),
							'first_name' => array( 'type' => 'string' ),
							'last_name'  => array( 'type' => 'string' ),
							'company'    => array( 'type' => 'string' ),
							'job_title'  => array( 'type' => 'string' ),
							'linkedin'   => array( 'type' => 'string' ),
							'instagram'  => array( 'type' => 'string' ),
						),
					),
				),
				'sequence_id' => array( 'type' => 'integer', 'default' => 0 ),
				'consent'     => array( 'type' => 'boolean', 'default' => true ),
			),
			'required'   => array( 'rows' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability', 'requires-consent' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$rows = isset( $arguments['rows'] ) && is_array( $arguments['rows'] ) ? $arguments['rows'] : array();
		if ( empty( $rows ) ) {
			return new WP_Error( 'no_rows', __( 'No lead rows provided.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( count( $rows ) > 1000 ) {
			return new WP_Error( 'too_many_rows', __( 'Maximum 1000 rows per import.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitise each row into the importer's column shape.
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = array(
				'email'      => isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '',
				'first_name' => isset( $row['first_name'] ) ? sanitize_text_field( $row['first_name'] ) : '',
				'last_name'  => isset( $row['last_name'] ) ? sanitize_text_field( $row['last_name'] ) : '',
				'company'    => isset( $row['company'] ) ? sanitize_text_field( $row['company'] ) : '',
				'job_title'  => isset( $row['job_title'] ) ? sanitize_text_field( $row['job_title'] ) : '',
				'linkedin'   => isset( $row['linkedin'] ) ? esc_url_raw( $row['linkedin'] ) : '',
				'instagram'  => isset( $row['instagram'] ) ? sanitize_text_field( $row['instagram'] ) : '',
			);
		}

		$summary = WP_MCP_AI_OA_Import::import_rows(
			$clean,
			isset( $arguments['sequence_id'] ) ? absint( $arguments['sequence_id'] ) : 0,
			! isset( $arguments['consent'] ) || ! empty( $arguments['consent'] )
		);
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		return array_merge(
			array(
				'success' => true,
				'message' => __( 'Leads imported.', 'mcp-ai-wpoos-pro' ),
			),
			$summary
		);
	}
}

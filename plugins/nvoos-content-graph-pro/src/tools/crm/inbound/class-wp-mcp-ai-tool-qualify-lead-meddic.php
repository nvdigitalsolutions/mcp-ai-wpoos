<?php
/**
 * Qualify Lead Meddic Tool (ecosystem port — Wave F2, CRM inbound batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/inbound/class-wp-mcp-ai-tool-qualify-lead-meddic.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Qualify Lead MEDDIC — MEDDIC qualification assessment (enterprise sales).
 *
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since  2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enterprise qualification using MEDDIC: Metrics, Economic Buyer, Decision
 * Criteria, Decision Process, Identify Pain, Champion.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Qualify_Lead_Meddic implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qualify_lead_meddic';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Qualify Lead (MEDDIC)', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Enterprise qualification using MEDDIC: Metrics, Economic Buyer, Decision Criteria, Decision Process, Identify Pain, Champion.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'          => array( 'type' => 'integer' ),
				'message_or_notes' => array( 'type' => 'string' ),
			),
			'required'   => array( 'lead_id' ),
		);
	}

	/**
	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Whether the tool requires base pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get the capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
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
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}

		$lead_id = absint( $arguments['lead_id'] );
		$p       = get_post( $lead_id );
		if ( ! $p || ! in_array( $p->post_type, array( 'mcp_ai_lead', 'mcp_crm_contacts' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'nvoos-content-graph-pro' ) );
		}

		$text = sanitize_textarea_field( $arguments['message_or_notes'] ?? $p->post_content ?? '' );
		if ( ! class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			return new WP_Error( 'classifier_missing', __( 'CRM Classifier not available.', 'nvoos-content-graph-pro' ) );
		}

		$meddic = WP_MCP_AI_CRM_Classifier::extract_meddic( $text );
		update_post_meta( $lead_id, 'meddic_assessment', $meddic );
		$total = 0;
		foreach ( $meddic as $v ) {
			$total += $v['score'];
		}
		$overall = $total / max( 1, count( $meddic ) );
		$missing = array();
		foreach ( $meddic as $k => $v ) {
			if ( 0 === $v['score'] ) {
				$missing[] = $k;
			}
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'lead_qualified_meddic', 'lead', $lead_id );
		}

		return array(
			'success'       => true,
			'lead_id'       => $lead_id,
			'meddic'        => $meddic,
			'overall_score' => round( $overall, 1 ),
			'missing_info'  => $missing,
			'is_qualified'  => $overall >= 45,
			'message'       => $overall >= 45
				? __( 'Lead meets MEDDIC qualification threshold.', 'nvoos-content-graph-pro' )
				: __( 'Lead needs more discovery before MEDDIC qualification.', 'nvoos-content-graph-pro' ),
		);
	}
}

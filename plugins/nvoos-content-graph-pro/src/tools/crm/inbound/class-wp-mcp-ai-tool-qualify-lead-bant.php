<?php
/**
 * Qualify Lead Bant Tool (ecosystem port — Wave F2, CRM inbound batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/inbound/class-wp-mcp-ai-tool-qualify-lead-bant.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Qualify Lead BANT — BANT qualification assessment.
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
 * Assess a lead using the BANT framework (Budget, Authority, Need, Timeline).
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Qualify_Lead_Bant implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'qualify_lead_bant';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Qualify Lead (BANT)', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Assess a lead using the BANT framework (Budget, Authority, Need, Timeline).', 'nvoos-content-graph-pro' );
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
				'message_or_notes' => array(
					'type'        => 'string',
					'description' => __( 'Conversation text, notes, or message body to analyse.', 'nvoos-content-graph-pro' ),
				),
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

		$bant = WP_MCP_AI_CRM_Classifier::extract_bant( $text );
		update_post_meta( $lead_id, 'bant_assessment', $bant );
		$overall = ( $bant['budget']['score'] + $bant['authority']['score'] + $bant['need']['score'] + $bant['timeline']['score'] ) / 4;
		$missing = array();
		foreach ( $bant as $k => $v ) {
			if ( 0 === $v['score'] ) {
				$missing[] = $k;
			}
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'lead_qualified_bant', 'lead', $lead_id );
		}

		return array(
			'success'       => true,
			'lead_id'       => $lead_id,
			'bant'          => $bant,
			'overall_score' => round( $overall, 1 ),
			'missing_info'  => $missing,
			'is_qualified'  => $overall >= 50,
			'message'       => $overall >= 50
				? __( 'Lead meets BANT qualification threshold.', 'nvoos-content-graph-pro' )
				: __( 'Lead needs more information before BANT qualification.', 'nvoos-content-graph-pro' ),
		);
	}
}

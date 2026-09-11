<?php
/**
 * Get Pipeline View Tool (ecosystem port — Wave F2, CRM analytics batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/analytics/class-wp-mcp-ai-tool-get-pipeline-view.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
* Pipeline View Tool — Kanban-style snapshot grouped by deal stage.
 *
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @subpackage CRM_Toolkit
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Pipeline View — Kanban-style snapshot grouped by deal stage. */
class WP_MCP_AI_Tool_Get_Pipeline_View implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }

	/**
	 * Reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' ); }

	/**
	 * Tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'get_pipeline_view'; }

	/**
	 * Tool display name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Pipeline View', 'nvoos-content-graph-pro' ); }

	/**
	 * Tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Kanban-style pipeline snapshot grouped by deal stage with weighted amounts.', 'nvoos-content-graph-pro' ); }

	/**
	 * Parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'deal_owner' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by assigned owner WP user ID.', 'nvoos-content-graph-pro' ),
				),
				'per_stage'  => array(
					'type'        => 'integer',
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
					'description' => __( 'Max deals per stage.', 'nvoos-content-graph-pro' ),
				),
			),
		);
	}
	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts'; }

	/**
	 * Whether this tool requires base pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true; }

	/**
	 * Capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' ); }

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) ); }

		$per_stage = min( 200, max( 1, absint( $arguments['per_stage'] ?? 50 ) ) );
		$stages    = WP_MCP_AI_CRM_Pipeline_Stages::get_stages();

		$meta_q = array();
		if ( ! empty( $arguments['deal_owner'] ) ) {
			$meta_q[] = array(
				'key'   => 'deal_owner',
				'value' => absint( $arguments['deal_owner'] ),
				'type'  => 'NUMERIC',
			);
		}

		$result = array(
			'success' => true,
			'stages'  => array(),
			'totals'  => array(
				'deal_count'      => 0,
				'total_amount'    => 0,
				'weighted_amount' => 0,
			),
		);

		foreach ( $stages as $stage_id => $stage_def ) {
			$args = array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => $per_stage,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'deal_stage',
						'value' => $stage_id,
					),
				),
				'no_found_rows'  => true,
			);
			if ( $meta_q ) {
				$args['meta_query'][] = $meta_q[0];
			}

			$deals_in_stage = array();
			$stage_amount   = 0;
			$q              = new WP_Query( $args );
			foreach ( $q->posts as $deal_id ) {
				$amount           = (float) get_post_meta( $deal_id, 'deal_amount', true );
				$prob             = (float) get_post_meta( $deal_id, 'deal_probability', true );
				$deals_in_stage[] = array(
					'id'               => $deal_id,
					'title'            => get_the_title( $deal_id ),
					'amount'           => $amount,
					'formatted_amount' => class_exists( 'WP_MCP_AI_CRM_Engine' ) ? WP_MCP_AI_CRM_Engine::format_currency( $amount ) : '$' . number_format_i18n( $amount, 2 ),
					'probability'      => $prob,
					'weighted_amount'  => $amount * $prob,
					'close_date'       => sanitize_text_field( (string) get_post_meta( $deal_id, 'close_date', true ) ),
				);
				$stage_amount    += $amount;
				++$result['totals']['deal_count'];
				$result['totals']['total_amount']    += $amount;
				$result['totals']['weighted_amount'] += $amount * $prob;
			}

			$result['stages'][] = array(
				'stage_id'     => $stage_id,
				'label'        => $stage_def['label'],
				'probability'  => $stage_def['probability'] ?? 0,
				'color'        => $stage_def['color'] ?? '',
				'is_won'       => ! empty( $stage_def['is_won'] ),
				'is_lost'      => ! empty( $stage_def['is_lost'] ),
				'deal_count'   => count( $deals_in_stage ),
				'stage_amount' => $stage_amount,
				'deals'        => $deals_in_stage,
			);
		}

		$result['totals']['formatted_total'] = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::format_currency( $result['totals']['total_amount'] )
			: '$' . number_format_i18n( $result['totals']['total_amount'], 2 );

		$result['totals']['formatted_weighted'] = class_exists( 'WP_MCP_AI_CRM_Engine' )
			? WP_MCP_AI_CRM_Engine::format_currency( $result['totals']['weighted_amount'] )
			: '$' . number_format_i18n( $result['totals']['weighted_amount'], 2 );

		return $result;
	}
}

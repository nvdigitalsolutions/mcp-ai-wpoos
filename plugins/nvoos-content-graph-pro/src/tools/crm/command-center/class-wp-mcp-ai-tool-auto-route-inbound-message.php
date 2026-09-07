<?php
/**
 * Auto-Route Inbound Message Tool (ecosystem port — Wave F2, CRM
 * workflow-rules + routing batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/command-center/class-wp-mcp-ai-tool-auto-route-inbound-message.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Auto-Route Inbound Message tool — workload-aware routing.
 */
class WP_MCP_AI_Tool_Auto_Route_Inbound_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'auto_route_inbound_message'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Auto-Route Inbound Message', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Automatically assign a new lead to the best owner using the configured routing strategy and workload.', 'nvoos-content-graph-pro' ); }
	/**
	 * Get the JSON Schema for the tool parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'  => array( 'type' => 'integer' ),
				'strategy' => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'round_robin', 'weighted' ),
					'default' => 'auto',
				),
			),
			'required'   => array( 'lead_id' ),
		); }
	/**
	 * Get the required WordPress capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts'; }
	/**
	 * Check whether this tool requires the base Pro version.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true; }
	/**
	 * Get capability flags for the tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' ); }
	/**
	 * Execute the tool.
	 *
	 * @param array $arguments The tool arguments.
	 * @param array $context   The execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$lead_id = absint( $arguments['lead_id'] );
		$lp      = get_post( $lead_id );
		if ( ! $lp ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'nvoos-content-graph-pro' ) ); }
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Engine not available.', 'nvoos-content-graph-pro' ) ); }
		$owner = WP_MCP_AI_CRM_Engine::get_next_owner();
		if ( ! $owner ) {
			return new WP_Error( 'no_owner', __( 'No owner available in routing pool.', 'nvoos-content-graph-pro' ) ); }
		$prev = get_post_meta( $lead_id, 'contact_owner', true );
		update_post_meta( $lead_id, 'contact_owner', $owner );
		$u = get_userdata( $owner );
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_auto_routed',
				'lead',
				$lead_id,
				array(
					'previous_owner' => $prev,
					'new_owner'      => $owner,
				)
			); }
		return array(
			'success'        => true,
			'message'        => sprintf(
				/* translators: %s: display name of the assigned owner */
				__( 'Lead routed to %s.', 'nvoos-content-graph-pro' ),
				$u ? $u->display_name : (string) $owner
			),
			'lead_id'        => $lead_id,
			'owner_id'       => $owner,
			'owner_name'     => $u ? $u->display_name : '',
			'previous_owner' => $prev,
		);
	}
}

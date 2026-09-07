<?php
/**
 * Check DNC Status Tool (ecosystem port — Wave F2, CRM consent/DNC
 * batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/compliance/class-wp-mcp-ai-tool-check-dnc-status.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since     2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks whether an identifier is on the Do Not Contact list.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Check_Dnc_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Reason the tool is unavailable.
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
		return 'check_dnc_status';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Check DNC Status', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Check whether an identifier (email or phone) is on the Do Not Contact list.', 'nvoos-content-graph-pro' );
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
				'identifier' => array( 'type' => 'string' ),
				'channel'    => array(
					'type'    => 'string',
					'default' => 'all',
				),
			),
			'required'   => array( 'identifier' ),
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
	 * Whether the tool requires Base Pro.
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
		return array( 'pro', 'database-read', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$id = strtolower( trim( sanitize_text_field( $arguments['identifier'] ) ) );
		$ch = sanitize_key( $arguments['channel'] ?? 'all' );
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Engine not available.', 'nvoos-content-graph-pro' ) );
		}
		$blocked = WP_MCP_AI_CRM_Engine::check_dnc( $id, $ch );
		// Allow external DNC sources via filter.
		$external = apply_filters( 'wp_mcp_ai_crm_dnc_lists', false, $id, $ch );
		return array(
			'success'    => true,
			'identifier' => $id,
			'channel'    => $ch,
			'is_blocked' => $blocked || $external,
			'source'     => $external ? 'external' : ( $blocked ? 'internal' : 'none' ),
			'message'    => ( $blocked || $external ) ? __( 'Identifier is blocked.', 'nvoos-content-graph-pro' ) : __( 'Identifier is not blocked.', 'nvoos-content-graph-pro' ),
		);
	}
}

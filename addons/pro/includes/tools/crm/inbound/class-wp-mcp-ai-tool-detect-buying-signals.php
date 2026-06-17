<?php
/**
 * Detect Buying Signals — keyword + filter-extensible signal detection.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect buying-intent keywords and phrases in a message body.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Detect_Buying_Signals implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'detect_buying_signals';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Detect Buying Signals', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Detect buying-intent keywords and phrases in a message body.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'message_body' => array( 'type' => 'string' ) ),
			'required'   => array( 'message_body' ),
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
		$body       = sanitize_textarea_field( $arguments['message_body'] );
		$lower      = mb_strtolower( $body );
		$default_kw = array(
			'pricing',
			'demo',
			'next step',
			'timeline',
			'budget',
			'decision maker',
			'authority',
			'trial',
			'competing',
			'competitor',
			'implement',
			'rollout',
			'buy',
			'purchase',
			'sign',
			'urgent',
			'asap',
		);
		$kw         = apply_filters( 'wp_mcp_ai_crm_buying_signal_keywords', $default_kw );
		$signals    = array();
		foreach ( $kw as $k ) {
			if ( false !== strpos( $lower, $k ) ) {
				$signals[] = $k;
			}
		}
		$signals = array_unique( $signals );
		$hot     = count( $signals ) >= 3;
		return array(
			'success'        => true,
			'buying_signals' => $signals,
			'signal_count'   => count( $signals ),
			'is_hot'         => $hot,
			'message'        => $hot
				? __( 'Multiple buying signals detected — lead appears hot.', 'mcp-ai-wpoos-pro' )
				: __( 'Buying signal scan complete.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

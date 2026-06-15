<?php
/**
 * Classify Message Intent — delegates to WP_MCP_AI_CRM_Classifier.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify an inbound message for intent, sentiment, buying signals, and
 * spam probability.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Classify_Message_Intent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'classify_message_intent';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Classify Message Intent', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Classify an inbound message for intent, sentiment, buying signals, and spam probability.', 'mcp-ai-wpoos-pro' );
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
				'message_body' => array( 'type' => 'string' ),
				'channel'      => array(
					'type'    => 'string',
					'default' => 'email',
				),
			),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}

		if ( ! class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			return new WP_Error( 'classifier_missing', __( 'CRM Classifier engine not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$body    = sanitize_textarea_field( $arguments['message_body'] );
		$channel = sanitize_key( $arguments['channel'] ?? 'email' );
		$result  = WP_MCP_AI_CRM_Classifier::classify( $body, $channel );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'        => true,
			'classification' => $result,
		);
	}
}

<?php
/**
 * Multilingual SEO Audit Tool
 *
 * SEO optimization audit for translated content including hreflang tags and meta descriptions.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Multilingual_SEO_Audit tool.
 */
class WP_MCP_AI_Tool_Multilingual_SEO_Audit implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_multilingual_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_multilingual_toolkit'] ) ) {
			return __( 'Multi-language Content toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Multilingual SEO Audit tool is not available.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'multilingual_seo_audit';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Multilingual SEO Audit', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'SEO optimization audit for translated content including hreflang tags and meta descriptions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Auditing translated content for SEO gaps such as hreflang tags and translated meta descriptions.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Translation quality checks; use translation_quality_check for completeness and consistency instead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'translation_quality_check', 'auto_translate_content' ),
			'notes'           => __( 'Toggle check_hreflang and check_meta to narrow the audit to tags or meta descriptions.', 'mcp-ai-wpoos-pro' ),
		);
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
				'post_id'        => array(
					'type'        => 'integer',
					'description' => 'Post ID to audit',
				),
				'language'       => array(
					'type'        => 'string',
					'description' => 'Language code',
				),
				'check_hreflang' => array(
					'type'        => 'boolean',
					'description' => 'Check hreflang tags',
					'default'     => true,
				),
				'check_meta'     => array(
					'type'        => 'boolean',
					'description' => 'Check translated meta descriptions',
					'default'     => true,
				),
			),
			'required'   => array(),
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
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'content'     => true,
			'translation' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// TODO: Implement multilingual_seo_audit logic.

		return array(
			'success' => true,
			'message' => __( 'Multilingual SEO Audit executed successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

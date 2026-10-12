<?php
/**
 * Tool that lists WPCode snippets.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-wpcode-snippet-repository.php';

/**
 * Lists WPCode snippets via the shared repository.
 *
 * Read-only companion to create_wpcode_snippet: dedupe checks, tag filters
 * and status inspection before installing or updating snippets.
 */
class WP_MCP_AI_Pro_Tool_List_WPCode_Snippets implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		return WP_MCP_AI_Pro_WPCode_Snippet_Repository::is_available();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		if ( ! function_exists( 'wpcode' ) || ! class_exists( 'WPCode_Snippet' ) ) {
			return __( 'Install and activate the WPCode plugin to manage code snippets.', 'mcp-ai-wpoos-pro' );
		}

		return '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_wpcode_snippets';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List WPCode Snippets', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists WPCode snippets with status, code type, location and tags. Use before creating or updating snippets to avoid duplicates.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking which snippets exist, their active state, or finding snippets by tag (e.g. gtmetrix-fix).', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'When you need the full snippet code; this tool returns metadata only.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'create_wpcode_snippet' ),
			'notes'           => __( 'Requires the WPCode plugin.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tag'    => array(
					'type'        => 'string',
					'description' => __( 'Filter snippets by tag slug (e.g. gtmetrix-fix).', 'mcp-ai-wpoos-pro' ),
				),
				'active' => array(
					'type'        => 'boolean',
					'description' => __( 'Filter by active (published) state. Omit to list all.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum snippets to return (default 50, max 100).', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => 'snippet_id',
			'consumes' => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'wpcode_edit_snippets';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// phpcs:disable WordPressVIPMinimum.Security.UserCan
		if ( ! $user_id || ! user_can( $user_id, 'wpcode_edit_snippets' ) ) { // phpcs:ignore WordPressVIPMinimum.Security.UserCan.Unknown
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage WPCode snippets.', 'mcp-ai-wpoos-pro' ) );
		}
		// phpcs:enable WordPressVIPMinimum.Security.UserCan

		$repository = new WP_MCP_AI_Pro_WPCode_Snippet_Repository();
		if ( ! $repository->is_available() ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_missing', __( 'WPCode must be installed and active to manage snippets.', 'mcp-ai-wpoos-pro' ) );
		}

		$args = array(
			'limit' => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50,
		);
		if ( isset( $arguments['tag'] ) && '' !== sanitize_text_field( $arguments['tag'] ) ) {
			$args['tag'] = sanitize_text_field( $arguments['tag'] );
		}
		if ( array_key_exists( 'active', $arguments ) ) {
			$args['active'] = (bool) $arguments['active'];
		}

		$rows = $repository->list_snippets( $args );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return array(
			'success'  => true,
			'snippets' => $rows,
			'count'    => count( $rows ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Tool is part of the Pro tier.
			'read-only',            // Lists data only.
			'cacheable',            // Safe to cache.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}

<?php
/**
 * Document Version Tracker Tool
 *
 * Tracks document revision history using WordPress post revisions.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves document version history from WordPress revisions.
 */
class WP_MCP_AI_Tool_LF_Document_Version_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_document_version_tracker'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Document Version Tracker', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Retrieves version history for legal documents using WordPress post revisions.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'document_id' => array(
					'type'        => 'integer',
					'description' => __( 'Document post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'get_history', 'get_current_version' ),
				),
			),
			'required'   => array( 'document_id' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$document_id = isset( $arguments['document_id'] ) ? absint( $arguments['document_id'] ) : 0;
		$action      = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'get_history';

		if ( ! $document_id ) {
			return new WP_Error( 'missing_required', __( 'Document ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$document = get_post( $document_id );
		if ( ! $document ) {
			return new WP_Error( 'not_found', __( 'Document not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'get_current_version' === $action ) {
			$revisions = wp_get_post_revisions( $document_id );
			return array(
				'success'    => true,
				'message'    => __( 'Current version retrieved. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
				'data'       => array(
					'document_id'     => $document_id,
					'current_version' => count( $revisions ) + 1,
					'last_modified'   => $document->post_modified,
					'author'          => get_the_author_meta( 'display_name', $document->post_author ),
				),
				'disclaimer' => self::DISCLAIMER,
			);
		}

		$revisions = wp_get_post_revisions( $document_id );
		$history   = array();
		$version   = count( $revisions ) + 1;

		$history[] = array(
			'version' => $version,
			'date'    => $document->post_modified,
			'author'  => get_the_author_meta( 'display_name', $document->post_author ),
			'current' => true,
		);

		foreach ( $revisions as $rev ) {
			--$version;
			$history[] = array(
				'version' => $version,
				'date'    => $rev->post_modified,
				'author'  => get_the_author_meta( 'display_name', $rev->post_author ),
				'current' => false,
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf( __( 'Found %d versions. ', 'mcp-ai-wpoos-pro' ), count( $history ) ) . self::DISCLAIMER,
			'data'       => array(
				'document_id'     => $document_id,
				'version_history' => $history,
				'total_versions'  => count( $history ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

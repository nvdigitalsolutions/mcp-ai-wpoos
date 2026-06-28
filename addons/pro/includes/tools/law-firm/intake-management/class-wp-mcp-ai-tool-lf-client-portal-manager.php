<?php
/**
 * Client Portal Manager Tool
 *
 * Manages client portal access, document sharing, and secure communication.
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
 * Manages client portal access and document sharing.
 */
class WP_MCP_AI_Tool_LF_Client_Portal_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
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
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_client_portal_manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Client Portal Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Manages client portal access including creating access, revoking access, listing shared documents, and sharing documents with clients.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create_access', 'revoke_access', 'list_shared', 'share_document' ),
				),
				'client_id'   => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the client record.', 'mcp-ai-wpoos-pro' ),
				),
				'document_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the document to share (attachment post ID).', 'mcp-ai-wpoos-pro' ),
				),
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Optional message to include with the action.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'client_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action      = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$client_id   = isset( $arguments['client_id'] ) ? absint( $arguments['client_id'] ) : 0;
		$document_id = isset( $arguments['document_id'] ) ? absint( $arguments['document_id'] ) : 0;
		$message     = isset( $arguments['message'] ) ? sanitize_textarea_field( $arguments['message'] ) : '';

		if ( empty( $action ) || ! $client_id ) {
			return new WP_Error( 'missing_required', __( 'Action and client ID are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$client_post = get_post( $client_id );
		if ( ! $client_post || 'mcp_ai_lf_client' !== $client_post->post_type ) {
			return new WP_Error( 'not_found', __( 'Client record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$portal_access = get_post_meta( $client_id, '_lf_portal_access', true );
		if ( ! is_array( $portal_access ) ) {
			$portal_access = array(
				'enabled'          => false,
				'shared_documents' => array(),
				'created_at'       => '',
				'revoked_at'       => '',
			);
		}

		switch ( $action ) {
			case 'create_access':
				$portal_access['enabled']    = true;
				$portal_access['created_at'] = current_time( 'Y-m-d H:i:s' );
				$portal_access['revoked_at'] = '';
				update_post_meta( $client_id, '_lf_portal_access', $portal_access );

				return array(
					'success'    => true,
					'message'    => __( 'Client portal access created. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'action'      => 'create_access',
						'client_id'   => $client_id,
						'client_name' => $client_post->post_title,
						'access'      => true,
						'created_at'  => $portal_access['created_at'],
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'revoke_access':
				$portal_access['enabled']    = false;
				$portal_access['revoked_at'] = current_time( 'Y-m-d H:i:s' );
				update_post_meta( $client_id, '_lf_portal_access', $portal_access );

				return array(
					'success'    => true,
					'message'    => __( 'Client portal access revoked. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'action'      => 'revoke_access',
						'client_id'   => $client_id,
						'client_name' => $client_post->post_title,
						'access'      => false,
						'revoked_at'  => $portal_access['revoked_at'],
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'list_shared':
				$shared = $portal_access['shared_documents'] ?? array();
				$docs   = array();
				foreach ( $shared as $doc ) {
					$doc_post = get_post( $doc['document_id'] ?? 0 );
					$docs[]   = array(
						'document_id' => $doc['document_id'] ?? 0,
						'title'       => $doc_post ? $doc_post->post_title : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
						'shared_at'   => $doc['shared_at'] ?? '',
						'message'     => $doc['message'] ?? '',
					);
				}

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %d: number of shared documents */
						__( '%d shared documents found. ', 'mcp-ai-wpoos-pro' ),
						count( $docs )
					) . self::DISCLAIMER,
					'data'       => array(
						'action'      => 'list_shared',
						'client_id'   => $client_id,
						'client_name' => $client_post->post_title,
						'documents'   => $docs,
						'total'       => count( $docs ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'share_document':
				if ( ! $document_id ) {
					return new WP_Error( 'missing_required', __( 'Document ID is required for sharing.', 'mcp-ai-wpoos-pro' ) );
				}

				$doc_post = get_post( $document_id );
				if ( ! $doc_post || 'attachment' !== $doc_post->post_type ) {
					return new WP_Error( 'not_found', __( 'Document not found.', 'mcp-ai-wpoos-pro' ) );
				}

				if ( ! isset( $portal_access['shared_documents'] ) || ! is_array( $portal_access['shared_documents'] ) ) {
					$portal_access['shared_documents'] = array();
				}

				$portal_access['shared_documents'][] = array(
					'document_id' => $document_id,
					'shared_at'   => current_time( 'Y-m-d H:i:s' ),
					'shared_by'   => $uid,
					'message'     => $message,
				);
				update_post_meta( $client_id, '_lf_portal_access', $portal_access );

				return array(
					'success'    => true,
					'message'    => __( 'Document shared with client portal. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'action'         => 'share_document',
						'client_id'      => $client_id,
						'client_name'    => $client_post->post_title,
						'document_id'    => $document_id,
						'document_title' => $doc_post->post_title,
						'shared_at'      => current_time( 'Y-m-d H:i:s' ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid portal action.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}

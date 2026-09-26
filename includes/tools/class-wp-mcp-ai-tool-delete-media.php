<?php
/**
 * Tool: delete_media — Deletes a Media Library attachment.
 *
 * Port of mcp-wordpress wp_delete_media tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a Media Library attachment by ID. Moves the attachment to the trash
 * by default, or permanently deletes it (including its files) when forced.
 */
class WP_MCP_AI_Tool_Delete_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Media', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Deletes a Media Library attachment by ID. By default moves the attachment to the trash; set force to true to permanently remove it and its files.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Removing an attachment by ID, moving it to the trash by default or permanently deleting it with force.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Detaching a file from a post while keeping it in the Media Library.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_media', 'update_media', 'search_attachments' ),
			'notes'           => __( 'force=true permanently removes the attachment and its files; the default trash move is reversible.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the attachment to delete.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'force'         => array(
					'type'        => 'boolean',
					'description' => __( 'When true, permanently deletes the attachment and its files instead of moving it to the trash.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'attachment_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'attachment_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'delete_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete media.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$force         = isset( $arguments['force'] ) ? (bool) $arguments['force'] : false;

		if ( ! $attachment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'attachment_id is required.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_media_not_found', __( 'The requested media attachment could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'delete_post', $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this media attachment.', 'mcp-ai-wpoos' ) );
		}

		$title = get_the_title( $post );

		$result = wp_delete_attachment( $attachment_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'wp_mcp_ai_media_delete_failed',
				$force
					? __( 'The media attachment could not be permanently deleted.', 'mcp-ai-wpoos' )
					: __( 'The media attachment could not be moved to the trash.', 'mcp-ai-wpoos' )
			);
		}

		$action_label = $force
			? __( 'permanently deleted', 'mcp-ai-wpoos' )
			: __( 'moved to trash', 'mcp-ai-wpoos' );

		$summary_text = sprintf(
			/* translators: 1: attachment title, 2: attachment ID, 3: action (e.g. "moved to trash") */
			__( 'Media attachment %1$s (ID: %2$d) %3$s.', 'mcp-ai-wpoos' ),
			$title,
			$attachment_id,
			$action_label
		);

		return array(
			'message'       => $summary_text,
			'attachment_id' => absint( $attachment_id ),
			'force'         => $force,
			'title'         => esc_html( $title ),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'content_creator', 'photographer' ),
			'risk_level'            => 'high',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Modifies state (deletes data).
			'local-only',           // No external API calls.
			'requires-capability',  // Requires delete_post capability on the attachment.
			'state-changing',       // Modifies database state.
			'data-destruction',     // Permanently removes the attachment and its files.
		);
	}
}

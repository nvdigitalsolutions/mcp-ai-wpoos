<?php
/**
 * Tool: update_media — Updates metadata for a Media Library attachment.
 *
 * Port of mcp-wordpress wp_update_media tool.
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
 * Updates the title, alt text, caption, or description of a Media Library attachment.
 */
class WP_MCP_AI_Tool_Update_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Media', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates the title, alt text, caption, or description of a Media Library attachment. Omitted fields are left unchanged.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Updating the title, alt text, caption, or description of an existing attachment.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Replacing the file itself; re-upload via upload_media instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_media', 'search_attachments', 'generate_image_alt_text', 'image_alt_text_optimizer' ),
			'notes'           => __( 'At least one of title, alt_text, caption, or description must be provided; omitted fields are left unchanged.', 'mcp-ai-wpoos' ),
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
					'description' => __( 'The ID of the attachment to update.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Optional new title for the attachment.', 'mcp-ai-wpoos' ),
				),
				'alt_text'      => array(
					'type'        => 'string',
					'description' => __( 'Optional new alt text for the attachment.', 'mcp-ai-wpoos' ),
				),
				'caption'       => array(
					'type'        => 'string',
					'description' => __( 'Optional new caption for the attachment.', 'mcp-ai-wpoos' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Optional new description for the attachment. Basic HTML is allowed.', 'mcp-ai-wpoos' ),
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
		return 'upload_files';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update media.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'attachment_id is required.', 'mcp-ai-wpoos' ) );
		}

		// Sanitize the optional fields at entry (Gate 1).
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$alt_text    = isset( $arguments['alt_text'] ) ? sanitize_text_field( $arguments['alt_text'] ) : '';
		$caption     = isset( $arguments['caption'] ) ? sanitize_text_field( $arguments['caption'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';

		// Track which fields were actually provided.
		$updated = array();
		if ( '' !== $title ) {
			$updated[] = 'title';
		}
		if ( '' !== $alt_text ) {
			$updated[] = 'alt_text';
		}
		if ( '' !== $caption ) {
			$updated[] = 'caption';
		}
		if ( '' !== $description ) {
			$updated[] = 'description';
		}

		if ( empty( $updated ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'At least one of title, alt_text, caption, or description must be provided.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_media_not_found', __( 'The requested media attachment could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this media attachment.', 'mcp-ai-wpoos' ) );
		}

		$post_args = array( 'ID' => $attachment_id );

		if ( '' !== $title ) {
			$post_args['post_title'] = $title;
		}

		if ( '' !== $caption ) {
			$post_args['post_excerpt'] = $caption;
		}

		if ( '' !== $description ) {
			$post_args['post_content'] = $description;
		}

		$result = wp_update_post( $post_args );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_media_update_failed',
				__( 'The media attachment could not be updated.', 'mcp-ai-wpoos' ),
				array( 'reason' => $result->get_error_message() )
			);
		}

		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		$summary_text = sprintf(
			/* translators: 1: attachment ID, 2: comma-separated list of updated fields */
			__( 'Media attachment %1$d updated: %2$s.', 'mcp-ai-wpoos' ),
			$attachment_id,
			implode( ', ', $updated )
		);

		return array(
			'message'       => $summary_text,
			'attachment_id' => absint( $attachment_id ),
			'updated'       => $updated,
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
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',                // Modifies attachment metadata.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires edit_post capability on the attachment.
			'state-changing',       // Modifies database state.
			'reversible',           // Metadata changes can be undone by editing again.
		);
	}
}

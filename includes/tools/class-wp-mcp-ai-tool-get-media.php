<?php
/**
 * Tool: get_media — Returns metadata for a Media Library attachment.
 *
 * Port of mcp-wordpress wp_get_media tool.
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
 * Returns metadata for a Media Library attachment, including URL, MIME type,
 * alt text, caption, dimensions, and file size.
 */
class WP_MCP_AI_Tool_Get_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Media', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns metadata for a Media Library attachment, including URL, MIME type, alt text, caption, description, dimensions, and file size.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Fetching full metadata (URL, alt text, caption, dimensions, file size) for a known attachment ID.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Searching the Media Library by keywords or MIME type; use search_attachments instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'search_attachments', 'update_media', 'delete_media', 'upload_media' ),
			'notes'           => __( 'attachment_id comes from upload_media or search_attachments responses.', 'mcp-ai-wpoos' ),
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
					'description' => __( 'The ID of the attachment to fetch.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
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
		return 'read';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view media.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'attachment_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view media.', 'mcp-ai-wpoos' ) );
		}

		$post = get_post( $attachment_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_media_not_found', __( 'The requested media attachment could not be found.', 'mcp-ai-wpoos' ) );
		}

		$file_url  = wp_get_attachment_url( $attachment_id );
		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$file_path = get_attached_file( $attachment_id );
		$file_size = 0;

		if ( $file_path && file_exists( $file_path ) && is_readable( $file_path ) ) {
			$file_size = absint( filesize( $file_path ) );
		}

		$title = get_the_title( $post );

		$summary_text = sprintf(
			/* translators: 1: attachment title, 2: attachment ID */
			__( 'Media attachment: %1$s (ID: %2$d).', 'mcp-ai-wpoos' ),
			$title,
			$attachment_id
		);

		$response = array(
			'message'       => $summary_text,
			'attachment_id' => absint( $attachment_id ),
			'title'         => esc_html( $title ),
			'url'           => $file_url ? esc_url_raw( $file_url ) : '',
			'mime_type'     => esc_html( $post->post_mime_type ),
			'alt_text'      => esc_html( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			'caption'       => esc_html( wp_get_attachment_caption( $attachment_id ) ),
			'description'   => wp_strip_all_tags( $post->post_content ),
			'date'          => esc_html( $post->post_date ),
		);

		if ( is_array( $metadata ) && isset( $metadata['width'] ) ) {
			$response['width'] = absint( $metadata['width'] );
		}

		if ( is_array( $metadata ) && isset( $metadata['height'] ) ) {
			$response['height'] = absint( $metadata['height'] );
		}

		if ( $file_size > 0 ) {
			$response['file_size'] = $file_size;
		}

		return $response;
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
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires read capability.
		);
	}
}

<?php
/**
 * Tool for converting image formats.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Convert images between different formats (PNG, JPEG, WebP, GIF).
 */
class WP_MCP_AI_Tool_Convert_Image_Format extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'convert_image_format';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Convert Image Format', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert an image to a different format (PNG, JPEG, WebP, GIF) with optional quality control.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'format'  => array(
						'type'        => 'string',
						'description' => __( 'Target image format.', 'wp-mcp-ai' ),
						'enum'        => array( 'png', 'jpeg', 'jpg', 'webp', 'gif' ),
					),
					'quality' => array(
						'type'        => 'integer',
						'description' => __( 'Output quality for JPEG and WebP formats (1-100). Higher is better quality but larger file size.', 'wp-mcp-ai' ),
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 90,
					),
				)
			),
			'required'             => array( 'format' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to convert images.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'wp-mcp-ai' ) );
		}

		$format = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : '';
		if ( '' === $format ) {
			return new WP_Error( 'wp_mcp_ai_missing_format', __( 'Target format must be specified.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		// Normalize format.
		$format = strtolower( $format );
		if ( 'jpg' === $format ) {
			$format = 'jpeg';
		}

		$allowed_formats = array( 'png', 'jpeg', 'webp', 'gif' );
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_format', __( 'Invalid target format specified.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$quality = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 90;
		$quality = max( 1, min( 100, $quality ) );

		// Load source image.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		$original_mime = $image_editor->mime_type;

		// Set new MIME type and quality.
		$mime_type_map = array(
			'png'  => 'image/png',
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			'gif'  => 'image/gif',
		);

		$new_mime = $mime_type_map[ $format ];

		// Set quality for formats that support it.
		if ( in_array( $format, array( 'jpeg', 'webp' ), true ) ) {
			$result = $image_editor->set_quality( $quality );
			if ( is_wp_error( $result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $result;
			}
		}

		// Change MIME type.
		$image_editor->mime_type = $new_mime;

		// Save as new attachment.
		$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'converted' );

		// Clean up temp file if exists.
		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$result_data = array(
			'attachment_id'   => $storage['attachment_id'],
			'url'             => $storage['url'],
			'file_name'       => $storage['file_name'],
			'mime_type'       => $storage['mime_type'],
			'bytes'           => $storage['bytes'],
			'title'           => $storage['title'],
			'original_format' => $this->mime_to_format( $original_mime ),
			'new_format'      => $format,
			'quality'         => in_array( $format, array( 'jpeg', 'webp' ), true ) ? $quality : null,
			'operation'       => 'convert',
			'text'            => sprintf(
				/* translators: 1: original format, 2: new format */
				__( 'Successfully converted image from %1$s to %2$s.', 'wp-mcp-ai' ),
				strtoupper( $this->mime_to_format( $original_mime ) ),
				strtoupper( $format )
			),
		);

		// Note: Inline content payload (base64 encoded image data) is intentionally NOT included
		// in the default response to prevent bloating tool results sent to chat clients and LLMs.
		// If base64 content is needed, it should be retrieved via a separate endpoint or parameter.

		/**
		 * Filter the convert image format result.
		 *
		 * @param array $result_data Result array.
		 * @param array $arguments   Tool arguments.
		 * @param array $context     Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_convert_image_format_result', $result_data, $arguments, $context );
	}

	/**
	 * Convert MIME type to format string.
	 *
	 * @param string $mime_type MIME type.
	 * @return string
	 */
	protected function mime_to_format( $mime_type ) {
		$map = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpeg',
			'image/jpg'  => 'jpeg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);

		return isset( $map[ $mime_type ] ) ? $map[ $mime_type ] : 'unknown';
	}
}

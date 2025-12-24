<?php
/**
 * Tool for resizing images.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Resize images to specific dimensions or scale proportionally.
 */
class WP_MCP_AI_Tool_Resize_Image extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'resize_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Resize Image', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Resize an image to specific dimensions or scale proportionally while maintaining aspect ratio.', 'wp-mcp-ai' );
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
					'width'          => array(
						'type'        => 'integer',
						'description' => __( 'Target width in pixels. Required if height is not specified.', 'wp-mcp-ai' ),
						'minimum'     => 1,
						'maximum'     => 10000,
					),
					'height'         => array(
						'type'        => 'integer',
						'description' => __( 'Target height in pixels. Required if width is not specified.', 'wp-mcp-ai' ),
						'minimum'     => 1,
						'maximum'     => 10000,
					),
					'maintain_ratio' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to maintain aspect ratio. If true and both width and height are specified, the image will fit within those dimensions.', 'wp-mcp-ai' ),
						'default'     => true,
					),
					'crop'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to crop the image to exact dimensions. Only applies when both width and height are specified.', 'wp-mcp-ai' ),
						'default'     => false,
					),
				)
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates new media files.
			'local-only',           // Works locally without external APIs.
			'idempotent',           // Can be called multiple times safely with same result.
			'performance-impact',   // Large images may temporarily affect performance.
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to resize images.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'wp-mcp-ai' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Get dimensions.
		$width  = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : 0;
		$height = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : 0;

		if ( ! $width && ! $height ) {
			return new WP_Error( 'wp_mcp_ai_missing_dimensions', __( 'Either width or height must be specified.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$maintain_ratio = isset( $arguments['maintain_ratio'] ) ? (bool) $arguments['maintain_ratio'] : true;
		$crop           = isset( $arguments['crop'] ) ? (bool) $arguments['crop'] : false;

		// Enrich arguments with metadata from context messages if available.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		$original_size = $image_editor->get_size();

		// Calculate target dimensions.
		if ( $maintain_ratio && ! $crop ) {
			if ( $width && $height ) {
				// Fit within dimensions.
				$result = $image_editor->resize( $width, $height, false );
			} elseif ( $width ) {
				// Scale by width.
				$ratio  = $width / $original_size['width'];
				$height = (int) round( $original_size['height'] * $ratio );
				$result = $image_editor->resize( $width, $height, false );
			} else {
				// Scale by height.
				$ratio  = $height / $original_size['height'];
				$width  = (int) round( $original_size['width'] * $ratio );
				$result = $image_editor->resize( $width, $height, false );
			}
		} elseif ( $crop && $width && $height ) {
			// Crop to exact dimensions.
			$result = $image_editor->resize( $width, $height, true );
		} else {
			// Stretch to dimensions (no aspect ratio).
			if ( ! $width ) {
				$width = $original_size['width'];
			}
			if ( ! $height ) {
				$height = $original_size['height'];
			}
			$result = $image_editor->resize( $width, $height, false );
		}

		if ( is_wp_error( $result ) ) {
			// Clean up temp file if exists.
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return $result;
		}

		// Save as new attachment.
		$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'resized' );

		// Clean up temp file if exists.
		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$new_size = isset( $storage['size'] ) ? $storage['size'] : array(
			'width'  => $width,
			'height' => $height,
		);

		$result = array(
			'attachment_id'   => $storage['attachment_id'],
			'url'             => $storage['url'],
			'file_name'       => $storage['file_name'],
			'mime_type'       => $storage['mime_type'],
			'bytes'           => $storage['bytes'],
			'title'           => $storage['title'],
			'original_width'  => $original_size['width'],
			'original_height' => $original_size['height'],
			'new_width'       => $new_size['width'],
			'new_height'      => $new_size['height'],
			'operation'       => 'resize',
			'text'            => sprintf(
				/* translators: 1: original dimensions, 2: new dimensions */
				__( 'Successfully resized image from %1$s to %2$s.', 'wp-mcp-ai' ),
				$original_size['width'] . 'x' . $original_size['height'],
				$new_size['width'] . 'x' . $new_size['height']
			),
		);

		// Note: Inline content payload (base64 encoded image data) is intentionally NOT included
		// in the default response to prevent bloating tool results sent to chat clients and LLMs.
		// If base64 content is needed, it should be retrieved via a separate endpoint or parameter.

		/**
		 * Filter the resize image result.
		 *
		 * @param array $result    Result array.
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_resize_image_result', $result, $arguments, $context );
	}
}

<?php
/**
 * Tool for cropping images.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Crop images to a specific region or aspect ratio.
 */
class WP_MCP_AI_Tool_Crop_Image extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'crop_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Crop Image', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Crop an image to a specific region defined by coordinates and dimensions, or to a target aspect ratio.', 'wp-mcp-ai' );
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
					'x'            => array(
						'type'        => 'integer',
						'description' => __( 'X coordinate of the top-left corner of the crop region (in pixels). Required for manual crop.', 'wp-mcp-ai' ),
						'minimum'     => 0,
					),
					'y'            => array(
						'type'        => 'integer',
						'description' => __( 'Y coordinate of the top-left corner of the crop region (in pixels). Required for manual crop.', 'wp-mcp-ai' ),
						'minimum'     => 0,
					),
					'width'        => array(
						'type'        => 'integer',
						'description' => __( 'Width of the crop region in pixels. Required for manual crop.', 'wp-mcp-ai' ),
						'minimum'     => 1,
					),
					'height'       => array(
						'type'        => 'integer',
						'description' => __( 'Height of the crop region in pixels. Required for manual crop.', 'wp-mcp-ai' ),
						'minimum'     => 1,
					),
					'aspect_ratio' => array(
						'type'        => 'string',
						'description' => __( 'Target aspect ratio for center crop (e.g., "16:9", "4:3", "1:1"). Alternative to manual crop.', 'wp-mcp-ai' ),
						'enum'        => array( '1:1', '16:9', '4:3', '3:2', '2:3', '9:16', '3:4' ),
					),
					'position'     => array(
						'type'        => 'string',
						'description' => __( 'Crop position when using aspect ratio: center, top, bottom, left, right.', 'wp-mcp-ai' ),
						'enum'        => array( 'center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ),
						'default'     => 'center',
					),
				)
			),
			'required'             => array(),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to crop images.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'wp-mcp-ai' ) );
		}

		// Load source image.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		$original_size = $image_editor->get_size();

		// Determine crop method.
		$aspect_ratio = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '';

		if ( $aspect_ratio ) {
			// Crop to aspect ratio.
			$crop_params = $this->calculate_aspect_ratio_crop( $original_size, $aspect_ratio, $arguments );
			if ( is_wp_error( $crop_params ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $crop_params;
			}
		} else {
			// Manual crop with coordinates.
			$x      = isset( $arguments['x'] ) ? absint( $arguments['x'] ) : null;
			$y      = isset( $arguments['y'] ) ? absint( $arguments['y'] ) : null;
			$width  = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : null;
			$height = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : null;

			if ( null === $x || null === $y || null === $width || null === $height ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return new WP_Error( 'wp_mcp_ai_missing_crop_params', __( 'Either aspect_ratio or all of x, y, width, and height must be specified.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
			}

			$crop_params = compact( 'x', 'y', 'width', 'height' );
		}

		// Perform crop.
		$result = $image_editor->crop( $crop_params['x'], $crop_params['y'], $crop_params['width'], $crop_params['height'] );

		if ( is_wp_error( $result ) ) {
			if ( isset( $image_editor->temp_file ) ) {
				$this->delete_temp_file( $image_editor->temp_file );
			}
			return $result;
		}

		// Save as new attachment.
		$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'cropped' );

		// Clean up temp file if exists.
		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$new_size = isset( $storage['size'] ) ? $storage['size'] : array( 'width' => $crop_params['width'], 'height' => $crop_params['height'] );

		$result_data = array(
			'attachment_id'   => $storage['attachment_id'],
			'url'             => $storage['url'],
			'file_name'       => $storage['file_name'],
			'mime_type'       => $storage['mime_type'],
			'bytes'           => $storage['bytes'],
			'title'           => $storage['title'],
			'original_width'  => $original_size['width'],
			'original_height' => $original_size['height'],
			'crop_x'          => $crop_params['x'],
			'crop_y'          => $crop_params['y'],
			'crop_width'      => $crop_params['width'],
			'crop_height'     => $crop_params['height'],
			'operation'       => 'crop',
			'text'            => sprintf(
				/* translators: 1: crop dimensions, 2: original dimensions */
				__( 'Successfully cropped image to %1$s from %2$s.', 'wp-mcp-ai' ),
				$new_size['width'] . 'x' . $new_size['height'],
				$original_size['width'] . 'x' . $original_size['height']
			),
		);

		// Note: Inline content payload (base64 encoded image data) is intentionally NOT included
		// in the default response to prevent bloating tool results sent to chat clients and LLMs.
		// If base64 content is needed, it should be retrieved via a separate endpoint or parameter.

		/**
		 * Filter the crop image result.
		 *
		 * @param array $result_data Result array.
		 * @param array $arguments   Tool arguments.
		 * @param array $context     Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_crop_image_result', $result_data, $arguments, $context );
	}

	/**
	 * Calculate crop coordinates for aspect ratio crop.
	 *
	 * @param array  $original_size Original image dimensions.
	 * @param string $aspect_ratio  Target aspect ratio.
	 * @param array  $arguments     Tool arguments.
	 * @return array|WP_Error Crop parameters or error.
	 */
	protected function calculate_aspect_ratio_crop( $original_size, $aspect_ratio, $arguments ) {
		// Parse aspect ratio.
		if ( ! preg_match( '/^(\d+):(\d+)$/', $aspect_ratio, $matches ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_aspect_ratio', __( 'Invalid aspect ratio format. Use format like "16:9".', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$ratio_width  = (int) $matches[1];
		$ratio_height = (int) $matches[2];
		$target_ratio = $ratio_width / $ratio_height;

		$img_width  = $original_size['width'];
		$img_height = $original_size['height'];
		$img_ratio  = $img_width / $img_height;

		// Calculate crop dimensions.
		if ( $img_ratio > $target_ratio ) {
			// Image is wider than target ratio - crop width.
			$crop_height = $img_height;
			$crop_width  = (int) round( $crop_height * $target_ratio );
		} else {
			// Image is taller than target ratio - crop height.
			$crop_width  = $img_width;
			$crop_height = (int) round( $crop_width / $target_ratio );
		}

		// Calculate position.
		$position = isset( $arguments['position'] ) ? sanitize_text_field( $arguments['position'] ) : 'center';

		switch ( $position ) {
			case 'top':
				$x = (int) round( ( $img_width - $crop_width ) / 2 );
				$y = 0;
				break;
			case 'bottom':
				$x = (int) round( ( $img_width - $crop_width ) / 2 );
				$y = $img_height - $crop_height;
				break;
			case 'left':
				$x = 0;
				$y = (int) round( ( $img_height - $crop_height ) / 2 );
				break;
			case 'right':
				$x = $img_width - $crop_width;
				$y = (int) round( ( $img_height - $crop_height ) / 2 );
				break;
			case 'top-left':
				$x = 0;
				$y = 0;
				break;
			case 'top-right':
				$x = $img_width - $crop_width;
				$y = 0;
				break;
			case 'bottom-left':
				$x = 0;
				$y = $img_height - $crop_height;
				break;
			case 'bottom-right':
				$x = $img_width - $crop_width;
				$y = $img_height - $crop_height;
				break;
			case 'center':
			default:
				$x = (int) round( ( $img_width - $crop_width ) / 2 );
				$y = (int) round( ( $img_height - $crop_height ) / 2 );
				break;
		}

		return array(
			'x'      => max( 0, $x ),
			'y'      => max( 0, $y ),
			'width'  => $crop_width,
			'height' => $crop_height,
		);
	}
}

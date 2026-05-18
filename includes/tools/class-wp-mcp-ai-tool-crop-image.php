<?php
/**
 * Tool for cropping images.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';
require_once WP_MCP_AI_PATH . 'includes/markup/interface-wp-mcp-ai-markup-aware-tool.php';

/**
 * Crop images to a specific region or aspect ratio.
 *
 * Implements {@see WP_MCP_AI_Markup_Aware_Tool_Interface} so the LLM
 * can defer the crop region to the user. When `request_user_crop` is
 * set and no manual `x/y/width/height` (or `aspect_ratio`) is
 * provided, the tool short-circuits the agentic loop with a
 * `crop`-mode markup elicitation. The user's painted rectangle is
 * rasterized to a `crop_rect` artifact, denormalized to pixel
 * coordinates if necessary, and fed back into `execute()`.
 */
class WP_MCP_AI_Tool_Crop_Image extends WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Markup_Aware_Tool_Interface {

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
		return __( 'Crop Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Crop an image to a specific region defined by coordinates and dimensions, or to a target aspect ratio.', 'mcp-ai-wpoos' );
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
					'x'                 => array(
						'type'        => 'integer',
						'description' => __( 'X coordinate of the top-left corner of the crop region (in pixels). Required for manual crop.', 'mcp-ai-wpoos' ),
						'minimum'     => 0,
					),
					'y'                 => array(
						'type'        => 'integer',
						'description' => __( 'Y coordinate of the top-left corner of the crop region (in pixels). Required for manual crop.', 'mcp-ai-wpoos' ),
						'minimum'     => 0,
					),
					'width'             => array(
						'type'        => 'integer',
						'description' => __( 'Width of the crop region in pixels. Required for manual crop.', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
					),
					'height'            => array(
						'type'        => 'integer',
						'description' => __( 'Height of the crop region in pixels. Required for manual crop.', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
					),
					'aspect_ratio'      => array(
						'type'        => 'string',
						'description' => __( 'Target aspect ratio for center crop (e.g., "16:9", "4:3", "1:1"). Alternative to manual crop.', 'mcp-ai-wpoos' ),
						'enum'        => array( '1:1', '16:9', '4:3', '3:2', '2:3', '9:16', '3:4' ),
					),
					'position'          => array(
						'type'        => 'string',
						'description' => __( 'Crop position when using aspect ratio: center, top, bottom, left, right.', 'mcp-ai-wpoos' ),
						'enum'        => array( 'center', 'top', 'bottom', 'left', 'right', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ),
						'default'     => 'center',
					),
					'request_user_crop' => array(
						'type'        => 'boolean',
						'description' => __( 'When true and no manual x/y/width/height (and no aspect_ratio) is supplied, pause execution and ask the user to draw the crop rectangle on the image in chat. The painted rectangle is converted to pixel coordinates automatically.', 'mcp-ai-wpoos' ),
						'default'     => false,
					),
				),
				$this->get_output_format_parameter_schema()
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'media_processing',

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'photographer', 'graphic_designer' ),

			'risk_level'            => 'standard',

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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to crop images.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'mcp-ai-wpoos' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Enrich arguments with metadata from context messages if available.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

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
				return new WP_Error( 'wp_mcp_ai_missing_crop_params', __( 'Either aspect_ratio or all of x, y, width, and height must be specified.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
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

		// Check if SVG output is requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'default';

		if ( 'svg' === $output_format ) {
			// Convert the cropped image to SVG.
			$storage = $this->convert_to_svg( $image_editor, $arguments, $user_id );
		} else {
			// Save as new raster attachment.
			$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'cropped' );
		}

		// Clean up temp file if exists.
		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$new_size = isset( $storage['size'] ) ? $storage['size'] : array(
			'width'  => $crop_params['width'],
			'height' => $crop_params['height'],
		);

		$message = sprintf(
			/* translators: 1: crop dimensions, 2: original dimensions, 3: output format */
			__( 'Successfully cropped image to %1$s from %2$s%3$s.', 'mcp-ai-wpoos' ),
			$new_size['width'] . 'x' . $new_size['height'],
			$original_size['width'] . 'x' . $original_size['height'],
			'svg' === $output_format ? ' and converted to SVG' : ''
		);

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
			'output_format'   => $output_format,
			'text'            => $message,
			'message'         => $message,
		);

		// Add vectorization metadata if SVG output was used.
		if ( 'svg' === $output_format && isset( $storage['vectorized'] ) ) {
			$result_data['vectorized']  = true;
			$result_data['svg_size']    = isset( $storage['svg_size'] ) ? $storage['svg_size'] : $storage['bytes'];
			$result_data['source_size'] = isset( $storage['source_size'] ) ? $storage['source_size'] : 0;
			$result_data['duration_ms'] = isset( $storage['duration_ms'] ) ? $storage['duration_ms'] : 0;
		}

		// Add image_url structure for agentic workflow and chat client display.
		if ( ! empty( $storage['url'] ) ) {
			$result_data['image_url'] = array(
				'url' => $storage['url'],
			);
		}

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
			return new WP_Error( 'wp_mcp_ai_invalid_aspect_ratio', __( 'Invalid aspect ratio format. Use format like "16:9".', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
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

	/**
	 * Decide whether to elicit a crop rectangle from the user.
	 *
	 * Returns a markup request when:
	 *  - `request_user_crop` is true; and
	 *  - the caller did not already supply pixel `x/y/width/height`; and
	 *  - the caller did not supply an `aspect_ratio` (which has its own
	 *    deterministic crop math); and
	 *  - the source image can be resolved to a WordPress attachment.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return WP_MCP_AI_Markup_Request|null
	 */
	public function needs_markup( array $arguments, array $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Markup_Request' ) ) {
			return null;
		}
		if ( empty( $arguments['request_user_crop'] ) ) {
			return null;
		}
		// Caller already specified the crop.
		if ( ! empty( $arguments['aspect_ratio'] ) ) {
			return null;
		}
		if ( isset( $arguments['x'], $arguments['y'], $arguments['width'], $arguments['height'] ) ) {
			return null;
		}

		// Enrich and resolve the source attachment without side effects.
		$enriched      = $this->enrich_arguments_from_messages( $arguments, $context );
		$attachment_id = isset( $enriched['attachment_id'] ) ? absint( $enriched['attachment_id'] ) : 0;
		if ( $attachment_id <= 0 && ! empty( $enriched['url'] ) && method_exists( $this, 'resolve_attachment_id_from_url' ) ) {
			$resolved = $this->resolve_attachment_id_from_url( (string) $enriched['url'] );
			if ( is_int( $resolved ) && $resolved > 0 ) {
				$attachment_id = $resolved;
			}
		}
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		try {
			return new WP_MCP_AI_Markup_Request(
				array(
					'tool_slug'      => $this->get_slug(),
					'target_type'    => 'image',
					'mode'           => 'crop',
					'target'         => array(
						'attachment_id' => $attachment_id,
						'width'         => $w,
						'height'        => $h,
					),
					'instructions'   => __( 'Drag a rectangle around the area to keep. Everything outside the rectangle will be cropped away.', 'mcp-ai-wpoos' ),
					'tool_arguments' => $arguments,
					'tool_context'   => $context,
					'assistant_id'   => isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0,
				)
			);
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Resume execution with a user-painted crop rectangle.
	 *
	 * The rasterizer returns `crop_rect` as `{x, y, width, height,
	 * normalized}`. When `normalized` is true the coordinates are in
	 * the [0,1] space relative to the source image dimensions and we
	 * convert them to pixels using the request target. The elicitation
	 * flag is then cleared so the recursion does not re-trigger.
	 *
	 * @param array                   $arguments Original tool arguments.
	 * @param WP_MCP_AI_Markup_Result $result    Validated markup result.
	 * @param array                   $context   Execution context.
	 * @return mixed Tool result.
	 */
	public function consume_markup( array $arguments, WP_MCP_AI_Markup_Result $result, array $context ) {
		$rect = $result->get_artifact( 'crop_rect', array() );
		if ( is_array( $rect ) && isset( $rect['width'], $rect['height'] ) && $rect['width'] > 0 && $rect['height'] > 0 ) {
			$normalized = ! empty( $rect['normalized'] );
			if ( $normalized ) {
				$target = $result->get_request()->get_target();
				$img_w  = isset( $target['width'] ) ? (int) $target['width'] : 0;
				$img_h  = isset( $target['height'] ) ? (int) $target['height'] : 0;
				if ( $img_w > 0 && $img_h > 0 ) {
					$arguments['x']      = (int) round( (float) $rect['x'] * $img_w );
					$arguments['y']      = (int) round( (float) $rect['y'] * $img_h );
					$arguments['width']  = max( 1, (int) round( (float) $rect['width'] * $img_w ) );
					$arguments['height'] = max( 1, (int) round( (float) $rect['height'] * $img_h ) );
				}
			} else {
				$arguments['x']      = (int) round( (float) $rect['x'] );
				$arguments['y']      = (int) round( (float) $rect['y'] );
				$arguments['width']  = max( 1, (int) round( (float) $rect['width'] ) );
				$arguments['height'] = max( 1, (int) round( (float) $rect['height'] ) );
			}
		}
		// Prevent infinite re-elicitation on the recursive call.
		$arguments['request_user_crop'] = false;

		return $this->execute( $arguments, $context );
	}
}

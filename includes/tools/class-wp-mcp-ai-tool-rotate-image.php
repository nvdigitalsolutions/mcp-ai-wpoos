<?php
/**
 * Tool for rotating and flipping images.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Rotate and flip images.
 */
class WP_MCP_AI_Tool_Rotate_Image extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'rotate_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Rotate Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Rotate an image by degrees or flip it horizontally/vertically.', 'mcp-ai-wpoos' );
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
					'angle'           => array(
						'type'        => 'number',
						'description' => __( 'Rotation angle in degrees (clockwise). Common values: 90, 180, 270.', 'mcp-ai-wpoos' ),
						'minimum'     => -360,
						'maximum'     => 360,
					),
					'flip_horizontal' => array(
						'type'        => 'boolean',
						'description' => __( 'Flip the image horizontally (mirror).', 'mcp-ai-wpoos' ),
						'default'     => false,
					),
					'flip_vertical'   => array(
						'type'        => 'boolean',
						'description' => __( 'Flip the image vertically.', 'mcp-ai-wpoos' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to rotate images.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'mcp-ai-wpoos' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$angle           = isset( $arguments['angle'] ) ? floatval( $arguments['angle'] ) : 0.0;
		$flip_horizontal = isset( $arguments['flip_horizontal'] ) ? (bool) $arguments['flip_horizontal'] : false;
		$flip_vertical   = isset( $arguments['flip_vertical'] ) ? (bool) $arguments['flip_vertical'] : false;

		// Validate angle is a valid number (not NaN or Infinity).
		if ( is_nan( $angle ) || is_infinite( $angle ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_angle', __( 'The angle parameter must be a valid number.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		if ( 0.0 === $angle && ! $flip_horizontal && ! $flip_vertical ) {
			return new WP_Error( 'wp_mcp_ai_no_operation', __( 'At least one of angle, flip_horizontal, or flip_vertical must be specified.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}

		// Enrich arguments with metadata from context messages if available.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		$operations = array();

		// Apply rotation.
		// WordPress rotates counter-clockwise for positive angles, so negate for clockwise rotation.
		if ( 0.0 !== $angle ) {
			$result = $image_editor->rotate( -$angle );
			if ( is_wp_error( $result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $result;
			}
			/* translators: %s: rotation angle in degrees */
			$operations[] = sprintf( __( 'rotated %s degrees', 'mcp-ai-wpoos' ), $angle );
		}

		// Apply horizontal flip.
		if ( $flip_horizontal ) {
			$result = $image_editor->flip( true, false );
			if ( is_wp_error( $result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $result;
			}
			$operations[] = __( 'flipped horizontally', 'mcp-ai-wpoos' );
		}

		// Apply vertical flip.
		if ( $flip_vertical ) {
			$result = $image_editor->flip( false, true );
			if ( is_wp_error( $result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $result;
			}
			$operations[] = __( 'flipped vertically', 'mcp-ai-wpoos' );
		}

		// Check if SVG output is requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'default';

		if ( 'svg' === $output_format ) {
			// Convert the rotated image to SVG.
			$storage = $this->convert_to_svg( $image_editor, $arguments, $user_id );
		} else {
			// Save as new raster attachment.
			$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'rotated' );
		}

		// Clean up temp file if exists.
		if ( isset( $image_editor->temp_file ) ) {
			$this->delete_temp_file( $image_editor->temp_file );
		}

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		$message = sprintf(
			/* translators: 1: list of operations performed, 2: output format */
			__( 'Successfully transformed image: %1$s%2$s.', 'mcp-ai-wpoos' ),
			implode( ', ', $operations ),
			'svg' === $output_format ? ' and converted to SVG' : ''
		);

		$result_data = array(
			'attachment_id'   => $storage['attachment_id'],
			'url'             => $storage['url'],
			'file_name'       => $storage['file_name'],
			'mime_type'       => $storage['mime_type'],
			'bytes'           => $storage['bytes'],
			'title'           => $storage['title'],
			'angle'           => $angle,
			'flip_horizontal' => $flip_horizontal,
			'flip_vertical'   => $flip_vertical,
			'operation'       => 'rotate',
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
		 * Filter the rotate image result.
		 *
		 * @param array $result_data Result array.
		 * @param array $arguments   Tool arguments.
		 * @param array $context     Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_rotate_image_result', $result_data, $arguments, $context );
	}
}

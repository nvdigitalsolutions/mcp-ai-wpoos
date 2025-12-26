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
		return __( 'Rotate Image', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Rotate an image by degrees or flip it horizontally/vertically.', 'wp-mcp-ai' );
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
						'description' => __( 'Rotation angle in degrees (clockwise). Common values: 90, 180, 270.', 'wp-mcp-ai' ),
						'minimum'     => -360,
						'maximum'     => 360,
					),
					'flip_horizontal' => array(
						'type'        => 'boolean',
						'description' => __( 'Flip the image horizontally (mirror).', 'wp-mcp-ai' ),
						'default'     => false,
					),
					'flip_vertical'   => array(
						'type'        => 'boolean',
						'description' => __( 'Flip the image vertically.', 'wp-mcp-ai' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to rotate images.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'wp-mcp-ai' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$angle           = isset( $arguments['angle'] ) ? floatval( $arguments['angle'] ) : 0;
		$flip_horizontal = isset( $arguments['flip_horizontal'] ) ? (bool) $arguments['flip_horizontal'] : false;
		$flip_vertical   = isset( $arguments['flip_vertical'] ) ? (bool) $arguments['flip_vertical'] : false;

		if ( 0 === $angle && ! $flip_horizontal && ! $flip_vertical ) {
			return new WP_Error( 'wp_mcp_ai_no_operation', __( 'At least one of angle, flip_horizontal, or flip_vertical must be specified.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
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
		if ( 0 !== $angle ) {
			$result = $image_editor->rotate( $angle );
			if ( is_wp_error( $result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $result;
			}
			/* translators: %s: rotation angle in degrees */
			$operations[] = sprintf( __( 'rotated %s degrees', 'wp-mcp-ai' ), $angle );
		}

		// Apply horizontal flip.
		if ( $flip_horizontal ) {
			$result = $image_editor->flip( false, true );
			if ( is_wp_error( $result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $result;
			}
			$operations[] = __( 'flipped horizontally', 'wp-mcp-ai' );
		}

		// Apply vertical flip.
		if ( $flip_vertical ) {
			$result = $image_editor->flip( true, false );
			if ( is_wp_error( $result ) ) {
				if ( isset( $image_editor->temp_file ) ) {
					$this->delete_temp_file( $image_editor->temp_file );
				}
				return $result;
			}
			$operations[] = __( 'flipped vertically', 'wp-mcp-ai' );
		}

		// Save as new attachment.
		$storage = $this->save_as_attachment( $image_editor, $arguments, $user_id, 'rotated' );

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
			'angle'           => $angle,
			'flip_horizontal' => $flip_horizontal,
			'flip_vertical'   => $flip_vertical,
			'operation'       => 'rotate',
			'text'            => sprintf(
				/* translators: %s: list of operations performed */
				__( 'Successfully transformed image: %s.', 'wp-mcp-ai' ),
				implode( ', ', $operations )
			),
		);

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

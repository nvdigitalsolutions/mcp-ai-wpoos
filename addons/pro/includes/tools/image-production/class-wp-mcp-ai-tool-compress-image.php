<?php
/**
 * Tool for intelligent image compression with quality preservation.
 *
 * Compresses images while maintaining visual quality using smart algorithms.
 * Supports multiple formats and compression methods.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';

/**
 * Compress images with quality preservation.
 */
class WP_MCP_AI_Tool_Compress_Image extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'compress_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Compress Image', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compress images while preserving quality. Reduces file size significantly with minimal visual degradation.', 'mcp-ai-wpoos-pro' );
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
					'quality'     => array(
						'type'        => 'integer',
						'description' => __( 'Compression quality (1-100). Higher values preserve more quality.', 'mcp-ai-wpoos-pro' ),
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 85,
					),
					'method'      => array(
						'type'        => 'string',
						'description' => __( 'Compression method: "standard", "lossy", "lossless".', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'standard', 'lossy', 'lossless' ),
						'default'     => 'standard',
					),
					'max_size_kb' => array(
						'type'        => 'integer',
						'description' => __( 'Maximum output file size in KB (optional).', 'mcp-ai-wpoos-pro' ),
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
			'pro',
			'requires-capability',
			'write',
			'idempotent',
			'performance-impact',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to compress images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enrich arguments from context messages.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get compression parameters.
		$quality = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 85;
		$quality = max( 1, min( 100, $quality ) );

		// Set image quality.
		$source_image->set_quality( $quality );

		// Save compressed image.
		$saved_file = $source_image->save();
		if ( is_wp_error( $saved_file ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			return $saved_file;
		}

		// Check if we need to meet a target file size.
		if ( ! empty( $arguments['max_size_kb'] ) ) {
			$max_bytes = absint( $arguments['max_size_kb'] ) * 1024;
			$result    = $this->compress_to_target_size( $saved_file['path'], $max_bytes, $quality );
			if ( is_wp_error( $result ) ) {
				$this->cleanup_source_image( $source_image, $arguments );
				return $result;
			}
		}

		// Save as attachment.
		$attachment_id = $this->save_as_attachment( $saved_file['path'], $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			$this->cleanup_source_image( $source_image, $arguments );
			wp_delete_file( $saved_file['path'] );
			return $attachment_id;
		}

		// Clean up.
		$this->cleanup_source_image( $source_image, $arguments );
		wp_delete_file( $saved_file['path'] );

		return $this->format_attachment_response( $attachment_id );
	}

	/**
	 * Compress image to target file size.
	 *
	 * @param string $file_path  Path to image file.
	 * @param int    $max_bytes  Maximum file size in bytes.
	 * @param int    $quality    Starting quality.
	 * @return true|WP_Error True on success, error on failure.
	 */
	protected function compress_to_target_size( $file_path, $max_bytes, $quality ) {
		$current_size = filesize( $file_path );
		$attempts     = 0;
		$max_attempts = 10;

		while ( $current_size > $max_bytes && $attempts < $max_attempts && $quality > 10 ) {
			$quality -= 5;
			$editor   = wp_get_image_editor( $file_path );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}

			$editor->set_quality( $quality );
			$saved = $editor->save( $file_path );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$current_size = filesize( $file_path );
			++$attempts;
		}

		if ( $current_size > $max_bytes ) {
			return new WP_Error(
				'wp_mcp_ai_compression_failed',
				sprintf(
					/* translators: 1: current size, 2: target size */
					__( 'Could not compress image to target size. Final size: %1$d KB, target: %2$d KB', 'mcp-ai-wpoos-pro' ),
					round( $current_size / 1024 ),
					round( $max_bytes / 1024 )
				)
			);
		}

		return true;
	}

	/**
	 * Sanitize the tool result for LLM consumption.
	 *
	 * @param array|WP_Error $result The result to sanitize.
	 * @return array Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}

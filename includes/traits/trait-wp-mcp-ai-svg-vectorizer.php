<?php
/**
 * Trait for SVG vectorization capabilities.
 *
 * Provides reusable methods for converting raster images to SVG vector format
 * using @neplex/vectorizer. Can be used by any image generation or manipulation tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait providing SVG vectorization functionality for image tools.
 *
 * This trait requires:
 * - WP_MCP_AI_NodeJS_Subprocess trait
 * - WordPress file functions (wp_tempnam, wp_delete_file, etc.)
 */
trait WP_MCP_AI_SVG_Vectorizer {

	/**
	 * Convert a raster image to SVG format using vectorization.
	 *
	 * @param array $storage    Stored raster image data with 'file', 'bytes', etc.
	 * @param array $arguments  Tool arguments for vectorization options.
	 * @return array|WP_Error SVG storage data or error.
	 */
	protected function convert_to_svg( array $storage, array $arguments ) {
		// Check if Node.js is available.
		if ( ! $this->is_nodejs_available() ) {
			return new WP_Error(
				'wp_mcp_ai_nodejs_required',
				__( 'Node.js is required for SVG vectorization but was not found on the system.', 'mcp-ai-wpoos' )
			);
		}

		$file_path = isset( $storage['file'] ) ? $storage['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Generated image file not found for SVG conversion.', 'mcp-ai-wpoos' )
			);
		}

		// Prepare SVG output file.
		$temp_output = wp_tempnam( 'svg-convert-' );
		if ( ! $temp_output ) {
			return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to create temporary SVG output file.', 'mcp-ai-wpoos' ) );
		}

		// Add .svg extension.
		$temp_output_svg = $temp_output . '.svg';
		rename( $temp_output, $temp_output_svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		$temp_output = $temp_output_svg;

		// Prepare vectorization options with sensible defaults.
		$vectorization_options = array(
			'colorMode'      => 'color',
			'colorPrecision' => isset( $arguments['color_precision'] ) ? absint( $arguments['color_precision'] ) : 6,
			'filterSpeckle'  => isset( $arguments['filter_speckle'] ) ? absint( $arguments['filter_speckle'] ) : 4,
			'mode'           => isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'spline',
			'hierarchical'   => isset( $arguments['hierarchical'] ) ? sanitize_text_field( $arguments['hierarchical'] ) : 'stacked',
		);

		// Execute vectorization script.
		$script_path = WP_MCP_AI_PATH . 'bin/vectorize-image.js';
		$script_args = array(
			$file_path,
			$temp_output,
			wp_json_encode( $vectorization_options ),
		);

		$vectorize_result = $this->execute_nodejs_script(
			$script_path,
			$script_args,
			array(
				'timeout'    => 60,
				'parse_json' => true,
			)
		);

		if ( is_wp_error( $vectorize_result ) ) {
			wp_delete_file( $temp_output );
			return $vectorize_result;
		}

		if ( ! isset( $vectorize_result['success'] ) || ! $vectorize_result['success'] ) {
			wp_delete_file( $temp_output );
			return new WP_Error(
				'wp_mcp_ai_vectorization_failed',
				isset( $vectorize_result['error'] ) ? $vectorize_result['error'] : __( 'SVG vectorization failed.', 'mcp-ai-wpoos' )
			);
		}

		// Read SVG file.
		$svg_data = file_get_contents( $temp_output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
		if ( false === $svg_data || '' === $svg_data ) {
			wp_delete_file( $temp_output );
			return new WP_Error( 'wp_mcp_ai_read_error', __( 'Failed to read vectorized SVG file.', 'mcp-ai-wpoos' ) );
		}

		// Cleanup temporary output file.
		wp_delete_file( $temp_output );

		// Save as WordPress attachment.
		$svg_storage = $this->save_svg_as_attachment( $svg_data, $arguments );
		if ( is_wp_error( $svg_storage ) ) {
			return $svg_storage;
		}

		// Add vectorization metadata.
		$svg_storage['vectorized']  = true;
		$svg_storage['svg_size']    = isset( $vectorize_result['output_size'] ) ? $vectorize_result['output_size'] : $svg_storage['bytes'];
		$svg_storage['source_size'] = isset( $vectorize_result['input_size'] ) ? $vectorize_result['input_size'] : $storage['bytes'];
		$svg_storage['duration_ms'] = isset( $vectorize_result['duration_ms'] ) ? $vectorize_result['duration_ms'] : 0;

		return $svg_storage;
	}

	/**
	 * Save SVG data as WordPress attachment.
	 *
	 * @param string $svg_data  SVG file content.
	 * @param array  $arguments Tool arguments for naming.
	 * @return array|WP_Error Attachment data or error.
	 */
	protected function save_svg_as_attachment( $svg_data, array $arguments ) {
		// Generate file name.
		$base_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : 'image';
		if ( empty( $base_name ) ) {
			$base_name = 'image';
		}

		// Remove extension if present.
		$base_name = preg_replace( '/\.(png|jpg|jpeg|gif|webp)$/i', '', $base_name );
		$file_name = $base_name . '-svg-' . gmdate( 'Ymd-His' ) . '.svg';

		// Upload SVG file.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $svg_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to save SVG file.', 'mcp-ai-wpoos' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to write SVG file to disk.', 'mcp-ai-wpoos' ) );
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => sanitize_text_field( __( 'SVG Image', 'mcp-ai-wpoos' ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register SVG as an attachment.', 'mcp-ai-wpoos' ), array( 'error' => $attachment_id ) );
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get attachment URL.
		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( false === $attachment_url ) {
			// Use a fallback URL if possible.
			$upload_dir     = wp_upload_dir();
			$attachment_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $attachment_url,
			'download_url'  => $attachment_url,
			'mime_type'     => 'image/svg+xml',
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => get_the_title( $attachment_id ),
		);
	}

	/**
	 * Get output format parameter schema for tool parameters.
	 *
	 * @return array Parameter schema for output_format.
	 */
	protected function get_output_format_parameter_schema() {
		return array(
			'output_format' => array(
				'type'        => 'string',
				'description' => __( 'Output format for the generated/edited image. Use "svg" to vectorize the raster output. Default is raster format.', 'mcp-ai-wpoos' ),
				'enum'        => array( 'default', 'svg' ),
				'default'     => 'default',
			),
		);
	}

	/**
	 * Add vectorization metadata to result if SVG output was used.
	 *
	 * @param array  $result_data Result data array.
	 * @param array  $storage     Storage data from save_svg_as_attachment.
	 * @param string $output_format Output format selected.
	 * @return array Updated result data.
	 */
	protected function add_vectorization_metadata( array $result_data, array $storage, $output_format ) {
		if ( 'svg' === $output_format && isset( $storage['vectorized'] ) ) {
			$result_data['vectorized']  = true;
			$result_data['svg_size']    = isset( $storage['svg_size'] ) ? $storage['svg_size'] : $storage['bytes'];
			$result_data['source_size'] = isset( $storage['source_size'] ) ? $storage['source_size'] : 0;
			$result_data['duration_ms'] = isset( $storage['duration_ms'] ) ? $storage['duration_ms'] : 0;
		}

		return $result_data;
	}
}

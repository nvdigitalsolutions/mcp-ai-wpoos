<?php
/**
 * Trait for SVG vectorization capabilities.
 *
 * Provides shared SVG conversion functionality that can be used by any tool
 * that generates raster images (PNG/JPG) and wants to offer SVG output.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for adding SVG vectorization to image generation tools.
 */
trait WP_MCP_AI_Tool_SVG_Vectorizer {

	/**
	 * Convert raster image to SVG using Node.js vectorizer.
	 *
	 * Uses @neplex/vectorizer npm package for AI-powered raster-to-vector conversion.
	 *
	 * @param string $base64_data Base64 encoded image data.
	 * @param array  $options     Optional vectorizer options.
	 * @return array|WP_Error Array with SVG data and metadata, or error.
	 */
	protected function vectorize_to_svg( $base64_data, $options = array() ) {
		// Decode base64 image data.
		$image_data = base64_decode( $base64_data );
		if ( false === $image_data ) {
			return new WP_Error(
				'invalid_base64',
				__( 'Failed to decode base64 image data for SVG conversion.', 'wp-mcp-ai' )
			);
		}

		// Check if Node.js is available.
		$node_path = $this->find_node_binary();
		if ( is_wp_error( $node_path ) ) {
			return $node_path;
		}

		// Check if vectorizer script exists.
		$script_path = WP_MCP_AI_PATH . 'bin/vectorize.js';
		if ( ! file_exists( $script_path ) ) {
			return new WP_Error(
				'vectorizer_script_missing',
				__( 'SVG vectorizer script not found. Please run npm install.', 'wp-mcp-ai' )
			);
		}

		// Check if @neplex/vectorizer is installed.
		$node_modules_check = WP_MCP_AI_PATH . 'node_modules/@neplex/vectorizer';
		if ( ! is_dir( $node_modules_check ) ) {
			return new WP_Error(
				'vectorizer_not_installed',
				__( 'SVG vectorizer package not installed. Please run: npm install @neplex/vectorizer', 'wp-mcp-ai' )
			);
		}

		// Create temporary files for input and output.
		$temp_dir    = sys_get_temp_dir();
		$temp_input  = tempnam( $temp_dir, 'svg_vec_' ) . '.png';
		$temp_output = tempnam( $temp_dir, 'svg_out_' ) . '.svg';

		// Write image data to temp file.
		if ( false === file_put_contents( $temp_input, $image_data ) ) {
			return new WP_Error(
				'temp_file_write_failed',
				__( 'Failed to write temporary image file for vectorization.', 'wp-mcp-ai' )
			);
		}

		// Merge with default options.
		$vectorizer_options = array_merge(
			$this->get_default_vectorizer_options(),
			$options
		);

		// Build command to run Node.js vectorizer.
		$command = sprintf(
			'%s %s %s %s %s 2>&1',
			escapeshellcmd( $node_path ),
			escapeshellarg( $script_path ),
			escapeshellarg( $temp_input ),
			escapeshellarg( $temp_output ),
			escapeshellarg( wp_json_encode( $vectorizer_options ) )
		);

		// Execute vectorization.
		$output      = array();
		$return_code = 0;
		exec( $command, $output, $return_code );

		// Clean up input file.
		if ( file_exists( $temp_input ) ) {
			unlink( $temp_input );
		}

		// Parse output JSON.
		$output_json = implode( "\n", $output );
		$result      = json_decode( $output_json, true );

		if ( 0 !== $return_code || ! $result || empty( $result['success'] ) ) {
			// Clean up output file if exists.
			if ( file_exists( $temp_output ) ) {
				unlink( $temp_output );
			}

			$error_msg = isset( $result['error'] ) ? $result['error'] : __( 'Vectorization failed with unknown error.', 'wp-mcp-ai' );
			return new WP_Error(
				'vectorization_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'SVG vectorization failed: %s', 'wp-mcp-ai' ),
					$error_msg
				)
			);
		}

		// Read SVG content.
		if ( ! file_exists( $temp_output ) ) {
			return new WP_Error(
				'svg_output_missing',
				__( 'SVG output file was not created.', 'wp-mcp-ai' )
			);
		}

		$svg_content = file_get_contents( $temp_output );
		unlink( $temp_output );

		if ( false === $svg_content || empty( $svg_content ) ) {
			return new WP_Error(
				'svg_read_failed',
				__( 'Failed to read SVG output file.', 'wp-mcp-ai' )
			);
		}

		return array(
			'svg_data' => $svg_content,
			'svg_size' => strlen( $svg_content ),
			'message'  => __( 'Successfully converted to SVG format.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Get default vectorizer options.
	 *
	 * @return array Default options.
	 */
	protected function get_default_vectorizer_options() {
		return array(
			'colorMode'        => 'color',
			'colorPrecision'   => 6,
			'filterSpeckle'    => 4,
			'cornerThreshold'  => 60,
			'lengthThreshold'  => 4.0,
			'maxIterations'    => 10,
			'spliceThreshold'  => 45,
			'pathPrecision'    => 8,
			'mode'             => 'stacked',
		);
	}

	/**
	 * Save SVG as media attachment.
	 *
	 * @param string $svg_data    SVG content.
	 * @param string $file_name   Optional file name.
	 * @param string $title_prefix Title prefix for attachment.
	 * @param int    $user_id     User ID.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function save_svg_attachment( $svg_data, $file_name, $title_prefix, $user_id ) {
		// Generate file name.
		if ( empty( $file_name ) ) {
			$file_name = 'svg-vector-' . time();
		}

		$file_name = sanitize_file_name( $file_name );
		if ( ! preg_match( '/\.svg$/i', $file_name ) ) {
			$file_name .= '.svg';
		}

		// Upload to WordPress.
		$upload = wp_upload_bits( $file_name, null, $svg_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'svg_upload_failed',
				$upload['error']
			);
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => $title_prefix . ' (SVG)',
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// SVG files don't need metadata generation like raster images.
		// Just update basic metadata.
		$metadata = array(
			'file'     => $upload['file'],
			'filesize' => filesize( $upload['file'] ),
		);
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/**
	 * Find Node.js binary path.
	 *
	 * @return string|WP_Error Node.js path or error.
	 */
	protected function find_node_binary() {
		// Common Node.js binary locations.
		$possible_paths = array(
			'/usr/bin/node',
			'/usr/local/bin/node',
			'/opt/homebrew/bin/node',
		);

		// Check NODE_PATH environment variable.
		$env_node = getenv( 'NODE_PATH' );
		if ( $env_node && file_exists( $env_node ) && is_executable( $env_node ) ) {
			return $env_node;
		}

		// Try 'which node' command.
		$which_output = shell_exec( 'which node 2>/dev/null' );
		if ( $which_output ) {
			$which_path = trim( $which_output );
			if ( file_exists( $which_path ) && is_executable( $which_path ) ) {
				return $which_path;
			}
		}

		// Check common paths.
		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}

		return new WP_Error(
			'node_not_found',
			__( 'Node.js not found. Please install Node.js to enable SVG conversion. SVG vectorization requires Node.js v14 or higher.', 'wp-mcp-ai' )
		);
	}
}

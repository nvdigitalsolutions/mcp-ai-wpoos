<?php
/**
 * Sharp Image Processing Trait — dual-path image processing plumbing.
 *
 * Shared plumbing for the image-production tools that process existing
 * images through Sharp: availability probing for the bundled local Sharp
 * runtime, the sharp-process.js subprocess runner, the Media Worker
 * sidecar route fallback, and media-library upload of the processed file.
 *
 * Routing is additive per the media-worker architecture rules: the local
 * Sharp subprocess is the default path, the worker route is the sidecar
 * fallback (or the preference when a tool's use_remote argument is set),
 * and when neither backend exists the caller surfaces an honest
 * wp_mcp_ai_sharp_unavailable error instead of claiming success.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Traits
 * @since 1.1.95
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-media-worker-client.php';

/**
 * Dual-path Sharp image processing for Pro image tools.
 *
 * Both backends return the same result shape
 * (output_path, original_size, optimized_size, reduction_percent,
 * dimensions) so consuming tools upload and respond identically regardless
 * of which engine actually ran. The result arrays use an 'error' key (never
 * a fake success envelope) when processing failed.
 *
 * WP_MCP_AI_Tool_Optimize_Image_Sharp predates this trait and is
 * deliberately left unrefactored; its private helpers are the origin of the
 * logic below. The Wave 2 AI-edit tools (colorize_image,
 * apply_artistic_style) reuse the sidecar-decode and media-upload plumbing
 * here together with the WP_MCP_AI_Provider_Image_Edit trait.
 *
 * @since 1.1.95
 */
trait WP_MCP_AI_Sharp_Image_Processing {

	use WP_MCP_AI_NodeJS_Subprocess;
	use WP_MCP_AI_Media_Worker_Client;

	/**
	 * Maximum output dimension (px) accepted for upscale operations.
	 *
	 * Mirrored with addons/media-worker/src/routes/image.js and
	 * addons/pro/bin/sharp-process.js.
	 */
	const MAX_UPSCALE_DIMENSION = 8192;

	/**
	 * Check whether the bundled local Sharp runtime is fully installed.
	 *
	 * Requires the vendor (or development node_modules) Sharp package with
	 * its runtime dependencies and platform binaries, plus a Node.js
	 * executable reachable through the Process Service.
	 *
	 * @return bool True when local Sharp processing is possible.
	 */
	protected function is_local_sharp_available() {
		// Check if package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/lib/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/sharp/lib/index.js';

		$sharp_exists = file_exists( $vendor_path ) || file_exists( $node_modules_path );

		if ( $sharp_exists ) {
			// Check if required dependencies exist (detect-libc, color, semver).
			$base_dir = file_exists( $vendor_path ) ? WP_MCP_AI_PRO_PATH . 'assets/vendor/sharp/' : WP_MCP_AI_PRO_PATH . 'node_modules/sharp/';

			$required_deps = array( 'detect-libc', 'color', 'semver' );
			foreach ( $required_deps as $dep ) {
				if ( ! is_dir( $base_dir . 'node_modules/' . $dep ) ) {
					$sharp_exists = false;
					break;
				}
			}

			// At least one platform binary must exist for Sharp to function.
			if ( $sharp_exists && ! is_dir( $base_dir . 'node_modules/@img' ) ) {
				$sharp_exists = false;
			}
		}

		if ( $sharp_exists ) {
			$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
			$sharp_exists    = $process_service->is_command_available( 'node' );
		}

		/**
		 * Filter: override local Sharp availability detection.
		 *
		 * Return false to force the sidecar path (or the honest
		 * unavailable error) even when a Sharp install looks complete.
		 *
		 * @param bool $sharp_exists Whether local Sharp processing is available.
		 */
		return apply_filters( 'wp_mcp_ai_local_sharp_available', $sharp_exists );
	}

	/**
	 * Process an image through the bundled sharp-process.js subprocess.
	 *
	 * The bundled script addons/pro/bin/sharp-process.js is invoked with the
	 * processing parameters serialised to a temporary JSON file. The
	 * wp_mcp_ai_sharp_process_image filter lets site owners short-circuit
	 * the built-in implementation with a custom one.
	 *
	 * @param array $params Processing parameters (source, operation, …).
	 * @return array|false Processing result array or array with 'error'.
	 */
	protected function process_image_with_sharp( $params ) {
		/**
		 * Filter to allow a custom Sharp processing implementation.
		 *
		 * Return a non-false value to bypass the built-in Node.js subprocess call.
		 *
		 * @param array|false $result Processing result or false to use built-in.
		 * @param array       $params Processing parameters.
		 */
		$custom_result = apply_filters( 'wp_mcp_ai_sharp_process_image', false, $params );
		if ( false !== $custom_result ) {
			return $custom_result;
		}

		// Determine the path to the processing script.
		$script_path = WP_MCP_AI_PRO_PATH . 'bin/sharp-process.js';
		if ( ! file_exists( $script_path ) ) {
			return array(
				'error' => __( 'Sharp processing script not found. Please reinstall the plugin.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Write params to a temporary JSON file.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$params_file = wp_tempnam( 'sharp-params-' );
		if ( ! $params_file ) {
			return array(
				'error' => sprintf(
					/* translators: %s: system temp directory path */
					__( 'Failed to create temporary file for Sharp processing. Check write permissions on %s.', 'mcp-ai-wpoos-pro' ),
					get_temp_dir()
				),
			);
		}

		// Determine the output file extension. Sanitize to alphanumeric only.
		$source_ext = isset( $params['format'] ) ? $params['format'] : pathinfo( $params['source'], PATHINFO_EXTENSION );
		$source_ext = preg_replace( '/[^a-zA-Z0-9]/', '', $source_ext );
		if ( '' === $source_ext ) {
			$source_ext = 'jpg';
		}

		// Create a uniquely-named output path with the correct extension.
		// wp_tempnam() creates a placeholder file to reserve the path; we delete it.
		// immediately so Sharp can write to the extension-appended path without conflict.
		$output_base = wp_tempnam( 'sharp-output-' );
		$output_file = $output_base . '.' . $source_ext;
		wp_delete_file( $output_base ); // Remove placeholder; Sharp creates the ext-versioned file.

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct filesystem operation; WP_Filesystem unavailable here.
		if ( false === file_put_contents( $params_file, wp_json_encode( $params ) ) ) {
			wp_delete_file( $params_file );
			return array( 'error' => __( 'Failed to write Sharp processing parameters.', 'mcp-ai-wpoos-pro' ) );
		}

		// Execute the Node.js script.
		$result = $this->execute_nodejs_script(
			$script_path,
			array( $params_file, $output_file ),
			array(
				'timeout'    => 120,
				'parse_json' => true,
			)
		);

		// Clean up the params temp file.
		wp_delete_file( $params_file );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $output_file );
			return array( 'error' => $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			wp_delete_file( $output_file );
			return array(
				'error' => isset( $result['error'] ) ? $result['error'] : __( 'Sharp processing failed with an unknown error.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $result;
	}

	/**
	 * Process an image through a Media Worker sidecar route.
	 *
	 * Multipart-uploads the source file with the given fields, decodes the
	 * worker's b64 response, and writes it to a site temp file so the result
	 * shape matches the local subprocess path.
	 *
	 * @param string $route           Worker API path (e.g. '/api/image/enhance').
	 * @param string $source_path     Local source file path.
	 * @param array  $fields          Multipart fields sent alongside the file.
	 * @param string $fallback_format File extension when the worker omits one.
	 * @return array Result array matching the local Sharp subprocess shape, or
	 *               array with 'error'.
	 */
	protected function process_image_via_sidecar( $route, $source_path, $fields, $fallback_format ) {
		$sidecar = $this->sidecar_upload( $route, $source_path, $fields, 120 );

		if ( is_wp_error( $sidecar ) ) {
			return array( 'error' => $sidecar->get_error_message() );
		}
		if ( empty( $sidecar['b64'] ) ) {
			return array( 'error' => isset( $sidecar['error'] ) ? $sidecar['error'] : __( 'Worker image processing returned no image data.', 'mcp-ai-wpoos-pro' ) );
		}

		// Decode the worker bytes and write them to a local temp file, so the
		// result matches the local Sharp subprocess shape and the rest of
		// execute() (media upload, response fields) is unchanged.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding worker-returned image bytes is the transport contract, not obfuscation.
		$bytes = base64_decode( $sidecar['b64'], true );
		if ( false === $bytes || '' === $bytes ) {
			return array( 'error' => __( 'Worker returned invalid image data.', 'mcp-ai-wpoos-pro' ) );
		}

		$format = isset( $sidecar['format'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', $sidecar['format'] ) : '';
		if ( '' === $format ) {
			$format = $fallback_format;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$base = wp_tempnam( 'sharp-sidecar-' );
		if ( ! $base ) {
			return array(
				'error' => sprintf(
					/* translators: %s: system temp directory path */
					__( 'Failed to create temporary file for processed image. Check write permissions on %s.', 'mcp-ai-wpoos-pro' ),
					get_temp_dir()
				),
			);
		}
		$final_path = $base . '.' . $format;
		wp_delete_file( $base ); // Remove placeholder; write the ext-versioned path.

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing processed image bytes to a site temp file.
		if ( false === file_put_contents( $final_path, $bytes ) ) {
			return array( 'error' => __( 'Failed to write processed image file.', 'mcp-ai-wpoos-pro' ) );
		}

		$reduction = null;
		if ( isset( $sidecar['savings_percent'] ) ) {
			$reduction = (float) rtrim( (string) $sidecar['savings_percent'], '%' );
		}

		$dimensions = null;
		if ( isset( $sidecar['width'], $sidecar['height'] ) ) {
			$dimensions = array(
				'width'  => (int) $sidecar['width'],
				'height' => (int) $sidecar['height'],
			);
		}

		return array(
			'output_path'       => $final_path,
			'original_size'     => isset( $sidecar['original_size'] ) ? (int) $sidecar['original_size'] : filesize( $source_path ),
			'optimized_size'    => isset( $sidecar['optimized_size'] ) ? (int) $sidecar['optimized_size'] : filesize( $final_path ),
			'reduction_percent' => $reduction,
			'dimensions'        => $dimensions,
		);
	}

	/**
	 * Upload a processed image file to the media library.
	 *
	 * @param string $file_path Processed file path.
	 * @param int    $parent_id Parent attachment ID (optional).
	 * @param string $title     Attachment title.
	 * @return int|false New attachment ID or false on failure.
	 */
	protected function upload_processed_image( $file_path, $parent_id, $title ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Get file info.
		$file_name = basename( $file_path );
		$file_type = wp_check_filetype( $file_name );

		// Prepare upload.
		$upload_dir  = wp_upload_dir();
		$target_path = $upload_dir['path'] . '/' . $file_name;

		// Copy file to uploads directory.
		if ( ! copy( $file_path, $target_path ) ) {
			return false;
		}

		// Prepare attachment data.
		$attachment_data = array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $parent_id,
		);

		// Insert attachment.
		$attachment_id = wp_insert_attachment( $attachment_data, $target_path, $parent_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			// Generate metadata.
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $target_path );
			wp_update_attachment_metadata( $attachment_id, $attachment_metadata );

			return $attachment_id;
		}

		return false;
	}
}

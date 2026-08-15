<?php
/**
 * WordPress-specific tool for vectorizing raster images to SVG.
 *
 * Lives in the WordPress adapter because it uses wp_get_image_editor,
 * wp_tempnam, wp_upload_bits, wp_insert_attachment, Node.js subprocess
 * via WP_MCP_AI_NodeJS_Subprocess trait, and WP_MCP_AI_Optional_Components.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;
use WP_MCP_AI_Media_Worker_Client;

/**
 * Converts raster images (PNG, JPEG, WebP, GIF) to SVG vector format
 * using the @neplex/vectorizer Node.js library.
 */
class VectorizeImageTool extends AbstractTool {
	use WP_MCP_AI_Media_Worker_Client;

	public function getSlug(): string {
		return 'vectorize_image';
	}

	public function getName(): string {
		return 'Vectorize Image';
	}

	public function getDescription(): string {
		return 'Convert a raster image (PNG, JPEG, WebP, GIF) to SVG vector format with configurable quality settings.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id'   => array(
					'type'        => 'integer',
					'description' => 'WordPress attachment ID of the image to vectorize.',
					'minimum'     => 1,
				),
				'image_url'       => array(
					'type'        => 'string',
					'description' => 'URL of the image to vectorize (used if attachment_id is not provided).',
					'format'      => 'uri',
				),
				'file_name'       => array(
					'type'        => 'string',
					'description' => 'Optional file name for the output SVG.',
				),
				'color_mode'      => array(
					'type'        => 'string',
					'description' => 'Color mode: color or binary.',
					'enum'        => array( 'color', 'binary' ),
					'default'     => 'color',
				),
				'color_precision' => array(
					'type'        => 'integer',
					'description' => 'Color quantization precision (1-8).',
					'minimum'     => 1,
					'maximum'     => 8,
					'default'     => 6,
				),
				'filter_speckle'  => array(
					'type'        => 'integer',
					'description' => 'Filter speckle size in pixels (0-100).',
					'minimum'     => 0,
					'maximum'     => 100,
					'default'     => 4,
				),
				'mode'            => array(
					'type'        => 'string',
					'description' => 'Path simplification: spline, polygon, or none.',
					'enum'        => array( 'spline', 'polygon', 'none' ),
					'default'     => 'spline',
				),
				'hierarchical'    => array(
					'type'        => 'string',
					'description' => 'Layer stacking: stacked or cutout.',
					'enum'        => array( 'stacked', 'cutout' ),
					'default'     => 'stacked',
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id   = \absint( $context['user_id'] ?? 0 );
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return $this->errors->accessDenied( 'You must be authenticated to vectorize images.' );
		}

		if ( $user_id && ! \user_can( $user_id, 'upload_files' ) ) {
			return $this->errors->accessDenied( 'You do not have permission to edit images.' );
		}

		if ( $user_id && \is_multisite() && ! \is_user_member_of_blog( $user_id, \get_current_blog_id() ) ) {
			return $this->errors->accessDenied( 'You do not have access to this site.' );
		}

		// Node.js (local) or the Media Worker sidecar is required. The
		// vectorizer library itself is bundled with the base plugin.
		if ( ! $this->isNodeJsAvailable() && ! $this->is_sidecar_upload_supported() ) {
			return $this->errors->create(
				'wp_mcp_ai_nodejs_required',
				'Node.js is required for image vectorization but was not found. Configure the Media Worker sidecar or install Node.js.'
			);
		}

		// Load source image.
		$source_file = $this->getSourceFilePath( $arguments );
		if ( \is_wp_error( $source_file ) ) {
			return $this->errors->create( $source_file->get_error_code(), $source_file->get_error_message() );
		}

		$image_editor = \wp_get_image_editor( $source_file );
		if ( \is_wp_error( $image_editor ) ) {
			return $this->errors->create( $image_editor->get_error_code(), $image_editor->get_error_message() );
		}

		// Save to temp file for Node.js processing.
		$temp_input = \wp_tempnam( 'vectorize-input-' );
		if ( ! $temp_input ) {
			return $this->errors->create( 'wp_mcp_ai_temp_file_error', 'Failed to create temporary file.' );
		}

		$saved = $image_editor->save( $temp_input );
		if ( \is_wp_error( $saved ) ) {
			\wp_delete_file( $temp_input );
			return $this->errors->create( $saved->get_error_code(), $saved->get_error_message() );
		}

		$temp_input = $saved['path'] ?? $temp_input;
		if ( ! \file_exists( $temp_input ) ) {
			return $this->errors->create( 'wp_mcp_ai_temp_file_error', 'Failed to save temporary file.' );
		}

		// Prepare output temp file.
		$temp_output = \wp_tempnam( 'vectorized-' );
		if ( ! $temp_output ) {
			\wp_delete_file( $temp_input );
			return $this->errors->create( 'wp_mcp_ai_temp_file_error', 'Failed to create temporary output file.' );
		}

		$temp_output_svg = $temp_output . '.svg';
		\rename( $temp_output, $temp_output_svg );
		$temp_output = $temp_output_svg;

		// Vectorization options.
		$options = array(
			'colorMode'      => \sanitize_text_field( $arguments['color_mode'] ?? 'color' ),
			'colorPrecision' => \absint( $arguments['color_precision'] ?? 6 ),
			'filterSpeckle'  => \absint( $arguments['filter_speckle'] ?? 4 ),
			'mode'           => \sanitize_text_field( $arguments['mode'] ?? 'spline' ),
			'hierarchical'   => \sanitize_text_field( $arguments['hierarchical'] ?? 'stacked' ),
		);

		// Execute vectorization: try the Media Worker sidecar first (opt-in
		// routing — fails fast when no sidecar URL is configured or the
		// health check fails), then fall back to the local script.
		$result = null;
		if ( $this->is_sidecar_upload_supported() ) {
			$sidecar = $this->sidecar_upload(
				'/api/image/vectorize',
				$temp_input,
				array( 'options' => \wp_json_encode( $options ) ),
				120
			);
			if ( ! \is_wp_error( $sidecar ) && ! empty( $sidecar['svg'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing the worker-returned SVG into the temp output file consumed by the shared save flow.
				if ( false !== \file_put_contents( $temp_output, $sidecar['svg'] ) ) {
					$result = array( 'success' => true );
				}
			}
		}

		if ( null === $result ) {
			// Execute Node.js vectorizer.
			$script_path = \WP_MCP_AI_PATH . 'bin/vectorize-image.js';
			$result      = $this->runNodeScript( $script_path, array( $temp_input, $temp_output, \wp_json_encode( $options ) ) );
		}

		\wp_delete_file( $temp_input );

		if ( \is_wp_error( $result ) ) {
			\wp_delete_file( $temp_output );
			return $this->errors->create( $result->get_error_code(), $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			\wp_delete_file( $temp_output );
			return $this->errors->create(
				'wp_mcp_ai_vectorization_failed',
				$result['error'] ?? 'Vectorization failed.'
			);
		}

		// Read SVG output.
		$svg_data = \file_get_contents( $temp_output );
		\wp_delete_file( $temp_output );

		if ( false === $svg_data || '' === $svg_data ) {
			return $this->errors->create( 'wp_mcp_ai_read_error', 'Failed to read vectorized SVG file.' );
		}

		// Save as WordPress attachment.
		$storage = $this->saveSvgAsAttachment( $svg_data, $arguments, $user_id );
		if ( \is_wp_error( $storage ) ) {
			return $this->errors->create( $storage->get_error_code(), $storage->get_error_message() );
		}

		return $this->success(
			\sprintf( 'Successfully vectorized image to SVG. Attachment ID: %d', $storage['attachment_id'] ),
			array(
				'attachment_id' => $storage['attachment_id'],
				'url'           => $storage['url'],
				'file_name'     => $storage['file_name'],
				'mime_type'     => 'image/svg+xml',
				'bytes'         => $storage['bytes'],
				'title'         => $storage['title'],
				'svg_size'      => $result['output_size'] ?? $storage['bytes'],
				'duration_ms'   => $result['duration_ms'] ?? 0,
				'options'       => $options,
			)
		);
	}

	// ─── Helpers ──────────────────────────────────────────────────────

	/**
	 * Get the local file path for the source image.
	 */
	private function getSourceFilePath( array $arguments ) {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$path = \get_attached_file( \absint( $arguments['attachment_id'] ) );
			if ( ! $path || ! \file_exists( $path ) ) {
				return new \WP_Error( 'wp_mcp_ai_not_found', 'Attachment file not found.' );
			}
			return $path;
		}

		if ( ! empty( $arguments['image_url'] ) ) {
			// Download remote image to temp file.
			$response = \wp_remote_get( $arguments['image_url'], array( 'timeout' => 30 ) );
			if ( \is_wp_error( $response ) ) {
				return $response;
			}

			$body = \wp_remote_retrieve_body( $response );
			if ( '' === $body ) {
				return new \WP_Error( 'wp_mcp_ai_error', 'Failed to download image.' );
			}

			$temp = \wp_tempnam( 'vectorize-dl-' );
			if ( ! $temp || false === \file_put_contents( $temp, $body ) ) {
				return new \WP_Error( 'wp_mcp_ai_temp_file_error', 'Failed to save downloaded image.' );
			}

			return $temp;
		}

		return new \WP_Error( 'wp_mcp_ai_error', 'Provide either attachment_id or image_url.' );
	}

	/**
	 * Save SVG data as a WordPress attachment.
	 */
	private function saveSvgAsAttachment( string $svg_data, array $arguments, int $user_id ) {
		$base_name = \sanitize_file_name( $arguments['file_name'] ?? 'vectorized-image' );
		if ( '' === $base_name ) {
			$base_name = 'vectorized-image';
		}

		$base_name = \preg_replace( '/\.(png|jpg|jpeg|gif|webp)$/i', '', $base_name );
		$file_name = $base_name . '-' . \gmdate( 'Ymd-His' ) . '.svg';

		if ( ! \function_exists( 'wp_upload_bits' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
		}

		// WordPress rejects image/svg+xml by default (an XSS surface for
		// arbitrary uploads); allow it for this single call only.
		$allow_svg_mime = static function ( $mimes ) {
			if ( ! is_array( $mimes ) ) {
				$mimes = array();
			}
			$mimes['svg'] = 'image/svg+xml';
			return $mimes;
		};
		\add_filter( 'upload_mimes', $allow_svg_mime );
		$upload = \wp_upload_bits( $file_name, null, $svg_data );
		\remove_filter( 'upload_mimes', $allow_svg_mime );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'wp_mcp_ai_upload_failed', 'Failed to save SVG file.' );
		}

		$file_path = $upload['file'] ?? '';
		if ( '' === $file_path || ! \file_exists( $file_path ) ) {
			return new \WP_Error( 'wp_mcp_ai_upload_failed', 'Failed to write SVG file to disk.' );
		}

		$attachment    = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => 'Vectorized Image',
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = \wp_insert_attachment( $attachment, $file_path );
		if ( \is_wp_error( $attachment_id ) ) {
			\wp_delete_file( $file_path );
			return new \WP_Error( 'wp_mcp_ai_attachment_error', 'Failed to register SVG as an attachment.' );
		}

		$bytes          = \file_exists( $file_path ) ? \filesize( $file_path ) : 0;
		$attachment_url = \wp_get_attachment_url( $attachment_id );

		if ( false === $attachment_url ) {
			$upload_dir     = \wp_upload_dir();
			$attachment_url = \str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => \wp_basename( $file_path ),
			'url'           => $attachment_url,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => \get_the_title( $attachment_id ),
		);
	}

	/**
	 * Check if Node.js is available on the system.
	 */
	private function isNodeJsAvailable(): bool {
		$result = \WP_MCP_AI_NodeJS_Subprocess_Helper::is_nodejs_available();
		return ! \is_wp_error( $result ) && $result;
	}

	/**
	 * Execute a Node.js script and return parsed JSON output.
	 */
	private function runNodeScript( string $script_path, array $args = array() ) {
		if ( ! \class_exists( 'WP_MCP_AI_NodeJS_Subprocess_Helper' ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', 'Node.js subprocess helper not available.' );
		}

		return \WP_MCP_AI_NodeJS_Subprocess_Helper::execute_script(
			$script_path,
			$args,
			array( 'timeout' => 60, 'parse_json' => true )
		);
	}
}

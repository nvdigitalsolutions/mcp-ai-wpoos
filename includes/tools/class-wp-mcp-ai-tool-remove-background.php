<?php
/**
 * Tool for removing image backgrounds.
 *
 * Supports two methods:
 * 1. Free: Python rembg library (requires rembg to be installed)
 * 2. Paid: remove.bg API service (requires API key in settings)
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';
require_once WP_MCP_AI_PATH . 'includes/tools/remove-background.php';

/**
 * Remove background from images using free (rembg) or paid (remove.bg API) methods.
 */
class WP_MCP_AI_Tool_Remove_Background extends WP_MCP_AI_Tool_Image_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'remove_background';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Remove Background', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Remove the background from an image, making it transparent. Supports free (rembg) and paid (remove.bg API) methods.', 'wp-mcp-ai' );
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
					'method' => array(
						'type'        => 'string',
						'description' => __( 'Background removal method: "auto" (tries free first, then paid if available), "free" (rembg only), or "paid" (remove.bg API only). Default is "auto".', 'wp-mcp-ai' ),
						'enum'        => array( 'auto', 'free', 'paid' ),
						'default'     => 'auto',
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
		$flags = array(
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates new media files.
			'performance-impact',   // Image processing can be resource-intensive.
			'idempotent',           // Can be called multiple times safely with same result.
		);

		// Check if paid method (remove.bg API) is configured.
		$api_key = get_option( 'wp_mcp_ai_removebg_api_key', '' );
		if ( ! empty( $api_key ) ) {
			$flags[] = 'external-api';      // May use remove.bg API.
			$flags[] = 'requires-credentials'; // Requires API key when using paid mode.
			$flags[] = 'network-dependent'; // Requires internet for paid mode.
			$flags[] = 'consumes-tokens';   // Uses external API credits (paid mode).
			$flags[] = 'rate-limited';      // Subject to API rate limits.
		}

		// If no API key, it's local-only (using rembg Python library).
		if ( empty( $api_key ) ) {
			$flags[] = 'local-only';        // Works locally without external APIs.
		}

		return $flags;
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to remove image backgrounds.', 'wp-mcp-ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to edit images.', 'wp-mcp-ai' )
			);
		}

		// Get method preference.
		$method = isset( $arguments['method'] ) ? sanitize_text_field( $arguments['method'] ) : 'auto';
		if ( ! in_array( $method, array( 'auto', 'free', 'paid' ), true ) ) {
			$method = 'auto';
		}

		// Load source image.
		$source_image = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $source_image ) ) {
			return $source_image;
		}

		// Get the source file path for processing.
		$source_path = $source_image->generate_filename();

		// Try methods based on preference.
		$result = null;
		$errors = array();

		if ( 'free' === $method || 'auto' === $method ) {
			$result = $this->remove_background_free( $source_path );
			if ( is_wp_error( $result ) ) {
				$errors['free'] = $result->get_error_message();
				if ( 'free' === $method ) {
					return $result; // User explicitly requested free method only.
				}
			}
		}

		// If free method failed or wasn't tried, and we're allowed to use paid.
		if ( is_wp_error( $result ) && ( 'paid' === $method || 'auto' === $method ) ) {
			$result = $this->remove_background_paid( $source_path );
			if ( is_wp_error( $result ) ) {
				$errors['paid'] = $result->get_error_message();
			}
		}

		// Clean up source image if it was a temp file.
		$this->cleanup_source_image( $source_image, $arguments );

		// Return error if all methods failed.
		if ( is_wp_error( $result ) ) {
			$error_messages = array();
			if ( ! empty( $errors ) ) {
				foreach ( $errors as $method_name => $error_msg ) {
					$error_messages[] = sprintf(
						/* translators: 1: method name, 2: error message */
						__( '%1$s: %2$s', 'wp-mcp-ai' ),
						ucfirst( $method_name ),
						$error_msg
					);
				}
			}

			return new WP_Error(
				'wp_mcp_ai_background_removal_failed',
				sprintf(
					/* translators: %s: error details */
					__( 'Failed to remove background. %s', 'wp-mcp-ai' ),
					implode( '; ', $error_messages )
				)
			);
		}

		// Save result as attachment.
		$attachment_id = $this->save_as_attachment( $result, $arguments, $context );
		if ( is_wp_error( $attachment_id ) ) {
			// Clean up the processed file.
			if ( file_exists( $result ) ) {
				wp_delete_file( $result );
			}
			return $attachment_id;
		}

		// Clean up processed file after saving as attachment.
		if ( file_exists( $result ) ) {
			wp_delete_file( $result );
		}

		// Return attachment details.
		return $this->format_attachment_response( $attachment_id );
	}

	/**
	 * Remove background using free Python rembg library.
	 *
	 * @param string $source_path Path to source image file.
	 * @return string|WP_Error Path to processed image on success, WP_Error on failure.
	 */
	protected function remove_background_free( $source_path ) {
		// Check if Python is available.
		$python_cmd = $this->find_python_command();
		if ( is_wp_error( $python_cmd ) ) {
			return $python_cmd;
		}

		// Create a temporary Python script to use rembg.
		$script_content = <<<'PYTHON'
import sys
import os

try:
    from rembg import remove
    from PIL import Image
    import io
    
    if len(sys.argv) != 3:
        print("Usage: script.py <input> <output>", file=sys.stderr)
        sys.exit(1)
    
    input_path = sys.argv[1]
    output_path = sys.argv[2]
    
    if not os.path.exists(input_path):
        print(f"Input file not found: {input_path}", file=sys.stderr)
        sys.exit(1)
    
    # Read input image.
    with open(input_path, 'rb') as f:
        input_data = f.read()
    
    # Remove background.
    output_data = remove(input_data)
    
    # Save output image.
    with open(output_path, 'wb') as f:
        f.write(output_data)
    
    print("success")
    sys.exit(0)
    
except ImportError as e:
    print(f"rembg not installed: {e}", file=sys.stderr)
    print("Install with: pipx install rembg (recommended) or use venv", file=sys.stderr)
    sys.exit(2)
except Exception as e:
    print(f"Error: {e}", file=sys.stderr)
    sys.exit(3)
PYTHON;

		$script_path = wp_tempnam( 'rembg-', '.py' );
		if ( ! $script_path ) {
			return new WP_Error(
				'wp_mcp_ai_temp_file_failed',
				__( 'Failed to create temporary script file.', 'wp-mcp-ai' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $script_path, $script_content );

		// Generate output filename.
		$pathinfo      = pathinfo( $source_path );
		$filename_base = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : 'image';
		$output_path   = wp_tempnam( $filename_base . '-nobg-' . time(), '.png' );

		// Execute Python script.
		$command = sprintf(
			'%s %s %s %s 2>&1',
			escapeshellcmd( $python_cmd ),
			escapeshellarg( $script_path ),
			escapeshellarg( $source_path ),
			escapeshellarg( $output_path )
		);

		$output      = array();
		$return_code = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $return_code );

		// Clean up script.
		wp_delete_file( $script_path );

		// Check for errors.
		if ( 0 !== $return_code ) {
			$error_message = implode( "\n", $output );

			if ( 2 === $return_code ) {
				return new WP_Error(
					'wp_mcp_ai_rembg_not_installed',
					__( 'The rembg library is not installed. On modern systems (Debian 12+, Ubuntu 23.04+), use: pipx install rembg OR create a virtual environment. See documentation for details.', 'wp-mcp-ai' )
				);
			}

			return new WP_Error(
				'wp_mcp_ai_rembg_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'rembg processing failed: %s', 'wp-mcp-ai' ),
					$error_message
				)
			);
		}

		// Verify output file was created.
		if ( ! file_exists( $output_path ) || 0 === filesize( $output_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_rembg_no_output',
				__( 'rembg did not produce output file.', 'wp-mcp-ai' )
			);
		}

		return $output_path;
	}

	/**
	 * Remove background using paid remove.bg API.
	 *
	 * @param string $source_path Path to source image file.
	 * @return string|WP_Error Path to processed image on success, WP_Error on failure.
	 */
	protected function remove_background_paid( $source_path ) {
		// Use the existing helper function.
		$result = wp_mcp_ai_remove_image_background( $source_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}

	/**
	 * Find Python command on the system.
	 *
	 * @return string|WP_Error Python command or error.
	 */
	protected function find_python_command() {
		$python_commands = array( 'python3', 'python' );

		foreach ( $python_commands as $cmd ) {
			$output      = array();
			$return_code = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( sprintf( 'which %s 2>&1', escapeshellcmd( $cmd ) ), $output, $return_code );

			if ( 0 === $return_code && ! empty( $output[0] ) ) {
				return $cmd;
			}
		}

		return new WP_Error(
			'wp_mcp_ai_python_not_found',
			__( 'Python is not available on this system. Please install Python 3 or configure remove.bg API key for paid service.', 'wp-mcp-ai' )
		);
	}

	/**
	 * Sanitize the tool result for LLM consumption.
	 *
	 * @param array|WP_Error $result The result to sanitize.
	 * @return array Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		// If result is an error, return sanitized error.
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}

		// If result is attachment data, return it.
		if ( is_array( $result ) && isset( $result['attachment_id'] ) ) {
			return array(
				'success'       => true,
				'attachment_id' => $result['attachment_id'],
				'url'           => $result['url'],
				'width'         => $result['width'],
				'height'        => $result['height'],
				'message'       => __( 'Background removed successfully. The image now has a transparent background.', 'wp-mcp-ai' ),
			);
		}

		return $result;
	}
}

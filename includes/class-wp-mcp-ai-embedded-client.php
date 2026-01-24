<?php
/**
 * Embedded LLM API client wrapper.
 *
 * Provides support for embedded small language models that run directly
 * within the WordPress environment without external dependencies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
	/**
	 * Provides a wrapper for embedded language models.
	 *
	 * This client downloads and manages small language models that can run
	 * directly on the server without requiring external API calls or separate
	 * inference servers like Ollama or LM Studio.
	 */
	class WP_MCP_AI_Embedded_Client {

		/**
		 * Models directory path
		 */
		const MODELS_DIR = 'mcp-ai-wpoos/models';

		/**
		 * Available embedded models with download URLs
		 *
		 * @var array
		 */
		private static $available_models = array(
			'granite-3.1-2b-instruct' => array(
				'name'        => 'IBM Granite 3.1 2B Instruct',
				'description' => 'Compact 2B parameter model optimized for instruction following',
				'size'        => 1200000000, // ~1.2GB
				'url'         => 'https://huggingface.co/ibm-granite/granite-3.1-2b-instruct-GGUF/resolve/main/granite-3.1-2b-instruct.Q4_K_M.gguf',
				'checksum'    => '', // To be filled after download verification
				'format'      => 'gguf',
				'license'     => 'Apache 2.0',
			),
			'phi-3-mini-4k-instruct'  => array(
				'name'        => 'Microsoft Phi-3 Mini',
				'description' => 'Efficient 3.8B parameter model with 4K context',
				'size'        => 2300000000, // ~2.3GB
				'url'         => 'https://huggingface.co/microsoft/Phi-3-mini-4k-instruct-gguf/resolve/main/Phi-3-mini-4k-instruct-q4.gguf',
				'checksum'    => '', // To be filled after download verification
				'format'      => 'gguf',
				'license'     => 'MIT',
			),
			'qwen2-0.5b-instruct'     => array(
				'name'        => 'Qwen2 0.5B Instruct',
				'description' => 'Ultra-compact 0.5B parameter model for basic tasks',
				'size'        => 350000000, // ~350MB
				'url'         => 'https://huggingface.co/Qwen/Qwen2-0.5B-Instruct-GGUF/resolve/main/qwen2-0_5b-instruct-q4_k_m.gguf',
				'checksum'    => '', // To be filled after download verification
				'format'      => 'gguf',
				'license'     => 'Apache 2.0',
			),
		);

		/**
		 * Get the configured model.
		 *
		 * @return string
		 */
		public function get_model() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return isset( $settings['embedded_model'] ) ? $settings['embedded_model'] : '';
		}

		/**
		 * Get the models directory path.
		 *
		 * @return string Full path to models directory.
		 */
		public function get_models_directory() {
			$upload_dir = wp_upload_dir();
			$models_dir = trailingslashit( $upload_dir['basedir'] ) . self::MODELS_DIR;

			// Create directory if it doesn't exist.
			if ( ! file_exists( $models_dir ) ) {
				wp_mkdir_p( $models_dir );
			}

			return $models_dir;
		}

		/**
		 * Get list of available models.
		 *
		 * @return array List of available models.
		 */
		public function get_available_models() {
			return self::$available_models;
		}

		/**
		 * Get list of downloaded models.
		 *
		 * @return array List of downloaded models with their status.
		 */
		public function get_downloaded_models() {
			$models_dir = $this->get_models_directory();
			$downloaded = array();

			foreach ( self::$available_models as $slug => $model ) {
				$filename = $slug . '.' . $model['format'];
				$filepath = trailingslashit( $models_dir ) . $filename;

				if ( file_exists( $filepath ) ) {
					$downloaded[ $slug ] = array_merge(
						$model,
						array(
							'slug'       => $slug,
							'filepath'   => $filepath,
							'filesize'   => filesize( $filepath ),
							'downloaded' => true,
							'modified'   => filemtime( $filepath ),
						)
					);
				}
			}

			return $downloaded;
		}

		/**
		 * Check if a model is downloaded.
		 *
		 * @param string $model_slug Model slug.
		 * @return bool True if model is downloaded.
		 */
		public function is_model_downloaded( $model_slug ) {
			$downloaded_models = $this->get_downloaded_models();
			return isset( $downloaded_models[ $model_slug ] );
		}

		/**
		 * Download a model.
		 *
		 * @param string $model_slug Model slug to download.
		 * @return array|WP_Error Download result or error.
		 */
		public function download_model( $model_slug ) {
			if ( ! isset( self::$available_models[ $model_slug ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_model',
					__( 'Invalid model specified.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$model      = self::$available_models[ $model_slug ];
			$models_dir = $this->get_models_directory();
			$filename   = $model_slug . '.' . $model['format'];
			$filepath   = trailingslashit( $models_dir ) . $filename;

			// Check if already downloaded.
			if ( file_exists( $filepath ) ) {
				return array(
					'success' => true,
					'message' => __( 'Model already downloaded.', 'mcp-ai-wpoos' ),
					'path'    => $filepath,
				);
			}

			WP_MCP_AI_Logger::log_event(
				'embedded_model_download_start',
				'Starting model download.',
				array(
					'model' => $model_slug,
					'size'  => $model['size'],
					'url'   => $model['url'],
				)
			);

			// Download the model file.
			// Note: For large files, this should be done via background process.
			// For now, we'll use wp_remote_get with a high timeout.
			$response = wp_remote_get(
				$model['url'],
				array(
					'timeout'  => 600, // 10 minutes for large files.
					'stream'   => true,
					'filename' => $filepath,
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Model download failed.',
					array( 'error' => $response->get_error_message() )
				);

				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error(
					'wp_mcp_ai_download_error',
					sprintf(
						/* translators: %d: HTTP response code */
						__( 'Model download failed with HTTP code %d.', 'mcp-ai-wpoos' ),
						$code
					),
					array( 'status' => $code )
				);
			}

			// Verify file size.
			if ( ! file_exists( $filepath ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_not_found',
					__( 'Downloaded file not found.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			$downloaded_size = filesize( $filepath );
			$expected_size   = $model['size'];

			// Allow 20% variance in file size to accommodate variations in actual model file sizes.
			// Model sizes on Hugging Face may differ from estimates due to quantization and metadata.
			$size_diff = abs( $downloaded_size - $expected_size );
			if ( $size_diff > ( $expected_size * 0.20 ) ) {
				// Delete incomplete file.
				wp_delete_file( $filepath );

				return new WP_Error(
					'wp_mcp_ai_size_mismatch',
					sprintf(
						/* translators: 1: downloaded size, 2: expected size */
						__( 'Downloaded file size (%1$s) does not match expected size (%2$s).', 'mcp-ai-wpoos' ),
						size_format( $downloaded_size ),
						size_format( $expected_size )
					),
					array( 'status' => 500 )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'embedded_model_download_complete',
				'Model download completed successfully.',
				array(
					'model' => $model_slug,
					'path'  => $filepath,
					'size'  => $downloaded_size,
				)
			);

			return array(
				'success' => true,
				'message' => __( 'Model downloaded successfully.', 'mcp-ai-wpoos' ),
				'path'    => $filepath,
				'size'    => $downloaded_size,
			);
		}

		/**
		 * Delete a downloaded model.
		 *
		 * @param string $model_slug Model slug to delete.
		 * @return array|WP_Error Deletion result or error.
		 */
		public function delete_model( $model_slug ) {
			if ( ! isset( self::$available_models[ $model_slug ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_model',
					__( 'Invalid model specified.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$model      = self::$available_models[ $model_slug ];
			$models_dir = $this->get_models_directory();
			$filename   = $model_slug . '.' . $model['format'];
			$filepath   = trailingslashit( $models_dir ) . $filename;

			if ( ! file_exists( $filepath ) ) {
				return new WP_Error(
					'wp_mcp_ai_model_not_found',
					__( 'Model file not found.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			$deleted = wp_delete_file( $filepath );

			if ( ! $deleted ) {
				return new WP_Error(
					'wp_mcp_ai_delete_failed',
					__( 'Failed to delete model file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			WP_MCP_AI_Logger::log_event(
				'embedded_model_deleted',
				'Model deleted successfully.',
				array( 'model' => $model_slug )
			);

			return array(
				'success' => true,
				'message' => __( 'Model deleted successfully.', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * Test the embedded model connection.
		 *
		 * @return array|WP_Error Test result or error.
		 */
		public function test_connection() {
			$model = $this->get_model();

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_model_selected',
					__( 'No embedded model has been selected.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( ! $this->is_model_downloaded( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_model_not_downloaded',
					__( 'Selected model has not been downloaded yet.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Check if inference binary is available.
			$inference_binary = $this->get_inference_binary();
			if ( is_wp_error( $inference_binary ) ) {
				return $inference_binary;
			}

			return array(
				'success' => true,
				'message' => __( 'Embedded model is ready.', 'mcp-ai-wpoos' ),
				'model'   => $model,
			);
		}

		/**
		 * Get the path to the inference binary.
		 *
		 * @return string|WP_Error Path to binary or error if not found.
		 */
		private function get_inference_binary() {
			// Check for llama.cpp binary in plugin directory.
			$plugin_dir = WP_MCP_AI_PATH;
			$bin_dir    = trailingslashit( $plugin_dir ) . 'bin/llama.cpp/';

			// Detect platform.
			$os   = PHP_OS_FAMILY;
			$arch = php_uname( 'm' );

			$binary_name = 'llama-cli';
			if ( 'Windows' === $os ) {
				$binary_name .= '.exe';
			}

			// Try platform-specific binary.
			$binary_path = $bin_dir . $os . '-' . $arch . '/' . $binary_name;

			if ( file_exists( $binary_path ) && is_executable( $binary_path ) ) {
				return $binary_path;
			}

			// Try generic binary.
			$binary_path = $bin_dir . $binary_name;

			if ( file_exists( $binary_path ) && is_executable( $binary_path ) ) {
				return $binary_path;
			}

			// Check if llama-cli is in system PATH (only if shell_exec is available).
			if ( function_exists( 'shell_exec' ) && ! $this->is_shell_exec_disabled() ) {
				$which_result = shell_exec( 'which llama-cli 2>/dev/null' );
				if ( ! empty( $which_result ) ) {
					return trim( $which_result );
				}

				// Check if llama-server is available (alternative).
				$which_result = shell_exec( 'which llama-server 2>/dev/null' );
				if ( ! empty( $which_result ) ) {
					return trim( $which_result );
				}
			}

			// Detect platform for installation instructions.
			$platform = $this->detect_platform();

			return new WP_Error(
				'wp_mcp_ai_no_inference_binary',
				$this->get_binary_installation_instructions( $platform ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Detect platform and architecture.
		 *
		 * @return array Platform information.
		 */
		private function detect_platform() {
			$os   = PHP_OS_FAMILY;
			$arch = php_uname( 'm' );

			$platform = array(
				'os'     => $os,
				'arch'   => $arch,
				'binary' => 'llama-cli',
				'dir'    => '',
			);

			// Normalize architecture names.
			if ( in_array( $arch, array( 'x86_64', 'amd64', 'AMD64' ), true ) ) {
				$platform['arch_normalized'] = 'x64';
			} elseif ( in_array( $arch, array( 'aarch64', 'arm64', 'ARM64' ), true ) ) {
				$platform['arch_normalized'] = 'arm64';
			} elseif ( strpos( $arch, 'arm' ) !== false ) {
				$platform['arch_normalized'] = 'arm';
			} else {
				$platform['arch_normalized'] = 'unknown';
			}

			// Set platform-specific details.
			if ( 'Windows' === $os ) {
				$platform['binary'] = 'llama-cli.exe';
				$platform['dir']    = 'windows-' . $platform['arch_normalized'];
			} elseif ( 'Linux' === $os ) {
				$platform['binary'] = 'llama-cli';
				$platform['dir']    = 'linux-' . $platform['arch_normalized'];

				// Detect Cloudways hosting (Ubuntu-based).
				if ( $this->is_cloudways_hosting() ) {
					$platform['hosting']     = 'cloudways';
					$platform['description'] = 'Cloudways Ubuntu ' . $platform['arch_normalized'];
				}
			} elseif ( 'Darwin' === $os ) {
				$platform['binary'] = 'llama-cli';
				$platform['dir']    = 'macos-' . $platform['arch_normalized'];
			}

			return $platform;
		}

		/**
		 * Check if running on Cloudways hosting.
		 *
		 * @return bool True if Cloudways detected.
		 */
		private function is_cloudways_hosting() {
			// Check for Cloudways-specific environment indicators.
			$indicators = array(
				defined( 'CLOUDWAYS_DEPLOYMENT' ),
				getenv( 'CLOUDWAYS_DEPLOYMENT' ) !== false,
				file_exists( '/cloudways.yml' ),
				strpos( gethostname(), 'cloudways' ) !== false,
			);

			return in_array( true, $indicators, true );
		}

		/**
		 * Get installation instructions for llama.cpp binary.
		 *
		 * @param array $platform Platform information.
		 * @return string Installation instructions.
		 */
		private function get_binary_installation_instructions( $platform ) {
			$os              = $platform['os'];
			$arch_normalized = $platform['arch_normalized'];

			$instructions = __( 'Inference binary (llama.cpp) not found. ', 'mcp-ai-wpoos' );

			// Platform-specific instructions.
			if ( 'Linux' === $os && 'x64' === $arch_normalized ) {
				if ( isset( $platform['hosting'] ) && 'cloudways' === $platform['hosting'] ) {
					$instructions .= sprintf(
						/* translators: %s: Download URL */
						__( 'For Cloudways hosting, download the pre-compiled Linux x64 binary from: %s. Upload it to your server at: wp-content/plugins/mcp-ai-wpoos/bin/llama.cpp/ and make it executable with: chmod +x llama-cli', 'mcp-ai-wpoos' ),
						'https://github.com/ggerganov/llama.cpp/releases/latest'
					);
				} else {
					$instructions .= sprintf(
						/* translators: %s: Download URL */
						__( 'Download the pre-compiled Linux x64 binary from %s, or install via: apt-get install llama.cpp (if available). Place the binary in wp-content/plugins/mcp-ai-wpoos/bin/llama.cpp/ or add it to your system PATH.', 'mcp-ai-wpoos' ),
						'https://github.com/ggerganov/llama.cpp/releases/latest'
					);
				}
			} elseif ( 'Linux' === $os && 'arm64' === $arch_normalized ) {
				$instructions .= sprintf(
					/* translators: %s: Download URL */
					__( 'Download the pre-compiled Linux ARM64 binary from %s. Place it in wp-content/plugins/mcp-ai-wpoos/bin/llama.cpp/ and make it executable with: chmod +x llama-cli', 'mcp-ai-wpoos' ),
					'https://github.com/ggerganov/llama.cpp/releases/latest'
				);
			} elseif ( 'Windows' === $os ) {
				$instructions .= sprintf(
					/* translators: %s: Download URL */
					__( 'Download the pre-compiled Windows binary from %s. Place llama-cli.exe in wp-content/plugins/mcp-ai-wpoos/bin/llama.cpp/', 'mcp-ai-wpoos' ),
					'https://github.com/ggerganov/llama.cpp/releases/latest'
				);
			} elseif ( 'Darwin' === $os ) {
				$instructions .= sprintf(
					/* translators: %s: Download URL */
					__( 'Download the pre-compiled macOS binary from %s, or install via Homebrew: brew install llama.cpp. Place the binary in wp-content/plugins/mcp-ai-wpoos/bin/llama.cpp/', 'mcp-ai-wpoos' ),
					'https://github.com/ggerganov/llama.cpp/releases/latest'
				);
			} else {
				$instructions .= sprintf(
					/* translators: 1: OS name, 2: Architecture */
					__( 'Your platform (%1$s %2$s) requires manual installation. Please download from https://github.com/ggerganov/llama.cpp/releases/latest or compile from source.', 'mcp-ai-wpoos' ),
					$os,
					$arch_normalized
				);
			}

			return $instructions;
		}

		/**
		 * Create a chat completion using the embedded model.
		 *
		 * @param array $messages Array of message objects.
		 * @param array $options  Optional parameters.
		 * @return array|WP_Error Normalized response or error.
		 */
		public function create_chat_completion( $messages, $options = array() ) {
			$model = $this->get_model();

			if ( empty( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_model_selected',
					__( 'No embedded model selected.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( ! $this->is_model_downloaded( $model ) ) {
				return new WP_Error(
					'wp_mcp_ai_model_not_downloaded',
					__( 'Model not downloaded. Please download the model first.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Get model file path.
			$downloaded_models = $this->get_downloaded_models();
			$model_filepath    = $downloaded_models[ $model ]['filepath'];

			// Get inference binary.
			$binary = $this->get_inference_binary();
			if ( is_wp_error( $binary ) ) {
				return $binary;
			}

			// Build prompt from messages.
			$prompt = $this->build_prompt( $messages );

			// Prepare inference command.
			$max_tokens  = isset( $options['max_tokens'] ) ? intval( $options['max_tokens'] ) : 512;
			$temperature = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : 0.7;
			$top_p       = isset( $options['top_p'] ) ? floatval( $options['top_p'] ) : 0.9;

			// Escape shell arguments.
			$binary_escaped = escapeshellarg( $binary );
			$model_escaped  = escapeshellarg( $model_filepath );
			$prompt_escaped = escapeshellarg( $prompt );

			// Build command.
			$command = sprintf(
				'%s -m %s -p %s -n %d --temp %.2f --top-p %.2f -c 2048 2>&1',
				$binary_escaped,
				$model_escaped,
				$prompt_escaped,
				$max_tokens,
				$temperature,
				$top_p
			);

			WP_MCP_AI_Logger::log_event(
				'embedded_inference_start',
				'Starting embedded model inference.',
				array(
					'model'      => $model,
					'max_tokens' => $max_tokens,
				)
			);

			// Check if shell_exec is available.
			if ( ! function_exists( 'shell_exec' ) || $this->is_shell_exec_disabled() ) {
				return new WP_Error(
					'wp_mcp_ai_shell_exec_disabled',
					__( 'shell_exec() function is not available. This is required for embedded model inference. Please contact your hosting provider to enable it.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			// Execute inference.
			$start_time = microtime( true );
			$output     = shell_exec( $command );
			$end_time   = microtime( true );

			if ( empty( $output ) ) {
				return new WP_Error(
					'wp_mcp_ai_inference_failed',
					__( 'Inference execution failed or returned no output.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			// Parse output (llama.cpp outputs the generated text directly).
			$generated_text = trim( $output );

			// Estimate token counts (rough approximation).
			$prompt_tokens     = intval( strlen( $prompt ) / 4 );
			$completion_tokens = intval( strlen( $generated_text ) / 4 );

			WP_MCP_AI_Logger::log_event(
				'embedded_inference_complete',
				'Embedded model inference completed.',
				array(
					'model'             => $model,
					'duration'          => $end_time - $start_time,
					'completion_tokens' => $completion_tokens,
				)
			);

			// Return normalized response (OpenAI-compatible format).
			return array(
				'id'      => 'emb_' . wp_generate_uuid4(),
				'object'  => 'chat.completion',
				'created' => time(),
				'model'   => $model,
				'choices' => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => $generated_text,
						),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => $prompt_tokens,
					'completion_tokens' => $completion_tokens,
					'total_tokens'      => $prompt_tokens + $completion_tokens,
				),
			);
		}

		/**
		 * Build a prompt string from messages array.
		 *
		 * @param array $messages Array of message objects.
		 * @return string Formatted prompt.
		 */
		private function build_prompt( $messages ) {
			$prompt = '';

			foreach ( $messages as $message ) {
				$role    = isset( $message['role'] ) ? $message['role'] : 'user';
				$content = isset( $message['content'] ) ? $message['content'] : '';

				if ( 'system' === $role ) {
					$prompt .= "System: {$content}\n\n";
				} elseif ( 'user' === $role ) {
					$prompt .= "User: {$content}\n\n";
				} elseif ( 'assistant' === $role ) {
					$prompt .= "Assistant: {$content}\n\n";
				}
			}

			$prompt .= 'Assistant: ';

			return $prompt;
		}

		/**
		 * Resolve timeout for HTTP requests.
		 *
		 * @param array $options Request options.
		 * @return int Timeout in seconds.
		 */
		private function resolve_timeout( $options ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// For embedded inference, use much longer timeout (local processing).
			$timeout = isset( $settings['request_timeout'] ) ? intval( $settings['request_timeout'] ) : 300;

			// Minimum 120 seconds for embedded inference.
			return max( 120, $timeout );
		}

		/**
		 * Check if shell_exec is disabled.
		 *
		 * @return bool True if shell_exec is disabled.
		 */
		private function is_shell_exec_disabled() {
			// Check if shell_exec is in the list of disabled functions.
			$disabled = ini_get( 'disable_functions' );
			if ( ! empty( $disabled ) ) {
				$disabled_functions = array_map( 'trim', explode( ',', $disabled ) );
				if ( in_array( 'shell_exec', $disabled_functions, true ) ) {
					return true;
				}
			}


			return false;
		}
	}
}

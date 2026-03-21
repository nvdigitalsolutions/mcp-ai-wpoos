<?php
/**
 * Server-Side Embedded LLM Client
 *
 * Manages lightweight GGUF model files stored in
 * wp-content/uploads/mcp-ai-wpoos/models/ and runs inference through
 * a locally-installed llama-cli binary via WP_MCP_AI_Process_Service.
 *
 * Ideal for cost-saving on simple, repetitive tasks (classification,
 * short Q&A, keyword extraction) that do not need a cloud API.
 *
 * Requirements:
 *  - PHP proc_open() must be enabled (available on VPS / dedicated servers).
 *  - At least one GGUF model must be downloaded through the admin UI.
 *  - llama-cli binary placed in the models directory or on the system PATH.
 *
 * The class falls back gracefully: callers should check is_available() before
 * use, and the language model router will return the standard "client-side only"
 * error when this client is not available, preserving existing behaviour.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {

	/**
	 * Server-side GGUF inference client.
	 */
	class WP_MCP_AI_Embedded_Client {

		/**
		 * Sub-directory inside the WordPress uploads folder.
		 */
		const MODELS_SUBDIR = 'mcp-ai-wpoos/models';

		/**
		 * Settings key for the active server-side model filename.
		 */
		const SETTING_MODEL = 'embedded_server_model';

		/**
		 * Maximum generation tokens when none are specified.
		 */
		const DEFAULT_MAX_TOKENS = 512;

		/**
		 * Default inference temperature.
		 */
		const DEFAULT_TEMPERATURE = 0.7;

		/**
		 * Pre-configured downloadable models.
		 *
		 * Keys are stable slug identifiers; filenames are the actual .gguf names.
		 *
		 * @var array
		 */
		const AVAILABLE_MODELS = array(
			'smollm2-360m-q8_0'   => array(
				'name'       => 'SmolLM2 360M Instruct (Q8_0) — ~395 MB',
				'filename'   => 'SmolLM2-360M-Instruct-Q8_0.gguf',
				'url'        => 'https://huggingface.co/bartowski/SmolLM2-360M-Instruct-GGUF/resolve/main/SmolLM2-360M-Instruct-Q8_0.gguf',
				'size_mb'    => 395,
				'min_ram_mb' => 512,
				'context'    => 2048,
				'license'    => 'Apache 2.0',
				'best_for'   => 'Basic classification, keyword extraction, simple routing',
			),
			'qwen2.5-0.5b-q8_0'  => array(
				'name'       => 'Qwen2.5 0.5B Instruct (Q8_0) — ~531 MB',
				'filename'   => 'Qwen2.5-0.5B-Instruct-Q8_0.gguf',
				'url'        => 'https://huggingface.co/bartowski/Qwen2.5-0.5B-Instruct-GGUF/resolve/main/Qwen2.5-0.5B-Instruct-Q8_0.gguf',
				'size_mb'    => 531,
				'min_ram_mb' => 768,
				'context'    => 32768,
				'license'    => 'Apache 2.0',
				'best_for'   => 'Short Q&A, summarization, simple instruction following',
			),
			'qwen2.5-1.5b-q4_k_m' => array(
				'name'       => 'Qwen2.5 1.5B Instruct (Q4_K_M) — ~986 MB',
				'filename'   => 'Qwen2.5-1.5B-Instruct-Q4_K_M.gguf',
				'url'        => 'https://huggingface.co/bartowski/Qwen2.5-1.5B-Instruct-GGUF/resolve/main/Qwen2.5-1.5B-Instruct-Q4_K_M.gguf',
				'size_mb'    => 986,
				'min_ram_mb' => 1536,
				'context'    => 32768,
				'license'    => 'Apache 2.0',
				'best_for'   => 'Better quality Q&A, content generation, tool routing',
			),
		);

		/**
		 * Get the absolute path to the models directory, creating it if needed.
		 *
		 * The directory is placed inside the WordPress uploads folder so it
		 * survives plugin updates and is excluded from plugin ZIP distributions.
		 *
		 * @return string|WP_Error Absolute directory path on success, WP_Error on failure.
		 */
		public function get_models_directory() {
			$upload_dir = wp_upload_dir();

			if ( ! empty( $upload_dir['error'] ) ) {
				return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
			}

			$dir = trailingslashit( $upload_dir['basedir'] ) . self::MODELS_SUBDIR;

			if ( ! file_exists( $dir ) ) {
				if ( ! wp_mkdir_p( $dir ) ) {
					return new WP_Error(
						'mkdir_failed',
						__( 'Failed to create the models directory in the uploads folder.', 'mcp-ai-wpoos' )
					);
				}

				// Protect the directory from direct HTTP access.
				$htaccess = $dir . '/.htaccess';
				if ( ! file_exists( $htaccess ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Filesystem write outside WP admin context.
					file_put_contents( $htaccess, "Deny from all\n" );
				}
			}

			return $dir;
		}

		/**
		 * Get the full list of pre-configured downloadable models with
		 * their current download status.
		 *
		 * @return array Model info arrays keyed by slug.
		 */
		public function get_available_models() {
			$models = array();

			foreach ( self::AVAILABLE_MODELS as $slug => $info ) {
				$models[ $slug ]               = $info;
				$models[ $slug ]['slug']       = $slug;
				$models[ $slug ]['downloaded'] = $this->is_model_downloaded( $slug );

				$dir = $this->get_models_directory();
				if ( ! is_wp_error( $dir ) && $this->is_model_downloaded( $slug ) ) {
					$path = trailingslashit( $dir ) . $info['filename'];
					$models[ $slug ]['file_size'] = file_exists( $path ) ? filesize( $path ) : 0;
				}
			}

			return $models;
		}

		/**
		 * Return only the models that have been fully downloaded to disk.
		 *
		 * @return array Model info arrays keyed by slug.
		 */
		public function get_downloaded_models() {
			return array_filter(
				$this->get_available_models(),
				static function ( $model ) {
					return ! empty( $model['downloaded'] );
				}
			);
		}

		/**
		 * Check whether a specific model is already downloaded.
		 *
		 * @param string $slug Model slug.
		 * @return bool
		 */
		public function is_model_downloaded( $slug ) {
			if ( ! isset( self::AVAILABLE_MODELS[ $slug ] ) ) {
				return false;
			}

			$dir = $this->get_models_directory();
			if ( is_wp_error( $dir ) ) {
				return false;
			}

			$path = trailingslashit( $dir ) . self::AVAILABLE_MODELS[ $slug ]['filename'];
			return file_exists( $path ) && filesize( $path ) > 0;
		}

		/**
		 * Get the path to the llama-cli inference binary.
		 *
		 * Looks for the binary in this order:
		 *   1. models directory  (admin-placed binary)
		 *   2. System PATH via which/where
		 *
		 * @return string|WP_Error Absolute binary path or WP_Error if not found.
		 */
		public function get_binary_path() {
			// 1. Check models directory for a user-placed binary.
			$dir = $this->get_models_directory();
			if ( ! is_wp_error( $dir ) ) {
				$candidates = array(
					trailingslashit( $dir ) . 'llama-cli',
					trailingslashit( $dir ) . 'llama-cli.exe',
				);
				foreach ( $candidates as $candidate ) {
					if ( file_exists( $candidate ) && is_executable( $candidate ) ) {
						return $candidate;
					}
				}
			}

			// 2. Check system PATH via the process service.
			$process = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
			$path    = $process->get_command_path( 'llama-cli' );
			if ( $path ) {
				return $path;
			}

			return new WP_Error(
				'binary_not_found',
				__(
					'llama-cli binary not found. Place it in the models directory or install llama.cpp on the server PATH.',
					'mcp-ai-wpoos'
				)
			);
		}

		/**
		 * Check whether server-side inference is available on this host.
		 *
		 * Returns true only when proc_open is enabled, the binary exists,
		 * and at least one model has been downloaded.
		 *
		 * @return bool
		 */
		public function is_available() {
			// proc_open must be available.
			if ( ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) ) {
				return false;
			}

			// Binary must be locatable.
			if ( is_wp_error( $this->get_binary_path() ) ) {
				return false;
			}

			// At least one model must be downloaded.
			return ! empty( $this->get_downloaded_models() );
		}

		/**
		 * Get the path to the currently configured GGUF model file.
		 *
		 * Uses the setting value if set; otherwise falls back to the first
		 * downloaded model.
		 *
		 * @return string|WP_Error
		 */
		public function get_active_model_path() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			$slug     = ! empty( $settings[ self::SETTING_MODEL ] ) ? sanitize_key( $settings[ self::SETTING_MODEL ] ) : '';

			// If the configured model is downloaded, use it.
			if ( $slug && $this->is_model_downloaded( $slug ) ) {
				$dir = $this->get_models_directory();
				if ( ! is_wp_error( $dir ) ) {
					return trailingslashit( $dir ) . self::AVAILABLE_MODELS[ $slug ]['filename'];
				}
			}

			// Fall back to the first downloaded model.
			$downloaded = $this->get_downloaded_models();
			if ( ! empty( $downloaded ) ) {
				$first = reset( $downloaded );
				$dir   = $this->get_models_directory();
				if ( ! is_wp_error( $dir ) ) {
					return trailingslashit( $dir ) . $first['filename'];
				}
			}

			return new WP_Error(
				'no_model_available',
				__( 'No embedded model is downloaded. Use Settings → NV oOS → Providers → Embedded LLM to download a model.', 'mcp-ai-wpoos' )
			);
		}

		/**
		 * Download a model from Hugging Face and save it to the models directory.
		 *
		 * @param string $slug Model slug key from AVAILABLE_MODELS.
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function download_model( $slug ) {
			$slug = sanitize_key( $slug );

			if ( ! isset( self::AVAILABLE_MODELS[ $slug ] ) ) {
				return new WP_Error( 'invalid_model', __( 'Invalid model slug.', 'mcp-ai-wpoos' ) );
			}

			$model = self::AVAILABLE_MODELS[ $slug ];
			$dir   = $this->get_models_directory();

			if ( is_wp_error( $dir ) ) {
				return $dir;
			}

			// Increase PHP time limit for large downloads.
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Intentional: may be restricted on some hosts.
				@set_time_limit( 0 );
			}

			$temp = download_url( $model['url'] );

			if ( is_wp_error( $temp ) ) {
				return $temp;
			}

			$dest = trailingslashit( $dir ) . $model['filename'];

			// Move from temp location using the WordPress filesystem API.
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if ( $wp_filesystem && $wp_filesystem->move( $temp, $dest, true ) ) {
				// Use WordPress file permission constant for consistency.
				$wp_filesystem->chmod( $dest, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

				return array(
					'success'  => true,
					'slug'     => $slug,
					'filename' => $model['filename'],
					'path'     => $dest,
					/* translators: %s: model name */
					'message'  => sprintf( __( '%s downloaded successfully.', 'mcp-ai-wpoos' ), $model['name'] ),
				);
			}

			// WP_Filesystem move failed; try native rename as fallback.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem not available in this context.
			if ( rename( $temp, $dest ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- WP_Filesystem unavailable as fallback path.
				chmod( $dest, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
				return array(
					'success'  => true,
					'slug'     => $slug,
					'filename' => $model['filename'],
					'path'     => $dest,
					/* translators: %s: model name */
					'message'  => sprintf( __( '%s downloaded successfully.', 'mcp-ai-wpoos' ), $model['name'] ),
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup of temp file.
			@unlink( $temp );

			return new WP_Error(
				'move_failed',
				/* translators: %s: model name */
				sprintf( __( 'Could not save %s to the models directory.', 'mcp-ai-wpoos' ), $model['name'] )
			);
		}

		/**
		 * Delete a downloaded model file from the models directory.
		 *
		 * @param string $slug Model slug.
		 * @return array|WP_Error
		 */
		public function delete_model( $slug ) {
			$slug = sanitize_key( $slug );

			if ( ! isset( self::AVAILABLE_MODELS[ $slug ] ) ) {
				return new WP_Error( 'invalid_model', __( 'Invalid model slug.', 'mcp-ai-wpoos' ) );
			}

			$dir = $this->get_models_directory();
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}

			$path = trailingslashit( $dir ) . self::AVAILABLE_MODELS[ $slug ]['filename'];

			if ( ! file_exists( $path ) ) {
				return new WP_Error( 'not_found', __( 'Model file not found.', 'mcp-ai-wpoos' ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct filesystem deletion required.
			if ( ! unlink( $path ) ) {
				return new WP_Error( 'delete_failed', __( 'Could not delete the model file.', 'mcp-ai-wpoos' ) );
			}

			return array(
				'success' => true,
				'slug'    => $slug,
				/* translators: %s: model name */
				'message' => sprintf( __( '%s deleted.', 'mcp-ai-wpoos' ), self::AVAILABLE_MODELS[ $slug ]['name'] ),
			);
		}

		/**
		 * Perform a quick smoke-test of the binary + active model.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			if ( ! function_exists( 'proc_open' ) ) {
				return new WP_Error(
					'proc_open_disabled',
					__( 'proc_open() is disabled on this server. Server-side embedded inference is not available on shared hosting.', 'mcp-ai-wpoos' )
				);
			}

			$binary = $this->get_binary_path();
			if ( is_wp_error( $binary ) ) {
				return $binary;
			}

			$model_path = $this->get_active_model_path();
			if ( is_wp_error( $model_path ) ) {
				return $model_path;
			}

			$result = $this->run_inference(
				'Respond with only the word "ok".',
				'',
				array( 'max_tokens' => 8 )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return array(
				'success' => true,
				'message' => __( 'Server-side embedded inference is working.', 'mcp-ai-wpoos' ),
				'model'   => basename( $model_path ),
				'binary'  => $binary,
				'output'  => $result,
			);
		}

		/**
		 * Create a chat completion using the server-side GGUF model.
		 *
		 * Returns an OpenAI-compatible response array identical in structure to
		 * the other client implementations so the rest of the plugin is unaffected.
		 *
		 * @param array $messages Array of message arrays (role + content).
		 * @param array $options  Optional: max_tokens, temperature, top_p, model.
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			if ( ! function_exists( 'proc_open' ) ) {
				return new WP_Error(
					'proc_open_disabled',
					__( 'proc_open() is disabled. Server-side embedded inference is unavailable.', 'mcp-ai-wpoos' ),
					array( 'status' => 503 )
				);
			}

			$binary = $this->get_binary_path();
			if ( is_wp_error( $binary ) ) {
				return $binary;
			}

			$model_path = $this->get_active_model_path();
			if ( is_wp_error( $model_path ) ) {
				return $model_path;
			}

			// Extract system prompt and build user/assistant turns.
			$system_prompt  = isset( $options['system_prompt'] ) ? (string) $options['system_prompt'] : '';
			$built_prompt   = $this->build_chatml_prompt( $messages, $system_prompt );
			$max_tokens     = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : self::DEFAULT_MAX_TOKENS;
			$temperature    = isset( $options['temperature'] ) ? (float) $options['temperature'] : self::DEFAULT_TEMPERATURE;

			$text = $this->run_inference(
				$built_prompt,
				$model_path,
				array(
					'max_tokens'  => $max_tokens,
					'temperature' => $temperature,
					'top_p'       => isset( $options['top_p'] ) ? (float) $options['top_p'] : 0.9,
					'binary'      => $binary,
				)
			);

			if ( is_wp_error( $text ) ) {
				return $text;
			}

			return $this->normalize_response( $text, basename( $model_path ) );
		}

		// -----------------------------------------------------------------------
		// Private helpers
		// -----------------------------------------------------------------------

		/**
		 * Build a ChatML-formatted prompt from an array of messages.
		 *
		 * ChatML is the template understood by llama-cli's --chat-template chatml flag
		 * and by all recent GGUF chat models.
		 *
		 * @param array  $messages      Array of ['role' => ..., 'content' => ...].
		 * @param string $system_prompt Optional system prompt override.
		 * @return string Formatted prompt string.
		 */
		private function build_chatml_prompt( array $messages, $system_prompt = '' ) {
			$output = '';

			// Prepend a system turn if a system_prompt was provided and none exists in messages.
			$has_system = false;
			foreach ( $messages as $msg ) {
				if ( isset( $msg['role'] ) && 'system' === $msg['role'] ) {
					$has_system = true;
					break;
				}
			}

			if ( ! $has_system && ! empty( $system_prompt ) ) {
				$output .= "<|im_start|>system\n" . $system_prompt . "\n<|im_end|>\n";
			}

			foreach ( $messages as $msg ) {
				$role    = isset( $msg['role'] ) ? sanitize_key( $msg['role'] ) : 'user';
				$content = isset( $msg['content'] ) ? (string) $msg['content'] : '';

				// Flatten content arrays (some providers nest [{type: text, text: ...}]).
				if ( is_array( $content ) ) {
					$parts = array();
					foreach ( $content as $part ) {
						if ( isset( $part['text'] ) ) {
							$parts[] = (string) $part['text'];
						} elseif ( isset( $part['content'] ) ) {
							$parts[] = (string) $part['content'];
						}
					}
					$content = implode( ' ', $parts );
				}

				$output .= "<|im_start|>{$role}\n{$content}\n<|im_end|>\n";
			}

			// Open the assistant turn so the model continues from here.
			$output .= '<|im_start|>assistant';

			return $output;
		}

		/**
		 * Execute llama-cli with the given prompt and return the raw text output.
		 *
		 * @param string $prompt  Full prompt string (or short smoke-test string).
		 * @param string $model   Absolute path to GGUF file. Empty for test runs.
		 * @param array  $options Inference options (max_tokens, temperature, top_p, binary).
		 * @return string|WP_Error Generated text on success, WP_Error on failure.
		 */
		private function run_inference( $prompt, $model, array $options = array() ) {
			$binary      = isset( $options['binary'] ) ? $options['binary'] : $this->get_binary_path();
			$max_tokens  = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : self::DEFAULT_MAX_TOKENS;
			$temperature = isset( $options['temperature'] ) ? (float) $options['temperature'] : self::DEFAULT_TEMPERATURE;
			$top_p       = isset( $options['top_p'] ) ? (float) $options['top_p'] : 0.9;

			if ( is_wp_error( $binary ) ) {
				return $binary;
			}

			// If model is empty this is a quick binary version check.
			if ( empty( $model ) ) {
				$model = ! is_wp_error( $this->get_active_model_path() )
					? $this->get_active_model_path()
					: '';
			}

			if ( empty( $model ) ) {
				return new WP_Error( 'no_model', __( 'No model path available for inference.', 'mcp-ai-wpoos' ) );
			}

			// Build the command as an array to prevent shell injection.
			$command = array(
				$binary,
				'--model',       $model,
				'--prompt',      $prompt,
				'--predict',     (string) $max_tokens,
				'--temp',        (string) $temperature,
				'--top-p',       (string) $top_p,
				'--ctx-size',    '2048',
				'--no-display-prompt',
				'--log-disable',
				'--reverse-prompt', '<|im_end|>',
				'-e',            // escape newlines in prompt
			);

			$process = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
			$result  = $process->run(
				$command,
				array(
					'timeout' => 120,
					'cwd'     => dirname( $model ),
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Strip any trailing reverse-prompt marker and whitespace in one pass.
			$text = preg_replace( '/<\|im_end\|>[\s]*$/', '', $result['output'] );

			return trim( $text );
		}

		/**
		 * Convert raw inference output into an OpenAI-compatible response array.
		 *
		 * @param string $text  Generated text from the model.
		 * @param string $model Model filename (used as the model identifier).
		 * @return array OpenAI-format response array.
		 */
		private function normalize_response( $text, $model ) {
			return array(
				'choices'  => array(
					array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => array(
								array(
									'type' => 'text',
									'text' => $text,
								),
							),
						),
						'finish_reason' => 'stop',
					),
				),
				'provider' => 'embedded',
				'model'    => $model,
			);
		}
	}
}

<?php
/**
 * Server-side Embedded LLM client.
 *
 * Manages GGUF model files stored in wp-content/uploads/mcp-ai-wpoos/models/
 * and runs inference via the llama.cpp llama-cli binary.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
	/**
	 * Server-side embedded LLM client using GGUF models and llama.cpp.
	 *
	 * Provides model management (download, delete, list) and server-side
	 * chat completion via the llama-cli binary.
	 */
	class WP_MCP_AI_Embedded_Client {

		/**
		 * Minimum fraction of the expected model size that a downloaded file must
		 * reach before it is considered complete.  0.5 = at least 50% of the
		 * expected size.  GGUF files are binary, so any file smaller than half the
		 * documented size is almost certainly incomplete or corrupt.
		 */
		const MIN_DOWNLOAD_RATIO = 0.5;

		/**
		 * Pre-configured GGUF models available for download.
		 *
		 * Each entry contains:
		 *  - name          Human-readable display name.
		 *  - filename      Local filename on disk (under models directory).
		 *  - download_url  Direct GGUF download URL from Hugging Face.
		 *  - size_mb       Approximate file size in megabytes.
		 *  - ram_gb        Minimum RAM (GB) recommended on the server.
		 *  - description   Short description shown in the admin UI.
		 *
		 * @var array
		 */
		protected static $available_models = array(

			// -- Lightweight (< 1 GB, 2 GB RAM) --
			'qwen2-0.5b-instruct-q4_k_m'      => array(
				'name'         => 'Qwen2 0.5B Instruct (Q4_K_M)',
				'filename'     => 'qwen2-0.5b-instruct-q4_k_m.gguf',
				'download_url' => 'https://huggingface.co/Qwen/Qwen2-0.5B-Instruct-GGUF/resolve/main/qwen2-0_5b-instruct-q4_k_m.gguf',
				'size_mb'      => 352,
				'ram_gb'       => 2,
				'description'  => 'Ultra-fast, minimal RAM. Good for simple tasks.',
				'category'     => 'lightweight',
			),
			'tinyllama-1.1b-chat-q4_k_m'      => array(
				'name'         => 'TinyLlama 1.1B Chat (Q4_K_M)',
				'filename'     => 'tinyllama-1.1b-chat-v1.0.Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/TheBloke/TinyLlama-1.1B-Chat-v1.0-GGUF/resolve/main/tinyllama-1.1b-chat-v1.0.Q4_K_M.gguf',
				'size_mb'      => 669,
				'ram_gb'       => 2,
				'description'  => 'Ultra-lightweight chat model. Fast responses on modest hardware.',
				'category'     => 'lightweight',
			),
			'llama-3.2-1b-instruct-q4_k_m'    => array(
				'name'         => 'Llama 3.2 1B Instruct (Q4_K_M)',
				'filename'     => 'Llama-3.2-1B-Instruct-Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/bartowski/Llama-3.2-1B-Instruct-GGUF/resolve/main/Llama-3.2-1B-Instruct-Q4_K_M.gguf',
				'size_mb'      => 756,
				'ram_gb'       => 2,
				'description'  => 'Meta Llama 3.2 1B. Multilingual, strong for its size.',
				'category'     => 'lightweight',
			),

			// -- General (1-2 GB, 3-4 GB RAM) --
			'qwen2.5-1.5b-instruct-q4_k_m'    => array(
				'name'         => 'Qwen2.5 1.5B Instruct (Q4_K_M)',
				'filename'     => 'qwen2.5-1.5b-instruct-q4_k_m.gguf',
				'download_url' => 'https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct-GGUF/resolve/main/qwen2.5-1.5b-instruct-q4_k_m.gguf',
				'size_mb'      => 986,
				'ram_gb'       => 3,
				'description'  => 'Excellent coding and multilingual support.',
				'category'     => 'general',
			),
			'deepseek-r1-1.5b-q4_k_m'         => array(
				'name'         => 'DeepSeek-R1 1.5B (Q4_K_M)',
				'filename'     => 'DeepSeek-R1-Distill-Qwen-1.5B-Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/bartowski/DeepSeek-R1-Distill-Qwen-1.5B-GGUF/resolve/main/DeepSeek-R1-Distill-Qwen-1.5B-Q4_K_M.gguf',
				'size_mb'      => 1010,
				'ram_gb'       => 3,
				'description'  => 'Reasoning-focused model. Strong chain-of-thought capability.',
				'category'     => 'reasoning',
			),
			'smollm2-1.7b-instruct-q4_k_m'    => array(
				'name'         => 'SmolLM2 1.7B Instruct (Q4_K_M)',
				'filename'     => 'SmolLM2-1.7B-Instruct-Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/bartowski/SmolLM2-1.7B-Instruct-GGUF/resolve/main/SmolLM2-1.7B-Instruct-Q4_K_M.gguf',
				'size_mb'      => 1060,
				'ram_gb'       => 3,
				'description'  => 'HuggingFace SmolLM2. Efficient for constrained environments.',
				'category'     => 'general',
			),
			'granite-3.1-2b-instruct-q4_k_m'  => array(
				'name'         => 'IBM Granite 3.1 2B Instruct (Q4_K_M)',
				'filename'     => 'granite-3.1-2b-instruct-q4_k_m.gguf',
				'download_url' => 'https://huggingface.co/bartowski/granite-3.1-2b-instruct-GGUF/resolve/main/granite-3.1-2b-instruct-Q4_K_M.gguf',
				'size_mb'      => 1240,
				'ram_gb'       => 4,
				'description'  => 'Recommended. Best balance of speed and quality.',
				'category'     => 'general',
			),
			'gemma-2-2b-it-q4_k_m'            => array(
				'name'         => 'Google Gemma 2 2B Instruct (Q4_K_M)',
				'filename'     => 'gemma-2-2b-it-Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/bartowski/gemma-2-2b-it-GGUF/resolve/main/gemma-2-2b-it-Q4_K_M.gguf',
				'size_mb'      => 1650,
				'ram_gb'       => 4,
				'description'  => 'Google Gemma 2 2B. Compact and efficient for CPU inference.',
				'category'     => 'general',
			),
			'llama-3.2-3b-instruct-q4_k_m'    => array(
				'name'         => 'Llama 3.2 3B Instruct (Q4_K_M)',
				'filename'     => 'Llama-3.2-3B-Instruct-Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/bartowski/Llama-3.2-3B-Instruct-GGUF/resolve/main/Llama-3.2-3B-Instruct-Q4_K_M.gguf',
				'size_mb'      => 2019,
				'ram_gb'       => 4,
				'description'  => 'Meta Llama 3.2 3B. Better reasoning and multilingual.',
				'category'     => 'general',
			),

			// -- Higher Quality (2+ GB, 6+ GB RAM) --
			'phi-3.5-mini-instruct-q4_k_m'    => array(
				'name'         => 'Microsoft Phi-3.5 Mini Instruct (Q4_K_M)',
				'filename'     => 'Phi-3.5-mini-instruct-Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/bartowski/Phi-3.5-mini-instruct-GGUF/resolve/main/Phi-3.5-mini-instruct-Q4_K_M.gguf',
				'size_mb'      => 2320,
				'ram_gb'       => 6,
				'description'  => 'Latest Microsoft Phi. Strong instruction following.',
				'category'     => 'general',
			),
			'phi-3-mini-4k-instruct-q4'       => array(
				'name'         => 'Microsoft Phi-3 Mini 4K Instruct (Q4)',
				'filename'     => 'phi-3-mini-4k-instruct-q4.gguf',
				'download_url' => 'https://huggingface.co/microsoft/Phi-3-mini-4k-instruct-gguf/resolve/main/Phi-3-mini-4k-instruct-q4.gguf',
				'size_mb'      => 2300,
				'ram_gb'       => 6,
				'description'  => 'Higher quality responses. Requires more RAM.',
				'category'     => 'general',
			),
			'mistral-7b-instruct-v0.3-q4_k_m' => array(
				'name'         => 'Mistral 7B Instruct v0.3 (Q4_K_M)',
				'filename'     => 'Mistral-7B-Instruct-v0.3-Q4_K_M.gguf',
				'download_url' => 'https://huggingface.co/bartowski/Mistral-7B-Instruct-v0.3-GGUF/resolve/main/Mistral-7B-Instruct-v0.3-Q4_K_M.gguf',
				'size_mb'      => 4370,
				'ram_gb'       => 8,
				'description'  => 'High-quality general model. Best quality, requires 8 GB+ RAM.',
				'category'     => 'general',
			),
		);

		// -------------------------------------------------------------------------
		// Public API – model management
		// -------------------------------------------------------------------------

		/**
		 * Get the absolute path to the models directory.
		 *
		 * Creates the directory (with an .htaccess guard) on first call.
		 *
		 * @return string Absolute path with trailing slash.
		 */
		public function get_models_directory() {
			$upload_dir = wp_upload_dir();
			$models_dir = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-wpoos/models/';

			if ( ! is_dir( $models_dir ) ) {
				wp_mkdir_p( $models_dir );

				// Deny direct HTTP access to raw model files.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP Filesystem API unavailable at early init; writing a one-line .htaccess guard via raw PHP is the only viable approach.
				$wrote = file_put_contents( $models_dir . '.htaccess', "Options -Indexes\nDeny from all\n" );
				if ( false === $wrote ) {
					// Log but don't halt – directory was created; .htaccess is a best-effort guard.
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'embedded_htaccess_failed',
							'Could not write .htaccess to models directory.',
							array( 'dir' => $models_dir )
						);
					}
				}
			}

			return $models_dir;
		}

		/**
		 * Return the catalogue of pre-configured GGUF models.
		 *
		 * @return array Keyed by model slug.
		 */
		public function get_available_models() {
			/**
			 * Filter the available server-side GGUF models.
			 *
			 * Third-party plugins or model packs can add additional models
			 * to the registry using this filter.
			 *
			 * @since 0.1.0
			 *
			 * @param array $models Keyed by model slug.
			 */
			return apply_filters( 'nvoos_embedded_available_models', static::$available_models );
		}

		/**
		 * Sanitize a GGUF model slug.
		 *
		 * Unlike sanitize_key(), this preserves dots so that version numbers
		 * such as "3.1" or "0.5b" in GGUF slugs (e.g. granite-3.1-2b-instruct-q4_k_m)
		 * are not stripped. Only lowercase alphanumeric characters, dots, dashes,
		 * and underscores are allowed. Consecutive dots and leading/trailing dots
		 * are also removed to prevent ambiguous path segments.
		 *
		 * Note: the sanitized slug is only ever used as a key into the hardcoded
		 * $available_models catalogue, never directly as a filesystem path; the
		 * catalogue itself provides the actual filename used in file operations.
		 *
		 * @param string $slug Raw model slug.
		 * @return string Sanitized slug safe for use as an array key or file path segment.
		 */
		public static function sanitize_model_slug( $slug ) {
			// Strip anything that is not alphanumeric, a dot, a dash, or an underscore.
			$slug = preg_replace( '/[^a-z0-9.\-_]/', '', strtolower( (string) $slug ) );
			// Collapse consecutive dots and trim leading/trailing dots.
			$slug = preg_replace( '/\.{2,}/', '.', $slug );
			return trim( $slug, '.' );
		}

		/**
		 * Check whether a model slug refers to a known server-side GGUF model.
		 *
		 * Used by the REST API and shortcode to distinguish server-side GGUF
		 * models (llama.cpp) from client-side WebLLM models when both share
		 * the 'embedded' provider.
		 *
		 * @param string $slug Model slug to test (may arrive pre-sanitized via sanitize_key or raw).
		 * @return bool True if $slug is in the GGUF catalogue.
		 */
		public static function is_server_model_slug( $slug ) {
			$slug = static::sanitize_model_slug( $slug );
			return isset( static::$available_models[ $slug ] );
		}

		/**
		 * Return only the models that are already downloaded to disk.
		 *
		 * @return array Keyed by model slug; values include an extra 'file_size' key.
		 */
		public function get_downloaded_models() {
			$models_dir = $this->get_models_directory();
			$downloaded = array();

			foreach ( static::$available_models as $slug => $model ) {
				$path = $models_dir . $model['filename'];
				if ( file_exists( $path ) ) {
					$downloaded[ $slug ]              = $model;
					$downloaded[ $slug ]['file_size'] = filesize( $path );
					$downloaded[ $slug ]['modified']  = filemtime( $path );
				}
			}

			return $downloaded;
		}

		/**
		 * Check whether a specific model has been downloaded.
		 *
		 * @param string $slug Model slug.
		 * @return bool
		 */
		public function is_model_downloaded( $slug ) {
			if ( ! isset( static::$available_models[ $slug ] ) ) {
				return false;
			}

			$models_dir = $this->get_models_directory();
			$filename   = static::$available_models[ $slug ]['filename'];
			$path       = $models_dir . $filename;

			return file_exists( $path ) && filesize( $path ) > 0;
		}

		/**
		 * Download a model from Hugging Face to the models directory.
		 *
		 * Uses wp_remote_get with a generous timeout because model files are
		 * large (350 MB – 2.3 GB). Progress is not streamed; the download
		 * blocks until complete or until the timeout is reached.
		 *
		 * @param string $slug Model slug from {@see get_available_models()}.
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function download_model( $slug ) {
			if ( ! isset( static::$available_models[ $slug ] ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_download_error',
						'Embedded model download requested for unknown slug.',
						array( 'slug' => $slug )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_invalid_model',
					sprintf(
						/* translators: %s: model slug */
						__( 'Invalid model slug: %s', 'mcp-ai-wpoos' ),
						$slug
					)
				);
			}

			$model      = static::$available_models[ $slug ];
			$models_dir = $this->get_models_directory();
			$dest_path  = $models_dir . $model['filename'];

			// Skip if already fully downloaded.
			if ( file_exists( $dest_path ) && filesize( $dest_path ) > 0 ) {
				return array(
					'success'   => true,
					'message'   => __( 'Model already downloaded.', 'mcp-ai-wpoos' ),
					'file_size' => filesize( $dest_path ),
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_model_download_start',
					'Starting embedded GGUF model download.',
					array(
						'slug'     => $slug,
						'filename' => $model['filename'],
						'size_mb'  => $model['size_mb'],
					)
				);
			}

			// Stream to a temp file first, then rename (atomic-ish move).
			$tmp_path = $dest_path . '.tmp';

			// Allow larger values to be set by the caller via filter.
			$timeout = (int) apply_filters( 'wp_mcp_ai_embedded_download_timeout', 600 );

			$response = wp_remote_get(
				$model['download_url'],
				array(
					'timeout'  => $timeout,
					'stream'   => true,
					'filename' => $tmp_path,
					'headers'  => array(
						'User-Agent' => 'WP-MCP-AI/' . WP_MCP_AI_VERSION,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp file cleanup after write error; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_download_error',
						'Embedded model download failed (HTTP error).',
						array(
							'slug'  => $slug,
							'error' => $response->get_error_message(),
						)
					);
				}
				return new WP_Error(
					'wp_mcp_ai_download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Model download failed: %s', 'mcp-ai-wpoos' ),
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp file cleanup after verify error; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_download_error',
						'Embedded model download failed (non-2xx HTTP status).',
						array(
							'slug'        => $slug,
							'http_status' => $code,
						)
					);
				}
				return new WP_Error(
					'wp_mcp_ai_download_failed',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Model download failed with HTTP status %d.', 'mcp-ai-wpoos' ),
						$code
					)
				);
			}

			// Validate that the downloaded file has a reasonable size (at least
			// MIN_DOWNLOAD_RATIO of the documented size).
			$min_expected = (int) ( $model['size_mb'] * self::MIN_DOWNLOAD_RATIO * 1024 * 1024 );
			if ( ! file_exists( $tmp_path ) || filesize( $tmp_path ) < $min_expected ) {
				@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp file cleanup after extraction error; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_download_error',
						'Embedded model download incomplete or corrupt.',
						array(
							'slug'         => $slug,
							'min_expected' => $min_expected,
							'actual_size'  => file_exists( $tmp_path ) ? filesize( $tmp_path ) : 0,
						)
					);
				}
				return new WP_Error(
					'wp_mcp_ai_download_incomplete',
					__( 'Downloaded file appears incomplete or corrupt.', 'mcp-ai-wpoos' )
				);
			}

			// Atomic rename.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic file replacement via rename(); WP_Filesystem::move() is not suitable here as it requires an initialized filesystem context and does not guarantee atomicity across filesystems.
			if ( ! rename( $tmp_path, $dest_path ) ) {
				@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp file cleanup after rename failure; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_download_error',
						'Embedded model downloaded but could not be saved to disk.',
						array(
							'slug' => $slug,
							'dest' => $dest_path,
						)
					);
				}
				return new WP_Error(
					'wp_mcp_ai_download_failed',
					__( 'Could not save model file to disk.', 'mcp-ai-wpoos' )
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_model_downloaded',
					'Embedded GGUF model downloaded successfully.',
					array(
						'slug'      => $slug,
						'filename'  => $model['filename'],
						'file_size' => filesize( $dest_path ),
					)
				);
			}

			return array(
				'success'   => true,
				'message'   => __( 'Model downloaded successfully.', 'mcp-ai-wpoos' ),
				'file_size' => filesize( $dest_path ),
			);
		}

		/**
		 * Delete a downloaded model from the models directory.
		 *
		 * @param string $slug Model slug.
		 * @return array|WP_Error
		 */
		public function delete_model( $slug ) {
			if ( ! isset( static::$available_models[ $slug ] ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_delete_error',
						'Embedded model deletion requested for unknown slug.',
						array( 'slug' => $slug )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_invalid_model',
					sprintf(
						/* translators: %s: model slug */
						__( 'Invalid model slug: %s', 'mcp-ai-wpoos' ),
						$slug
					)
				);
			}

			$models_dir = $this->get_models_directory();
			$path       = $models_dir . static::$available_models[ $slug ]['filename'];

			if ( ! file_exists( $path ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_delete_error',
						'Embedded model file not found for deletion.',
						array( 'slug' => $slug )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_model_not_found',
					__( 'Model file not found.', 'mcp-ai-wpoos' )
				);
			}

			if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deleting a managed GGUF model file; WP_Filesystem::delete() requires an initialised filesystem object which is not available in this server-side model-management context.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_model_delete_error',
						'Could not delete embedded model file.',
						array(
							'slug' => $slug,
							'path' => $path,
						)
					);
				}
				return new WP_Error(
					'wp_mcp_ai_delete_failed',
					__( 'Could not delete model file.', 'mcp-ai-wpoos' )
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_model_deleted',
					'Embedded GGUF model deleted successfully.',
					array( 'slug' => $slug )
				);
			}

			return array(
				'success' => true,
				'message' => __( 'Model deleted successfully.', 'mcp-ai-wpoos' ),
			);
		}

		// -------------------------------------------------------------------------
		// Public API – binary management
		// -------------------------------------------------------------------------

		/**
		 * Return the current status of the llama-cli binary.
		 *
		 * @return array {
		 *     @type bool   $found     Whether a usable binary was located.
		 *     @type string $path      Absolute path to the binary (empty when not found).
		 *     @type string $platform  Human-readable platform string (e.g. "linux x64").
		 *     @type string $message   Short status description.
		 * }
		 */
		public function get_binary_status() {
			$platform = $this->detect_platform();
			$result   = $this->get_inference_binary();

			if ( is_wp_error( $result ) ) {
				return array(
					'found'    => false,
					'path'     => '',
					'platform' => $platform['os'] . ' ' . $platform['arch'],
					'message'  => __( 'llama-cli binary not found.', 'mcp-ai-wpoos' ),
				);
			}

			return array(
				'found'    => true,
				'path'     => $result,
				'platform' => $platform['os'] . ' ' . $platform['arch'],
				'message'  => __( 'llama-cli binary is installed.', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * Return the list of shared library files co-located with the llama-cli binary.
		 *
		 * Scans the directory that contains the llama-cli binary for files matching
		 * lib*.so or lib*.so.* (ELF shared objects and their SONAME / linker-name
		 * symlinks). On platforms other than Linux this will almost always return an
		 * empty list because shared libraries are not required there.
		 *
		 * @return array {
		 *     @type bool   $found   True when the binary directory contains at least one
		 *                           shared library file.
		 *     @type array  $libs    List of shared library filenames (basenames only),
		 *                           sorted alphabetically.
		 *     @type string $bin_dir Absolute path to the directory that was scanned, or
		 *                           empty string when the binary could not be located.
		 * }
		 */
		public function get_shared_libs_status() {
			$binary_status = $this->get_binary_status();

			if ( empty( $binary_status['found'] ) || empty( $binary_status['path'] ) ) {
				return array(
					'found'   => false,
					'libs'    => array(),
					'bin_dir' => '',
				);
			}

			$bin_dir = trailingslashit( dirname( $binary_status['path'] ) );

			// Ensure any missing SONAME names are created (or repaired via copy
			// fallback) before we scan the directory.  This auto-repairs existing
			// installations where the initial extraction ran before the symlink/
			// copy logic existed, or where symlink() was blocked by the server.
			$this->create_soname_symlinks( $bin_dir );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.glob_glob -- Listing binary files by pattern in a plugin-managed directory; WP_Filesystem has no glob equivalent and opendir/readdir would require significantly more code for simple pattern matching.
			$lib_files = glob( $bin_dir . 'lib*.so*' );
			if ( ! is_array( $lib_files ) ) {
				$lib_files = array();
			}

			$libs = array_map( 'basename', $lib_files );
			sort( $libs );

			return array(
				'found'   => ! empty( $libs ),
				'libs'    => $libs,
				'bin_dir' => $bin_dir,
			);
		}

		/**
		 * Download the llama-cli binary from the latest GitHub release.
		 *
		 * Workflow:
		 *  1. Query the GitHub Releases API to locate the most recent release.
		 *  2. Find the tar.gz asset that matches the current platform.
		 *  3. Stream the tar.gz to a temp file.
		 *  4. Extract the `llama-cli` binary from the tar.gz archive.
		 *  5. Move it into the plugin's `bin/llama.cpp/` directory and make it executable.
		 *
		 * Only supported on Linux (x64 and arm64). macOS and Windows return a
		 * WP_Error directing the user to install via Homebrew / manually.
		 *
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function download_binary() {
			$platform = $this->detect_platform();

			// Only automated download is supported on Linux.
			if ( 'linux' !== $platform['os'] ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary automated download is not supported on this platform.',
						array( 'os' => $platform['os'] )
					);
				}
				$instructions = $this->get_binary_installation_instructions( $platform['os'] );
				return new WP_Error(
					'wp_mcp_ai_binary_unsupported_platform',
					sprintf(
						/* translators: %s: manual installation instructions */
						__( 'Automated binary download is only supported on Linux servers. Please install manually:\n\n%s', 'mcp-ai-wpoos' ),
						$instructions
					)
				);
			}

			if ( 'unknown' === $platform['arch'] ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary automated download failed: unknown CPU architecture.'
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_unsupported_arch',
					__( 'Could not determine server CPU architecture. Please install llama-cli manually.', 'mcp-ai-wpoos' )
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_binary_download_start',
					'Starting embedded llama-cli binary download.',
					array(
						'os'   => $platform['os'],
						'arch' => $platform['arch'],
					)
				);
			}

			// Resolve the destination directory (plugin bin/llama.cpp/).
			$bin_dir = WP_MCP_AI_PATH . 'bin/llama.cpp/';
			if ( ! is_dir( $bin_dir ) ) {
				wp_mkdir_p( $bin_dir );
			}

			if ( ! is_writable( $bin_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking writability of the binary directory before download; WP_Filesystem::is_writable() requires an initialised filesystem object not available at this point.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary download failed: binary directory is not writable.',
						array( 'bin_dir' => $bin_dir )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_not_writable',
					sprintf(
						/* translators: %s: directory path */
						__( 'Binary directory is not writable: %s', 'mcp-ai-wpoos' ),
						$bin_dir
					)
				);
			}

			// --- Step 1: Find the download URL via GitHub API ---
			$asset_url = $this->resolve_binary_download_url( $platform );
			if ( is_wp_error( $asset_url ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary download failed: could not resolve download URL.',
						array( 'error' => $asset_url->get_error_message() )
					);
				}
				return $asset_url;
			}

			// --- Step 2: Download the tar.gz to a temp file (with .tar.gz extension for PharData) ---
			$tmp_base    = wp_tempnam( 'llama-cli-download', $bin_dir );
			$tmp_archive = $tmp_base . '.tar.gz';
			// Rename the placeholder so PharData can detect the format from the extension.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic binary replacement; WP_Filesystem::move() is not suitable here as it requires an initialized filesystem context and does not guarantee atomicity across filesystems.
			if ( ! rename( $tmp_base, $tmp_archive ) ) {
				@unlink( $tmp_base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp file cleanup after rename failure; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary download failed: could not create temporary file.'
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_temp_failed',
					__( 'Could not create temporary file for binary download.', 'mcp-ai-wpoos' )
				);
			}

			$timeout = (int) apply_filters( 'wp_mcp_ai_embedded_binary_download_timeout', 300 );

			$response = wp_remote_get(
				$asset_url,
				array(
					'timeout'  => $timeout,
					'stream'   => true,
					'filename' => $tmp_archive,
					'headers'  => array(
						'User-Agent' => 'WP-MCP-AI/' . WP_MCP_AI_VERSION,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				@unlink( $tmp_archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp archive cleanup after extraction error; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary download failed (HTTP error).',
						array( 'error' => $response->get_error_message() )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_download_failed',
					sprintf(
						/* translators: %s: error detail */
						__( 'Binary download failed: %s', 'mcp-ai-wpoos' ),
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				@unlink( $tmp_archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp archive cleanup after extract success; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary download failed (non-2xx HTTP status).',
						array( 'http_status' => $code )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_download_failed',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Binary download failed with HTTP status %d.', 'mcp-ai-wpoos' ),
						$code
					)
				);
			}

			// --- Step 3: Extract llama-cli and shared libraries from the tar.gz archive ---
			$bin_name    = 'llama-cli';
			$dest_path   = $bin_dir . $bin_name;
			$extract_err = $this->extract_binary_from_archive( $tmp_archive, $bin_name, $dest_path, $bin_dir );

			@unlink( $tmp_archive ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Final temp archive cleanup; unlink() used directly as WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.

			if ( is_wp_error( $extract_err ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary download failed during archive extraction.',
						array( 'error' => $extract_err->get_error_message() )
					);
				}
				return $extract_err;
			}

			// --- Step 4: Make it executable ---
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting execute permission on a downloaded binary; WP_Filesystem::chmod() requires an initialised filesystem object which is unavailable in this CLI/binary-management context.
			if ( ! chmod( $dest_path, 0755 ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_download_error',
						'Embedded binary extracted but could not be made executable.',
						array( 'path' => $dest_path )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_chmod_failed',
					sprintf(
						/* translators: %s: absolute path to binary file */
						__( 'Binary was extracted but could not be made executable. Please run: chmod +x %s', 'mcp-ai-wpoos' ),
						$dest_path
					)
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_binary_downloaded',
					'Embedded llama-cli binary installed successfully.',
					array(
						'path'     => $dest_path,
						'platform' => $platform['os'] . ' ' . $platform['arch'],
					)
				);
			}

			return array(
				'success' => true,
				'message' => __( 'llama-cli binary installed successfully.', 'mcp-ai-wpoos' ),
				'path'    => $dest_path,
			);
		}

		/**
		 * Query the GitHub Releases API and return the download URL of the
		 * platform-appropriate llama.cpp tar.gz asset.
		 *
		 * @param array $platform Platform info from detect_platform().
		 * @return string|WP_Error Download URL string or WP_Error.
		 */
		private function resolve_binary_download_url( $platform ) {
			$api_url  = 'https://api.github.com/repos/ggml-org/llama.cpp/releases/latest';
			$response = wp_remote_get(
				$api_url,
				array(
					'timeout' => 15,
					'headers' => array(
						'User-Agent' => 'WP-MCP-AI/' . WP_MCP_AI_VERSION,
						'Accept'     => 'application/vnd.github+json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_github_api_failed',
					sprintf(
						/* translators: %s: error detail */
						__( 'Could not reach GitHub API: %s', 'mcp-ai-wpoos' ),
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error(
					'wp_mcp_ai_github_api_failed',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'GitHub API returned status %d. Please try again later.', 'mcp-ai-wpoos' ),
						$code
					)
				);
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) || empty( $data['assets'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_github_api_failed',
					json_last_error() !== JSON_ERROR_NONE
						? sprintf(
							/* translators: %s: JSON parse error string */
							__( 'Could not parse GitHub release data (JSON error: %s).', 'mcp-ai-wpoos' ),
							json_last_error_msg()
						)
						: __( 'GitHub release data contained no assets.', 'mcp-ai-wpoos' )
				);
			}

			// Build the asset name fragment we're looking for:
			// e.g. "bin-ubuntu-x64.tar.gz" for Linux x64.
			$arch_map = array(
				'x64'   => 'x64',
				'arm64' => 'arm64',
			);
			$arch_str = isset( $arch_map[ $platform['arch'] ] ) ? $arch_map[ $platform['arch'] ] : $platform['arch'];
			$needle   = 'bin-ubuntu-' . $arch_str . '.tar.gz';

			foreach ( $data['assets'] as $asset ) {
				if ( ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( false !== stripos( $asset['name'], $needle ) ) {
					return $asset['browser_download_url'];
				}
			}

			return new WP_Error(
				'wp_mcp_ai_binary_asset_not_found',
				sprintf(
					/* translators: %1$s: asset name fragment, %2$s: release tag */
					__( 'Could not find a "%1$s" asset in release %2$s. Please install llama-cli manually.', 'mcp-ai-wpoos' ),
					$needle,
					isset( $data['tag_name'] ) ? $data['tag_name'] : 'unknown'
				)
			);
		}

		/**
		 * Extract the `llama-cli` binary and shared libraries from a downloaded tar.gz archive.
		 *
		 * The llama.cpp release tar.gz contains the binary at a path like
		 * `llama-bXXXX-bin-ubuntu-x64/llama-cli` and shared libraries such as
		 * `libmtmd.so.0`, `libllama.so`, etc. We scan all entries, copy the first
		 * file whose base name matches $bin_name to $dest_path, and copy any shared
		 * library files (matching `lib*.so` or `lib*.so.N`) into $bin_dir so the
		 * dynamic linker can find them at runtime via LD_LIBRARY_PATH.
		 *
		 * @param string $archive_path Absolute path to the downloaded tar.gz archive.
		 * @param string $bin_name     Expected binary filename (e.g. 'llama-cli').
		 * @param string $dest_path    Absolute destination path for the binary.
		 * @param string $bin_dir      Directory to extract shared libraries into.
		 *                             Defaults to the directory of $dest_path.
		 * @return true|WP_Error
		 */
		private function extract_binary_from_archive( $archive_path, $bin_name, $dest_path, $bin_dir = '' ) {
			if ( ! class_exists( 'PharData' ) ) {
				return new WP_Error(
					'wp_mcp_ai_phar_unavailable',
					__( 'PHP Phar extension is not available. Please install llama-cli manually.', 'mcp-ai-wpoos' )
				);
			}

			// Default $bin_dir to the directory that will contain the binary.
			if ( '' === $bin_dir ) {
				$bin_dir = dirname( $dest_path );
			}
			$bin_dir = trailingslashit( $bin_dir );

			try {
				$phar    = new PharData( $archive_path );
				$found   = false;
				$lib_pat = '/^lib.+\.so(\.\d+)*$/';

				foreach ( new RecursiveIteratorIterator( $phar ) as $entry ) {
					$filename = $entry->getFilename();

					if ( $filename === $bin_name ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a shared library binary to create a SONAME copy; WP_Filesystem::get_contents() requires an initialised filesystem object unavailable in this binary-management context.
						$contents = file_get_contents( $entry->getPathname() );
						if ( false === $contents ) {
							return new WP_Error(
								'wp_mcp_ai_archive_extract_failed',
								__( 'Could not read binary from archive.', 'mcp-ai-wpoos' )
							);
						}
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a shared library SONAME copy when symlink() is blocked; WP_Filesystem::put_contents() requires an initialised filesystem object unavailable in this binary-management context.
						if ( false === file_put_contents( $dest_path, $contents ) ) {
							return new WP_Error(
								'wp_mcp_ai_archive_write_failed',
								__( 'Could not write binary to disk.', 'mcp-ai-wpoos' )
							);
						}
						$found = true;
					} elseif ( 1 === preg_match( $lib_pat, $filename ) ) {
						// Extract shared libraries (e.g. libmtmd.so.0, libllama.so)
						// into the binary directory so they are found at runtime.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a shared library binary to create a linker-name copy; WP_Filesystem::get_contents() requires an initialised filesystem object unavailable in this binary-management context.
						$lib_contents = file_get_contents( $entry->getPathname() );
						if ( false !== $lib_contents ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a shared library linker-name copy when symlink() is blocked; WP_Filesystem::put_contents() requires an initialised filesystem object unavailable in this binary-management context.
							$wrote = file_put_contents( $bin_dir . $filename, $lib_contents );
							if ( false === $wrote && class_exists( 'WP_MCP_AI_Logger' ) ) {
								WP_MCP_AI_Logger::log_event(
									'embedded_lib_extract_failed',
									sprintf(
										/* translators: %s: shared library filename */
										__( 'Could not extract shared library "%s" from archive. The binary may fail at runtime if this library is required.', 'mcp-ai-wpoos' ),
										$filename
									),
									array( 'dest' => $bin_dir . $filename )
								);
							}
						}
					}
				}
			} catch ( Exception $e ) {
				return new WP_Error(
					'wp_mcp_ai_archive_open_failed',
					sprintf(
						/* translators: %s: exception message */
						__( 'Could not open downloaded archive: %s', 'mcp-ai-wpoos' ),
						$e->getMessage()
					)
				);
			}

			if ( ! $found ) {
				return new WP_Error(
					'wp_mcp_ai_binary_not_in_archive',
					sprintf(
						/* translators: %s: binary filename */
						__( 'Binary "%s" was not found inside the downloaded archive.', 'mcp-ai-wpoos' ),
						$bin_name
					)
				);
			}

			// Create SONAME symlinks for any versioned shared libraries that were
			// extracted. Recent llama.cpp releases ship lib*.so.X.Y.Z as the real
			// file and lib*.so.X / lib*.so as symlinks. PHP's PharData cannot
			// extract symlink entries from tar archives, so the SONAME
			// (e.g. libmtmd.so.0) is never written to disk, causing the binary to
			// fail with "error while loading shared libraries: libmtmd.so.0".
			$this->create_soname_symlinks( $bin_dir );

			return true;
		}

		/**
		 * Create missing SONAME and linker-name symlinks for versioned shared
		 * libraries in $bin_dir.
		 *
		 * Each llama.cpp tar.gz archive contains:
		 *  libmtmd.so.0.9.8  – actual ELF shared object
		 *  libmtmd.so.0      – symlink → libmtmd.so.0.9.8  (SONAME)
		 *  libmtmd.so        – symlink → libmtmd.so.0       (linker name)
		 *
		 * PharData silently skips symlink entries, so only the versioned file is
		 * extracted. This method scans $bin_dir for lib*.so.MAJOR.rest files and
		 * creates lib*.so.MAJOR and lib*.so symlinks when they are absent, ensuring
		 * the dynamic linker can resolve the binary's SONAME at runtime.
		 *
		 * @param string $bin_dir Absolute path to the directory (with trailing slash).
		 */
		private function create_soname_symlinks( $bin_dir ) {
			$bin_dir = trailingslashit( $bin_dir );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.glob_glob -- Listing shared library files by pattern in the binary directory; WP_Filesystem has no glob equivalent and opendir/readdir would require significantly more code for simple pattern matching.
			$versioned_libs = glob( $bin_dir . 'lib*.so.*' );
			if ( empty( $versioned_libs ) ) {
				return;
			}

			foreach ( $versioned_libs as $filepath ) {
				$basename = basename( $filepath );

				// Match lib<name>.so.<MAJOR>.<minor_rest> only.
				// lib<name>.so.0       → already a SONAME; skip (no dot after major).
				// lib<name>.so.0.9.8   → versioned; create .so.0 and .so symlinks.
				// Use [^.]+ for the name to avoid matching malformed names (e.g. lib..so.*).
				if ( ! preg_match( '/^lib[^.]+\.so\.\d+\./', $basename ) ) {
					continue;
				}

				// Extract the base prefix (lib<name>.so) and the major version number.
				// The earlier pattern guarantees this match will succeed.
				if ( ! preg_match( '/^(lib[^.]+\.so)\.(\d+)/', $basename, $m ) ) {
					continue;
				}
				$base_so     = $m[1]; // e.g. libmtmd.so.
				$major       = $m[2]; // e.g. 0.
				$soname      = $base_so . '.' . $major; // e.g. libmtmd.so.0.
				$soname_path = $bin_dir . $soname;
				$linker_path = $bin_dir . $base_so;

				// Create the SONAME name (lib*.so.MAJOR) for lib*.so.MAJOR.x.y.
				// Tries a symlink first (when the function is available and
				// permitted); falls back to a file copy when symlink() is listed
				// in PHP's disable_functions (e.g. Cloudways) or when the call
				// itself fails (e.g. permission error).
				// IMPORTANT: @symlink() does NOT suppress the E_ERROR thrown when
				// symlink is in disable_functions — only warnings/notices are
				// suppressed by @.  We must guard with function_exists() first.
				if ( ! file_exists( $soname_path ) && ! is_link( $soname_path ) ) {
					$soname_created = false;
					if ( function_exists( 'symlink' ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Creating a SONAME symlink for a shared library; WP_Filesystem has no symlink() equivalent; fallback to file copy if symlink() is blocked.
						$soname_created = @symlink( $basename, $soname_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- symlink() is blocked on some hosts (e.g. Cloudways); @ prevents a fatal and the boolean return value is checked immediately below.
					}
					if ( ! $soname_created ) {
						// symlink() unavailable or failed; copy the versioned file
						// so the dynamic linker can still find the SONAME at runtime.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading library binary for SONAME copy fallback; WP_Filesystem::get_contents() requires an initialised filesystem object unavailable here.
						$lib_data = file_get_contents( $filepath );
						if ( false === $lib_data || false === file_put_contents( $soname_path, $lib_data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing SONAME copy fallback when symlink is unavailable; WP_Filesystem::put_contents() requires an initialised filesystem object unavailable here.
							if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
								WP_MCP_AI_Logger::log_event(
									'embedded_lib_soname_failed',
									sprintf(
										/* translators: 1: SONAME filename, 2: versioned filename */
										__( 'Could not create shared library SONAME "%1$s" (tried symlink and copy of "%2$s"). The binary may fail at runtime.', 'mcp-ai-wpoos' ),
										$soname,
										$basename
									),
									array( 'path' => $soname_path )
								);
							}
						}
					}
				}

				// Create the linker-name (lib*.so) pointing at the SONAME.
				// Falls back to a file copy when symlink() is unavailable or blocked.
				// No error is logged on copy failure: the linker-name is used by
				// the compile-time linker (ld), not by the runtime loader.  The
				// runtime only needs the SONAME (lib*.so.MAJOR) to be present;
				// absent the linker-name is a minor inconvenience, not a blocker.
				if ( ! file_exists( $linker_path ) && ! is_link( $linker_path ) ) {
					$linker_created = false;
					if ( function_exists( 'symlink' ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Creating a linker-name symlink for a shared library; WP_Filesystem has no symlink() equivalent; fallback to file copy if symlink() is blocked.
						$linker_created = @symlink( $soname, $linker_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- symlink() is blocked on some hosts (e.g. Cloudways); @ prevents a fatal and the boolean return value is checked immediately below.
					}
					if ( ! $linker_created ) {
						// Copy the SONAME file (symlink or copy) to the linker name.
						if ( file_exists( $soname_path ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading library binary for linker-name copy fallback; WP_Filesystem::get_contents() requires an initialised filesystem object unavailable here.
							$soname_data = file_get_contents( $soname_path );
							if ( false !== $soname_data ) {
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing linker-name copy fallback when symlink is unavailable; WP_Filesystem::put_contents() requires an initialised filesystem object unavailable here.
								file_put_contents( $linker_path, $soname_data );
							}
						}
					}
				}
			}
		}

		// -------------------------------------------------------------------------
		// Public API – inference
		// -------------------------------------------------------------------------

		/**
		 * Test that the llama.cpp binary is reachable and executable.
		 *
		 * @return array|WP_Error
		 */
		public function test_connection() {
			$binary = $this->get_inference_binary();

			if ( is_wp_error( $binary ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_connection_test_error',
						'Embedded LLM connection test failed: binary not found.',
						array( 'error' => $binary->get_error_message() )
					);
				}
				return $binary;
			}

			// Run with --version flag; safe and quick.
			// Pass true for $use_stderr_fallback because llama-cli builds b8479+
			// write their version string to stderr rather than stdout.
			$output = $this->run_binary( $binary, array( '--version' ), true );

			if ( is_wp_error( $output ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_connection_test_error',
						'Embedded LLM connection test failed: binary execution error.',
						array( 'error' => $output->get_error_message() )
					);
				}
				return $output;
			}

			if ( '' === $output ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_connection_test_error',
						'Embedded LLM connection test failed: binary returned no output.'
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_error',
					__( 'llama-cli binary returned no output. Please verify the binary is executable.', 'mcp-ai-wpoos' )
				);
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_connection_test_success',
					'Embedded LLM connection test passed.',
					array( 'version' => trim( $output ) )
				);
			}

			return array(
				'success' => true,
				'message' => __( 'llama-cli binary is working.', 'mcp-ai-wpoos' ),
				'version' => trim( $output ),
			);
		}

		/**
		 * Run a chat completion request via the llama.cpp binary.
		 *
		 * @param array $messages Array of message objects (role/content pairs).
		 * @param array $options  Optional inference parameters:
		 *                        - model        (string) Model slug to use.
		 *                        - max_tokens   (int)    Maximum new tokens to generate.
		 *                        - temperature  (float)  Sampling temperature.
		 *                        - top_p        (float)  Top-p nucleus sampling.
		 *                        - context_size (int)    Context window size.
		 * @return array|WP_Error Array with 'choices' key or WP_Error.
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			// Resolve model slug.
			// Use sanitize_model_slug() (not sanitize_key()) to preserve dots in GGUF
			// version numbers such as "3.1" in granite-3.1-2b-instruct-q4_k_m.
			$settings   = WP_MCP_AI_Admin_Settings::get_settings();
			$model_slug = isset( $options['model'] ) ? static::sanitize_model_slug( $options['model'] ) : '';
			if ( empty( $model_slug ) ) {
				$model_slug = isset( $settings['embedded_server_model'] )
				? static::sanitize_model_slug( $settings['embedded_server_model'] )
				: '';
			}

			// Fall back to first downloaded model.
			if ( empty( $model_slug ) || ! isset( static::$available_models[ $model_slug ] ) ) {
				$downloaded = $this->get_downloaded_models();
				if ( empty( $downloaded ) ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'embedded_inference_error',
							'Server-side embedded LLM inference failed: no model downloaded.'
						);
					}
					return new WP_Error(
						'wp_mcp_ai_no_embedded_model',
						__( 'No embedded GGUF model is downloaded. Please download a model in Settings → NV oOS → Providers → Embedded LLM (Pro).', 'mcp-ai-wpoos' )
					);
				}
				reset( $downloaded );
				$model_slug = key( $downloaded );
			}

			if ( ! $this->is_model_downloaded( $model_slug ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_inference_error',
						'Server-side embedded LLM inference failed: requested model not downloaded.',
						array( 'model' => $model_slug )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_model_not_downloaded',
					sprintf(
						/* translators: %s: model name */
						__( 'Embedded model "%s" is not downloaded. Please download it in Settings → NV oOS → Providers → Embedded LLM (Pro).', 'mcp-ai-wpoos' ),
						static::$available_models[ $model_slug ]['name']
					)
				);
			}

			$binary = $this->get_inference_binary();
			if ( is_wp_error( $binary ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_inference_error',
						'Server-side embedded LLM inference failed: binary not found.',
						array(
							'model' => $model_slug,
							'error' => $binary->get_error_message(),
						)
					);
				}
				return $binary;
			}

			// Confirm the binary is still executable before starting inference.
			// Permissions may have changed between get_inference_binary()'s check and now
			// (e.g. web-server user lacks execute rights, or chmod was undone by a deploy).
			// Catching this early prevents the SSE stream from hanging indefinitely at the
			// "generating" step waiting for a process that will never start.
			if ( ! is_executable( $binary ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_inference_error',
						'Server-side embedded LLM inference failed: binary not executable.',
						array(
							'model'  => $model_slug,
							'binary' => $binary,
						)
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_not_executable',
					sprintf(
						/* translators: %s: absolute path to the llama-cli binary */
						__( 'llama-cli binary is not executable. Please run: chmod +x %s', 'mcp-ai-wpoos' ),
						$binary
					)
				);
			}

			$models_dir = $this->get_models_directory();
			$model_path = $models_dir . static::$available_models[ $model_slug ]['filename'];

			// Build inference parameters with clamped values.
			$max_tokens   = max( 1, min( isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 512, 4096 ) );
			$temperature  = max( 0.0, min( isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.7, 2.0 ) );
			$top_p        = max( 0.0, min( isset( $options['top_p'] ) ? (float) $options['top_p'] : 0.9, 1.0 ) );
			$context_size = max( 128, min( isset( $options['context_size'] ) ? (int) $options['context_size'] : 2048, 8192 ) );

			$prompt = $this->build_prompt( $messages );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_inference_start',
					'Starting server-side embedded LLM inference.',
					array(
						'model'         => $model_slug,
						'message_count' => count( $messages ),
						'max_tokens'    => $max_tokens,
						'temperature'   => $temperature,
					)
				);
			}

			// Build argument array (no shell expansion – each element is a distinct argument).
			$args = array(
				'-m',
				$model_path,
				'-p',
				$prompt,
				'-n',
				(string) $max_tokens,
				'--temp',
				number_format( $temperature, 2, '.', '' ),
				'--top-p',
				number_format( $top_p, 2, '.', '' ),
				'-c',
				(string) $context_size,
				'--no-display-prompt',
			);

			// Embedded inference via llama-cli is a long-running synchronous process.
			// Without removing the PHP execution time limit the process may be killed
			// mid-inference (default max_execution_time is often 30 s on shared hosts),
			// which drops the SSE connection and produces ERR_HTTP2_PROTOCOL_ERROR on
			// the client. ignore_user_abort(true) prevents PHP from dying if nginx closes
			// the upstream connection before inference finishes (fastcgi_read_timeout).
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: set_time_limit() may emit warnings on restricted hosts; failure is non-critical. Bounded to 300s for WordPress.org compliance.
			}
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			$output = $this->run_binary( $binary, $args );

			if ( is_wp_error( $output ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_inference_error',
						'Server-side embedded LLM inference failed during execution.',
						array(
							'model' => $model_slug,
							'error' => $output->get_error_message(),
						)
					);
				}
				return $output;
			}

			if ( '' === $output ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_inference_error',
						'Server-side embedded LLM inference produced no output.',
						array( 'model' => $model_slug )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_inference_failed',
					__( 'Embedded LLM inference produced no output. Please verify the binary and model file are valid.', 'mcp-ai-wpoos' )
				);
			}

			$content = trim( $output );

			// Log the event.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'embedded_inference_complete',
					'Server-side embedded LLM inference complete',
					array(
						'model'         => $model_slug,
						'prompt_length' => strlen( $prompt ),
						'output_length' => strlen( $content ),
					)
				);
			}

			// Return in the same shape as the other clients so the router can
			// treat it uniformly.
			return array(
				'choices' => array(
					array(
						'message'       => array(
							'role'    => 'assistant',
							'content' => $content,
						),
						'finish_reason' => 'stop',
					),
				),
				'model'   => $model_slug,
				'usage'   => array(
					'prompt_tokens'     => 0,
					'completion_tokens' => 0,
					'total_tokens'      => 0,
				),
			);
		}

		// -------------------------------------------------------------------------
		// Private helpers
		// -------------------------------------------------------------------------

		/**
		 * Execute the llama-cli binary with the supplied arguments.
		 *
		 * Uses Symfony\Component\Process\Process for robust, shell-injection-safe
		 * process management.  The command is passed as an array so no shell
		 * expansion ever occurs, matching the previous proc_open behaviour.
		 *
		 * On Linux the binary's own directory is prepended to LD_LIBRARY_PATH so
		 * that shared libraries bundled alongside llama-cli (e.g. libmtmd.so.0,
		 * libllama.so) are found by the dynamic linker even when they are not
		 * installed system-wide.
		 *
		 * @param string $binary              Absolute path to the llama-cli binary.
		 * @param array  $args                Argument tokens (each a separate element).
		 * @param bool   $use_stderr_fallback When true, return stderr content as a
		 *                                    fallback when stdout is empty after a
		 *                                    successful (exit_code 0) run.  This is
		 *                                    needed for llama-cli builds b8479+ which
		 *                                    write --version output to stderr instead
		 *                                    of stdout.  Must NOT be set for inference
		 *                                    calls where stderr contains only logging.
		 * @return string|WP_Error Stdout output (or stderr fallback), or WP_Error on failure.
		 */
		private function run_binary( $binary, array $args, $use_stderr_fallback = false ) {
			if ( ! class_exists( 'Symfony\Component\Process\Process' ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_execution_error',
						'Embedded LLM binary execution failed: Symfony Process component unavailable.'
					);
				}
				return new WP_Error(
					'wp_mcp_ai_process_unavailable',
					__( 'Symfony Process component is not available. Please run composer install.', 'mcp-ai-wpoos' )
				);
			}

			$cmd = array_merge( array( $binary ), $args );

			// On Linux, prepend the binary's own directory to LD_LIBRARY_PATH so
			// shared libraries (e.g. libmtmd.so.0) that were extracted alongside
			// llama-cli are resolved without requiring a system-wide install.
			// getenv() with no arguments returns all current env vars (PHP 7.4+).
			$env      = null;
			$platform = $this->detect_platform();
			if ( 'linux' === $platform['os'] ) {
				$bin_dir     = dirname( $binary );
				$current_env = getenv();
				if ( is_array( $current_env ) ) {
					$ld_path                        = $current_env['LD_LIBRARY_PATH'] ?? '';
					$current_env['LD_LIBRARY_PATH'] = $bin_dir . ( '' !== $ld_path ? ':' . $ld_path : '' );
					$env                            = $current_env;
				}
			}

			// timeout=null means no limit; inference can take 60–120 s on slow hosts.
			// Symfony Process reads stdout and stderr alternately, which avoids the
			// pipe-buffer deadlock that sequential stream_get_contents() calls risk
			// when either stream fills the OS buffer before the other is drained.
			$process = new \Symfony\Component\Process\Process( $cmd, null, $env, null, null );

			try {
				$process->run();
			} catch ( \Symfony\Component\Process\Exception\ProcessTimedOutException $e ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_execution_error',
						'Embedded LLM binary process timed out.'
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_timeout',
					__( 'llama-cli process timed out.', 'mcp-ai-wpoos' )
				);
			} catch ( \Exception $e ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_execution_error',
						'Embedded LLM binary process could not be started.',
						array( 'exception' => $e->getMessage() )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_exec_failed',
					sprintf(
						/* translators: %s: exception message */
						__( 'Could not start llama-cli process: %s', 'mcp-ai-wpoos' ),
						$e->getMessage()
					)
				);
			}

			$exit_code = $process->getExitCode();
			$stdout    = $process->getOutput();
			$stderr    = $process->getErrorOutput();

			// Non-zero exit codes indicate a binary-level error.
			if ( 0 !== $exit_code ) {
				$error_detail = ! empty( $stderr ) ? trim( $stderr ) : 'exit code ' . $exit_code;
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'embedded_binary_execution_error',
						'Embedded LLM binary exited with non-zero exit code.',
						array( 'exit_code' => $exit_code )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_binary_error',
					sprintf(
						/* translators: %s: error detail from binary */
						__( 'llama-cli exited with error: %s', 'mcp-ai-wpoos' ),
						$error_detail
					)
				);
			}

			// Some llama-cli builds (b8479+) write --version to stderr rather than
			// stdout.  When the caller opted in to the fallback and stdout is empty
			// after a successful run, return stderr so the caller can confirm the
			// binary is working.  Inference calls must NOT use this fallback because
			// their stderr contains only progress/logging, not generated tokens.
			if ( $use_stderr_fallback && '' === (string) $stdout && '' !== (string) $stderr ) {
				return trim( $stderr );
			}

			return (string) $stdout;
		}

		/**
		 * Detect the current platform.
		 *
		 * @return array Array with 'os' (linux|darwin|windows) and 'arch' (x64|arm64|unknown) keys.
		 */
		private function detect_platform() {
			$uname = php_uname( 's' );
			$arch  = php_uname( 'm' );

			if ( stripos( $uname, 'linux' ) !== false ) {
				$os = 'linux';
			} elseif ( stripos( $uname, 'darwin' ) !== false ) {
				$os = 'darwin';
			} elseif ( stripos( $uname, 'windows' ) !== false ) {
				$os = 'windows';
			} else {
				$os = 'unknown';
			}

			if ( stripos( $arch, 'x86_64' ) !== false || stripos( $arch, 'amd64' ) !== false ) {
				$cpu_arch = 'x64';
			} elseif ( stripos( $arch, 'aarch64' ) !== false || stripos( $arch, 'arm64' ) !== false ) {
				$cpu_arch = 'arm64';
			} else {
				$cpu_arch = 'unknown';
			}

			return array(
				'os'   => $os,
				'arch' => $cpu_arch,
				'raw'  => $uname . ' ' . $arch,
			);
		}

		/**
		 * Check if the current server is hosted on Cloudways.
		 *
		 * @return bool
		 */
		private function is_cloudways_hosting() {
			if ( defined( 'CLOUDWAYS_DEPLOYMENT' ) ) {
				return true;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_getenv -- Reading LD_LIBRARY_PATH for process environment construction; getenv() is used here (not $_ENV or $_SERVER) because proc_open() requires an explicit environment array built at runtime.
			if ( getenv( 'CLOUDWAYS_DEPLOYMENT' ) ) {
				return true;
			}

			if ( file_exists( '/cloudways.yml' ) ) {
				return true;
			}

			$hostname = gethostname();
			if ( false !== $hostname && stripos( $hostname, 'cloudways' ) !== false ) {
				return true;
			}

			return false;
		}

		/**
		 * Find and return the path to the llama-cli binary.
		 *
		 * Searches in:
		 *  1. Plugin bin directory (platform-specific sub-folder).
		 *  2. Plugin bin directory (flat).
		 *  3. System PATH (/usr/local/bin, /usr/bin).
		 *  4. Configured path from settings (must be within allowed directories or
		 *     must be named 'llama-cli' / 'llama-cli.exe' to prevent misuse).
		 *
		 * @return string|WP_Error Absolute path to the binary, or WP_Error if not found.
		 */
		private function get_inference_binary() {
			$platform = $this->detect_platform();
			$bin_name = 'windows' === $platform['os'] ? 'llama-cli.exe' : 'llama-cli';

			// 1. Plugin bin directory – platform-specific sub-folder.
			$plugin_dir      = WP_MCP_AI_PATH;
			$platform_subdir = $plugin_dir . 'bin/llama.cpp/' . $platform['os'] . '-' . $platform['arch'] . '/' . $bin_name;
			if ( is_executable( $platform_subdir ) ) {
				return $platform_subdir;
			}

			// 2. Plugin bin directory – flat.
			$plugin_flat = $plugin_dir . 'bin/llama.cpp/' . $bin_name;
			if ( is_executable( $plugin_flat ) ) {
				return $plugin_flat;
			}

			// 3. System PATH locations.
			$system_paths = array(
				'/usr/local/bin/' . $bin_name,
				'/usr/bin/' . $bin_name,
				'/opt/homebrew/bin/' . $bin_name,
			);

			foreach ( $system_paths as $sys_path ) {
				if ( is_executable( $sys_path ) ) {
					return $sys_path;
				}
			}

			// 4. Settings-configured custom path.
			// Validate that the binary name is exactly 'llama-cli' or 'llama-cli.exe'
			// to prevent administrators from accidentally pointing to unrelated executables.
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$custom_path = isset( $settings['embedded_binary_path'] )
			? sanitize_text_field( $settings['embedded_binary_path'] )
			: '';

			if ( ! empty( $custom_path ) ) {
				$custom_basename = basename( $custom_path );
				if ( in_array( $custom_basename, array( 'llama-cli', 'llama-cli.exe' ), true )
					&& is_executable( $custom_path ) ) {
					return $custom_path;
				}
			}

			$instructions = $this->get_binary_installation_instructions( $platform['os'] );

			return new WP_Error(
				'wp_mcp_ai_binary_not_found',
				sprintf(
					/* translators: %s: installation instructions */
					__( 'llama-cli binary not found. Please install it:\n\n%s', 'mcp-ai-wpoos' ),
					$instructions
				)
			);
		}

		/**
		 * Return human-readable installation instructions for llama.cpp.
		 *
		 * @param string $os Operating system identifier (linux|darwin|windows|unknown).
		 * @return string Plain-text installation guide.
		 */
		private function get_binary_installation_instructions( $os ) {
			$plugin_dir = WP_MCP_AI_PATH;

			switch ( $os ) {
				case 'linux':
					return sprintf(
						/* translators: %1$s: plugin bin directory */
						__(
							"Install llama-cli on Linux:\n\n1. Download the latest release archive from:\n   https://github.com/ggml-org/llama.cpp/releases/latest\n   (download the file named like 'llama-bXXXX-bin-ubuntu-x64.tar.gz')\n\n2. Extract and install the binary and shared libraries:\n   tar -xzf llama-bXXXX-bin-ubuntu-x64.tar.gz\n   mkdir -p %1\$s\n   mv llama-bXXXX-bin-ubuntu-x64/llama-cli %1\$s/llama-cli\n   cp llama-bXXXX-bin-ubuntu-x64/lib*.so* %1\$s/ 2>/dev/null || true\n   chmod +x %1\$s/llama-cli\n\n3. Verify:\n   %1\$s/llama-cli --version",
							'mcp-ai-wpoos'
						),
						$plugin_dir . 'bin/llama.cpp'
					);

				case 'darwin':
					return __(
						"Install llama-cli on macOS:\n\n1. Via Homebrew:\n   brew install llama.cpp\n\n2. Verify:\n   llama-cli --version",
						'mcp-ai-wpoos'
					);

				case 'windows':
					return sprintf(
						/* translators: %s: plugin directory */
						__(
							"Install llama-cli on Windows:\n\n1. Download llama-cli.exe from:\n   https://github.com/ggml-org/llama.cpp/releases/latest\n\n2. Place in:\n   %s\\bin\\llama.cpp\\llama-cli.exe",
							'mcp-ai-wpoos'
						),
						$plugin_dir
					);

				default:
					return __(
						"Install llama-cli from:\nhttps://github.com/ggml-org/llama.cpp/releases/latest\n\nPlace the binary in the plugin's bin/llama.cpp/ directory.",
						'mcp-ai-wpoos'
					);
			}
		}

		/**
		 * Build a single prompt string from a messages array.
		 *
		 * Uses a simple but effective ChatML-style format that llama.cpp
		 * (llama-cli) understands out of the box.
		 *
		 * @param array $messages Array of message objects with 'role' and 'content' keys.
		 * @return string Formatted prompt.
		 */
		private function build_prompt( array $messages ) {
			$prompt = '';

			foreach ( $messages as $message ) {
				$role    = isset( $message['role'] ) ? $message['role'] : 'user';
				$content = isset( $message['content'] ) ? (string) $message['content'] : '';

				switch ( $role ) {
					case 'system':
						$prompt .= '<|im_start|>system' . "\n" . $content . '<|im_end|>' . "\n";
						break;
					case 'assistant':
						$prompt .= '<|im_start|>assistant' . "\n" . $content . '<|im_end|>' . "\n";
						break;
					case 'user':
					default:
						$prompt .= '<|im_start|>user' . "\n" . $content . '<|im_end|>' . "\n";
						break;
				}
			}

			// Append the assistant turn start so llama-cli continues from here.
			$prompt .= '<|im_start|>assistant' . "\n";

			return $prompt;
		}
	}
}

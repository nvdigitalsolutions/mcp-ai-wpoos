<?php
/**
 * Service for generating music using OpenAI Jukebox model.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Handles music generation via locally-installed OpenAI Jukebox.
 *
 * This service provides a clean separation of concerns by handling only
 * the music generation logic without WordPress-specific concerns.
 *
 * Note: Jukebox requires local installation and significant GPU resources.
 * See https://github.com/openai/jukebox for installation instructions.
 */
class WP_MCP_AI_Jukebox_Service {

	/**
	 * Default sample length in seconds.
	 */
	const DEFAULT_SAMPLE_LENGTH = 20;

	/**
	 * Maximum sample length in seconds (practical limit for reasonable processing time).
	 */
	const MAX_SAMPLE_LENGTH = 60;

	/**
	 * Default sampling temperature.
	 */
	const DEFAULT_TEMPERATURE = 0.98;

	/**
	 * Default model size.
	 */
	const DEFAULT_MODEL = '5b_lyrics'; // Options: 1b_lyrics, 5b, 5b_lyrics.

	/**
	 * Check if Jukebox is installed and available.
	 *
	 * @return array Status information with keys:
	 *               - installed: bool
	 *               - python_path: string|null
	 *               - jukebox_path: string|null
	 *               - message: string
	 */
	public function check_installation() {
		$python_path  = get_option( 'wp_mcp_ai_jukebox_python_path', 'python3' );
		$jukebox_path = get_option( 'wp_mcp_ai_jukebox_install_path', '' );

		// Validate Python path to prevent command injection.
		// Only allow common Python executable names or absolute paths.
		$allowed_python_names = array( 'python', 'python3', 'python3.7', 'python3.8', 'python3.9', 'python3.10', 'python3.11', 'python3.12' );
		$python_basename      = basename( $python_path );
		$is_absolute          = strpos( $python_path, '/' ) === 0;

		if ( ! in_array( $python_basename, $allowed_python_names, true ) && ! $is_absolute ) {
			return array(
				'installed'    => false,
				'python_path'  => null,
				'jukebox_path' => null,
				'message'      => __( 'Invalid Python path configuration. Must be a standard Python executable or absolute path.', 'wp-mcp-ai' ),
			);
		}

		// Check Python availability using Process Service.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		
		$python_result = $process_service->run_silent(
			array( $python_path, '--version' ),
			array( 'timeout' => 5 )
		);
		
		if ( ! $python_result['success'] || empty( $python_result['output'] ) || false === strpos( strtolower( $python_result['output'] ), 'python' ) ) {
			return array(
				'installed'    => false,
				'python_path'  => null,
				'jukebox_path' => null,
				'message'      => __( 'Python is not available at the configured path.', 'wp-mcp-ai' ),
			);
		}

		// Check if jukebox path is configured.
		if ( empty( $jukebox_path ) || ! is_dir( $jukebox_path ) ) {
			return array(
				'installed'    => false,
				'python_path'  => $python_path,
				'jukebox_path' => null,
				'message'      => __( 'Jukebox installation path is not configured or does not exist.', 'wp-mcp-ai' ),
			);
		}

		// Check if sample.py exists in the jukebox path.
		$sample_script = trailingslashit( $jukebox_path ) . 'jukebox/sample.py';
		if ( ! file_exists( $sample_script ) ) {
			return array(
				'installed'    => false,
				'python_path'  => $python_path,
				'jukebox_path' => $jukebox_path,
				'message'      => sprintf(
					/* translators: %s: path to sample.py */
					__( 'Jukebox sample script not found at: %s', 'wp-mcp-ai' ),
					$sample_script
				),
			);
		}

		return array(
			'installed'    => true,
			'python_path'  => $python_path,
			'jukebox_path' => $jukebox_path,
			'message'      => __( 'Jukebox is installed and available.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Generate music from a text prompt using Jukebox.
	 *
	 * @param string $prompt  Text description including optional artist, genre, and lyrics.
	 * @param array  $options Optional configuration.
	 *                        - model: string (1b_lyrics, 5b, 5b_lyrics, default: 5b_lyrics)
	 *                        - sample_length: int (seconds, default 20, max 60)
	 *                        - temperature: float (0.0-1.0, default 0.98)
	 *                        - artist: string (optional artist style to emulate)
	 *                        - genre: string (optional genre specification)
	 *                        - lyrics: string (optional lyrics to sing)
	 *                        - total_sample_length_in_seconds: int (override for sample length)
	 *                        - output_path: string (custom output directory)
	 *
	 * @return array|WP_Error Array with audio data or WP_Error on failure.
	 *                        Success array contains:
	 *                        - audio_file: string (path to generated audio file)
	 *                        - format: string (audio format, typically 'wav')
	 *                        - sample_length: int (requested duration in seconds)
	 *                        - model: string (the model used)
	 *                        - prompt: string (the prompt used)
	 */
	public function generate_music( $prompt, array $options = array() ) {
		$prompt = trim( (string) $prompt );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_jukebox_prompt',
				__( 'Jukebox generation prompt cannot be empty.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Check installation status.
		$status = $this->check_installation();
		if ( ! $status['installed'] ) {
			return new WP_Error(
				'wp_mcp_ai_jukebox_not_installed',
				$status['message'],
				array( 'status' => 503 )
			);
		}

		// Parse options.
		$model         = isset( $options['model'] ) ? sanitize_text_field( $options['model'] ) : self::DEFAULT_MODEL;
		$sample_length = isset( $options['sample_length'] ) ? absint( $options['sample_length'] ) : self::DEFAULT_SAMPLE_LENGTH;
		$temperature   = isset( $options['temperature'] ) ? floatval( $options['temperature'] ) : self::DEFAULT_TEMPERATURE;
		$artist        = isset( $options['artist'] ) ? sanitize_text_field( $options['artist'] ) : '';
		$genre         = isset( $options['genre'] ) ? sanitize_text_field( $options['genre'] ) : '';
		$lyrics        = isset( $options['lyrics'] ) ? sanitize_textarea_field( $options['lyrics'] ) : '';

		// Validate model.
		$allowed_models = array( '1b_lyrics', '5b', '5b_lyrics' );
		if ( ! in_array( $model, $allowed_models, true ) ) {
			$model = self::DEFAULT_MODEL;
		}

		// Validate sample length.
		if ( $sample_length < 1 ) {
			$sample_length = self::DEFAULT_SAMPLE_LENGTH;
		} elseif ( $sample_length > self::MAX_SAMPLE_LENGTH ) {
			$sample_length = self::MAX_SAMPLE_LENGTH;
		}

		// Validate temperature.
		if ( $temperature < 0.0 ) {
			$temperature = 0.0;
		} elseif ( $temperature > 1.0 ) {
			$temperature = 1.0;
		}

		// Build metadata JSON for conditioning.
		$metadata = array(
			'artist' => ! empty( $artist ) ? $artist : 'unknown',
			'genre'  => ! empty( $genre ) ? $genre : 'pop',
		);

		if ( ! empty( $lyrics ) ) {
			$metadata['lyrics'] = $lyrics;
		}

		// Create temporary metadata file.
		$temp_dir      = sys_get_temp_dir();
		$metadata_file = tempnam( $temp_dir, 'jukebox_metadata_' ) . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		file_put_contents( $metadata_file, wp_json_encode( $metadata ) );

		// Determine output path.
		$output_path = isset( $options['output_path'] ) && ! empty( $options['output_path'] )
			? $options['output_path']
			: wp_upload_dir()['path'];

		// Ensure output directory exists.
		if ( ! is_dir( $output_path ) ) {
			wp_mkdir_p( $output_path );
		}

		// Build command to run Jukebox.
		$python_path   = $status['python_path'];
		$jukebox_path  = $status['jukebox_path'];
		$sample_script = trailingslashit( $jukebox_path ) . 'jukebox/sample.py';

		$command = sprintf(
			'cd %s && %s %s --model=%s --name=%s --levels=3 --sample_length_in_seconds=%d --total_sample_length_in_seconds=%d --sr=44100 --n_samples=1 --hop_fraction=0.5,0.5,0.125 --mode=primed --temp=%s --metadata_file=%s 2>&1',
			escapeshellarg( $jukebox_path ),
			escapeshellcmd( $python_path ),
			escapeshellarg( $sample_script ),
			escapeshellarg( $model ),
			escapeshellarg( 'wp_mcp_ai_' . gmdate( 'Ymd_His' ) ),
			absint( $sample_length ),
			absint( $sample_length ),
			escapeshellarg( (string) $temperature ),
			escapeshellarg( $metadata_file )
		);

		WP_MCP_AI_Logger::log_event(
			'jukebox_generation_start',
			'Starting Jukebox music generation',
			array(
				'model'         => $model,
				'sample_length' => $sample_length,
				'temperature'   => $temperature,
				'prompt'        => substr( $prompt, 0, 100 ),
			)
		);

		// Execute command using Process Service.
		// PERFORMANCE NOTE: This is a long-running operation (hours for larger samples).
		// Consider running this in async mode or as a background job.
		// The timeout is set generously to allow for processing.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		
		$result = $process_service->run(
			array(
				$python_path,
				$sample_script,
				'--model=' . $model,
				'--name=wp_mcp_ai_' . gmdate( 'Ymd_His' ),
				'--levels=3',
				'--sample_length_in_seconds=' . absint( $sample_length ),
				'--total_sample_length_in_seconds=' . absint( $sample_length ),
				'--sr=44100',
				'--n_samples=1',
				'--hop_fraction=0.5,0.5,0.125',
				'--mode=primed',
				'--temp=' . (string) $temperature,
				'--metadata_file=' . $metadata_file,
			),
			array(
				'timeout' => 3600, // 1 hour timeout for music generation
				'cwd'     => $jukebox_path,
			)
		);

		// Clean up metadata file.
		if ( file_exists( $metadata_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $metadata_file );
		}

		// Check if command succeeded.
		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_event(
				'jukebox_generation_failed',
				'Jukebox generation command failed',
				array(
					'error' => $result->get_error_message(),
					'data'  => $result->get_error_data(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_jukebox_generation_failed',
				__( 'Jukebox generation failed. Check logs for details.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'error'  => $result,
				)
			);
		}

		// Parse output to find the generated audio file.
		// Jukebox typically outputs files to a 'samples' subdirectory.
		$output_text = $result['output'];
		$audio_file  = null;

		// Try to find the generated file in the output path or jukebox samples directory.
		$samples_dir = trailingslashit( $jukebox_path ) . 'samples';
		if ( is_dir( $samples_dir ) ) {
			// Find the most recently created .wav file.
			$files = glob( $samples_dir . '/*.wav' );
			if ( ! empty( $files ) ) {
				usort(
					$files,
					fn( $a, $b ) => filemtime( $b ) - filemtime( $a )
				);
				$audio_file = $files[0];
			}
		}

		if ( empty( $audio_file ) || ! file_exists( $audio_file ) ) {
			return new WP_Error(
				'wp_mcp_ai_jukebox_file_not_found',
				__( 'Jukebox completed but the generated audio file could not be found.', 'wp-mcp-ai' ),
				array(
					'status' => 500,
					'output' => $output_text,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'jukebox_generation_complete',
			'Jukebox music generation completed successfully',
			array(
				'audio_file'    => $audio_file,
				'sample_length' => $sample_length,
			)
		);

		return array(
			'audio_file'    => $audio_file,
			'format'        => 'wav',
			'sample_length' => $sample_length,
			'model'         => $model,
			'prompt'        => $prompt,
			'artist'        => $artist,
			'genre'         => $genre,
			'lyrics'        => $lyrics,
		);
	}
}

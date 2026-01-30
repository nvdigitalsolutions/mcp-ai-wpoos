<?php
/**
 * Tool that analyzes file suitability for OpenAI processing.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Analyzes if a file is suitable for OpenAI processing.
 */
class WP_MCP_AI_Tool_Analyze_File_Suitability implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum file sizes for different purposes (in bytes).
	 *
	 * @var array
	 */
	const MAX_FILE_SIZES = array(
		'assistants' => 536870912, // 512 MB.
		'fine-tune'  => 1073741824, // 1 GB.
		'batch'      => 104857600, // 100 MB.
		'vision'     => 20971520, // 20 MB.
		'whisper'    => 26214400, // 25 MB.
	);

	/**
	 * Allowed file types for different purposes.
	 *
	 * @var array
	 */
	const ALLOWED_FILE_TYPES = array(
		'assistants' => array( 'pdf', 'txt', 'md', 'json', 'csv', 'docx', 'xlsx', 'pptx' ),
		'fine-tune'  => array( 'jsonl' ),
		'batch'      => array( 'jsonl' ),
		'vision'     => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
		'whisper'    => array( 'mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm', 'flac' ),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_file_suitability';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze File Suitability', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes if a WordPress attachment file is suitable for OpenAI processing. Checks file size, format, and provides recommendations.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'file_id'       => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID to analyze.', 'mcp-ai-wpoos' ),
				),
				'purpose'       => array(
					'type'        => 'string',
					'description' => __( 'Intended purpose for the file.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'assistants', 'fine-tune', 'batch', 'vision', 'whisper' ),
					'default'     => 'assistants',
				),
				'check_content' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to perform content analysis (default: true).', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'   => array( 'file_id', 'purpose' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate file_id.
		if ( empty( $arguments['file_id'] ) ) {
			return new WP_Error(
				'missing_file_id',
				__( 'The file_id parameter is required.', 'mcp-ai-wpoos' )
			);
		}

		$file_id   = absint( $arguments['file_id'] );
		$file_path = get_attached_file( $file_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'The file could not be found.', 'mcp-ai-wpoos' )
			);
		}

		// Validate purpose.
		if ( empty( $arguments['purpose'] ) ) {
			return new WP_Error(
				'missing_purpose',
				__( 'The purpose parameter is required.', 'mcp-ai-wpoos' )
			);
		}

		$purpose = sanitize_key( $arguments['purpose'] );
		if ( ! isset( self::MAX_FILE_SIZES[ $purpose ] ) ) {
			return new WP_Error(
				'invalid_purpose',
				__( 'Invalid purpose specified.', 'mcp-ai-wpoos' )
			);
		}

		$check_content = isset( $arguments['check_content'] ) ? (bool) $arguments['check_content'] : true;

		// Perform analysis.
		$analysis = $this->analyze_file( $file_id, $file_path, $purpose, $check_content );

		// Generate user-facing message based on analysis.
		if ( $analysis['suitable'] ) {
			$message = sprintf(
				/* translators: 1: file type, 2: purpose */
				__( 'File (type: %1$s) is suitable for %2$s purpose.', 'mcp-ai-wpoos' ),
				$analysis['file_type'],
				$purpose
			);
		} else {
			$warning_count = count( $analysis['warnings'] );
			$message       = sprintf(
				/* translators: %d: number of warnings */
				_n( 'File has %d warning preventing use.', 'File has %d warnings preventing use.', $warning_count, 'mcp-ai-wpoos' ),
				$warning_count
			);
		}

		return $this->format_chat_response( $analysis, $message );
	}

	/**
	 * Analyze a file for suitability.
	 *
	 * @param int    $file_id File attachment ID.
	 * @param string $file_path Full path to the file.
	 * @param string $purpose Intended purpose.
	 * @param bool   $check_content Whether to check content.
	 * @return array Analysis results.
	 */
	private function analyze_file( $file_id, $file_path, $purpose, $check_content ) {
		$file_size = filesize( $file_path );
		$file_type = wp_check_filetype( $file_path );
		$file_ext  = $file_type['ext'];
		$mime_type = $file_type['type'];

		$max_size      = self::MAX_FILE_SIZES[ $purpose ];
		$allowed_types = self::ALLOWED_FILE_TYPES[ $purpose ];

		$warnings        = array();
		$recommendations = array();
		$suitable        = true;

		// Check file size.
		if ( $file_size > $max_size ) {
			$suitable   = false;
			$warnings[] = sprintf(
				/* translators: 1: file size, 2: max size, 3: purpose */
				__( 'File size (%1$s) exceeds maximum allowed for %3$s purpose (%2$s).', 'mcp-ai-wpoos' ),
				size_format( $file_size ),
				size_format( $max_size ),
				$purpose
			);
		} elseif ( $file_size > ( $max_size * 0.9 ) ) {
			$recommendations[] = __( 'File size is close to the maximum. Consider compressing if possible.', 'mcp-ai-wpoos' );
		}

		// Check file type.
		if ( ! in_array( $file_ext, $allowed_types, true ) ) {
			$suitable   = false;
			$warnings[] = sprintf(
				/* translators: 1: file extension, 2: purpose, 3: allowed types */
				__( 'File type "%1$s" is not supported for %2$s purpose. Allowed types: %3$s.', 'mcp-ai-wpoos' ),
				$file_ext,
				$purpose,
				implode( ', ', $allowed_types )
			);
		}

		// Content-specific checks.
		if ( $check_content && $suitable ) {
			if ( 'vision' === $purpose && in_array( $file_ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
				$this->check_image_properties( $file_path, $warnings, $recommendations );
			} elseif ( 'whisper' === $purpose ) {
				$this->check_audio_properties( $file_path, $warnings, $recommendations );
			} elseif ( in_array( $purpose, array( 'assistants', 'fine-tune', 'batch' ), true ) ) {
				$this->check_text_properties( $file_path, $file_ext, $warnings, $recommendations );
			}
		}

		// General recommendations.
		if ( $suitable ) {
			if ( empty( $warnings ) ) {
				$recommendations[] = sprintf(
					/* translators: %s: purpose */
					__( 'File is optimal for %s purpose.', 'mcp-ai-wpoos' ),
					$purpose
				);
			}
		}

		return array(
			'suitable'        => $suitable,
			'file_id'         => $file_id,
			'file_size'       => $file_size,
			'file_size_human' => size_format( $file_size ),
			'file_type'       => $file_ext,
			'mime_type'       => $mime_type,
			'purpose'         => $purpose,
			'max_size'        => $max_size,
			'max_size_human'  => size_format( $max_size ),
			'warnings'        => $warnings,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Check image properties.
	 *
	 * @param string $file_path File path.
	 * @param array  &$warnings Warnings array.
	 * @param array  &$recommendations Recommendations array.
	 */
	private function check_image_properties( $file_path, &$warnings, &$recommendations ) {
		$image_info = getimagesize( $file_path );

		if ( ! $image_info ) {
			$warnings[] = __( 'Unable to read image properties.', 'mcp-ai-wpoos' );
			return;
		}

		list( $width, $height ) = $image_info;

		// OpenAI vision prefers images not too large.
		if ( $width > 4096 || $height > 4096 ) {
			$recommendations[] = __( 'Image dimensions are very large. Consider resizing for faster processing.', 'mcp-ai-wpoos' );
		}

		// Check if image is too small.
		if ( $width < 100 || $height < 100 ) {
			$warnings[] = __( 'Image is very small. May not provide good results for vision tasks.', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Check audio properties.
	 *
	 * @param string $file_path File path.
	 * @param array  &$warnings Warnings array.
	 * @param array  &$recommendations Recommendations array.
	 */
	private function check_audio_properties( $file_path, &$warnings, &$recommendations ) {
		// Basic audio file checks (could be enhanced with actual audio library).
		$file_size = filesize( $file_path );

		// Whisper works best with clear audio.
		$recommendations[] = __( 'For best transcription results, ensure audio has minimal background noise.', 'mcp-ai-wpoos' );

		// Warn about very short files.
		if ( $file_size < 10240 ) { // Less than 10KB.
			$warnings[] = __( 'Audio file seems very short. May not contain useful content.', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Check text file properties.
	 *
	 * @param string $file_path File path.
	 * @param string $file_ext File extension.
	 * @param array  &$warnings Warnings array.
	 * @param array  &$recommendations Recommendations array.
	 */
	private function check_text_properties( $file_path, $file_ext, &$warnings, &$recommendations ) {
		if ( in_array( $file_ext, array( 'txt', 'md', 'json', 'jsonl', 'csv' ), true ) ) {
			$file_size = filesize( $file_path );

			// Check if file is empty.
			if ( $file_size < 10 ) {
				$warnings[] = __( 'File appears to be empty or nearly empty.', 'mcp-ai-wpoos' );
				return;
			}

			// For JSONL, check format.
			if ( 'jsonl' === $file_ext ) {
				$this->check_jsonl_format( $file_path, $warnings, $recommendations );
			}

			$recommendations[] = __( 'Text-based files should use UTF-8 encoding for best results.', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Check JSONL format.
	 *
	 * @param string $file_path File path.
	 * @param array  &$warnings Warnings array.
	 * @param array  &$recommendations Recommendations array.
	 */
	private function check_jsonl_format( $file_path, &$warnings, &$recommendations ) {
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			$warnings[] = __( 'Unable to read JSONL file.', 'mcp-ai-wpoos' );
			return;
		}

		$line_count = 0;
		$valid_json = 0;

		while ( ! feof( $handle ) && $line_count < 10 ) { // Check first 10 lines.
			$line = fgets( $handle );
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			++$line_count;
			$decoded = json_decode( $line, true );
			if ( null !== $decoded ) {
				++$valid_json;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( $line_count > 0 && $valid_json === 0 ) {
			$warnings[] = __( 'JSONL file does not appear to contain valid JSON lines.', 'mcp-ai-wpoos' );
		} elseif ( $valid_json < $line_count ) {
			$warnings[] = __( 'Some lines in JSONL file may not be valid JSON.', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'media_processing',

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'content_creator', 'web_developer' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'requires-capability',
		);
	}
}

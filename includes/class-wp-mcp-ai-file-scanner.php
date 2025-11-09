<?php
/**
 * File Content Scanner for WP oOS.
 *
 * Scans uploaded files for malicious content, suspicious patterns,
 * and security threats before processing.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File content security scanner.
 */
class WP_MCP_AI_File_Scanner {

	/**
	 * Malicious pattern signatures.
	 *
	 * @var array
	 */
	protected static $malware_patterns = array();

	/**
	 * Suspicious file patterns.
	 *
	 * @var array
	 */
	protected static $suspicious_patterns = array();

	/**
	 * Initialize scanner patterns.
	 */
	protected static function init_patterns() {
		if ( ! empty( self::$malware_patterns ) ) {
			return;
		}

		// Common malware/exploit patterns.
		self::$malware_patterns = array(
			'php_eval' => '/eval\s*\(/i',
			'php_base64_decode' => '/base64_decode\s*\(/i',
			'php_system' => '/(?:system|exec|passthru|shell_exec|popen|proc_open)\s*\(/i',
			'php_file_ops' => '/(?:file_get_contents|file_put_contents|fopen|fwrite)\s*\([\'"](?:https?|ftp):\/\//i',
			'sql_injection' => '/(?:union\s+select|drop\s+table|insert\s+into|delete\s+from)/i',
			'xss_script' => '/<script[^>]*>.*?<\/script>/is',
			'xss_iframe' => '/<iframe[^>]*>/i',
			'web_shell' => '/(?:c99|r57|WSO|FilesMan|IndoXploit)/',
			'php_backdoor' => '/(?:assert|preg_replace.*\/e|create_function)\s*\(/i',
		);

		// Suspicious patterns (not necessarily malware but concerning).
		self::$suspicious_patterns = array(
			'obfuscated' => '/(?:eval|assert)\s*\(\s*(?:base64_decode|gzinflate|str_rot13)/i',
			'encoded_data' => '/[a-zA-Z0-9+\/]{500,}={0,2}/', // Long base64-like strings.
			'unusual_encoding' => '/\\x[0-9a-f]{2}/i',
			'php_tags_in_upload' => '/<\?(?:php|=)/i',
			'dangerous_functions' => '/(?:curl_exec|fsockopen|socket_create)/i',
		);

		/**
		 * Filter malware detection patterns.
		 *
		 * @since 1.0.0
		 *
		 * @param array $patterns Malware patterns.
		 */
		self::$malware_patterns = apply_filters( 'wp_mcp_ai_malware_patterns', self::$malware_patterns );

		/**
		 * Filter suspicious content patterns.
		 *
		 * @since 1.0.0
		 *
		 * @param array $patterns Suspicious patterns.
		 */
		self::$suspicious_patterns = apply_filters( 'wp_mcp_ai_suspicious_patterns', self::$suspicious_patterns );
	}

	/**
	 * Scan file for threats.
	 *
	 * @param string $file_path Path to file to scan.
	 * @return array Scan result with 'safe' boolean and findings.
	 */
	public static function scan_file( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array(
				'safe'     => false,
				'error'    => 'File not accessible',
				'findings' => array(),
			);
		}

		// Get file info.
		$file_size = filesize( $file_path );
		$file_type = wp_check_filetype( $file_path );
		$mime_type = $file_type['type'];

		// Initialize result.
		$result = array(
			'safe'     => true,
			'findings' => array(),
			'metadata' => array(
				'size'      => $file_size,
				'mime_type' => $mime_type,
				'extension' => $file_type['ext'],
			),
		);

		// Check file size limit (default 10MB).
		$max_size = apply_filters( 'wp_mcp_ai_file_scan_max_size', 10 * MB_IN_BYTES );
		if ( $file_size > $max_size ) {
			$result['findings'][] = array(
				'severity' => 'warning',
				'type'     => 'file_too_large',
				'message'  => sprintf( 'File size %s exceeds maximum %s', size_format( $file_size ), size_format( $max_size ) ),
			);
		}

		// Skip binary files that can't contain PHP/script code.
		$binary_types = array( 'image/jpeg', 'image/png', 'image/gif', 'application/pdf' );
		if ( in_array( $mime_type, $binary_types, true ) ) {
			// Still check for suspicious file size.
			if ( $file_size > 50 * MB_IN_BYTES ) {
				$result['findings'][] = array(
					'severity' => 'warning',
					'type'     => 'unusually_large_binary',
					'message'  => 'Binary file is unusually large',
				);
			}
			return $result;
		}

		// Read file content (limit to 5MB for text scanning).
		$scan_limit = min( $file_size, 5 * MB_IN_BYTES );
		$content = file_get_contents( $file_path, false, null, 0, $scan_limit );

		if ( false === $content ) {
			$result['safe'] = false;
			$result['error'] = 'Failed to read file content';
			return $result;
		}

		// Scan for malware patterns.
		self::init_patterns();

		foreach ( self::$malware_patterns as $name => $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				$result['safe'] = false;
				$result['findings'][] = array(
					'severity' => 'critical',
					'type'     => 'malware_' . $name,
					'message'  => sprintf( 'Malicious pattern detected: %s', $name ),
				);
			}
		}

		// Scan for suspicious patterns.
		foreach ( self::$suspicious_patterns as $name => $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				$result['findings'][] = array(
					'severity' => 'warning',
					'type'     => 'suspicious_' . $name,
					'message'  => sprintf( 'Suspicious pattern detected: %s', $name ),
				);
			}
		}

		// Check MIME type matches extension.
		if ( ! empty( $file_type['ext'] ) ) {
			$expected_mime = self::get_expected_mime_type( $file_type['ext'] );
			if ( $expected_mime && $mime_type !== $expected_mime ) {
				$result['findings'][] = array(
					'severity' => 'warning',
					'type'     => 'mime_mismatch',
					'message'  => sprintf( 'MIME type %s does not match extension %s', $mime_type, $file_type['ext'] ),
				);
			}
		}

		// Log scan results if threats found.
		if ( ! $result['safe'] || ! empty( $result['findings'] ) ) {
			if ( class_exists( 'WP_MCP_AI_SIEM_Logger' ) ) {
				WP_MCP_AI_SIEM_Logger::log_security_event(
					WP_MCP_AI_SIEM_Logger::EVENT_FILE_SCAN,
					$result['safe'] ? 'Suspicious file detected' : 'Malicious file blocked',
					array(
						'file'     => basename( $file_path ),
						'findings' => $result['findings'],
					),
					$result['safe'] ? WP_MCP_AI_SIEM_Logger::SEVERITY_WARNING : WP_MCP_AI_SIEM_Logger::SEVERITY_CRITICAL
				);
			}

			WP_MCP_AI_Logger::log_event(
				'file_scan',
				$result['safe'] ? 'File scan completed with warnings' : 'Malicious file detected and blocked',
				array(
					'file'     => basename( $file_path ),
					'safe'     => $result['safe'],
					'findings' => $result['findings'],
				)
			);
		}

		/**
		 * Filter file scan result.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $result    Scan result.
		 * @param string $file_path Path to scanned file.
		 */
		return apply_filters( 'wp_mcp_ai_file_scan_result', $result, $file_path );
	}

	/**
	 * Get expected MIME type for file extension.
	 *
	 * @param string $extension File extension.
	 * @return string|false Expected MIME type or false.
	 */
	protected static function get_expected_mime_type( $extension ) {
		$mime_types = array(
			'txt'  => 'text/plain',
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'zip'  => 'application/zip',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'csv'  => 'text/csv',
		);

		return isset( $mime_types[ $extension ] ) ? $mime_types[ $extension ] : false;
	}

	/**
	 * Scan uploaded file (WordPress upload handler integration).
	 *
	 * @param array $file Upload file data array.
	 * @return array|WP_Error File data or error.
	 */
	public static function scan_upload( $file ) {
		if ( ! self::is_enabled() ) {
			return $file;
		}

		if ( ! isset( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
			return $file;
		}

		$scan_result = self::scan_file( $file['tmp_name'] );

		if ( ! $scan_result['safe'] ) {
			return new WP_Error(
				'file_scan_failed',
				__( 'File upload blocked: malicious content detected.', 'wp-mcp-ai' ),
				array( 'findings' => $scan_result['findings'] )
			);
		}

		// Add scan metadata to file.
		$file['wp_mcp_ai_scan'] = $scan_result;

		return $file;
	}

	/**
	 * Check if file scanning is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filter to enable/disable file scanning.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether file scanning is enabled. Default false.
		 */
		return apply_filters( 'wp_mcp_ai_file_scan_enabled', false );
	}

	/**
	 * Get scan statistics.
	 *
	 * @return array Scan statistics.
	 */
	public static function get_statistics() {
		$stats = get_option( 'wp_mcp_ai_file_scan_stats', array(
			'total_scans'     => 0,
			'threats_blocked' => 0,
			'warnings'        => 0,
			'last_scan'       => null,
		) );

		return $stats;
	}

	/**
	 * Update scan statistics.
	 *
	 * @param array $scan_result Scan result.
	 */
	protected static function update_statistics( $scan_result ) {
		$stats = self::get_statistics();

		$stats['total_scans']++;
		$stats['last_scan'] = current_time( 'mysql', true );

		if ( ! $scan_result['safe'] ) {
			$stats['threats_blocked']++;
		} elseif ( ! empty( $scan_result['findings'] ) ) {
			$stats['warnings']++;
		}

		update_option( 'wp_mcp_ai_file_scan_stats', $stats, false );
	}
}

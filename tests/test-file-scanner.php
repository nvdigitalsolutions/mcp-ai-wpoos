<?php
/**
 * Tests for File Scanner.
 *
 * @package WP_MCP_AI
 */

/**
 * File Scanner test case.
 */
class Test_File_Scanner extends WP_UnitTestCase {

	/**
	 * Test scanner is disabled by default.
	 */
	public function test_disabled_by_default() {
		$this->assertFalse( WP_MCP_AI_File_Scanner::is_enabled() );
	}

	/**
	 * Test scanning a safe text file.
	 */
	public function test_scan_safe_file() {
		$temp_file = wp_tempnam();
		file_put_contents( $temp_file, 'This is a safe text file.' );

		$result = WP_MCP_AI_File_Scanner::scan_file( $temp_file );

		$this->assertTrue( $result['safe'] );
		$this->assertEmpty( $result['findings'] );

		unlink( $temp_file );
	}

	/**
	 * Test scanning file with PHP eval.
	 */
	public function test_scan_malicious_eval() {
		$temp_file = wp_tempnam();
		file_put_contents( $temp_file, '<?php eval($_POST["cmd"]); ?>' );

		$result = WP_MCP_AI_File_Scanner::scan_file( $temp_file );

		$this->assertFalse( $result['safe'] );
		$this->assertNotEmpty( $result['findings'] );

		// Check for eval detection.
		$found_eval = false;
		foreach ( $result['findings'] as $finding ) {
			if ( strpos( $finding['type'], 'malware_php_eval' ) !== false ) {
				$found_eval = true;
				break;
			}
		}
		$this->assertTrue( $found_eval );

		unlink( $temp_file );
	}

	/**
	 * Test scanning file with base64_decode.
	 */
	public function test_scan_suspicious_base64() {
		$temp_file = wp_tempnam();
		file_put_contents( $temp_file, '<?php $code = base64_decode("encoded"); ?>' );

		$result = WP_MCP_AI_File_Scanner::scan_file( $temp_file );

		// Should detect base64_decode pattern.
		$this->assertFalse( $result['safe'] );

		unlink( $temp_file );
	}

	/**
	 * Test scanning file with XSS script.
	 */
	public function test_scan_xss_script() {
		$temp_file = wp_tempnam();
		file_put_contents( $temp_file, '<script>alert("XSS")</script>' );

		$result = WP_MCP_AI_File_Scanner::scan_file( $temp_file );

		$this->assertFalse( $result['safe'] );

		unlink( $temp_file );
	}

	/**
	 * Test scanning non-existent file.
	 */
	public function test_scan_nonexistent_file() {
		$result = WP_MCP_AI_File_Scanner::scan_file( '/nonexistent/file.txt' );

		$this->assertFalse( $result['safe'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test scan statistics.
	 */
	public function test_get_statistics() {
		$stats = WP_MCP_AI_File_Scanner::get_statistics();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_scans', $stats );
		$this->assertArrayHasKey( 'threats_blocked', $stats );
		$this->assertArrayHasKey( 'warnings', $stats );
	}

	/**
	 * Test scanning large file.
	 */
	public function test_scan_large_file() {
		$temp_file = wp_tempnam();
		// Create a 1MB file.
		$content = str_repeat( 'Safe content. ', 100000 );
		file_put_contents( $temp_file, $content );

		$result = WP_MCP_AI_File_Scanner::scan_file( $temp_file );

		// Should scan without errors.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'safe', $result );

		unlink( $temp_file );
	}
}

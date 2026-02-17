<?php
/**
 * Tests for file preprocessing helper and vector storage enhancements.
 *
 * @package WP_MCP_AI
 */

/**
 * Test File Preprocessing Helper
 */
class Test_File_Preprocessing_Helper extends WP_UnitTestCase {

	/**
	 * Test format suitability checking.
	 */
	public function test_reliable_formats_are_supported() {
		$reliable_formats = array( 'pdf', 'txt', 'md', 'json', 'docx', 'html' );

		foreach ( $reliable_formats as $format ) {
			$result = $this->check_format_via_reflection( $format, 'assistants' );
			$this->assertTrue( $result['suitable'], "Format {$format} should be suitable" );
			$this->assertEmpty( $result['warnings'], "Format {$format} should have no warnings" );
		}
	}

	/**
	 * Test unreliable formats are flagged.
	 */
	public function test_unreliable_formats_are_flagged() {
		$unreliable_formats = array( 'csv', 'xlsx', 'xls', 'pptx', 'ppt' );

		foreach ( $unreliable_formats as $format ) {
			$result = $this->check_format_via_reflection( $format, 'assistants' );
			$this->assertFalse( $result['suitable'], "Format {$format} should not be suitable" );
			$this->assertNotEmpty( $result['warnings'], "Format {$format} should have warnings" );
			$this->assertNotEmpty( $result['recommendations'], "Format {$format} should have recommendations" );
		}
	}

	/**
	 * Test UTF-8 encoding check.
	 */
	public function test_utf8_encoding_check() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-preprocessing-helper.php';

		// Create a temporary UTF-8 file.
		$temp_file = tempnam( sys_get_temp_dir(), 'utf8_test_' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp_file, 'This is UTF-8 encoded text: café, naïve, résumé' );

		$result = WP_MCP_AI_File_Preprocessing_Helper::check_file_encoding( $temp_file );

		$this->assertTrue( $result['is_utf8'], 'UTF-8 file should be detected as UTF-8' );
		$this->assertEquals( 'UTF-8', $result['encoding'] );

		// Clean up.
		unlink( $temp_file );
	}

	/**
	 * Test preprocessing recommendations are provided.
	 */
	public function test_preprocessing_recommendations_exist() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-preprocessing-helper.php';

		$formats_to_test = array( 'pdf', 'docx', 'txt', 'html', 'json', 'csv' );

		foreach ( $formats_to_test as $format ) {
			$recommendations = WP_MCP_AI_File_Preprocessing_Helper::get_preprocessing_recommendations( $format );
			$this->assertNotEmpty( $recommendations, "Format {$format} should have preprocessing recommendations" );
			$this->assertIsArray( $recommendations );
		}
	}

	/**
	 * Test chunking recommendations are provided.
	 */
	public function test_chunking_recommendations_exist() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-preprocessing-helper.php';

		$recommendations = WP_MCP_AI_File_Preprocessing_Helper::get_chunking_recommendations( 'pdf', 1024 );
		$this->assertNotEmpty( $recommendations );
		$this->assertIsArray( $recommendations );

		// Large files should get additional recommendations.
		$large_file_recommendations = WP_MCP_AI_File_Preprocessing_Helper::get_chunking_recommendations( 'pdf', 15 * 1024 * 1024 );
		$this->assertGreaterThan( count( $recommendations ), count( $large_file_recommendations ) );
	}

	/**
	 * Test file suitability tool constants.
	 */
	public function test_file_suitability_tool_constants() {
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-analyze-file-suitability.php';

		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Analyze_File_Suitability' );

		// Check ALLOWED_FILE_TYPES exists and has expected structure.
		$this->assertTrue( $reflection->hasConstant( 'ALLOWED_FILE_TYPES' ) );
		$allowed_types = $reflection->getConstant( 'ALLOWED_FILE_TYPES' );
		$this->assertIsArray( $allowed_types );
		$this->assertArrayHasKey( 'assistants', $allowed_types );

		// Check UNRELIABLE_FILE_TYPES exists.
		$this->assertTrue( $reflection->hasConstant( 'UNRELIABLE_FILE_TYPES' ) );
		$unreliable_types = $reflection->getConstant( 'UNRELIABLE_FILE_TYPES' );
		$this->assertIsArray( $unreliable_types );
		$this->assertArrayHasKey( 'assistants', $unreliable_types );

		// Verify CSV, XLSX are in unreliable, not in allowed.
		$this->assertContains( 'csv', $unreliable_types['assistants'] );
		$this->assertContains( 'xlsx', $unreliable_types['assistants'] );
		$this->assertNotContains( 'csv', $allowed_types['assistants'] );
		$this->assertNotContains( 'xlsx', $allowed_types['assistants'] );

		// Verify PDF is in allowed, not in unreliable.
		$this->assertContains( 'pdf', $allowed_types['assistants'] );
	}

	/**
	 * Helper to check format suitability via reflection.
	 *
	 * @param string $format File extension.
	 * @param string $purpose Purpose.
	 * @return array Result.
	 */
	private function check_format_via_reflection( $format, $purpose ) {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-preprocessing-helper.php';

		$reflection = new ReflectionClass( 'WP_MCP_AI_File_Preprocessing_Helper' );
		$method     = $reflection->getMethod( 'check_format_suitability' );
		$method->setAccessible( true );

		return $method->invokeArgs( null, array( $format, $purpose ) );
	}
}

<?php
/**
 * Tests for Gemini Client file support enhancements.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Gemini Client file data support.
 */
class Test_Gemini_Client_File_Support extends WP_UnitTestCase {
	/**
	 * Gemini Client instance.
	 *
	 * @var WP_MCP_AI_Gemini_Client
	 */
	protected $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
		$this->client = new WP_MCP_AI_Gemini_Client();
	}

	/**
	 * Test that extract_file_parts method exists.
	 */
	public function test_extract_file_parts_method_exists() {
		$this->assertTrue( method_exists( $this->client, 'extract_file_parts' ), 'extract_file_parts method should exist' );
	}

	/**
	 * Test that format_file_part method exists.
	 */
	public function test_format_file_part_method_exists() {
		$this->assertTrue( method_exists( $this->client, 'format_file_part' ), 'format_file_part method should exist' );
	}

	/**
	 * Test extract_file_parts returns empty array for string content.
	 */
	public function test_extract_file_parts_returns_empty_for_string() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->client, 'simple text' );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEmpty( $result, 'Should return empty array for string content' );
	}

	/**
	 * Test extract_file_parts returns empty array for numeric content.
	 */
	public function test_extract_file_parts_returns_empty_for_numeric() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->client, 123 );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEmpty( $result, 'Should return empty array for numeric content' );
	}

	/**
	 * Test extract_file_parts returns empty array for non-array content.
	 */
	public function test_extract_file_parts_returns_empty_for_invalid() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->client, null );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEmpty( $result, 'Should return empty array for null content' );
	}

	/**
	 * Test extract_file_parts extracts file segments.
	 */
	public function test_extract_file_parts_extracts_file_segments() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$content = array(
			array(
				'type' => 'text',
				'text' => 'Analyze this video',
			),
			array(
				'type'      => 'file',
				'file_uri'  => 'https://generativelanguage.googleapis.com/v1beta/files/abc123',
				'mime_type' => 'video/mp4',
			),
		);

		$result = $reflection->invoke( $this->client, $content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should extract one file part' );
		$this->assertArrayHasKey( 'fileData', $result[0], 'File part should have fileData key' );
	}

	/**
	 * Test extract_file_parts handles input_file type.
	 */
	public function test_extract_file_parts_handles_input_file_type() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$content = array(
			array(
				'type'     => 'input_file',
				'fileUri'  => 'https://generativelanguage.googleapis.com/v1beta/files/xyz789',
				'mimeType' => 'video/quicktime',
			),
		);

		$result = $reflection->invoke( $this->client, $content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should extract one file part' );
	}

	/**
	 * Test format_file_part creates valid fileData structure.
	 */
	public function test_format_file_part_creates_valid_structure() {
		$reflection = new ReflectionMethod( $this->client, 'format_file_part' );
		$reflection->setAccessible( true );

		$segment = array(
			'file_uri'  => 'https://generativelanguage.googleapis.com/v1beta/files/test123',
			'mime_type' => 'video/mp4',
		);

		$result = $reflection->invoke( $this->client, $segment );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertArrayHasKey( 'fileData', $result, 'Should have fileData key' );
		$this->assertArrayHasKey( 'fileUri', $result['fileData'], 'fileData should have fileUri' );
		$this->assertArrayHasKey( 'mimeType', $result['fileData'], 'fileData should have mimeType' );
		$this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/files/test123', $result['fileData']['fileUri'], 'fileUri should match' );
		$this->assertEquals( 'video/mp4', $result['fileData']['mimeType'], 'mimeType should match' );
	}

	/**
	 * Test format_file_part handles camelCase properties.
	 */
	public function test_format_file_part_handles_camel_case() {
		$reflection = new ReflectionMethod( $this->client, 'format_file_part' );
		$reflection->setAccessible( true );

		$segment = array(
			'fileUri'  => 'https://generativelanguage.googleapis.com/v1beta/files/test456',
			'mimeType' => 'video/webm',
		);

		$result = $reflection->invoke( $this->client, $segment );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/files/test456', $result['fileData']['fileUri'], 'Should extract camelCase fileUri' );
		$this->assertEquals( 'video/webm', $result['fileData']['mimeType'], 'Should extract camelCase mimeType' );
	}

	/**
	 * Test format_file_part handles uri property.
	 */
	public function test_format_file_part_handles_uri_property() {
		$reflection = new ReflectionMethod( $this->client, 'format_file_part' );
		$reflection->setAccessible( true );

		$segment = array(
			'uri'       => 'https://generativelanguage.googleapis.com/v1beta/files/test789',
			'mime_type' => 'video/mpeg',
		);

		$result = $reflection->invoke( $this->client, $segment );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/files/test789', $result['fileData']['fileUri'], 'Should extract uri as fileUri' );
	}

	/**
	 * Test format_file_part returns null for missing file URI.
	 */
	public function test_format_file_part_returns_null_for_missing_uri() {
		$reflection = new ReflectionMethod( $this->client, 'format_file_part' );
		$reflection->setAccessible( true );

		$segment = array(
			'mime_type' => 'video/mp4',
		);

		$result = $reflection->invoke( $this->client, $segment );

		$this->assertNull( $result, 'Should return null when file URI is missing' );
	}

	/**
	 * Test format_file_part returns null for missing MIME type.
	 */
	public function test_format_file_part_returns_null_for_missing_mime_type() {
		$reflection = new ReflectionMethod( $this->client, 'format_file_part' );
		$reflection->setAccessible( true );

		$segment = array(
			'file_uri' => 'https://generativelanguage.googleapis.com/v1beta/files/test123',
		);

		$result = $reflection->invoke( $this->client, $segment );

		$this->assertNull( $result, 'Should return null when MIME type is missing' );
	}

	/**
	 * Test extract_file_parts ignores non-file segments.
	 */
	public function test_extract_file_parts_ignores_non_file_segments() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$content = array(
			array(
				'type' => 'text',
				'text' => 'Some text',
			),
			array(
				'type'      => 'input_image',
				'image_url' => 'https://example.com/image.jpg',
			),
		);

		$result = $reflection->invoke( $this->client, $content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertEmpty( $result, 'Should not extract non-file segments' );
	}

	/**
	 * Test extract_file_parts extracts multiple files.
	 */
	public function test_extract_file_parts_extracts_multiple_files() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$content = array(
			array(
				'type'      => 'file',
				'file_uri'  => 'https://generativelanguage.googleapis.com/v1beta/files/file1',
				'mime_type' => 'video/mp4',
			),
			array(
				'type'      => 'file',
				'file_uri'  => 'https://generativelanguage.googleapis.com/v1beta/files/file2',
				'mime_type' => 'video/webm',
			),
		);

		$result = $reflection->invoke( $this->client, $content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 2, $result, 'Should extract both file parts' );
	}

	/**
	 * Test extract_file_parts skips invalid file segments.
	 */
	public function test_extract_file_parts_skips_invalid_segments() {
		$reflection = new ReflectionMethod( $this->client, 'extract_file_parts' );
		$reflection->setAccessible( true );

		$content = array(
			array(
				'type'      => 'file',
				'file_uri'  => 'https://generativelanguage.googleapis.com/v1beta/files/valid',
				'mime_type' => 'video/mp4',
			),
			array(
				'type'      => 'file',
				// Missing file_uri.
				'mime_type' => 'video/mp4',
			),
			array(
				'type'     => 'file',
				'file_uri' => 'https://generativelanguage.googleapis.com/v1beta/files/invalid',
				// Missing mime_type.
			),
		);

		$result = $reflection->invoke( $this->client, $content );

		$this->assertIsArray( $result, 'Should return array' );
		$this->assertCount( 1, $result, 'Should extract only valid file part' );
	}
}

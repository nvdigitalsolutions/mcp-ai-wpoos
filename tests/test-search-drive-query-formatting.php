<?php
/**
 * Tests for Google Drive search tool query formatting.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for search_drive tool query formatting.
 */
class Test_Search_Drive_Query_Formatting extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var WP_MCP_AI_Tool_Search_Drive
	 */
	private $tool;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the tool class.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-search-drive.php';
		$this->tool = new WP_MCP_AI_Tool_Search_Drive();
	}

	/**
	 * Test simple text query is wrapped with fullText contains.
	 */
	public function test_simple_query_formatting() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		// Simple text should be wrapped.
		$result = $method->invoke( $this->tool, '360' );
		$this->assertStringContainsString( "fullText contains '360'", $result );
		$this->assertStringContainsString( 'trashed = false', $result );
	}

	/**
	 * Test query with single quotes is properly escaped.
	 */
	public function test_query_with_single_quotes() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, "O'Reilly" );
		$this->assertStringContainsString( "fullText contains 'O\\'Reilly'", $result );
	}

	/**
	 * Test advanced query with operators is preserved.
	 */
	public function test_advanced_query_preserved() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$advanced_query = "name contains 'report'";
		$result         = $method->invoke( $this->tool, $advanced_query );

		// Should preserve the original query structure.
		$this->assertStringContainsString( "name contains 'report'", $result );
		// Should still add trash filter.
		$this->assertStringContainsString( 'trashed = false', $result );
	}

	/**
	 * Test query with mimeType operator is preserved.
	 */
	public function test_mimetype_query_preserved() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$mime_query = "mimeType = 'application/pdf'";
		$result     = $method->invoke( $this->tool, $mime_query );

		$this->assertStringContainsString( "mimeType = 'application/pdf'", $result );
		$this->assertStringContainsString( 'trashed = false', $result );
	}

	/**
	 * Test query with 'and' operator is preserved.
	 */
	public function test_and_operator_preserved() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$complex_query = "name contains 'test' and mimeType = 'application/pdf'";
		$result        = $method->invoke( $this->tool, $complex_query );

		$this->assertStringContainsString( "name contains 'test'", $result );
		$this->assertStringContainsString( "mimeType = 'application/pdf'", $result );
		$this->assertStringContainsString( 'trashed = false', $result );
	}

	/**
	 * Test explicit trashed query is not duplicated.
	 */
	public function test_explicit_trashed_not_duplicated() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$trashed_query = 'trashed = true';
		$result        = $method->invoke( $this->tool, $trashed_query );

		// Should not add another trashed filter.
		$this->assertEquals( 1, substr_count( $result, 'trashed' ) );
		$this->assertStringContainsString( 'trashed = true', $result );
	}

	/**
	 * Test item type filter for files only.
	 */
	public function test_item_type_files_only() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'apply_item_type_filter' );
		$method->setAccessible( true );

		$query  = "fullText contains 'test' and trashed = false";
		$result = $method->invoke( $this->tool, $query, 'files' );

		$this->assertStringContainsString( "mimeType != 'application/vnd.google-apps.folder'", $result );
	}

	/**
	 * Test item type filter for folders only.
	 */
	public function test_item_type_folders_only() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'apply_item_type_filter' );
		$method->setAccessible( true );

		$query  = "fullText contains 'test' and trashed = false";
		$result = $method->invoke( $this->tool, $query, 'folders' );

		$this->assertStringContainsString( "mimeType = 'application/vnd.google-apps.folder'", $result );
	}

	/**
	 * Test item type filter for all (default).
	 */
	public function test_item_type_all() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'apply_item_type_filter' );
		$method->setAccessible( true );

		$query  = "fullText contains 'test' and trashed = false";
		$result = $method->invoke( $this->tool, $query, 'all' );

		// Should not add mimeType filter.
		$this->assertStringNotContainsString( 'mimeType', $result );
		$this->assertEquals( $query, $result );
	}

	/**
	 * Test item type filter doesn't override explicit mimeType queries.
	 */
	public function test_item_type_respects_explicit_mimetype() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'apply_item_type_filter' );
		$method->setAccessible( true );

		$query  = "mimeType = 'application/pdf' and trashed = false";
		$result = $method->invoke( $this->tool, $query, 'files' );

		// Should not add duplicate mimeType filter.
		$this->assertEquals( 1, substr_count( $result, 'mimeType' ) );
		$this->assertStringContainsString( "mimeType = 'application/pdf'", $result );
	}

	/**
	 * Test URL building with formatted query.
	 */
	public function test_url_building() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'build_files_list_url' );
		$method->setAccessible( true );

		$query  = "fullText contains 'test' and trashed = false";
		$result = $method->invoke( $this->tool, $query, 10, '', false, 'modified' );

		$this->assertStringContainsString( 'https://www.googleapis.com/drive/v3/files', $result );
		$this->assertStringContainsString( 'pageSize=10', $result );
		$this->assertStringContainsString( 'orderBy=modifiedTime+desc', $result );
		// Check that query is URL encoded.
		$this->assertStringContainsString( 'q=', $result );
	}

	/**
	 * Test URL building with include_shared parameter.
	 */
	public function test_url_building_with_shared() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'build_files_list_url' );
		$method->setAccessible( true );

		$query  = "fullText contains 'test' and trashed = false";
		$result = $method->invoke( $this->tool, $query, 10, '', true, 'modified' );

		$this->assertStringContainsString( 'corpora=allDrives', $result );
		$this->assertStringContainsString( 'includeItemsFromAllDrives=true', $result );
		$this->assertStringContainsString( 'supportsAllDrives=true', $result );
	}

	/**
	 * Test URL building with sort by created time.
	 */
	public function test_url_building_sort_by_created() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'build_files_list_url' );
		$method->setAccessible( true );

		$query  = "fullText contains 'test' and trashed = false";
		$result = $method->invoke( $this->tool, $query, 10, '', false, 'created' );

		$this->assertStringContainsString( 'orderBy=createdTime+desc', $result );
	}

	/**
	 * Test query formatting with 'in parents' operator.
	 */
	public function test_in_parents_operator() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$query  = "'folder-id' in parents";
		$result = $method->invoke( $this->tool, $query );

		$this->assertStringContainsString( "'folder-id' in parents", $result );
		$this->assertStringContainsString( 'trashed = false', $result );
	}

	/**
	 * Test case-insensitive operator detection.
	 */
	public function test_case_insensitive_operators() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$query  = "name CONTAINS 'test'";
		$result = $method->invoke( $this->tool, $query );

		// Should detect CONTAINS as an operator and not wrap it.
		$this->assertStringContainsString( "name CONTAINS 'test'", $result );
		$this->assertStringNotContainsString( 'fullText contains', $result );
	}

	/**
	 * Test query with comparison operators.
	 */
	public function test_comparison_operators() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		// Test with <= operator.
		$query  = "modifiedTime <= '2024-01-01T00:00:00'";
		$result = $method->invoke( $this->tool, $query );

		$this->assertStringContainsString( "modifiedTime <= '2024-01-01T00:00:00'", $result );
		$this->assertStringNotContainsString( 'fullText contains', $result );
	}

	/**
	 * Test empty query handling.
	 */
	public function test_empty_query() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, '' );

		// Empty query should just get trash filter.
		$this->assertEquals( "fullText contains '' and trashed = false", $result );
	}

	/**
	 * Test query with backslash is fully escaped (backslash before single quote).
	 */
	public function test_query_with_backslash() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		// Input: "path\" (path + one backslash).
		// Expected in Drive query: fullText contains 'path\\' (backslash escaped to \\).
		// Using chr() to keep the expected string unambiguous regardless of PHP quoting rules.
		// chr(92)=\, chr(39)='.
		$input    = 'path' . chr( 92 );
		$result   = $method->invoke( $this->tool, $input );
		$expected = 'fullText contains ' . chr( 39 ) . 'path' . chr( 92 ) . chr( 92 ) . chr( 39 );
		$this->assertStringContainsString( $expected, $result );
	}

	/**
	 * Test query with backslash followed by single quote (escape bypass attempt).
	 */
	public function test_query_with_backslash_and_single_quote() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'format_drive_query' );
		$method->setAccessible( true );

		// Input: "test\'injection" (backslash + single-quote in the middle).
		// Expected in Drive query: fullText contains 'test\\\'injection'
		//   - \\ = escaped backslash (Drive API literal: \)
		//   - \' = escaped single-quote (Drive API literal: ')
		// Using chr() to keep the expected string unambiguous regardless of PHP quoting rules.
		// chr(92)=\, chr(39)='.
		$input            = 'test' . chr( 92 ) . chr( 39 ) . 'injection';
		$result           = $method->invoke( $this->tool, $input );
		$expected_segment = 'fullText contains '
			. chr( 39 )           // opening single-quote
			. 'test'
			. chr( 92 ) . chr( 92 ) // escaped backslash: \\
			. chr( 92 ) . chr( 39 ) // escaped single-quote: \'
			. 'injection'
			. chr( 39 );            // closing single-quote
		$this->assertStringContainsString( $expected_segment, $result );
	}
}

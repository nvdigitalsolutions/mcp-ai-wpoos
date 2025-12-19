<?php
/**
 * Memory streaming tests.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Memory_Streaming_Test extends WP_UnitTestCase {
	/**
	 * Ensure low-level readers respect byte budgets.
	 */
	public function test_read_file_contents_stops_at_byte_budget() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$rest     = new WP_MCP_AI_REST( $registry, $client );

		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'read_file_contents' );
		$method->setAccessible( true );

		$tmp_file = wp_tempnam();
		file_put_contents( $tmp_file, str_repeat( 'A', 4096 ) );

		$bytes_consumed = 0;
		$contents       = $method->invokeArgs( $rest, array( $tmp_file, 512, &$bytes_consumed ) );

		$this->assertSame( 512, strlen( $contents ) );
		$this->assertSame( 512, $bytes_consumed );

		unlink( $tmp_file );
	}

	/**
	 * Ensure prepare_memory_documents halts when byte budgets are exhausted.
	 */
	public function test_prepare_memory_documents_respects_total_byte_budget() {
		add_filter( 'wp_mcp_ai_memory_max_total_bytes', array( $this, 'limit_total_bytes' ) );
		add_filter( 'wp_mcp_ai_memory_max_document_bytes', array( $this, 'limit_document_bytes' ) );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$rest     = new WP_MCP_AI_REST( $registry, $client );

		$attachment_ids = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$upload = wp_upload_bits( "memory-{$i}.txt", null, str_repeat( 'Chunked memory line. ', 200 ) );
			$this->assertFalse( $upload['error'] );
			$attachment_ids[] = self::factory()->attachment->create_upload_object( $upload['file'] );
		}

		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'prepare_memory_documents' );
		$method->setAccessible( true );

		$documents = $method->invoke( $rest, $attachment_ids );

		remove_filter( 'wp_mcp_ai_memory_max_total_bytes', array( $this, 'limit_total_bytes' ) );
		remove_filter( 'wp_mcp_ai_memory_max_document_bytes', array( $this, 'limit_document_bytes' ) );

		$this->assertCount( 1, $documents, 'Only one document should be processed when byte budget is exhausted.' );
		$this->assertSame( $attachment_ids[0], $documents[0]['id'] );
		$this->assertArrayHasKey( 'chunks', $documents[0] );
		$this->assertNotEmpty( $documents[0]['chunks'] );
	}

	/**
	 * Ensure byte accounting reflects actual I/O even when text is truncated to the character limit.
	 */
	public function test_prepare_memory_documents_counts_bytes_before_truncation() {
		add_filter( 'wp_mcp_ai_memory_max_total_bytes', array( $this, 'limit_total_bytes_truncation' ) );
		add_filter( 'wp_mcp_ai_memory_max_document_bytes', array( $this, 'limit_document_bytes_truncation' ) );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$rest     = new WP_MCP_AI_REST( $registry, $client );

		$large_upload = wp_upload_bits( 'memory-large.txt', null, str_repeat( 'A', 20000 ) );
		$this->assertFalse( $large_upload['error'] );
		$large_id = self::factory()->attachment->create_upload_object( $large_upload['file'] );

		$second_upload = wp_upload_bits( 'memory-second.txt', null, str_repeat( 'B', 4096 ) );
		$this->assertFalse( $second_upload['error'] );
		$second_id = self::factory()->attachment->create_upload_object( $second_upload['file'] );

		$method = new ReflectionMethod( WP_MCP_AI_REST::class, 'prepare_memory_documents' );
		$method->setAccessible( true );

		$documents = $method->invoke( $rest, array( $large_id, $second_id ) );

		remove_filter( 'wp_mcp_ai_memory_max_total_bytes', array( $this, 'limit_total_bytes_truncation' ) );
		remove_filter( 'wp_mcp_ai_memory_max_document_bytes', array( $this, 'limit_document_bytes_truncation' ) );

		$this->assertCount( 1, $documents, 'Second document should be skipped once the byte budget is fully consumed.' );
		$this->assertSame( $large_id, $documents[0]['id'] );
		$this->assertArrayHasKey( 'truncated', $documents[0] );
	}

	/**
	 * Restrict total bytes for testing.
	 *
	 * @return int
	 */
	public function limit_total_bytes() {
		return 1024;
	}

	/**
	 * Restrict per-document bytes for testing.
	 *
	 * @return int
	 */
	public function limit_document_bytes() {
		return 1024;
	}

	/**
	 * Restrict total bytes for truncation accounting test.
	 *
	 * @return int
	 */
	public function limit_total_bytes_truncation() {
		return 8192;
	}

	/**
	 * Restrict per-document bytes for truncation accounting test.
	 *
	 * @return int
	 */
	public function limit_document_bytes_truncation() {
		return 65536;
	}
}

<?php
/**
 * Tests for Transcript Repository assistant_id type handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that assistant_id is properly handled as a string in queries.
 */
class Test_Transcript_Assistant_ID_Type extends WP_UnitTestCase {

	/**
	 * Test that wpdb::prepare handles string assistant_id correctly.
	 *
	 * This test verifies that our fix for the assistant_id type mismatch
	 * produces the correct SQL query format.
	 */
	public function test_wpdb_prepare_with_string_assistant_id() {
		global $wpdb;

		// Simulate the query we're using in the repository.
		$session_key  = '56e42dbe-d60c-4f99-8393-759042a4723e';
		$user_id      = 1;
		$assistant_id = 372;

		// Build the query the way the repository now does it (with %s for assistant_id).
		$where_clauses = array( 'session_key = %s', 'user_id = %d', 'assistant_id = %s' );
		$where_values  = array( $session_key, $user_id, (string) $assistant_id );
		$where_sql     = implode( ' AND ', $where_clauses );

		$query_template = "SELECT * FROM test_table WHERE {$where_sql}";
		$prepared_query = $wpdb->prepare( $query_template, $where_values );

		// Verify the query contains the assistant_id as a quoted string.
		// wpdb::prepare should produce: assistant_id = '372'.
		$this->assertStringContainsString( "assistant_id = '372'", $prepared_query );

		// Verify the session_key is also quoted.
		$this->assertStringContainsString( "session_key = '{$session_key}'", $prepared_query );

		// Verify the user_id is NOT quoted (it's an integer).
		$this->assertStringContainsString( 'user_id = 1', $prepared_query );
	}

	/**
	 * Test that the old way (using %d for assistant_id) produces different query.
	 *
	 * This demonstrates the bug we fixed.
	 */
	public function test_wpdb_prepare_with_integer_assistant_id() {
		global $wpdb;

		$session_key  = '56e42dbe-d60c-4f99-8393-759042a4723e';
		$user_id      = 1;
		$assistant_id = 372;

		// Build the query the OLD way (with %d for assistant_id).
		$where_clauses = array( 'session_key = %s', 'user_id = %d', 'assistant_id = %d' );
		$where_values  = array( $session_key, $user_id, $assistant_id );
		$where_sql     = implode( ' AND ', $where_clauses );

		$query_template = "SELECT * FROM test_table WHERE {$where_sql}";
		$prepared_query = $wpdb->prepare( $query_template, $where_values );

		// With %d, wpdb::prepare produces: assistant_id = 372 (no quotes).
		$this->assertStringContainsString( 'assistant_id = 372', $prepared_query );

		// This would fail to match if the column is defined as VARCHAR/TEXT in the database.
		$this->assertStringNotContainsString( "assistant_id = '372'", $prepared_query );
	}

	/**
	 * Test string cast ensures we get the right type.
	 */
	public function test_assistant_id_string_cast() {
		$assistant_id = 372;

		// Verify casting works.
		$this->assertIsInt( $assistant_id );
		$this->assertIsString( (string) $assistant_id );
		$this->assertEquals( '372', (string) $assistant_id );
	}
}

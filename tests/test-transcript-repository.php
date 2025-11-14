<?php
/**
 * Tests for Transcript Repository.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Transcript Repository functionality.
 */
class Test_Transcript_Repository extends WP_UnitTestCase {

	/**
	 * Transcript repository instance.
	 *
	 * @var WP_MCP_AI_Transcript_Repository
	 */
	private $repository;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the transcript repository if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Repository' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-transcript-repository.php';
		}

		$this->repository = new WP_MCP_AI_Transcript_Repository();
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		$this->repository = null;
		parent::tearDown();
	}

	/**
	 * Test that get_table_name returns empty string when JetEngine CCT is not available.
	 */
	public function test_get_table_name_without_jetengine() {
		$table_name = $this->repository->get_table_name();

		// Without JetEngine CCT class, should return empty string.
		$this->assertEquals( '', $table_name );
	}

	/**
	 * Test that table_exists returns false when JetEngine CCT is not available.
	 */
	public function test_table_exists_without_jetengine() {
		$exists = $this->repository->table_exists();

		// Without JetEngine CCT class, should return false.
		$this->assertFalse( $exists );
	}

	/**
	 * Test that delete_transcript returns false when table doesn't exist.
	 */
	public function test_delete_transcript_without_table() {
		$result = $this->repository->delete_transcript( 'test_session', 1 );

		// Without table, should return false.
		$this->assertFalse( $result );
	}

	/**
	 * Test that repository can be instantiated.
	 */
	public function test_repository_instantiation() {
		$this->assertInstanceOf( 'WP_MCP_AI_Transcript_Repository', $this->repository );
	}

	/**
	 * Test that repository has required public methods.
	 */
	public function test_repository_has_required_methods() {
		$this->assertTrue( method_exists( $this->repository, 'get_table_name' ) );
		$this->assertTrue( method_exists( $this->repository, 'table_exists' ) );
		$this->assertTrue( method_exists( $this->repository, 'delete_transcript' ) );
	}

	/**
	 * Test delete_transcript validates parameters.
	 */
	public function test_delete_transcript_accepts_correct_parameters() {
		// This test verifies the method signature is correct.
		// Even without table, it should not throw errors when called with valid params.
		$result = $this->repository->delete_transcript( 'valid_session_key', 123 );

		// Should return false (table doesn't exist), not throw error.
		$this->assertFalse( $result );
	}
}

<?php
/**
 * Test: Paper Repository — CRUD operations.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Paper_Repository
 *
 * @covers WP_MCP_AI_Paper_Repository
 */
class Test_WP_MCP_AI_Paper_Repository extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Repository under test.
	 *
	 * @var WP_MCP_AI_Paper_Repository
	 */
	private $repo;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->repo = $this->manager->get_repository( 'repo-test' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->repo = null;
		$this->tear_down_paper_store();
		parent::tearDown();
	}

	/**
	 * Save and find a record.
	 */
	public function test_save_and_find() {
		$record = $this->make_record( 'save-find' );
		$saved  = $this->repo->save( $record );

		$this->assertIsArray( $saved );
		$this->assertSame( 'save-find', $saved['id'] );

		$found = $this->repo->find( 'save-find' );
		$this->assertIsArray( $found );
		$this->assertSame( 'save-find', $found['id'] );
	}

	/**
	 * Find nonexistent record should return null.
	 */
	public function test_find_nonexistent_returns_null() {
		$this->assertNull( $this->repo->find( 'ghost' ) );
	}

	/**
	 * Exists should return correct boolean.
	 */
	public function test_exists() {
		$this->assertFalse( $this->repo->exists( 'nope' ) );

		$this->repo->save( $this->make_record( 'yep' ) );
		$this->assertTrue( $this->repo->exists( 'yep' ) );
	}

	/**
	 * Update should modify specific fields.
	 */
	public function test_update_modifies_fields() {
		$this->repo->save( $this->make_record( 'original', 'Original' ) );

		$updated = $this->repo->update( 'original', array( 'title' => 'Updated Title' ) );

		$this->assertSame( 'Updated Title', $updated['title'] );
		$this->assertSame( 'original', $updated['id'] );
	}

	/**
	 * Update nonexistent should return WP_Error.
	 */
	public function test_update_nonexistent_returns_wp_error() {
		$result = $this->repo->update( 'ghost', array( 'title' => 'Boo' ) );
		$this->assertWPError( $result );
	}

	/**
	 * Delete should remove record.
	 */
	public function test_delete_removes_record() {
		$this->repo->save( $this->make_record( 'to-delete' ) );
		$this->assertTrue( $this->repo->exists( 'to-delete' ) );

		$result = $this->repo->delete( 'to-delete' );
		$this->assertTrue( $result );
		$this->assertFalse( $this->repo->exists( 'to-delete' ) );
	}

	/**
	 * Delete nonexistent should return WP_Error.
	 */
	public function test_delete_nonexistent_returns_wp_error() {
		$result = $this->repo->delete( 'ghost' );
		$this->assertWPError( $result );
	}

	/**
	 * All should return all records.
	 */
	public function test_all() {
		$this->repo->save( $this->make_record( 'r1', 'R1' ) );
		$this->repo->save( $this->make_record( 'r2', 'R2' ) );
		$this->repo->save( $this->make_record( 'r3', 'R3' ) );

		$all = $this->repo->all();
		$this->assertCount( 3, $all );
	}

	/**
	 * Count should return correct number.
	 */
	public function test_count() {
		$this->assertSame( 0, $this->repo->count() );

		$this->repo->save( $this->make_record( 'c1' ) );
		$this->repo->save( $this->make_record( 'c2' ) );

		$this->assertSame( 2, $this->repo->count() );
	}

	/**
	 * Truncate should delete all records.
	 */
	public function test_truncate() {
		$this->repo->save( $this->make_record( 'a' ) );
		$this->repo->save( $this->make_record( 'b' ) );
		$this->repo->save( $this->make_record( 'c' ) );

		$this->assertSame( 3, $this->repo->count() );

		$deleted = $this->repo->truncate();
		$this->assertSame( 3, $deleted );
		$this->assertSame( 0, $this->repo->count() );
	}

	/**
	 * Save should reject records with empty ID.
	 */
	public function test_save_rejects_empty_id() {
		$result = $this->repo->save( array( 'title' => 'No ID' ) );
		$this->assertWPError( $result );
	}

	/**
	 * Save should auto-set type to collection name if empty.
	 */
	public function test_save_auto_sets_type() {
		$record = array(
			'id'    => 'auto-type',
			'title' => 'Auto Type',
		);
		$saved = $this->repo->save( $record );
		$this->assertSame( 'repo-test', $saved['type'] );
	}

	/**
	 * Save should auto-set status if empty.
	 */
	public function test_save_auto_sets_status() {
		$record = array(
			'id'    => 'auto-status',
			'type'  => 'test',
			'title' => 'Auto Status',
		);
		$saved = $this->repo->save( $record );
		$this->assertSame( 'published', $saved['status'] );
	}
}

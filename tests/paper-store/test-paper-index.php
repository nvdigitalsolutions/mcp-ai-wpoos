<?php
/**
 * Test: Paper Index — Inverted index operations.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Paper_Index
 *
 * @covers WP_MCP_AI_Paper_Index
 */
class Test_WP_MCP_AI_Paper_Index extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Index instance under test.
	 *
	 * @var WP_MCP_AI_Paper_Index
	 */
	private $index;

	/**
	 * JSON driver for reading records.
	 *
	 * @var WP_MCP_AI_Paper_Json_Driver
	 */
	private $driver;

	/**
	 * Temporary collection directory.
	 *
	 * @var string
	 */
	private $collection_dir;

	/**
	 * Temporary indexes directory.
	 *
	 * @var string
	 */
	private $indexes_dir;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();

		$this->collection_dir = $this->paper_root . 'index-test/';
		$this->indexes_dir    = $this->paper_root . '_indexes/';
		mkdir( $this->collection_dir, 0777, true );
		mkdir( $this->indexes_dir, 0777, true );

		$this->driver = new WP_MCP_AI_Paper_Json_Driver();
		$this->index  = new WP_MCP_AI_Paper_Index( 'index-test', $this->collection_dir, $this->indexes_dir );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->index = null;
		$this->tear_down_paper_store();
		parent::tearDown();
	}

	/**
	 * New index should have zero count.
	 */
	public function test_new_index_has_zero_count() {
		$this->assertSame( 0, $this->index->get_count() );
	}

	/**
	 * Indexing a record should increment count.
	 */
	public function test_index_record_increments_count() {
		$record = $this->make_record( 'idx-1', 'Record One' );
		$record['tags'] = array( 'alpha' );

		$this->index->index_record( $record );
		$this->assertSame( 1, $this->index->get_count() );
	}

	/**
	 * Should find records by tag.
	 */
	public function test_find_by_tag() {
		$r1 = $this->make_record( 'a', 'A' );
		$r1['tags'] = array( 'red' );
		$this->index->index_record( $r1 );

		$r2 = $this->make_record( 'b', 'B' );
		$r2['tags'] = array( 'blue' );
		$this->index->index_record( $r2 );

		$ids = $this->index->find_by_tag( 'red' );
		$this->assertContains( 'a', $ids );
		$this->assertNotContains( 'b', $ids );
	}

	/**
	 * Should find records by status.
	 */
	public function test_find_by_status() {
		$pub = $this->make_record( 'pub', 'Pub' );
		$pub['status'] = 'published';
		$this->index->index_record( $pub );

		$draft = $this->make_record( 'draft', 'Draft' );
		$draft['status'] = 'draft';
		$this->index->index_record( $draft );

		$this->assertContains( 'pub', $this->index->find_by_status( 'published' ) );
		$this->assertContains( 'draft', $this->index->find_by_status( 'draft' ) );
	}

	/**
	 * Should find records by type.
	 */
	public function test_find_by_type() {
		$record = $this->make_record( 'typed', 'Typed' );
		$record['type'] = 'knowledge';
		$this->index->index_record( $record );

		$ids = $this->index->find_by_type( 'knowledge' );
		$this->assertContains( 'typed', $ids );
	}

	/**
	 * Should find records by author ID.
	 */
	public function test_find_by_author() {
		$record = $this->make_record( 'auth-test' );
		$record['author_id'] = 42;
		$this->index->index_record( $record );

		$ids = $this->index->find_by_author( 42 );
		$this->assertContains( 'auth-test', $ids );

		$this->assertEmpty( $this->index->find_by_author( 99 ) );
	}

	/**
	 * Should find records by date bucket.
	 */
	public function test_find_by_date_bucket() {
		$record = $this->make_record( 'date-test' );
		$record['created_at'] = '2026-05-15T10:00:00+00:00';
		$this->index->index_record( $record );

		$ids = $this->index->find_by_date_bucket( '2026-05' );
		$this->assertContains( 'date-test', $ids );
	}

	/**
	 * Removing a record should update the index.
	 */
	public function test_remove_record() {
		$record = $this->make_record( 'to-remove' );
		$record['tags'] = array( 'temp' );
		$this->index->index_record( $record );

		$this->assertSame( 1, $this->index->get_count() );

		$this->index->remove_record( 'to-remove' );

		$this->assertSame( 0, $this->index->get_count() );
		$this->assertEmpty( $this->index->find_by_tag( 'temp' ) );
	}

	/**
	 * Rebuild should scan directory and populate index.
	 */
	public function test_rebuild_scans_collection_directory() {
		// Write records to disk via driver.
		$record_a = $this->make_record( 'scan-a', 'A' );
		$record_b = $this->make_record( 'scan-b', 'B' );
		$this->driver->write( $this->collection_dir . 'scan-a.json', $record_a );
		$this->driver->write( $this->collection_dir . 'scan-b.json', $record_b );

		// Build the index.
		$this->index->rebuild( $this->driver, '.json' );

		$this->assertSame( 2, $this->index->get_count() );
	}

	/**
	 * Ensure exists should build index if missing.
	 */
	public function test_ensure_exists_builds_if_missing() {
		// Write a record to disk.
		$this->driver->write(
			$this->collection_dir . 'exists.json',
			$this->make_record( 'exists' )
		);

		// Index file should not exist yet.
		$this->assertFileDoesNotExist( $this->indexes_dir . 'index-test.idx.json' );

		$this->index->ensure_exists( $this->driver, '.json' );

		$this->assertSame( 1, $this->index->get_count() );
	}

	/**
	 * Drop should remove the index file.
	 */
	public function test_drop_removes_index_file() {
		$this->index->index_record( $this->make_record( 'drop-me' ) );

		$index_path = $this->indexes_dir . 'index-test.idx.json';
		$this->assertFileExists( $index_path );

		$this->index->drop();
		$this->assertFileDoesNotExist( $index_path );
	}

	/**
	 * Get all tags should return tag counts.
	 */
	public function test_get_all_tags() {
		$r1 = $this->make_record( 't1', 'T1' );
		$r1['tags'] = array( 'php', 'wordpress' );
		$this->index->index_record( $r1 );

		$r2 = $this->make_record( 't2', 'T2' );
		$r2['tags'] = array( 'wordpress', 'api' );
		$this->index->index_record( $r2 );

		$tags = $this->index->get_all_tags();
		$this->assertArrayHasKey( 'php', $tags );
		$this->assertArrayHasKey( 'wordpress', $tags );
		$this->assertArrayHasKey( 'api', $tags );
		$this->assertSame( 2, $tags['wordpress'] );
	}
}

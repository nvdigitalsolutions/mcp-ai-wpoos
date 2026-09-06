<?php
/**
 * Test: Paper JSON Driver — read/write/delete operations.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Paper_Json_Driver
 *
 * @covers WP_MCP_AI_Paper_Json_Driver
 */
class Test_WP_MCP_AI_Paper_Json_Driver extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * JSON driver under test.
	 *
	 * @var WP_MCP_AI_Paper_Json_Driver
	 */
	private $driver;

	/**
	 * Temporary test directory path.
	 *
	 * @var string
	 */
	private $test_dir;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->driver   = new WP_MCP_AI_Paper_Json_Driver();
		$this->test_dir = $this->paper_root . 'json-driver-test/';
		mkdir( $this->test_dir, 0777, true );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->driver = null;
		$this->tear_down_paper_store();
		parent::tearDown();
	}

	/**
	 * Get extension should return .json
	 */
	public function test_get_extension_returns_json() {
		$this->assertSame( '.json', $this->driver->get_extension() );
	}

	/**
	 * Read should return WP_Error when file does not exist.
	 */
	public function test_read_nonexistent_file_returns_wp_error() {
		$result = $this->driver->read( $this->test_dir . 'nonexistent.json' );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_file_not_found', $result->get_error_code() );
	}

	/**
	 * Read should return WP_Error for malformed JSON.
	 */
	public function test_read_malformed_json_returns_wp_error() {
		$file = $this->test_dir . 'malformed.json';
		file_put_contents( $file, 'not valid json!!!' );

		$result = $this->driver->read( $file );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_invalid_json', $result->get_error_code() );
	}

	/**
	 * Read should return WP_Error when required fields are missing.
	 */
	public function test_read_missing_required_fields_returns_wp_error() {
		$file = $this->test_dir . 'incomplete.json';
		file_put_contents( $file, wp_json_encode( array( 'title' => 'No ID' ) ) );

		$result = $this->driver->read( $file );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_missing_fields', $result->get_error_code() );
	}

	/**
	 * Write and read a valid record.
	 */
	public function test_write_and_read_valid_record() {
		$file   = $this->test_dir . 'valid.json';
		$record = $this->make_record( 'valid-record', 'Valid Record' );

		$write_result = $this->driver->write( $file, $record );
		$this->assertTrue( $write_result );

		$read_result = $this->driver->read( $file );
		$this->assertIsArray( $read_result );
		$this->assertSame( 'valid-record', $read_result['id'] );
		$this->assertSame( 'Valid Record', $read_result['title'] );
		$this->assertArrayHasKey( 'created_at', $read_result );
		$this->assertArrayHasKey( 'updated_at', $read_result );
	}

	/**
	 * Write should auto-set timestamps.
	 */
	public function test_write_auto_sets_timestamps() {
		$file   = $this->test_dir . 'timestamps.json';
		$record = array(
			'id'    => 'ts-test',
			'type'  => 'test',
			'title' => 'Timestamp Test',
		);

		$this->driver->write( $file, $record );
		$read = $this->driver->read( $file );

		$this->assertNotEmpty( $read['created_at'] );
		$this->assertNotEmpty( $read['updated_at'] );
	}

	/**
	 * Write should preserve existing created_at.
	 */
	public function test_write_preserves_existing_created_at() {
		$file   = $this->test_dir . 'preserve-ts.json';
		$record = $this->make_record( 'preserve-test' );
		$record['created_at'] = '2020-01-01T00:00:00+00:00';

		$this->driver->write( $file, $record );
		$read = $this->driver->read( $file );

		$this->assertSame( '2020-01-01T00:00:00+00:00', $read['created_at'] );
	}

	/**
	 * Write should reject records missing required fields.
	 */
	public function test_write_rejects_missing_fields() {
		$file   = $this->test_dir . 'bad.json';
		$record = array( 'title' => 'No ID or type' );

		$result = $this->driver->write( $file, $record );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_missing_fields', $result->get_error_code() );
	}

	/**
	 * Delete should remove the file.
	 */
	public function test_delete_removes_file() {
		$file   = $this->test_dir . 'to-delete.json';
		$record = $this->make_record( 'to-delete' );

		$this->driver->write( $file, $record );
		$this->assertFileExists( $file );

		$this->driver->delete( $file );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * Delete nonexistent file should return WP_Error.
	 */
	public function test_delete_nonexistent_returns_wp_error() {
		$result = $this->driver->delete( $this->test_dir . 'ghost.json' );
		$this->assertWPError( $result );
		$this->assertSame( 'paper_file_not_found', $result->get_error_code() );
	}

	/**
	 * Get required fields.
	 */
	public function test_get_required_fields() {
		$fields = $this->driver->get_required_fields();
		$this->assertContains( 'id', $fields );
		$this->assertContains( 'type', $fields );
		$this->assertContains( 'title', $fields );
	}
}

<?php
/**
 * Test: paper_store_write tool.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Tool_Paper_Store_Write
 *
 * @covers WP_MCP_AI_Tool_Paper_Store_Write
 */
class Test_WP_MCP_AI_Tool_Paper_Store_Write extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Paper_Store_Write
	 */
	private $tool;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->tool = new WP_MCP_AI_Tool_Paper_Store_Write();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->tool = null;
		$this->tear_down_paper_store();
		parent::tearDown();
	}

	/**
	 * Write should create a new record.
	 */
	public function test_write_creates_record() {
		$result = $this->tool->execute(
			array(
				'collection'  => 'write-test',
				'id'          => 'new-record',
				'title'       => 'New Record',
				'description' => 'A freshly created record.',
				'tags'        => array( 'fresh' ),
				'status'      => 'published',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'new-record', $result['record']['id'] );

		// Verify it's on disk.
		$repo = $this->manager->get_repository( 'write-test' );
		$this->assertNotNull( $repo->find( 'new-record' ) );
	}

	/**
	 * Write should reject duplicate IDs.
	 */
	public function test_write_rejects_duplicate_id() {
		$this->seed_records(
			'write-test',
			array(
				array(
					'id'    => 'dup',
					'title' => 'Original',
					'type'  => 'write-test',
				),
			)
		);

		$result = $this->tool->execute(
			array(
				'collection' => 'write-test',
				'id'         => 'dup',
				'title'      => 'Duplicate',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_id', $result->get_error_code() );
	}

	/**
	 * Write should require required params.
	 */
	public function test_write_requires_params() {
		$result = $this->tool->execute( array() );
		$this->assertWPError( $result );
	}

	/**
	 * Write should deny users without edit_posts.
	 */
	public function test_write_denies_no_capability() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->tool->execute(
			array(
				'collection' => 'test',
				'id'         => 'x',
				'title'      => 'X',
			)
		);
		$this->assertWPError( $result );
	}

	/**
	 * Tool slug.
	 */
	public function test_get_slug() {
		$this->assertSame( 'paper_store_write', $this->tool->get_slug() );
	}
}

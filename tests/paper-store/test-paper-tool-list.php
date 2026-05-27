<?php
/**
 * Test: paper_store_list tool.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Tool_Paper_Store_List
 *
 * @covers WP_MCP_AI_Tool_Paper_Store_List
 */
class Test_WP_MCP_AI_Tool_Paper_Store_List extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Paper_Store_List
	 */
	private $tool;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->tool = new WP_MCP_AI_Tool_Paper_Store_List();

		// Seed test data.
		$this->seed_records(
			'list-test',
			array(
				array(
					'id'     => 'record-1',
					'title'  => 'Record One',
					'tags'   => array( 'alpha' ),
					'status' => 'published',
					'type'   => 'knowledge',
				),
				array(
					'id'     => 'record-2',
					'title'  => 'Record Two',
					'tags'   => array( 'beta' ),
					'status' => 'draft',
					'type'   => 'knowledge',
				),
			)
		);

		// Set user capability.
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
	 * List should return all records in a collection.
	 */
	public function test_list_all_records() {
		$result = $this->tool->execute( array( 'collection' => 'list-test' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * List should filter by tag.
	 */
	public function test_list_filter_by_tag() {
		$result = $this->tool->execute(
			array(
				'collection' => 'list-test',
				'tags'       => 'alpha',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * List should filter by status.
	 */
	public function test_list_filter_by_status() {
		$result = $this->tool->execute(
			array(
				'collection' => 'list-test',
				'status'     => 'draft',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['total'] );
	}

	/**
	 * List should require collection parameter.
	 */
	public function test_list_requires_collection() {
		$result = $this->tool->execute( array() );
		$this->assertWPError( $result );
	}

	/**
	 * List should deny users without read capability.
	 */
	public function test_list_denies_no_capability() {
		wp_set_current_user( 0 ); // No user.

		$result = $this->tool->execute( array( 'collection' => 'list-test' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Tool slug should be correct.
	 */
	public function test_get_slug() {
		$this->assertSame( 'paper_store_list', $this->tool->get_slug() );
	}

	/**
	 * Tool should have read capability.
	 */
	public function test_get_required_capability() {
		$this->assertSame( 'read', $this->tool->get_required_capability() );
	}
}

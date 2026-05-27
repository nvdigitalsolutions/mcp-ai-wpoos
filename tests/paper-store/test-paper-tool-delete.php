<?php
/**
 * Test: paper_store_delete tool.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Tool_Paper_Store_Delete
 *
 * @covers WP_MCP_AI_Tool_Paper_Store_Delete
 */
class Test_WP_MCP_AI_Tool_Paper_Store_Delete extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Paper_Store_Delete
	 */
	private $tool;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->tool = new WP_MCP_AI_Tool_Paper_Store_Delete();

		$this->seed_records(
			'delete-test',
			array(
				array(
					'id'    => 'to-delete',
					'title' => 'Delete Me',
					'type'  => 'delete-test',
				),
			)
		);

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
	 * Delete should remove a record.
	 */
	public function test_delete_removes_record() {
		$result = $this->tool->execute(
			array(
				'collection' => 'delete-test',
				'record_id'  => 'to-delete',
			)
		);

		$this->assertTrue( $result['success'] );

		$repo = $this->manager->get_repository( 'delete-test' );
		$this->assertFalse( $repo->exists( 'to-delete' ) );
	}

	/**
	 * Delete nonexistent should return WP_Error.
	 */
	public function test_delete_nonexistent() {
		$result = $this->tool->execute(
			array(
				'collection' => 'delete-test',
				'record_id'  => 'ghost',
			)
		);

		$this->assertWPError( $result );
	}

	/**
	 * Delete should require params.
	 */
	public function test_delete_requires_params() {
		$result = $this->tool->execute( array() );
		$this->assertWPError( $result );
	}

	/**
	 * Delete should deny without delete_posts capability.
	 */
	public function test_delete_denies_no_capability() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->tool->execute(
			array(
				'collection' => 'delete-test',
				'record_id'  => 'to-delete',
			)
		);
		$this->assertWPError( $result );
	}
}

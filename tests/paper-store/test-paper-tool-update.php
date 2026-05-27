<?php
/**
 * Test: paper_store_update tool.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Tool_Paper_Store_Update
 *
 * @covers WP_MCP_AI_Tool_Paper_Store_Update
 */
class Test_WP_MCP_AI_Tool_Paper_Store_Update extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Paper_Store_Update
	 */
	private $tool;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->tool = new WP_MCP_AI_Tool_Paper_Store_Update();

		$this->seed_records(
			'update-test',
			array(
				array(
					'id'          => 'to-update',
					'title'       => 'Original Title',
					'description' => 'Original description.',
					'tags'        => array( 'old' ),
					'status'      => 'draft',
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
	 * Update should modify fields.
	 */
	public function test_update_modifies_fields() {
		$result = $this->tool->execute(
			array(
				'collection' => 'update-test',
				'record_id'  => 'to-update',
				'title'      => 'Updated Title',
				'status'     => 'published',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Updated Title', $result['record']['title'] );
		$this->assertSame( 'published', $result['record']['status'] );
		$this->assertSame( 'Original description.', $result['record']['description'] );
	}

	/**
	 * Update nonexistent should return WP_Error.
	 */
	public function test_update_nonexistent() {
		$result = $this->tool->execute(
			array(
				'collection' => 'update-test',
				'record_id'  => 'ghost',
				'title'      => 'Boo',
			)
		);

		$this->assertWPError( $result );
	}

	/**
	 * Update with no fields should return WP_Error.
	 */
	public function test_update_no_fields() {
		$result = $this->tool->execute(
			array(
				'collection' => 'update-test',
				'record_id'  => 'to-update',
			)
		);

		$this->assertWPError( $result );
	}

	/**
	 * Update should require params.
	 */
	public function test_update_requires_params() {
		$result = $this->tool->execute( array() );
		$this->assertWPError( $result );
	}

	/**
	 * Update should deny without edit_posts.
	 */
	public function test_update_denies_no_capability() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array(
				'collection' => 'update-test',
				'record_id'  => 'to-update',
				'title'      => 'X',
			)
		);
		$this->assertWPError( $result );
	}
}

<?php
/**
 * Test: paper_store_read tool.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

/**
 * Test_WP_MCP_AI_Tool_Paper_Store_Read
 *
 * @covers WP_MCP_AI_Tool_Paper_Store_Read
 */
class Test_WP_MCP_AI_Tool_Paper_Store_Read extends WP_UnitTestCase {

	use WP_MCP_AI_Paper_Store_Test_Helpers;

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Paper_Store_Read
	 */
	private $tool;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_paper_store();
		$this->tool = new WP_MCP_AI_Tool_Paper_Store_Read();

		$this->seed_records(
			'read-test',
			array(
				array(
					'id'          => 'existing',
					'title'       => 'Existing Record',
					'description' => 'A record that exists.',
					'tags'        => array( 'test' ),
					'status'      => 'published',
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
	 * Read should return an existing record.
	 */
	public function test_read_existing_record() {
		$result = $this->tool->execute(
			array(
				'collection' => 'read-test',
				'record_id'  => 'existing',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Existing Record', $result['record']['title'] );
	}

	/**
	 * Read should return WP_Error for nonexistent record.
	 */
	public function test_read_nonexistent() {
		$result = $this->tool->execute(
			array(
				'collection' => 'read-test',
				'record_id'  => 'nope',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	/**
	 * Read should require collection and record_id.
	 */
	public function test_read_requires_params() {
		$result = $this->tool->execute( array() );
		$this->assertWPError( $result );
	}

	/**
	 * Read should deny users without read capability.
	 */
	public function test_read_denies_no_capability() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array(
				'collection' => 'read-test',
				'record_id'  => 'existing',
			)
		);
		$this->assertWPError( $result );
	}

	/**
	 * Tool slug.
	 */
	public function test_get_slug() {
		$this->assertSame( 'paper_store_read', $this->tool->get_slug() );
	}
}

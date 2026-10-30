<?php
/**
 * Tests for the annotation store (Proposal 091, Track E).
 *
 * @package WP_MCP_AI
 */

/**
 * Annotation store test case.
 */
class Test_Annotation_Store extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for REST capability checks.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Helper: create a transcript post and return its ID.
	 *
	 * @param int $assistant_id Optional assistant meta.
	 * @return int
	 */
	private function create_transcript( $assistant_id = 0 ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Transcript fixture',
			)
		);
		if ( $assistant_id > 0 ) {
			update_post_meta( $post_id, '_wp_mcp_ai_assistant_id', $assistant_id );
		}
		return $post_id;
	}

	/**
	 * Recording stores the annotation; re-recording the same turn overwrites.
	 *
	 * @return void
	 */
	public function test_record_and_overwrite() {
		$transcript_id = $this->create_transcript();

		$first = WP_MCP_AI_Annotation_Store::record( $transcript_id, 0, 1, "Good\nanswer." );
		$this->assertSame( 1, $first['rating'] );
		$this->assertSame( "Good\nanswer.", $first['comment'] );

		$second = WP_MCP_AI_Annotation_Store::record( $transcript_id, 0, -1, 'Actually wrong.' );
		$this->assertSame( -1, $second['rating'] );

		$entries = WP_MCP_AI_Annotation_Store::get_annotations( $transcript_id );
		$this->assertCount( 1, $entries );
		$this->assertSame( -1, $entries[0]['rating'] );
		$this->assertSame( 'Actually wrong.', $entries[0]['comment'] );
	}

	/**
	 * Invalid inputs produce WP_Error responses.
	 *
	 * @return void
	 */
	public function test_record_rejects_invalid_input() {
		$this->assertWPError( WP_MCP_AI_Annotation_Store::record( 999999, 0, 1 ) );
		$this->assertWPError( WP_MCP_AI_Annotation_Store::record( $this->create_transcript(), 0, 42 ) );
	}

	/**
	 * Dataset export includes rows and honors the assistant filter.
	 *
	 * @return void
	 */
	public function test_export_dataset_assistant_filter() {
		$included = $this->create_transcript( 11 );
		$excluded = $this->create_transcript( 22 );

		WP_MCP_AI_Annotation_Store::record( $included, 0, 1, 'Included.' );
		WP_MCP_AI_Annotation_Store::record( $excluded, 0, 1, 'Excluded.' );

		$all = WP_MCP_AI_Annotation_Store::export_dataset();
		$this->assertCount( 2, $all );

		$filtered = WP_MCP_AI_Annotation_Store::export_dataset( 11 );
		$this->assertCount( 1, $filtered );
		$this->assertSame( $included, $filtered[0]['transcript_id'] );
	}

	/**
	 * REST export is admin-gated.
	 *
	 * @return void
	 */
	public function test_rest_export_gate() {
		$transcript_id = $this->create_transcript( 11 );
		WP_MCP_AI_Annotation_Store::record( $transcript_id, 0, 1, 'Annotated.' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/mcp-ai/v1/annotations' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['annotations'] );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$forbidden = rest_do_request( new WP_REST_Request( 'GET', '/mcp-ai/v1/annotations' ) );
		$this->assertSame( 403, $forbidden->get_status() );
	}
}

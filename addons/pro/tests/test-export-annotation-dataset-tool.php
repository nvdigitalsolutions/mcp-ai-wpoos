<?php
/**
 * Tests for the Proposal 091 Pro observability surfaces (Track E):
 *
 *   - the `export_annotation_dataset` Pro tool (canonical envelope,
 *     capability gate, assistant filter, honest empty state);
 *   - the shared observability data provider on the analytics service page
 *     (annotation totals + recent GenAI span counts).
 *
 * @package WP_MCP_AI
 */

/**
 * Export annotation dataset tool + observability provider test case.
 */
class Test_Export_Annotation_Dataset_Tool extends WP_UnitTestCase {

	/**
	 * Create an admin user so capability checks and annotation authoring pass.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		if ( ! class_exists( 'WP_MCP_AI_Annotation_Store' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/chat/class-wp-mcp-ai-annotation-store.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_Annotation_Dataset' ) ) {
			require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-export-annotation-dataset.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Analytics_Service_Page' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin/class-wp-mcp-ai-analytics-service-page.php';
		}
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
	 * The tool registers with the canonical metadata and capability flags.
	 *
	 * @return void
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Export_Annotation_Dataset();

		$this->assertSame( 'export_annotation_dataset', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
		$this->assertContains( 'read-only', $tool->get_capability_flags() );
		$this->assertContains( 'pro', $tool->get_capability_flags() );
		$this->assertSame( array(), $tool->get_parameters_schema()['required'] );
	}

	/**
	 * Executing with no annotations returns an honest empty dataset.
	 *
	 * @return void
	 */
	public function test_execute_empty_dataset() {
		$tool = new WP_MCP_AI_Tool_Export_Annotation_Dataset();

		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( array(), $result['rows'] );
	}

	/**
	 * Recorded annotations surface as dataset rows with transcript coordinates.
	 *
	 * @return void
	 */
	public function test_execute_returns_rows() {
		$transcript_id = $this->create_transcript();

		WP_MCP_AI_Annotation_Store::record( $transcript_id, 0, 1, 'Loved it' );
		WP_MCP_AI_Annotation_Store::record( $transcript_id, 1, -1, 'Wrong answer' );

		$tool   = new WP_MCP_AI_Tool_Export_Annotation_Dataset();
		$result = $tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( $transcript_id, $result['rows'][0]['transcript_id'] );
		$this->assertSame( 1, $result['rows'][0]['rating'] );
		$this->assertSame( 'Loved it', $result['rows'][0]['comment'] );
	}

	/**
	 * The assistant_id argument filters to transcripts owned by that assistant.
	 *
	 * @return void
	 */
	public function test_execute_assistant_filter() {
		$owned   = $this->create_transcript( 41 );
		$foreign = $this->create_transcript( 42 );

		WP_MCP_AI_Annotation_Store::record( $owned, 0, 1 );
		WP_MCP_AI_Annotation_Store::record( $foreign, 0, -1 );

		$tool   = new WP_MCP_AI_Tool_Export_Annotation_Dataset();
		$result = $tool->execute( array( 'assistant_id' => 41 ), array() );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( $owned, $result['rows'][0]['transcript_id'] );
		$this->assertSame( 1, $result['rows'][0]['rating'] );
	}

	/**
	 * The observability provider aggregates annotations and recent span counts.
	 *
	 * @return void
	 */
	public function test_observability_provider_shape() {
		$transcript_id = $this->create_transcript();

		WP_MCP_AI_Annotation_Store::record( $transcript_id, 0, 1 );
		WP_MCP_AI_Annotation_Store::record( $transcript_id, 1, -1 );
		WP_MCP_AI_Annotation_Store::record( $transcript_id, 2, 0 );

		update_option(
			'wp_mcp_ai_recent_activity',
			array(
				array(
					'type'    => 'genai_span',
					'context' => array( 'gen_ai.operation.name' => 'chat' ),
				),
				array(
					'type'    => 'genai_span',
					'context' => array( 'gen_ai.operation.name' => 'execute_tool' ),
				),
				array(
					'type'    => 'something_else',
					'context' => array(),
				),
			)
		);

		$data = WP_MCP_AI_Analytics_Service_Page::get_observability_data();

		$this->assertSame( 3, $data['annotations_total'] );
		$this->assertSame( 1, $data['annotations_positive'] );
		$this->assertSame( 1, $data['annotations_negative'] );
		$this->assertSame( 1, $data['transcripts_annotated'] );
		$this->assertSame( 1, $data['spans_chat'] );
		$this->assertSame( 1, $data['spans_tool'] );
		$this->assertSame(
			array(
				'positive' => 1,
				'neutral'  => 1,
				'negative' => 1,
			),
			$data['by_rating']
		);
	}
}

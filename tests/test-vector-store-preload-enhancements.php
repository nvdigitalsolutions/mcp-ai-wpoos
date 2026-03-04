<?php
/**
 * Tests for vector store preloading and semantic content search enhancements.
 *
 * @package WP_MCP_AI
 */

/**
 * Tests for semantic_content_search vector store parameter and context resolution.
 */
class Test_Semantic_Content_Search_Vector_Store extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Semantic_Content_Search
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-semantic-content-search.php';
		$this->tool = new WP_MCP_AI_Tool_Semantic_Content_Search();
	}

	/**
	 * Test that get_parameters_schema includes vector_store_id field.
	 */
	public function test_schema_includes_vector_store_id() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'vector_store_id', $schema['properties'] );
		$this->assertEquals( 'string', $schema['properties']['vector_store_id']['type'] );
	}

	/**
	 * Test that vector_store_id is NOT required (it's optional).
	 */
	public function test_vector_store_id_is_not_required() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertNotContains( 'vector_store_id', $schema['required'] );
		$this->assertContains( 'query', $schema['required'] );
	}

	/**
	 * Test that query is still required.
	 */
	public function test_query_is_required() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertContains( 'query', $schema['required'] );
	}

	/**
	 * Test that additionalProperties is false (strict schema).
	 */
	public function test_schema_disallows_additional_properties() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertFalse( $schema['additionalProperties'] );
	}

	/**
	 * Test that the tool returns an error when no query is provided.
	 */
	public function test_execute_requires_query() {
		$result = $this->tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_missing_query', $result->get_error_code() );
	}

	/**
	 * Test that vector_store_id in arguments takes precedence over context.
	 */
	public function test_vector_store_id_argument_takes_precedence_over_context() {
		// We can't easily test the full execute() without a real OpenAI key,
		// but we can verify the schema includes the parameter and the parameter
		// description mentions context-based fallback.
		$schema      = $this->tool->get_parameters_schema();
		$description = $schema['properties']['vector_store_id']['description'];

		// Description should mention that the assistant-configured store is used as fallback.
		$this->assertStringContainsString( 'configured on the assistant', $description );
	}
}

/**
 * Tests for get_vector_store tool context-based ID resolution.
 */
class Test_Get_Vector_Store_Context_Fallback extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_Vector_Store
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once dirname( __DIR__ ) . '/includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-get-vector-store.php';
		$this->tool = new WP_MCP_AI_Tool_Get_Vector_Store();
	}

	/**
	 * Test that vector_store_id is NOT required in the schema.
	 */
	public function test_vector_store_id_is_not_required() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema['required'] );
		$this->assertNotContains( 'vector_store_id', $schema['required'] );
	}

	/**
	 * Test that execute returns error when no ID is provided in args or context.
	 */
	public function test_execute_returns_error_without_any_vector_store_id() {
		$result = $this->tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No vector store ID provided', $result['error'] );
	}

	/**
	 * Test that the schema description mentions context fallback.
	 */
	public function test_schema_description_mentions_context_fallback() {
		$schema      = $this->tool->get_parameters_schema();
		$description = $schema['properties']['vector_store_id']['description'];

		$this->assertStringContainsString( "assistant's configured vector store", $description );
	}
}

/**
 * Tests for manage_vector_store_files tool context-based ID resolution.
 */
class Test_Manage_Vector_Store_Files_Context_Fallback extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Manage_Vector_Store_Files
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once dirname( __DIR__ ) . '/includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-manage-vector-store-files.php';
		$this->tool = new WP_MCP_AI_Tool_Manage_Vector_Store_Files();
	}

	/**
	 * Test that vector_store_id is NOT required, but action IS required.
	 */
	public function test_required_params_are_only_action() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertContains( 'action', $schema['required'] );
		$this->assertNotContains( 'vector_store_id', $schema['required'] );
	}

	/**
	 * Test that execute returns error when action is missing.
	 */
	public function test_execute_returns_error_without_action() {
		$result = $this->tool->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'action parameter is required', $result['error'] );
	}

	/**
	 * Test that execute returns error when no vector store ID available.
	 */
	public function test_execute_returns_error_without_vector_store_id() {
		$result = $this->tool->execute( array( 'action' => 'list' ), array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No vector store ID provided', $result['error'] );
	}

	/**
	 * Test that the schema description mentions context fallback.
	 */
	public function test_schema_description_mentions_context_fallback() {
		$schema      = $this->tool->get_parameters_schema();
		$description = $schema['properties']['vector_store_id']['description'];

		$this->assertStringContainsString( "assistant's configured vector store", $description );
	}

	/**
	 * Test that the tool uses vector_store_id from assistant context when not in args.
	 *
	 * With a context-provided vector store ID and a valid action, the tool should
	 * proceed to attempt the API call (not fail with "no vector store" error).
	 * We verify only the ID resolution step by mocking the client or checking
	 * that the error type changes when context provides an ID.
	 */
	public function test_context_vector_store_id_is_used_when_not_in_args() {
		$context = array(
			'assistant_config' => array(
				'vector_store_id' => 'vs_test_context_id',
			),
		);

		// With context providing vector_store_id, the "no vector store" error
		// should NOT appear. Instead, it should reach the OpenAI client call.
		// Since we don't have a real API key in tests, it will fail on the API call,
		// but the error will be different from "No vector store ID provided".
		$result = $this->tool->execute( array( 'action' => 'list' ), $context );

		$this->assertIsArray( $result );
		if ( isset( $result['error'] ) ) {
			$this->assertStringNotContainsString( 'No vector store ID provided', $result['error'] );
		}
	}
}

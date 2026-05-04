<?php
/**
 * Tests for WP_MCP_AI_Structured_Output_Guardrail.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

/**
 * Structured Output Guardrail tests.
 */
class Test_Harness_Structured_Output_Guardrail extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-structured-output-guardrail.php';
	}

	// ── validate_against_schema ───────────────────────────────────────────────

	public function test_valid_string_passes() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			'hello',
			array( 'type' => 'string' )
		);
		$this->assertEmpty( $errors );
	}

	public function test_wrong_type_fails() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			42,
			array( 'type' => 'string' )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_required_property_missing_fails() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			array( 'name' => 'Alice' ),
			array(
				'type'       => 'object',
				'required'   => array( 'name', 'email' ),
				'properties' => array(
					'name'  => array( 'type' => 'string' ),
					'email' => array( 'type' => 'string' ),
				),
			)
		);
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'email', implode( ' ', $errors ) );
	}

	public function test_valid_object_passes() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			array( 'name' => 'Alice', 'email' => 'alice@example.com' ),
			array(
				'type'       => 'object',
				'required'   => array( 'name', 'email' ),
				'properties' => array(
					'name'  => array( 'type' => 'string' ),
					'email' => array( 'type' => 'string' ),
				),
			)
		);
		$this->assertEmpty( $errors );
	}

	public function test_enum_violation_fails() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			'green',
			array( 'type' => 'string', 'enum' => array( 'red', 'blue' ) )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_enum_valid_passes() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			'red',
			array( 'type' => 'string', 'enum' => array( 'red', 'blue' ) )
		);
		$this->assertEmpty( $errors );
	}

	public function test_min_length_violation_fails() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			'hi',
			array( 'type' => 'string', 'minLength' => 5 )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_max_length_violation_fails() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			'too long text here',
			array( 'type' => 'string', 'maxLength' => 5 )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_minimum_violation_fails() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			-1,
			array( 'type' => 'integer', 'minimum' => 0 )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_maximum_violation_fails() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			101,
			array( 'type' => 'integer', 'maximum' => 100 )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_array_items_validated() {
		$errors = WP_MCP_AI_Structured_Output_Guardrail::validate_against_schema(
			array( 'a', 'b', 42 ),
			array( 'type' => 'array', 'items' => array( 'type' => 'string' ) )
		);
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( '[2]', implode( ' ', $errors ) );
	}

	// ── validate_inline ───────────────────────────────────────────────────────

	public function test_valid_json_inline_passes() {
		$result = WP_MCP_AI_Structured_Output_Guardrail::validate_inline(
			'{"name":"Alice","score":10}',
			array(
				'type'       => 'object',
				'required'   => array( 'name', 'score' ),
				'properties' => array(
					'name'  => array( 'type' => 'string' ),
					'score' => array( 'type' => 'integer' ),
				),
			)
		);
		$this->assertTrue( $result['valid'] );
		$this->assertEmpty( $result['errors'] );
		$this->assertSame( 'Alice', $result['data']['name'] );
	}

	public function test_invalid_json_fails() {
		$result = WP_MCP_AI_Structured_Output_Guardrail::validate_inline(
			'not valid json',
			array( 'type' => 'object' )
		);
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_markdown_fence_stripped() {
		$result = WP_MCP_AI_Structured_Output_Guardrail::validate_inline(
			"```json\n{\"name\":\"Bob\"}\n```",
			array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string' ) ) )
		);
		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'Bob', $result['data']['name'] );
	}

	// ── enforce_structured_output filter ─────────────────────────────────────

	public function test_filter_can_disable_validation() {
		add_filter( 'wp_mcp_ai_enforce_structured_output', '__return_false' );

		// Create a post with a schema.
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );
		update_post_meta(
			$post_id,
			WP_MCP_AI_Structured_Output_Guardrail::SCHEMA_META_KEY,
			wp_json_encode( array( 'type' => 'object', 'required' => array( 'must_have' ) ) )
		);

		// Even though the schema requires 'must_have', validation is skipped.
		$result = WP_MCP_AI_Structured_Output_Guardrail::validate( '{}', $post_id );
		$this->assertTrue( $result['valid'] );

		remove_all_filters( 'wp_mcp_ai_enforce_structured_output' );
	}

	// ── make_schema_critic ────────────────────────────────────────────────────

	public function test_critic_returns_accept_on_valid_json() {
		$schema = array(
			'type'     => 'object',
			'required' => array( 'answer' ),
			'properties' => array( 'answer' => array( 'type' => 'string' ) ),
		);
		$critic = WP_MCP_AI_Structured_Output_Guardrail::make_schema_critic( $schema );
		$result = $critic( 'task', '{"answer":"yes"}' );
		$this->assertSame( 'accept', $result['verdict'] );
	}

	public function test_critic_returns_revise_on_invalid_json() {
		$schema = array(
			'type'     => 'object',
			'required' => array( 'answer' ),
			'properties' => array( 'answer' => array( 'type' => 'string' ) ),
		);
		$critic  = WP_MCP_AI_Structured_Output_Guardrail::make_schema_critic( $schema );
		$result  = $critic( 'task', '{}' );
		$this->assertSame( 'revise', $result['verdict'] );
		$this->assertNotEmpty( $result['feedback'] );
	}
}

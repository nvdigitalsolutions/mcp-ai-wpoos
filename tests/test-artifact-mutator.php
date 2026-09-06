<?php
/**
 * Tests for the Artifact Mutators (Phase D.1).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test artifact mutator strategies.
 */
class Test_Artifact_Mutator extends WP_UnitTestCase {

	/**
	 * Clean up filter state.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_artifact_mutator_temperature' );

		parent::tearDown();
	}

	/**
	 * Build a stub LLM callable returning a fixed JSON mutation.
	 *
	 * @param array  $captured Captured (messages, options).
	 * @param string $prompt   Prompt to return.
	 * @param string $summary  Change summary to return.
	 * @return callable
	 */
	private function stub_llm( &$captured, $prompt = 'Improved prompt text.', $summary = 'Tightened instructions.' ) {
		return static function ( $messages, $options ) use ( &$captured, $prompt, $summary ) {
			$captured = array(
				'messages' => $messages,
				'options'  => $options,
			);

			return array(
				'content' => wp_json_encode(
					array(
						'prompt'         => $prompt,
						'change_summary' => $summary,
					)
				),
			);
		};
	}

	/**
	 * A minimal parent context.
	 *
	 * @return array
	 */
	private function parent_context() {
		return array(
			'parent'        => array(
				'hash'     => 'abc123',
				'artifact' => array( 'prompt' => 'Old prompt.' ),
			),
			'failure_cases' => array(
				array(
					'tool_slug' => 'web_search',
					'error'     => 'wp_mcp_ai_search_failed',
				),
			),
		);
	}

	/**
	 * Failure-driven mutation produces a canonical envelope.
	 */
	public function test_failure_driven_produces_envelope() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$captured = null;
		$result   = WP_MCP_AI_Artifact_Mutator::failure_driven( $this->stub_llm( $captured ), $this->parent_context() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( WP_MCP_AI_Artifact_Mutator::KIND_FAILURE_DRIVEN, $result['kind'] );
		$this->assertSame( array( 'abc123' ), $result['parent_hashes'] );
		$this->assertSame( 'Improved prompt text.', $result['artifact']['prompt'] );
		$this->assertSame( 'Tightened instructions.', $result['change_summary'] );
		$this->assertNotEmpty( $result['diff'] );
		$this->assertArrayHasKey( 'temperature', $result['meta'] );
	}

	/**
	 * Mutation without a parent is an error.
	 */
	public function test_failure_driven_requires_parent() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$captured = null;
		$result   = WP_MCP_AI_Artifact_Mutator::failure_driven( $this->stub_llm( $captured ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mutator_no_parent', $result->get_error_code() );
	}

	/**
	 * The learning-log mutator forwards log entries to the LLM.
	 */
	public function test_learning_log_mutator_includes_log_entries() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$captured                = null;
		$context                 = $this->parent_context();
		$context['learning_log'] = array(
			array(
				'score_delta'    => -0.2,
				'change_summary' => 'Removed tool hints',
			),
			array(
				'score_delta' => 0.1,
				'diff'        => '+ Added a fallback instruction',
			),
		);

		$result = WP_MCP_AI_Artifact_Mutator::with_learning_log( $this->stub_llm( $captured ), $context );

		$this->assertIsArray( $result );
		$this->assertSame( WP_MCP_AI_Artifact_Mutator::KIND_LEARNING_LOG, $result['kind'] );

		$user_message = $captured['messages'][1]['content'];
		$this->assertStringContainsString( 'Removed tool hints', $user_message );
		$this->assertStringContainsString( '-0.200', $user_message );
		$this->assertStringContainsString( 'Added a fallback instruction', $user_message );
	}

	/**
	 * Crossover needs at least two parents.
	 */
	public function test_crossover_requires_two_parents() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$captured = null;
		$context  = array(
			'parents' => array(
				array(
					'hash'     => 'a',
					'artifact' => array( 'prompt' => 'One' ),
				),
			),
		);

		$result = WP_MCP_AI_Artifact_Mutator::crossover( $this->stub_llm( $captured ), $context );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mutator_crossover_needs_parents', $result->get_error_code() );
	}

	/**
	 * Crossover combines both parents and records their hashes.
	 */
	public function test_crossover_combines_parents() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$captured = null;
		$context  = array(
			'parents' => array(
				array(
					'hash'     => 'parent-a',
					'artifact' => array( 'prompt' => 'Alpha approach.' ),
				),
				array(
					'hash'     => 'parent-b',
					'artifact' => array( 'prompt' => 'Beta approach.' ),
				),
			),
		);

		$result = WP_MCP_AI_Artifact_Mutator::crossover( $this->stub_llm( $captured ), $context );

		$this->assertIsArray( $result );
		$this->assertSame( WP_MCP_AI_Artifact_Mutator::KIND_CROSSOVER, $result['kind'] );
		$this->assertSame( array( 'parent-a', 'parent-b' ), $result['parent_hashes'] );

		$user_message = $captured['messages'][1]['content'];
		$this->assertStringContainsString( 'Alpha approach.', $user_message );
		$this->assertStringContainsString( 'Beta approach.', $user_message );
	}

	/**
	 * Malformed LLM output is a WP_Error.
	 */
	public function test_invalid_llm_response_is_error() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$stub = static function () {
			return array( 'content' => 'not json at all' );
		};

		$result = WP_MCP_AI_Artifact_Mutator::failure_driven( $stub, $this->parent_context() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mutator_invalid_response', $result->get_error_code() );
	}

	/**
	 * Empty prompts in otherwise valid JSON are rejected.
	 */
	public function test_empty_prompt_is_error() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$stub = static function () {
			return array(
				'content' => wp_json_encode(
					array(
						'prompt'         => '   ',
						'change_summary' => 'Nothing.',
					)
				),
			);
		};

		$result = WP_MCP_AI_Artifact_Mutator::failure_driven( $stub, $this->parent_context() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_mutator_empty_prompt', $result->get_error_code() );
	}

	/**
	 * PII in the mutator output is scrubbed when the filter is loaded.
	 */
	public function test_mutator_output_pii_scrubbed() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pii_Filter' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pii_Filter class not available.' );
		}

		$captured = null;
		$result   = WP_MCP_AI_Artifact_Mutator::failure_driven(
			$this->stub_llm( $captured, 'Contact bob@example.com for help.' ),
			$this->parent_context()
		);

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( 'bob@example.com', $result['artifact']['prompt'] );
	}

	/**
	 * The line diff marks added/removed lines and handles identical input.
	 */
	public function test_diff_artifacts() {
		if ( ! class_exists( 'WP_MCP_AI_Artifact_Mutator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Artifact_Mutator class not available.' );
		}

		$same = WP_MCP_AI_Artifact_Mutator::diff_artifacts(
			array( 'prompt' => "a\nb" ),
			array( 'prompt' => "a\nb" )
		);
		$this->assertSame( '', $same );

		$diff = WP_MCP_AI_Artifact_Mutator::diff_artifacts(
			array( 'prompt' => "line1\nline2\nline3" ),
			array( 'prompt' => "line1\nline2X\nline3" )
		);
		$this->assertStringContainsString( '- line2', $diff );
		$this->assertStringContainsString( '+ line2X', $diff );
		$this->assertStringNotContainsString( 'line1', $diff );

		// Long diffs are truncated with a marker.
		$old = array();
		$new = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$old[] = 'old ' . $i;
			$new[] = 'new ' . $i;
		}
		$long = WP_MCP_AI_Artifact_Mutator::diff_artifacts(
			array( 'prompt' => implode( "\n", $old ) ),
			array( 'prompt' => implode( "\n", $new ) ),
			10
		);
		$this->assertStringContainsString( 'more changed lines', $long );
	}
}

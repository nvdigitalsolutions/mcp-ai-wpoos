<?php
/**
 * Tests for the WP_MCP_AI_Tool_Envelope trait.
 *
 * Validates the canonical success-envelope helper landed in Phase P1 of the
 * Unix Theory Compliance Enhancement Proposal.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * @group unix-theory
 * @group tools
 */
class Test_Tool_Envelope_Trait extends WP_UnitTestCase {

	/**
	 * Returns a fresh anonymous tool that composes only the envelope trait.
	 *
	 * @return object
	 */
	private function make_tool() {
		return new class() {
			use WP_MCP_AI_Tool_Envelope;

			public function call_success( $message, $data = null ) {
				return $this->format_success_response( $message, $data );
			}
		};
	}

	/**
	 * The trait file must exist where Phase P1 places it.
	 */
	public function test_trait_file_is_loaded() {
		$this->assertTrue( trait_exists( 'WP_MCP_AI_Tool_Envelope' ) );
	}

	/**
	 * With no $data, the envelope is the minimal success shape.
	 */
	public function test_format_success_response_minimal() {
		$tool   = $this->make_tool();
		$result = $tool->call_success( 'Done.' );

		$this->assertSame(
			array(
				'success' => true,
				'message' => 'Done.',
			),
			$result
		);
	}

	/**
	 * Associative array $data is merged into the response at the top level.
	 */
	public function test_format_success_response_merges_associative_data() {
		$tool   = $this->make_tool();
		$result = $tool->call_success(
			'Created.',
			array(
				'post_id' => 42,
				'slug'    => 'hello-world',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Created.', $result['message'] );
		$this->assertSame( 42, $result['post_id'] );
		$this->assertSame( 'hello-world', $result['slug'] );
	}

	/**
	 * Scalar $data is placed under the `data` key.
	 */
	public function test_format_success_response_nests_scalar_data_under_data_key() {
		$tool   = $this->make_tool();
		$result = $tool->call_success( 'Counted.', 7 );

		$this->assertSame( 7, $result['data'] );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * The legacy chat-response trait must continue to expose the same helper
	 * because ~227 base + Pro tool classes call it via `use WP_MCP_AI_Tool_Chat_Response`.
	 */
	public function test_chat_response_trait_still_exposes_format_success_response() {
		$tool = new class() {
			use WP_MCP_AI_Tool_Chat_Response;

			public function call_success( $message, $data = null ) {
				return $this->format_success_response( $message, $data );
			}
		};

		$result = $tool->call_success( 'Done.', array( 'id' => 1 ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Done.', $result['message'] );
		$this->assertSame( 1, $result['id'] );
	}

	/**
	 * The envelope helper must NEVER return `success => false`. This is the
	 * cardinal rule the Phase P1 PHPCS sniff enforces statically. Verify the
	 * runtime helper has no `false` branch.
	 */
	public function test_envelope_never_emits_success_false() {
		$tool = $this->make_tool();

		foreach ( array( null, '', 0, false, array(), array( 'x' => 1 ) ) as $data ) {
			$result = $tool->call_success( 'msg', $data );
			$this->assertTrue( $result['success'], 'success must always be true; failure path uses WP_Error.' );
		}
	}
}

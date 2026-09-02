<?php
/**
 * Conversation Summarizer port tests (Wave D1c).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\ConversationSummarizer`. Assertions pin
 * behaviour against the base plugin's `WP_MCP_AI_Conversation_Summarizer`
 * (ecosystem port plan, principle: behaviour-preserving).
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\ConversationSummarizer;

/**
 * Test double exposing the protected formatting helpers and recording
 * every completion call for prompt-shape assertions.
 */
class Test_Summarizer_Client_Double {
	public $result;
	public $calls = array();

	public function create_chat_completion( array $messages, array $options = array() ) {
		$this->calls[] = $messages;
		return $this->result;
	}
}

/**
 * Subclass exposing protected methods for contract testing.
 */
class Test_Summarizer_Exposed extends ConversationSummarizer {
	public function build_text( array $messages ): string {
		return $this->build_conversation_text( $messages );
	}

	public function extract( array $result ): string {
		return $this->extract_summary_from_result( $result );
	}
}

/**
 * @group chat
 */
class Test_Conversation_Summarizer extends \WP_UnitTestCase {

	public function test_should_summarize_uses_default_and_custom_thresholds(): void {
		$summarizer = new ConversationSummarizer( new Test_Summarizer_Client_Double() );

		$this->assertFalse( $summarizer->should_summarize( array_fill( 0, 30, array() ) ) );
		$this->assertTrue( $summarizer->should_summarize( array_fill( 0, 31, array() ) ) );
		$this->assertTrue( $summarizer->should_summarize( array_fill( 0, 6, array() ), 5 ) );
		$this->assertFalse( $summarizer->should_summarize( array_fill( 0, 5, array() ), 5 ) );
	}

	public function test_should_summarize_tool_result_respects_threshold(): void {
		$summarizer = new ConversationSummarizer( new Test_Summarizer_Client_Double() );

		// Threshold <= 0 disables tool-result summarization.
		$this->assertFalse( $summarizer->should_summarize_tool_result( 'anything', 0 ) );
		$this->assertFalse( $summarizer->should_summarize_tool_result( 'abc', 5 ) );
		$this->assertTrue( $summarizer->should_summarize_tool_result( 'abcdef', 5 ) );
	}

	public function test_summarize_empty_messages_returns_empty_string(): void {
		$client     = new Test_Summarizer_Client_Double();
		$summarizer = new ConversationSummarizer( $client );

		$this->assertSame( '', $summarizer->summarize( array() ) );
		$this->assertSame( array(), $client->calls );
	}

	public function test_summarize_system_only_messages_returns_empty_string(): void {
		$client     = new Test_Summarizer_Client_Double();
		$summarizer = new ConversationSummarizer( $client );

		$this->assertSame(
			'',
			$summarizer->summarize( array( array( 'role' => 'system', 'content' => 'ignored' ) ) )
		);
		$this->assertSame( array(), $client->calls );
	}

	public function test_summarize_extracts_choices_content(): void {
		$client = new Test_Summarizer_Client_Double();
		$client->result = array(
			'choices' => array(
				array( 'message' => array( 'content' => '  Compact summary.  ' ) ),
			),
		);
		$summarizer = new ConversationSummarizer( $client );

		$summary = $summarizer->summarize(
			array( array( 'role' => 'user', 'content' => 'Hello there' ) )
		);

		$this->assertSame( 'Compact summary.', $summary );
		$this->assertCount( 1, $client->calls );
	}

	public function test_summarize_extracts_plain_content(): void {
		$client = new Test_Summarizer_Client_Double();
		$client->result = array( 'content' => '  Plain summary. ' );
		$summarizer = new ConversationSummarizer( $client );

		$this->assertSame(
			'Plain summary.',
			$summarizer->summarize( array( array( 'role' => 'user', 'content' => 'Hi' ) ) )
		);
	}

	public function test_summarize_propagates_wp_error(): void {
		$client = new Test_Summarizer_Client_Double();
		$client->result = new \WP_Error( 'provider_down', 'Provider unavailable.' );
		$summarizer = new ConversationSummarizer( $client );

		$result = $summarizer->summarize( array( array( 'role' => 'user', 'content' => 'Hi' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'provider_down', $result->get_error_code() );
	}

	public function test_summarize_clamps_max_tokens_into_prompt(): void {
		$client     = new Test_Summarizer_Client_Double();
		$client->result = array( 'content' => 'ok' );
		$summarizer = new ConversationSummarizer( $client );

		$summarizer->summarize(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'max_tokens' => 10 )
		);
		$user_prompt = $client->calls[0][1]['content'];
		$this->assertStringContainsString( 'under 50 words', $user_prompt );

		$summarizer->summarize(
			array( array( 'role' => 'user', 'content' => 'Hi' ) ),
			array( 'max_tokens' => 99999 )
		);
		$user_prompt = $client->calls[1][1]['content'];
		$this->assertStringContainsString( 'under 4000 words', $user_prompt );
	}

	public function test_build_conversation_text_formats_and_skips_system(): void {
		$summarizer = new Test_Summarizer_Exposed( new Test_Summarizer_Client_Double() );

		$text = $summarizer->build_text(
			array(
				array( 'role' => 'user', 'content' => 'Question one' ),
				array( 'role' => 'system', 'content' => 'Hidden' ),
				array( 'role' => 'assistant', 'content' => 'Answer' ),
				'not-an-array',
			)
		);

		$this->assertSame( "USER: Question one\n\nASSISTANT: Answer", $text );
	}

	public function test_build_conversation_text_flattens_array_content(): void {
		$summarizer = new Test_Summarizer_Exposed( new Test_Summarizer_Client_Double() );

		$text = $summarizer->build_text(
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array( 'type' => 'text', 'text' => 'Part one' ),
						'Part two',
						array( 'type' => 'image_url', 'image_url' => array() ),
					),
				),
			)
		);

		$this->assertSame( 'USER: Part one Part two', $text );
	}

	public function test_build_conversation_text_truncates_long_messages(): void {
		$summarizer = new Test_Summarizer_Exposed( new Test_Summarizer_Client_Double() );

		$long = str_repeat( 'x', 2500 );
		$text = $summarizer->build_text(
			array( array( 'role' => 'user', 'content' => $long ) )
		);

		$this->assertStringStartsWith( 'USER: ' . str_repeat( 'x', 2000 ) . '… [truncated]', $text );
		$this->assertLessThan( strlen( $long ) + 8, strlen( $text ) );
	}

	public function test_extract_summary_prefers_choices_then_content(): void {
		$summarizer = new Test_Summarizer_Exposed( new Test_Summarizer_Client_Double() );

		$this->assertSame(
			'From choices',
			$summarizer->extract(
				array(
					'choices' => array( array( 'message' => array( 'content' => ' From choices ' ) ) ),
					'content' => ' From content ',
				)
			)
		);
		$this->assertSame( 'From content', $summarizer->extract( array( 'content' => ' From content ' ) ) );
		$this->assertSame( '', $summarizer->extract( array( 'unexpected' => 'shape' ) ) );
	}
}

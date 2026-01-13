<?php
/**
 * Tests for Remote Connection Tool - Description Truncation
 *
 * Tests the description truncation functionality to reduce token usage.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test case for Remote Connection Tool Description Truncation.
 */
class Test_Remote_Connection_Tool_Description_Truncation extends WP_UnitTestCase {

	/**
	 * Remote connection tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Remote_WP_Connection
	 */
	protected $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php';

		$this->tool = new WP_MCP_AI_Tool_Remote_WP_Connection();
	}

	/**
	 * Test truncate_to_sentences method with basic text.
	 *
	 * Uses reflection to test protected method.
	 */
	public function test_truncate_to_sentences_basic() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_to_sentences' );
		$method->setAccessible( true );

		$text = 'This is the first sentence. This is the second sentence. This is the third sentence. This is the fourth sentence.';
		$result = $method->invoke( $this->tool, $text, 2 );

		// Should contain first two sentences only.
		$this->assertStringContainsString( 'first sentence', $result );
		$this->assertStringContainsString( 'second sentence', $result );
		$this->assertStringNotContainsString( 'fourth sentence', $result );
		$this->assertStringEndsWith( '...', $result );
	}

	/**
	 * Test truncate_to_sentences with HTML content.
	 */
	public function test_truncate_to_sentences_strips_html() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_to_sentences' );
		$method->setAccessible( true );

		$text = '<p>This is a <strong>formatted</strong> sentence.</p><p>This is another sentence.</p>';
		$result = $method->invoke( $this->tool, $text, 2 );

		// HTML should be stripped.
		$this->assertStringNotContainsString( '<p>', $result );
		$this->assertStringNotContainsString( '<strong>', $result );
		$this->assertStringContainsString( 'formatted', $result );
	}

	/**
	 * Test truncate_to_sentences with different punctuation.
	 */
	public function test_truncate_to_sentences_various_punctuation() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_to_sentences' );
		$method->setAccessible( true );

		$text = 'This is a question? This is an exclamation! This is a statement. More text here.';
		$result = $method->invoke( $this->tool, $text, 3 );

		$this->assertStringContainsString( 'question?', $result );
		$this->assertStringContainsString( 'exclamation!', $result );
		$this->assertStringContainsString( 'statement.', $result );
		$this->assertStringNotContainsString( 'More text', $result );
	}

	/**
	 * Test truncate_to_sentences with short text.
	 */
	public function test_truncate_to_sentences_short_text() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_to_sentences' );
		$method->setAccessible( true );

		$text = 'This is a single sentence.';
		$result = $method->invoke( $this->tool, $text, 3 );

		// Should not add ellipsis if text is not truncated.
		$this->assertEquals( $text, $result );
		$this->assertStringNotContainsString( '...', $result );
	}

	/**
	 * Test truncate_to_sentences with empty text.
	 */
	public function test_truncate_to_sentences_empty_text() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_to_sentences' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, '', 3 );

		$this->assertEmpty( $result );
	}

	/**
	 * Test truncate_product_descriptions method.
	 */
	public function test_truncate_product_descriptions() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_product_descriptions' );
		$method->setAccessible( true );

		$products = array(
			(object) array(
				'id' => 1,
				'name' => 'Test Product',
				'description' => 'This is the first sentence. This is the second sentence. This is the third sentence. This is the fourth sentence.',
				'short_description' => 'Short first sentence. Short second sentence. Short third sentence.',
			),
		);

		$result = $method->invoke( $this->tool, $products );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );

		// Description should be truncated to 3 sentences.
		$this->assertStringContainsString( 'first sentence', $result[0]->description );
		$this->assertStringContainsString( 'third sentence', $result[0]->description );
		$this->assertStringNotContainsString( 'fourth sentence', $result[0]->description );

		// Short description should be truncated to 2 sentences.
		$this->assertStringContainsString( 'Short first sentence', $result[0]->short_description );
		$this->assertStringContainsString( 'Short second sentence', $result[0]->short_description );
		$this->assertStringNotContainsString( 'Short third sentence', $result[0]->short_description );
	}

	/**
	 * Test truncate_product_descriptions with products missing descriptions.
	 */
	public function test_truncate_product_descriptions_missing_descriptions() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_product_descriptions' );
		$method->setAccessible( true );

		$products = array(
			(object) array(
				'id' => 1,
				'name' => 'Test Product',
			),
		);

		$result = $method->invoke( $this->tool, $products );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertObjectNotHasProperty( 'description', $result[0] );
	}

	/**
	 * Test truncate_product_descriptions with non-array input.
	 */
	public function test_truncate_product_descriptions_non_array() {
		$reflection = new ReflectionClass( $this->tool );
		$method = $reflection->getMethod( 'truncate_product_descriptions' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tool, null );

		$this->assertNull( $result );
	}
}

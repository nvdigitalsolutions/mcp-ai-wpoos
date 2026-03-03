<?php
/**
 * Tests for the Agent Skills parser.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Skill_Parser_Test extends WP_UnitTestCase {

	/**
	 * Parser instance.
	 *
	 * @var WP_MCP_AI_Skill_Parser
	 */
	private $parser;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->parser = new WP_MCP_AI_Skill_Parser();
	}

	/**
	 * Test parsing a minimal valid SKILL.md content.
	 */
	public function test_parse_minimal_skill() {
		$content = "---\nname: test-skill\ndescription: A test skill for unit testing.\n---\n\n# Test Skill\n\nThis is the instruction body.";

		$result = $this->parser->parse( $content );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertSame( 'test-skill', $result['name'] );
		$this->assertSame( 'A test skill for unit testing.', $result['description'] );
		$this->assertStringContainsString( 'Test Skill', $result['instructions'] );
		$this->assertStringContainsString( 'instruction body', $result['instructions'] );
		$this->assertSame( '', $result['license'] );
		$this->assertSame( '', $result['compatibility'] );
		$this->assertSame( array(), $result['metadata'] );
		$this->assertSame( array(), $result['allowed_tools'] );
	}

	/**
	 * Test parsing a full SKILL.md with all optional fields.
	 */
	public function test_parse_full_skill() {
		$content = <<<'SKILL'
---
name: brand-guidelines
description: Applies brand colors and typography to artifacts.
license: Apache-2.0
compatibility: Requires specific fonts installed
allowed-tools: pdftools network-requests
metadata:
  author: example-org
  version: "1.0"
---

# Brand Styling

Apply brand colors to all outputs.

## Colors
- Dark: #141413
- Light: #faf9f5
SKILL;

		$result = $this->parser->parse( $content );

		$this->assertNotWPError( $result );
		$this->assertSame( 'brand-guidelines', $result['name'] );
		$this->assertSame( 'Applies brand colors and typography to artifacts.', $result['description'] );
		$this->assertSame( 'Apache-2.0', $result['license'] );
		$this->assertSame( 'Requires specific fonts installed', $result['compatibility'] );
		$this->assertStringContainsString( 'Brand Styling', $result['instructions'] );
		$this->assertStringContainsString( '#141413', $result['instructions'] );

		// Check metadata.
		$this->assertIsArray( $result['metadata'] );
		$this->assertArrayHasKey( 'author', $result['metadata'] );
		$this->assertSame( 'example-org', $result['metadata']['author'] );
		$this->assertArrayHasKey( 'version', $result['metadata'] );
		$this->assertSame( '1.0', $result['metadata']['version'] );

		// Check allowed tools.
		$this->assertIsArray( $result['allowed_tools'] );
		$this->assertCount( 2, $result['allowed_tools'] );
		$this->assertContains( 'pdftools', $result['allowed_tools'] );
		$this->assertContains( 'network-requests', $result['allowed_tools'] );
	}

	/**
	 * Test that empty content returns an error.
	 */
	public function test_parse_empty_content() {
		$result = $this->parser->parse( '' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_empty_content', $result->get_error_code() );
	}

	/**
	 * Test that content without frontmatter returns an error.
	 */
	public function test_parse_no_frontmatter() {
		$result = $this->parser->parse( '# Just some markdown without frontmatter' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_no_frontmatter', $result->get_error_code() );
	}

	/**
	 * Test that missing name field returns an error.
	 */
	public function test_parse_missing_name() {
		$content = "---\ndescription: A skill without a name.\n---\n\nSome instructions.";

		$result = $this->parser->parse( $content );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_missing_name', $result->get_error_code() );
	}

	/**
	 * Test that missing description field returns an error.
	 */
	public function test_parse_missing_description() {
		$content = "---\nname: test-skill\n---\n\nSome instructions.";

		$result = $this->parser->parse( $content );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_missing_description', $result->get_error_code() );
	}

	/**
	 * Test that invalid name format returns an error.
	 */
	public function test_parse_invalid_name_format() {
		// Uppercase not allowed.
		$content = "---\nname: Test-Skill\ndescription: Invalid name.\n---\n\nInstructions.";

		$result = $this->parser->parse( $content );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_invalid_name', $result->get_error_code() );
	}

	/**
	 * Test that consecutive hyphens in name are rejected.
	 */
	public function test_parse_consecutive_hyphens_in_name() {
		$content = "---\nname: test--skill\ndescription: Bad hyphens.\n---\n\nInstructions.";

		$result = $this->parser->parse( $content );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_invalid_name', $result->get_error_code() );
	}

	/**
	 * Test that name starting with hyphen is rejected.
	 */
	public function test_parse_name_starting_with_hyphen() {
		$content = "---\nname: -test-skill\ndescription: Leading hyphen.\n---\n\nInstructions.";

		$result = $this->parser->parse( $content );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_invalid_name', $result->get_error_code() );
	}

	/**
	 * Test that name exceeding max length returns an error.
	 */
	public function test_parse_name_too_long() {
		$long_name = str_repeat( 'a', 65 );
		$content   = "---\nname: {$long_name}\ndescription: Name is too long.\n---\n\nInstructions.";

		$result = $this->parser->parse( $content );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_name_too_long', $result->get_error_code() );
	}

	/**
	 * Test that a single character name is valid.
	 */
	public function test_parse_single_char_name() {
		$content = "---\nname: a\ndescription: Single char name.\n---\n\nInstructions.";

		$result = $this->parser->parse( $content );

		$this->assertNotWPError( $result );
		$this->assertSame( 'a', $result['name'] );
	}

	/**
	 * Test that numeric name is valid.
	 */
	public function test_parse_numeric_name() {
		$content = "---\nname: skill123\ndescription: Numeric name.\n---\n\nInstructions.";

		$result = $this->parser->parse( $content );

		$this->assertNotWPError( $result );
		$this->assertSame( 'skill123', $result['name'] );
	}

	/**
	 * Test parsing a file from disk.
	 */
	public function test_parse_file() {
		$tmp_dir  = sys_get_temp_dir() . '/wp-mcp-ai-test-skill-' . uniqid();
		$tmp_file = $tmp_dir . '/SKILL.md';

		mkdir( $tmp_dir, 0755, true );

		$content = "---\nname: file-skill\ndescription: Skill from file.\n---\n\n# File Skill\n\nInstructions from a file.";

		file_put_contents( $tmp_file, $content );

		$result = $this->parser->parse_file( $tmp_file );

		$this->assertNotWPError( $result );
		$this->assertSame( 'file-skill', $result['name'] );

		// Cleanup.
		unlink( $tmp_file );
		rmdir( $tmp_dir );
	}

	/**
	 * Test that parsing a nonexistent file returns an error.
	 */
	public function test_parse_file_not_found() {
		$result = $this->parser->parse_file( '/nonexistent/path/SKILL.md' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_file_not_found', $result->get_error_code() );
	}

	/**
	 * Test that frontmatter with YAML comments is handled.
	 */
	public function test_parse_yaml_comments() {
		$content = "---\n# This is a comment\nname: commented-skill\ndescription: Has comments.\n---\n\nInstructions.";

		$result = $this->parser->parse( $content );

		$this->assertNotWPError( $result );
		$this->assertSame( 'commented-skill', $result['name'] );
	}

	/**
	 * Test parsing preserves markdown in instruction body.
	 */
	public function test_parse_preserves_markdown_instructions() {
		$content = <<<'SKILL'
---
name: markdown-skill
description: Tests markdown preservation.
---

# Heading

## Subheading

- List item 1
- List item 2

**Bold text** and *italic text*.

```python
print("code block")
```
SKILL;

		$result = $this->parser->parse( $content );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '# Heading', $result['instructions'] );
		$this->assertStringContainsString( '- List item 1', $result['instructions'] );
		$this->assertStringContainsString( '**Bold text**', $result['instructions'] );
	}
}

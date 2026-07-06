<?php
/**
 * Tests for load_skill tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test load_skill tool — reads Agent Skill SKILL.md files by name.
 */
class Test_Tool_Load_Skill extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Load_Skill
	 */
	private $tool;

	/**
	 * Subscriber user ID (minimal caps).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->tool          = new WP_MCP_AI_Tool_Load_Skill();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'load_skill', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Missing name returns wp_mcp_ai_load_skill_missing_name error.
	 */
	public function test_missing_name_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_missing_name', $result->get_error_code() );
	}

	/**
	 * Skill name that exceeds max length returns wp_mcp_ai_load_skill_name_too_long.
	 */
	public function test_name_too_long_returns_error() {
		$result = $this->tool->execute(
			array( 'name' => str_repeat( 'x', 300 ) ),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_name_too_long', $result->get_error_code() );
	}

	/**
	 * Non-installed skill returns wp_mcp_ai_load_skill_not_found error.
	 */
	public function test_nonexistent_skill_returns_not_found() {
		$result = $this->tool->execute(
			array( 'name' => 'phpunit-nonexistent-skill-' . uniqid() ),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_not_found', $result->get_error_code() );
	}

	/**
	 * When called with a non-zero assistant_id that hasn't assigned the skill,
	 * returns wp_mcp_ai_load_skill_not_assigned.
	 */
	public function test_skill_not_in_assistant_allow_list() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$result = $this->tool->execute(
			array( 'name' => 'some-valid-looking-skill' ),
			array( 'assistant_id' => $assistant_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_not_assigned', $result->get_error_code() );
	}

	/**
	 * When assistant_id=0 and no user_id, returns forbidden.
	 */
	public function test_no_user_and_no_assistant_returns_forbidden() {
		$result = $this->tool->execute(
			array( 'name' => 'some-skill' ),
			array(
				'user_id'      => 0,
				'assistant_id' => 0,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_forbidden', $result->get_error_code() );
	}
}

<?php
/**
 * Tests for the Phase 3 progressive disclosure pipeline.
 *
 * Covers:
 *   - WP_MCP_AI_Skill_Registry::build_skills_index_prompt()
 *   - WP_MCP_AI_Tool_Load_Skill (slug, schema, capability flags, execute)
 *
 * The test deliberately avoids calling into the assistant CPT directly so it
 * works whether the Pro add-on is loaded or not.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Skill_Progressive_Disclosure_Test extends WP_UnitTestCase {

	/**
	 * Temporary skills directory.
	 *
	 * @var string
	 */
	private $test_skills_dir;

	/**
	 * Admin user id used for capability checks.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up fixtures: redirect uploads, install two skills, create an admin.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-skill-registry.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Skill_Parser' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-skill-parser.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
			require_once dirname( __DIR__ ) . '/includes/interfaces/interface-wp-mcp-ai-tool.php';
		}
		if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
			require_once dirname( __DIR__ ) . '/includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Load_Skill' ) ) {
			require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-load-skill.php';
		}

		WP_MCP_AI_Skill_Registry::reset();

		$this->test_skills_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-progressive-' . uniqid();
		mkdir( $this->test_skills_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture isolation.
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		// Install two skills via the registry's normal pipeline.
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( "---\nname: alpha\ndescription: Alpha skill description.\nlicense: MIT\n---\n\n# Alpha\n\nAlpha instructions.\n" );
		$registry->install_skill( "---\nname: beta\ndescription: Beta skill description.\nlicense: MIT\n---\n\n# Beta\n\nBeta instructions.\n" );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		$this->recursive_rmdir( $this->test_skills_dir );
		WP_MCP_AI_Skill_Registry::reset();
		delete_option( WP_MCP_AI_Skill_Registry::OPTION_SKILL_INDEX );

		parent::tearDown();
	}

	/**
	 * Redirect uploads basedir to the temporary test directory.
	 *
	 * @param array $dirs Upload dir info.
	 * @return array
	 */
	public function filter_upload_dir( $dirs ) {
		$dirs['basedir'] = dirname( $this->test_skills_dir );
		return $dirs;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Path to remove.
	 * @return void
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test teardown.
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test teardown.
	}

	/* ─── Index prompt ────────────────────────────────────────────────────── */

	/**
	 * The index prompt contains the names and descriptions but never the
	 * full instruction body (that is the whole point of progressive disclosure).
	 */
	public function test_index_prompt_lists_names_without_instructions() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$prompt   = $registry->build_skills_index_prompt( array( 'alpha', 'beta' ) );

		$this->assertNotEmpty( $prompt );
		$this->assertStringContainsString( 'alpha', $prompt );
		$this->assertStringContainsString( 'Alpha skill description.', $prompt );
		$this->assertStringContainsString( 'beta', $prompt );
		$this->assertStringContainsString( 'load_skill', $prompt );
		// Instructions must NOT leak into the system prompt.
		$this->assertStringNotContainsString( 'Alpha instructions.', $prompt );
		$this->assertStringNotContainsString( 'Beta instructions.', $prompt );
	}

	/**
	 * Empty / non-array input must return an empty string (so the assistant
	 * CPT can append unconditionally without producing garbage prompt text).
	 */
	public function test_index_prompt_returns_empty_for_empty_input() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$this->assertSame( '', $registry->build_skills_index_prompt( array() ) );
		$this->assertSame( '', $registry->build_skills_index_prompt( null ) );
	}

	/**
	 * Unknown skill names are silently filtered out (mirrors build_skills_prompt).
	 */
	public function test_index_prompt_skips_unknown_skills() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$prompt   = $registry->build_skills_index_prompt( array( 'alpha', 'does-not-exist' ) );
		$this->assertStringContainsString( 'alpha', $prompt );
		$this->assertStringNotContainsString( 'does-not-exist', $prompt );
	}

	/* ─── load_skill tool ────────────────────────────────────────────────── */

	/**
	 * The tool exposes the expected slug and schema.
	 */
	public function test_load_skill_metadata() {
		$tool = new WP_MCP_AI_Tool_Load_Skill();
		$this->assertSame( 'load_skill', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_description() );

		$schema = $tool->get_parameters_schema();
		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'name' ), $schema['required'] );
		$this->assertArrayHasKey( 'name', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['name']['type'] );

		$flags = $tool->get_capability_flags();
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'idempotent', $flags );
	}

	/**
	 * A missing name returns a structured WP_Error.
	 */
	public function test_load_skill_rejects_missing_name() {
		$tool   = new WP_MCP_AI_Tool_Load_Skill();
		$result = $tool->execute( array(), array( 'user_id' => $this->admin_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_missing_name', $result->get_error_code() );
	}

	/**
	 * An assistant context with skill X assigned can load skill X.
	 */
	public function test_load_skill_returns_instructions_for_assigned_skill() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $assistant_id, '_wp_mcp_ai_skills', array( 'alpha' ) );

		$tool   = new WP_MCP_AI_Tool_Load_Skill();
		$result = $tool->execute(
			array( 'name' => 'alpha' ),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => $assistant_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertSame( 'alpha', $result['name'] );
		$this->assertStringContainsString( 'Alpha instructions.', $result['instructions'] );
	}

	/**
	 * A skill that exists but is NOT assigned to this assistant must be
	 * refused (defence against prompt-injected name leakage).
	 */
	public function test_load_skill_rejects_unassigned_skill() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $assistant_id, '_wp_mcp_ai_skills', array( 'alpha' ) );

		$tool   = new WP_MCP_AI_Tool_Load_Skill();
		$result = $tool->execute(
			array( 'name' => 'beta' ),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => $assistant_id,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_not_assigned', $result->get_error_code() );
	}

	/**
	 * An unknown skill name returns a not_found error (after the assignment
	 * check passes — i.e. when called outside an assistant context).
	 */
	public function test_load_skill_returns_not_found_for_unknown_skill() {
		$tool   = new WP_MCP_AI_Tool_Load_Skill();
		$result = $tool->execute(
			array( 'name' => 'never-installed' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_not_found', $result->get_error_code() );
	}

	/**
	 * Anonymous users without an assistant context cannot enumerate skills.
	 */
	public function test_load_skill_requires_authentication_when_no_assistant_context() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Load_Skill();
		$result = $tool->execute( array( 'name' => 'alpha' ), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_load_skill_forbidden', $result->get_error_code() );
	}

	/**
	 * The wp_mcp_ai_skill_loaded action fires on success with the resolved
	 * skill name + assistant id.
	 */
	public function test_load_skill_fires_action_on_success() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $assistant_id, '_wp_mcp_ai_skills', array( 'alpha' ) );

		$captured = array();
		add_action(
			'wp_mcp_ai_skill_loaded',
			function ( $name, $aid ) use ( &$captured ) {
				$captured[] = array( $name, $aid );
			},
			10,
			2
		);

		$tool = new WP_MCP_AI_Tool_Load_Skill();
		$tool->execute(
			array( 'name' => 'alpha' ),
			array(
				'user_id'      => $this->admin_id,
				'assistant_id' => $assistant_id,
			)
		);

		remove_all_actions( 'wp_mcp_ai_skill_loaded' );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'alpha', $captured[0][0] );
		$this->assertSame( $assistant_id, $captured[0][1] );
	}
}

<?php
/**
 * Tests for the Pro Skill Manager REST controller.
 *
 * Exercises all endpoints of WP_MCP_AI_Skill_Manager_REST_Controller:
 *   GET    /mcp-ai-pro/v1/skills
 *   GET    /mcp-ai-pro/v1/skills/{name}
 *   POST   /mcp-ai-pro/v1/skills
 *   PUT    /mcp-ai-pro/v1/skills/{name}
 *   DELETE /mcp-ai-pro/v1/skills/{name}
 *   POST   /mcp-ai-pro/v1/skills/install-url  (tested without real HTTP)
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Skill_Manager_REST_Test extends WP_Test_REST_TestCase {

	/**
	 * Temporary skill directory for test isolation.
	 *
	 * @var string
	 */
	private $test_skills_dir;

	/**
	 * Admin user ID for authenticated requests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Sample minimal valid SKILL.md content.
	 *
	 * @var string
	 */
	private $sample_skill_content = "---\nname: test-skill\ndescription: A test skill for unit testing.\nlicense: MIT\n---\n\n# Test Skill\n\nDo the test thing.\n";

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure core classes are loaded (may not be autoloaded in test context).
		if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-skill-registry.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Skill_Parser' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-skill-parser.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Skill_Manager_REST_Controller' ) ) {
			require_once dirname( __DIR__ ) . '/addons/pro/includes/rest/class-wp-mcp-ai-skill-manager-rest-controller.php';
		}

		// Reset registry singleton and redirect uploads to a temp dir.
		WP_MCP_AI_Skill_Registry::reset();

		$this->test_skills_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-rest-skills-' . uniqid();
		mkdir( $this->test_skills_dir, 0755, true );
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		// Create and authenticate an admin user.
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Boot REST server and register routes.
		do_action( 'rest_api_init' );
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
	 * Redirect uploads basedir to temp for isolation.
	 *
	 * @param array $dirs Upload dir info.
	 * @return array Modified upload dir.
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
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
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

	// Helper: install a skill directly for setup.

	/**
	 * Install a skill via the registry for test precondition setup.
	 *
	 * @param string $name    Skill slug.
	 * @param string $content Optional SKILL.md content override.
	 * @return array Parsed skill data.
	 */
	private function seed_skill( $name = 'test-skill', $content = null ) {
		if ( null === $content ) {
			$content = "---\nname: {$name}\ndescription: Seeded test skill.\nlicense: MIT\n---\n\n# {$name}\n\nBody.\n";
		}

		return WP_MCP_AI_Skill_Registry::instance()->install_skill( $content );
	}

	// Permission tests.

	/**
	 * Non-authenticated requests should get 401.
	 */
	public function test_get_items_requires_authentication() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Subscriber (non-admin) should get 403.
	 */
	public function test_get_items_requires_manage_options() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// GET /skills.

	/**
	 * GET /skills on an empty registry returns an empty array.
	 */
	public function test_get_items_empty() {
		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * GET /skills returns all installed skills.
	 */
	public function test_get_items_with_installed_skills() {
		$this->seed_skill( 'skill-alpha' );
		$this->seed_skill( 'skill-beta' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 2, $data );

		$names = array_column( $data, 'name' );
		$this->assertContains( 'skill-alpha', $names );
		$this->assertContains( 'skill-beta', $names );
	}

	/**
	 * GET /skills items include expected keys.
	 */
	public function test_get_items_response_shape() {
		$this->seed_skill( 'shape-test' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$item = $data[0];
		$this->assertArrayHasKey( 'name', $item );
		$this->assertArrayHasKey( 'description', $item );
		$this->assertArrayHasKey( 'license', $item );
		$this->assertArrayHasKey( 'compatibility', $item );
		$this->assertArrayHasKey( 'metadata', $item );
		$this->assertArrayHasKey( 'allowed_tools', $item );

		// Full content should NOT appear in collection view.
		$this->assertArrayNotHasKey( 'raw_content', $item );
		$this->assertArrayNotHasKey( 'instructions', $item );
	}

	// GET /skills/{name}.

	/**
	 * GET /skills/{name} returns 404 for unknown skill.
	 */
	public function test_get_item_not_found() {
		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills/no-such-skill' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * GET /skills/{name} returns full skill data including raw content.
	 */
	public function test_get_item_success() {
		$this->seed_skill( 'full-skill' );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills/full-skill' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'full-skill', $data['name'] );
		$this->assertArrayHasKey( 'raw_content', $data );
		$this->assertArrayHasKey( 'instructions', $data );
		$this->assertStringContainsString( 'full-skill', $data['raw_content'] );
	}

	// POST /skills.

	/**
	 * POST /skills with valid content installs the skill and returns 201.
	 */
	public function test_create_item_success() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/skills' );
		$request->set_param( 'content', $this->sample_skill_content );

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'test-skill', $data['name'] );
	}

	/**
	 * POST /skills with invalid SKILL.md returns 400.
	 */
	public function test_create_item_invalid_content() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/skills' );
		$request->set_param( 'content', 'This is not valid SKILL.md content.' );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * POST /skills with missing content returns 400 (required param).
	 */
	public function test_create_item_missing_content() {
		$request  = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/skills' );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// PUT /skills/{name}.

	/**
	 * PUT /skills/{name} with valid content updates the skill.
	 */
	public function test_update_item_success() {
		$this->seed_skill( 'test-skill' );

		$updated_content = "---\nname: test-skill\ndescription: Updated description.\nlicense: Apache-2.0\n---\n\n# Updated\n\nNew body.\n";
		$request         = new WP_REST_Request( 'PUT', '/mcp-ai-pro/v1/skills/test-skill' );
		$request->set_param( 'content', $updated_content );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'test-skill', $data['name'] );
		$this->assertSame( 'Updated description.', $data['description'] );
		$this->assertSame( 'Apache-2.0', $data['license'] );
	}

	/**
	 * PUT /skills/{name} returns 404 when skill does not exist.
	 */
	public function test_update_item_not_found() {
		$request = new WP_REST_Request( 'PUT', '/mcp-ai-pro/v1/skills/ghost-skill' );
		$request->set_param( 'content', "---\nname: ghost-skill\ndescription: A ghost.\n---\n\nBody.\n" );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * PUT /skills/{name} returns 422 when name in URL does not match SKILL.md.
	 */
	public function test_update_item_name_mismatch() {
		$this->seed_skill( 'correct-name' );

		$request = new WP_REST_Request( 'PUT', '/mcp-ai-pro/v1/skills/correct-name' );
		$request->set_param( 'content', "---\nname: different-name\ndescription: Mismatch.\n---\n\nBody.\n" );

		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
	}

	// DELETE /skills/{name}.

	/**
	 * DELETE /skills/{name} removes the skill successfully.
	 */
	public function test_delete_item_success() {
		$this->seed_skill( 'delete-me' );

		$request  = new WP_REST_Request( 'DELETE', '/mcp-ai-pro/v1/skills/delete-me' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( 'delete-me', $data['name'] );

		// Confirm the skill is gone.
		$skill = WP_MCP_AI_Skill_Registry::instance()->get_skill( 'delete-me' );
		$this->assertNull( $skill );
	}

	/**
	 * DELETE /skills/{name} returns 404 for unknown skill.
	 */
	public function test_delete_item_not_found() {
		$request  = new WP_REST_Request( 'DELETE', '/mcp-ai-pro/v1/skills/never-existed' );
		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// POST /skills/install-url  (validation path only).

	/**
	 * POST /skills/install-url with a non-HTTP URL returns 400.
	 */
	public function test_install_url_rejects_non_http() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/skills/install-url' );
		$request->set_param( 'url', 'ftp://example.com/SKILL.md' );

		$response = rest_do_request( $request );

		// REST param validation will reject the invalid URI or our handler will return 400.
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
	}

	/**
	 * POST /skills/install-url without url param returns 400.
	 */
	public function test_install_url_missing_url() {
		$request  = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/skills/install-url' );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}

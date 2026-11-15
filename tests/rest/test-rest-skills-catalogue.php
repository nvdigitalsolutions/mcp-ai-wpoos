<?php
/**
 * Tests for the installed Agent Skills catalogue route.
 *
 * `GET /mcp-ai/v1/skills` backs the console assistant editor's skills picker
 * (090-B parity). The route is a base-plugin surface: the registry lives in
 * includes/, so it must work without the Pro addon.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

/**
 * Tests for the skills catalogue REST route.
 */
class Test_REST_Skills_Catalogue extends WP_UnitTestCase {

	/**
	 * Per-test skills directory.
	 *
	 * @var string
	 */
	private $skills_dir;

	/**
	 * Set up fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Skill_Registry::reset();

		$this->skills_dir = sys_get_temp_dir() . '/wp-mcp-ai-rest-skills-' . uniqid();
		mkdir( $this->skills_dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture directory outside the plugin.

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		$this->recursive_rmdir( $this->skills_dir );

		WP_MCP_AI_Skill_Registry::reset();
		delete_option( WP_MCP_AI_Skill_Registry::OPTION_SKILL_INDEX );

		$GLOBALS['wp_rest_server'] = null;

		parent::tearDown();
	}

	/**
	 * Point the skills directory at the per-test temp dir.
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = $this->skills_dir;
		return $upload_dir;
	}

	/**
	 * Install two skills into the temp registry.
	 *
	 * @return void
	 */
	private function install_skills() {
		$registry = WP_MCP_AI_Skill_Registry::instance();

		$registry->install_skill(
			"---\nname: alpha-skill\ndescription: Summarises long threads.\nlicense: MIT\ncompatibility: wp-6.5\n---\n\n# Alpha\n\nSecret instructions."
		);

		$registry->install_skill(
			"---\nname: beta-skill\ndescription: Translates content.\n---\n\n# Beta\n\nMore instructions."
		);
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
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
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
	}

	/**
	 * The route is registered on the base plugin surface.
	 */
	public function test_route_is_registered() {
		global $wp_rest_server;

		// Fire a fresh rest_api_init so the plugin's own registration hooks
		// (and not a direct register_routes() call) declare the routes.
		$wp_rest_server = null;
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes( 'mcp-ai/v1' );

		$this->assertArrayHasKey( '/mcp-ai/v1/skills', $routes );
	}

	/**
	 * Administrators get the installed catalogue with metadata only.
	 */
	public function test_admin_can_read_the_catalogue() {
		$this->install_skills();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$controller = new WP_MCP_AI_REST_MCP_Controller();
		$response   = $controller->handle_skills_catalogue( new WP_REST_Request( 'GET', '/mcp-ai/v1/skills' ) );

		$this->assertNotWPError( $response );

		$data = $response->get_data();

		$this->assertSame( 2, $data['total'] );
		$this->assertCount( 2, $data['skills'] );

		$by_name = array();
		foreach ( $data['skills'] as $skill ) {
			$by_name[ $skill['name'] ] = $skill;
		}

		$this->assertArrayHasKey( 'alpha-skill', $by_name );
		$this->assertArrayHasKey( 'beta-skill', $by_name );

		$this->assertSame( 'Summarises long threads.', $by_name['alpha-skill']['description'] );
		$this->assertSame( 'MIT', $by_name['alpha-skill']['license'] );
		$this->assertSame( 'wp-6.5', $by_name['alpha-skill']['compatibility'] );

		// Catalogue metadata only: instructions and tool grants never ship here.
		foreach ( $data['skills'] as $skill ) {
			$this->assertSame(
				array( 'name', 'description', 'license', 'compatibility' ),
				array_keys( $skill )
			);
			$this->assertArrayNotHasKey( 'instructions', $skill );
			$this->assertArrayNotHasKey( 'allowed_tools', $skill );
		}
	}

	/**
	 * Non-administrators are refused.
	 */
	public function test_editor_is_denied() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$controller = new WP_MCP_AI_REST_MCP_Controller();
		$result     = $controller->permissions_check_skills_catalogue( new WP_REST_Request( 'GET', '/mcp-ai/v1/skills' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Administrators pass the permission check.
	 */
	public function test_admin_passes_permission_check() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$controller = new WP_MCP_AI_REST_MCP_Controller();

		$this->assertTrue(
			$controller->permissions_check_skills_catalogue( new WP_REST_Request( 'GET', '/mcp-ai/v1/skills' ) )
		);
	}

	/**
	 * An empty registry returns an empty list, not an error.
	 */
	public function test_empty_registry_returns_empty_list() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$controller = new WP_MCP_AI_REST_MCP_Controller();
		$response   = $controller->handle_skills_catalogue( new WP_REST_Request( 'GET', '/mcp-ai/v1/skills' ) );

		$this->assertNotWPError( $response );

		$data = $response->get_data();

		$this->assertSame( array(), $data['skills'] );
		$this->assertSame( 0, $data['total'] );
	}
}

<?php
/**
 * Characterization tests for the ported skill-manager REST controller
 * (Wave F1, sub-cluster 3b-2).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   controller; the mcp-ai-pro/v1 skills surface is asserted.
 * - Standalone matrix (base plugin absent): the ported controller in
 *   `src/rest/class-wp-mcp-ai-skill-manager-rest-controller.php` is
 *   asserted in full, backed by the Platform addon's SkillRegistry/
 *   SkillParser ports.
 *
 * Skills write into the test environment's uploads dir (byte-identical
 * `get_skills_dir()`), never the repo tree — install/uninstall round-trips
 * are safe in both matrices.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Skill manager REST controller tests.
 */
class Test_Skill_Manager_REST_Controller extends WP_UnitTestCase {

	/**
	 * Sample valid SKILL.md content for the round-trip tests.
	 *
	 * @var string
	 */
	private const SKILL_CONTENT = "---\nname: test-rest-skill\ndescription: Test skill installed via REST.\n---\n\n# Test Skill\n\nInstructions body.\n";

	/**
	 * Track the admin user ID used across dispatches.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Create an administrator for the capability-gated dispatches.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Remove any skill installed by the round-trip tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$registry   = defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_Skill_Registry::instance()
			: \NvoosContentGraphAiPlatform\Skills\SkillRegistry::instance();
		$skills_dir = trailingslashit( $registry->get_skills_dir() ) . 'test-rest-skill';
		if ( is_dir( $skills_dir ) ) {
			$items = scandir( $skills_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup only.
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( '.' !== $item && '..' !== $item ) {
						unlink( trailingslashit( $skills_dir ) . $item ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup only.
					}
				}
			}
			rmdir( $skills_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Cleanup only.
		}
		parent::tearDown();
	}

	/**
	 * Ensure the routes are registered and dispatch helpers are available.
	 *
	 * @return void
	 */
	private function register_routes(): void {
		// Standalone: the skills-manager init lands with sub-cluster 3b-3 —
		// construct the controller manually so its rest_api_init hook fires.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			new WP_MCP_AI_Skill_Manager_REST_Controller();
		}

		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a REST request as the admin user.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @param array  $params Body/query params.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = array() ): WP_REST_Response {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The byte-identical routes must register and gate subscribers.
	 */
	public function test_routes_register_and_gate(): void {
		$this->register_routes();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/skills', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/skills/install-url', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/skills/(?P<name>[a-z0-9][a-z0-9-]{0,62}[a-z0-9]?)', $routes );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request  = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/skills' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Listing skills as an admin must return a 200 with an array payload.
	 */
	public function test_list_skills_as_admin(): void {
		$this->register_routes();

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/skills' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	/**
	 * Install → read → uninstall round-trip through the REST surface.
	 */
	public function test_create_read_delete_skill_round_trip(): void {
		$this->register_routes();

		$created = $this->dispatch( 'POST', '/mcp-ai-pro/v1/skills', array( 'content' => self::SKILL_CONTENT ) );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'test-rest-skill', $created->get_data()['name'] );

		$read = $this->dispatch( 'GET', '/mcp-ai-pro/v1/skills/test-rest-skill' );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'test-rest-skill', $read->get_data()['name'] );
		$this->assertStringContainsString( '# Test Skill', $read->get_data()['raw_content'] );

		$deleted = $this->dispatch( 'DELETE', '/mcp-ai-pro/v1/skills/test-rest-skill' );
		$this->assertSame( 200, $deleted->get_status() );

		$missing = $this->dispatch( 'GET', '/mcp-ai-pro/v1/skills/test-rest-skill' );
		$this->assertSame( 404, $missing->get_status() );
	}

	/**
	 * Updating a skill whose frontmatter name mismatches must 422 without
	 * touching the installed skill.
	 */
	public function test_update_name_mismatch_returns_422(): void {
		$this->register_routes();

		$this->dispatch( 'POST', '/mcp-ai-pro/v1/skills', array( 'content' => self::SKILL_CONTENT ) );

		$mismatch = $this->dispatch(
			'PUT',
			'/mcp-ai-pro/v1/skills/test-rest-skill',
			array(
				'content' => "---\nname: other-name\ndescription: Mismatched.\n---\n\n# Other\n",
			)
		);
		$this->assertSame( 422, $mismatch->get_status() );
		$this->assertSame( 'rest_skill_name_mismatch', $mismatch->as_error()->get_error_code() );

		// The original skill must remain untouched.
		$read = $this->dispatch( 'GET', '/mcp-ai-pro/v1/skills/test-rest-skill' );
		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 'test-rest-skill', $read->get_data()['name'] );
	}

	/**
	 * Invalid SKILL.md content must surface a parser error, never a 201.
	 */
	public function test_create_with_invalid_content_returns_error(): void {
		$this->register_routes();

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/skills', array( 'content' => 'Just some text with no frontmatter.' ) );
		$this->assertTrue( $response->is_error() );
	}

	/**
	 * The install-url endpoint must reject non-HTTPS URLs without fetching.
	 */
	public function test_install_url_rejects_insecure_urls(): void {
		$this->register_routes();

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/skills/install-url', array( 'url' => 'http://example.com/SKILL.md' ) );
		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'rest_skill_invalid_url', $response->as_error()->get_error_code() );
	}
}

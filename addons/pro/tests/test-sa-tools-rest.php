<?php
/**
 * Tests for the Schedule Anything tool catalogue REST surface.
 *
 * Covers GET /mcp-ai-pro/v1/sa-tools: permission gating, the light row
 * shape (slug/name/description/group/group_label), server-side search,
 * group filtering, pagination with has_more, and the groups facet.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_SA_Tools_REST.
 */
class Test_SA_Tools_REST extends WP_UnitTestCase {

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * Slugs registered by this test class (cleaned up in tearDown).
	 *
	 * @var array<string>
	 */
	protected $stub_slugs = array();

	/**
	 * Load the Schedule Anything REST controller.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			self::markTestSkipped( 'Pro Schedule Manager not available.' );
		}

		$base = dirname( __DIR__, 3 );

		require_once $base . '/addons/pro/includes/schedule-anything/class-wp-mcp-ai-pro-sa-toolkit-adapter.php';
		require_once $base . '/addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php';

		WP_MCP_AI_SA_REST_Controller::init();
	}

	/**
	 * Reset state + register routes between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'sa_tenant_meta' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		do_action( 'rest_api_init' );
	}

	/**
	 * Unregister stubs so they never leak into other test files.
	 */
	protected function tearDown(): void {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		foreach ( $this->stub_slugs as $slug ) {
			$registry->unregister_tool( $slug );
		}
		$this->stub_slugs = array();

		parent::tearDown();
	}

	/**
	 * Register N stub tools sharing a unique prefix.
	 *
	 * @param int $count Number of stubs.
	 * @return string The unique prefix shared by the stubs.
	 */
	protected function register_stubs( $count ) {
		require_once __DIR__ . '/class-sa-tools-rest-stub-tool.php';

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$prefix   = 'sa_test_tool_' . wp_generate_password( 6, false, false ) . '_';

		for ( $i = 1; $i <= $count; $i++ ) {
			$slug = $prefix . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
			$registry->register_tool( new SA_Tools_REST_Stub_Tool( $slug ) );
			$this->stub_slugs[] = $slug;
		}

		return $prefix;
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path (no query string).
	 * @param array  $params Query parameters.
	 * @return WP_REST_Response
	 */
	protected function dispatch( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * Unauthenticated list is forbidden.
	 */
	public function test_list_requires_permission() {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/sa-tools' );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * The unfiltered list reflects the REAL registry: non-empty, paginated,
	 * grouped, and carrying the WordPress Core group.
	 */
	public function test_list_reads_real_registry() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/sa-tools' );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertGreaterThan( 0, $data['total'] );
		$this->assertNotEmpty( $data['tools'] );
		$this->assertTrue( $data['has_more'] );

		$group_slugs = wp_list_pluck( $data['groups'], 'slug' );
		$this->assertContains( 'wordpress-core', $group_slugs );
	}

	/**
	 * Authenticated list returns light rows with the expected fields.
	 */
	public function test_list_returns_light_rows() {
		$prefix = $this->register_stubs( 3 );
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/sa-tools', array( 'search' => $prefix ) );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 3, $data['total'] );
		$this->assertFalse( $data['has_more'] );

		$row = $data['tools'][0];
		$this->assertArrayHasKey( 'slug', $row );
		$this->assertArrayHasKey( 'name', $row );
		$this->assertArrayHasKey( 'description', $row );
		$this->assertArrayHasKey( 'group', $row );
		$this->assertArrayHasKey( 'group_label', $row );
		// The palette never ships the full inputSchema.
		$this->assertArrayNotHasKey( 'inputSchema', $row );
	}

	/**
	 * Search matches tool NAMES (the slug never contains the probe phrase).
	 */
	public function test_search_matches_name() {
		$prefix = $this->register_stubs( 1 );
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/sa-tools', array( 'search' => 'probe name' ) );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $prefix . '01', $data['tools'][0]['slug'] );
	}

	/**
	 * Pagination slices correctly and reports has_more.
	 */
	public function test_pagination() {
		$prefix = $this->register_stubs( 25 );
		wp_set_current_user( $this->admin_id );

		$page1_params = array(
			'search'   => $prefix,
			'per_page' => 10,
			'page'     => 1,
		);
		$page1        = $this->dispatch( 'GET', '/mcp-ai-pro/v1/sa-tools', $page1_params )->get_data();
		$this->assertSame( 25, $page1['total'] );
		$this->assertCount( 10, $page1['tools'] );
		$this->assertTrue( $page1['has_more'] );

		$page3_params = array(
			'search'   => $prefix,
			'per_page' => 10,
			'page'     => 3,
		);
		$page3        = $this->dispatch( 'GET', '/mcp-ai-pro/v1/sa-tools', $page3_params )->get_data();
		$this->assertCount( 5, $page3['tools'] );
		$this->assertFalse( $page3['has_more'] );
	}

	/**
	 * Group facet exposes the groups of matching tools.
	 */
	public function test_groups_facet() {
		$prefix = $this->register_stubs( 2 );
		wp_set_current_user( $this->admin_id );

		$data = $this->dispatch( 'GET', '/mcp-ai-pro/v1/sa-tools', array( 'search' => $prefix ) )->get_data();

		$this->assertNotEmpty( $data['groups'] );
		$slugs = wp_list_pluck( $data['groups'], 'slug' );
		$this->assertContains( 'other', $slugs );
	}
}

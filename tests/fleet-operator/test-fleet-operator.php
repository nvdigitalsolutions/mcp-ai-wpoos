<?php
/**
 * Fleet Operator addon tests.
 *
 * Covers operator credential lifecycle, audience binding, rate limiting,
 * tool allowlist resolution, config generation, and the REST/tool-pipeline
 * integration hooks.
 *
 * @package WP_MCP_AI
 */

// Load the addon classes directly — the addon is not active during tests.
require_once dirname( __DIR__, 2 ) . '/addons/fleet-operator/includes/class-wp-mcp-ai-operator-credential-repository.php';
require_once dirname( __DIR__, 2 ) . '/addons/fleet-operator/includes/class-wp-mcp-ai-operator-tool-scope.php';
require_once dirname( __DIR__, 2 ) . '/addons/fleet-operator/includes/class-wp-mcp-ai-operator-config-generator.php';
require_once dirname( __DIR__, 2 ) . '/addons/fleet-operator/includes/class-wp-mcp-ai-operator-authenticator.php';

/**
 * Test suite for the Fleet Operator addon.
 */
class Test_WP_MCP_AI_Fleet_Operator extends WP_UnitTestCase {

	/**
	 * Created admin user for operator credentials.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		WP_MCP_AI_Operator_Authenticator::reset_current_operator();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Operator_Credential_Repository::OPTION_KEY );
		WP_MCP_AI_Operator_Authenticator::reset_current_operator();
		parent::tearDown();
	}

	/**
	 * Create an operator with defaults for most tests.
	 *
	 * @return array Created result (record + token).
	 */
	protected function create_operator() {
		return WP_MCP_AI_Operator_Credential_Repository::create(
			'Hermes',
			$this->admin_id,
			array( 'create_post', 'get_recent_posts', 'woo_*' ),
			'readwrite',
			0,
			60
		);
	}

	/** Credential lifecycle. */

	/**
	 * Round trip: create → verify → same record.
	 */
	public function test_create_and_verify_round_trip() {
		$created = $this->create_operator();
		$this->assertNotWPError( $created );

		$record = WP_MCP_AI_Operator_Credential_Repository::verify( $created['token'] );
		$this->assertNotWPError( $record );
		$this->assertSame( $created['record']['id'], $record['id'] );
		$this->assertSame( 'active', $record['status'] );
		$this->assertSame( $this->admin_id, $record['user_id'] );
	}

	/**
	 * Wrong secret is rejected.
	 */
	public function test_verify_rejects_wrong_secret() {
		$created = $this->create_operator();
		$id      = $created['record']['id'];
		$bad     = $id . '.' . str_repeat( 'a', 48 );

		$result = WP_MCP_AI_Operator_Credential_Repository::verify( $bad );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_operator_invalid_secret', $result->get_error_code() );
	}

	/**
	 * Revoked credentials are rejected.
	 */
	public function test_verify_rejects_revoked() {
		$created = $this->create_operator();
		$this->assertTrue( WP_MCP_AI_Operator_Credential_Repository::revoke( $created['record']['id'] ) );

		$result = WP_MCP_AI_Operator_Credential_Repository::verify( $created['token'] );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_operator_revoked', $result->get_error_code() );
	}

	/**
	 * Expired credentials are rejected.
	 */
	public function test_verify_rejects_expired() {
		$created = $this->create_operator();
		$id      = $created['record']['id'];

		$all                      = get_option( WP_MCP_AI_Operator_Credential_Repository::OPTION_KEY, array() );
		$all[ $id ]['expires_at'] = time() - 10;
		update_option( WP_MCP_AI_Operator_Credential_Repository::OPTION_KEY, $all, false );

		$result = WP_MCP_AI_Operator_Credential_Repository::verify( $created['token'] );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_operator_expired', $result->get_error_code() );
	}

	/**
	 * Audience binding rejects tokens presented on another site URL.
	 */
	public function test_audience_mismatch_rejected() {
		$created = $this->create_operator();

		$result = WP_MCP_AI_Operator_Credential_Repository::audience_matches(
			$created['record'],
			'https://other-site.example.com'
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_operator_audience_mismatch', $result->get_error_code() );

		$this->assertTrue(
			WP_MCP_AI_Operator_Credential_Repository::audience_matches(
				$created['record'],
				untrailingslashit( home_url( '/' ) )
			)
		);
	}

	/**
	 * The per-operator rate limit kicks in after N verifications.
	 */
	public function test_rate_limit_enforced() {
		$created = WP_MCP_AI_Operator_Credential_Repository::create(
			'Hermes',
			$this->admin_id,
			array( 'create_post' ),
			'readwrite',
			0,
			2
		);
		$this->assertNotWPError( $created );

		$this->assertNotWPError( WP_MCP_AI_Operator_Credential_Repository::verify( $created['token'] ) );
		$this->assertNotWPError( WP_MCP_AI_Operator_Credential_Repository::verify( $created['token'] ) );

		$third = WP_MCP_AI_Operator_Credential_Repository::verify( $created['token'] );
		$this->assertWPError( $third );
		$this->assertSame( 'wp_mcp_ai_operator_rate_limited', $third->get_error_code() );
	}

	/** Tool scope. */

	/**
	 * Entry sanitization keeps slugs, globs, and group prefixes.
	 */
	public function test_sanitize_entry_keeps_globs_and_groups() {
		$this->assertSame( 'woo_*', WP_MCP_AI_Operator_Tool_Scope::sanitize_entry( '  woo_*  ' ) );
		$this->assertSame( 'group:content_publishing', WP_MCP_AI_Operator_Tool_Scope::sanitize_entry( 'group:content_publishing' ) );
		$this->assertSame( 'create_post', WP_MCP_AI_Operator_Tool_Scope::sanitize_entry( 'create_post' ) );
		$this->assertSame( '', WP_MCP_AI_Operator_Tool_Scope::sanitize_entry( "create_post\n<script>" ) );
		$this->assertSame( '', WP_MCP_AI_Operator_Tool_Scope::sanitize_entry( '' ) );
	}

	/**
	 * Toolkit group entries resolve to tool slugs via the registry.
	 */
	public function test_expand_allowlist_resolves_group() {
		$expanded = WP_MCP_AI_Operator_Tool_Scope::expand_allowlist( array( 'group:content_publishing' ) );

		$this->assertContains( 'create_post', $expanded );
		$this->assertNotContains( 'group:content_publishing', $expanded );
	}

	/**
	 * Exact slugs and globs match; strangers do not.
	 */
	public function test_is_tool_allowed_glob_and_exact() {
		$allowlist = array( 'create_post', 'woo_*' );

		$this->assertTrue( WP_MCP_AI_Operator_Tool_Scope::is_tool_allowed( 'create_post', $allowlist ) );
		$this->assertTrue( WP_MCP_AI_Operator_Tool_Scope::is_tool_allowed( 'woo_products', $allowlist ) );
		$this->assertFalse( WP_MCP_AI_Operator_Tool_Scope::is_tool_allowed( 'delete_user', $allowlist ) );
	}

	/**
	 * MCP tools/list payloads are filtered down to the allowlist.
	 */
	public function test_filter_tools_list_scopes_entries() {
		$mcp_tools = array(
			array(
				'name'        => 'create_post',
				'description' => 'Create a post.',
			),
			array(
				'name'        => 'delete_user',
				'description' => 'Delete a user.',
			),
			array(
				'name'        => 'woo_products',
				'description' => 'List products.',
			),
		);

		$filtered = WP_MCP_AI_Operator_Tool_Scope::filter_tools_list( $mcp_tools, array( 'create_post', 'woo_*' ) );

		$names = wp_list_pluck( $filtered, 'name' );
		$this->assertSame( array( 'create_post', 'woo_products' ), $names );
	}

	/**
	 * Write classification detects state-changing tools.
	 */
	public function test_tool_is_write_detects_write_tools() {
		$this->assertTrue( WP_MCP_AI_Operator_Tool_Scope::tool_is_write( 'create_post' ) );
		$this->assertFalse( WP_MCP_AI_Operator_Tool_Scope::tool_is_write( 'no_such_tool' ) );
	}

	/** Config generator. */

	/**
	 * Generated fragments contain endpoint, env indirection, and the allowlist.
	 */
	public function test_generator_output_contains_expected_fragments() {
		$generated = WP_MCP_AI_Operator_Config_Generator::generate_for_site(
			'Hermes',
			'https://example.com',
			'op_abc123.SECRET',
			array( 'create_post', 'get_recent_posts' )
		);

		$this->assertStringContainsString( 'mcp_servers:', $generated['yaml'] );
		$this->assertStringContainsString( 'https://example.com/wp-json/mcp-ai/v1/mcp', $generated['yaml'] );
		$this->assertStringContainsString( 'Authorization: "Bearer ${env:', $generated['yaml'] );
		$this->assertStringContainsString( 'trust: untrusted', $generated['yaml'] );
		$this->assertStringContainsString( "'create_post'", $generated['yaml'] );
		$this->assertSame( 'NVOOS_HERMES_TOKEN=op_abc123.SECRET', $generated['env'] );
	}

	/**
	 * Fleet generation concatenates per-site fragments.
	 */
	public function test_generator_fleet_output() {
		$fleet = WP_MCP_AI_Operator_Config_Generator::generate_fleet(
			array(
				array(
					'label'     => 'Site A',
					'site_url'  => 'https://a.example.com',
					'token'     => 'op_a.SECRET',
					'allowlist' => array( 'create_post' ),
				),
				array(
					'label'     => 'Site B',
					'site_url'  => 'https://b.example.com',
					'token'     => 'op_b.SECRET',
					'allowlist' => array( 'woo_*' ),
				),
			)
		);

		$this->assertStringContainsString( 'site-a:', $fleet['yaml'] );
		$this->assertStringContainsString( 'site-b:', $fleet['yaml'] );
		$this->assertStringContainsString( 'NVOOS_SITE_A_TOKEN=op_a.SECRET', $fleet['env'] );
		$this->assertStringContainsString( 'NVOOS_SITE_B_TOKEN=op_b.SECRET', $fleet['env'] );
	}

	/** Authenticator integration. */

	/**
	 * Foreign token formats are left untouched.
	 */
	public function test_pre_validate_ignores_foreign_tokens() {
		$auth = new WP_MCP_AI_Operator_Authenticator();

		$this->assertNull( $auth->pre_validate_bearer( null, 'cred_abc.secret', new WP_REST_Request() ) );
		$this->assertNull( $auth->pre_validate_bearer( null, 'mcp_at_xyz', new WP_REST_Request() ) );
		$this->assertNull( WP_MCP_AI_Operator_Authenticator::current_operator() );
	}

	/**
	 * A valid op_ token authenticates and maps to its authorizing user.
	 */
	public function test_pre_validate_and_map_user_operator_flow() {
		$created = $this->create_operator();
		$auth    = new WP_MCP_AI_Operator_Authenticator();
		$request = new WP_REST_Request();

		$this->assertTrue( $auth->pre_validate_bearer( null, $created['token'], $request ) );
		$this->assertSame( $created['record']['id'], WP_MCP_AI_Operator_Authenticator::current_operator()['id'] );
		$this->assertSame( $this->admin_id, $auth->map_bearer_user( null, null, $request ) );

		// An unknown non-operator user mapping passes through unchanged.
		WP_MCP_AI_Operator_Authenticator::reset_current_operator();
		$this->assertSame( 0, $auth->map_bearer_user( 0, null, $request ) );
	}

	/**
	 * Tools/call enforcement blocks tools outside the allowlist.
	 */
	public function test_enforce_tool_call_blocks_out_of_scope() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Resolve slugs through the registry: aliases can differ per site.
		$allowed_tool = $registry->get_tool( 'create_post' );
		$allowed_slug = $allowed_tool ? $allowed_tool->get_slug() : 'create_post';

		$all_slugs = array();
		foreach ( $registry->get_tools() as $candidate ) {
			$all_slugs[] = $candidate->get_slug();
		}
		$allowlist      = array( $allowed_slug, 'get_recent_posts', 'woo_*' );
		$forbidden_slug = null;
		foreach ( $all_slugs as $slug ) {
			if ( ! WP_MCP_AI_Operator_Tool_Scope::is_tool_allowed( $slug, $allowlist ) ) {
				$forbidden_slug = $slug;
				break;
			}
		}
		$this->assertNotNull( $forbidden_slug, 'A forbidden tool must exist for this test.' );

		$created = WP_MCP_AI_Operator_Credential_Repository::create(
			'Hermes',
			$this->admin_id,
			$allowlist,
			'readwrite',
			0,
			60
		);
		$auth    = new WP_MCP_AI_Operator_Authenticator();

		$this->assertTrue( $auth->pre_validate_bearer( null, $created['token'], new WP_REST_Request() ) );

		$forbidden = $registry->get_tool( $forbidden_slug );
		$result    = $auth->enforce_tool_call( null, $forbidden, array(), array() );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_operator_tool_forbidden', $result->get_error_code() );

		$this->assertNull( $auth->enforce_tool_call( null, $allowed_tool, array(), array() ) );
	}

	/**
	 * Read-mode credentials cannot invoke write-capable tools.
	 */
	public function test_read_mode_blocks_write_tool() {
		$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'create_post' );
		$this->assertNotNull( $tool );

		$created = WP_MCP_AI_Operator_Credential_Repository::create(
			'Reader',
			$this->admin_id,
			array( $tool->get_slug() ),
			'read',
			0,
			60
		);
		$auth    = new WP_MCP_AI_Operator_Authenticator();

		$this->assertTrue( $auth->pre_validate_bearer( null, $created['token'], new WP_REST_Request() ) );

		$result = $auth->enforce_tool_call( null, $tool, array(), array() );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_operator_read_only', $result->get_error_code() );
	}

	/**
	 * Enforcement passes through when no operator authenticated the request.
	 */
	public function test_enforce_passes_through_without_operator() {
		$auth = new WP_MCP_AI_Operator_Authenticator();
		$tool = WP_MCP_AI_Tool_Registry::get_instance()->get_tool( 'create_post' );

		$this->assertNull( $auth->enforce_tool_call( null, $tool, array(), array() ) );

		$existing = 'short-circuited';
		$this->assertSame( $existing, $auth->enforce_tool_call( $existing, $tool, array(), array() ) );
	}

	/**
	 * Hooks are registered on the base plugin's integration points.
	 */
	public function test_register_hooks() {
		$auth = new WP_MCP_AI_Operator_Authenticator();
		$auth->register_hooks();

		$this->assertSame( 10, has_filter( 'wp_mcp_ai_pre_validate_bearer_token', array( $auth, 'pre_validate_bearer' ) ) );
		$this->assertSame( 10, has_filter( 'wp_mcp_ai_mcp_tools_list', array( $auth, 'scope_tools_list' ) ) );
		$this->assertSame( 10, has_filter( 'wp_mcp_ai_pre_execute_tool', array( $auth, 'enforce_tool_call' ) ) );
		$this->assertSame( 10, has_action( 'wp_mcp_ai_after_tool_execution', array( $auth, 'audit_tool_execution' ) ) );
	}
}

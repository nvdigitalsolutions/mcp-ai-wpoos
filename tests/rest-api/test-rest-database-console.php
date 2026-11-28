<?php
/**
 * Database console REST controller tests (Proposal 167 P3).
 *
 * Pins the three properties the console depends on:
 *
 *  1. the routes register from a context-free module, so a front-end REST
 *     request (the console's origin) is served — bug class 8;
 *  2. every route refuses a session without `manage_options`;
 *  3. a GET never leaks the sealed credential, and a corrupted scope stays
 *     visible instead of disappearing.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Database_Console_Test extends WP_UnitTestCase {

	/**
	 * Skip when the Pro addon or the database kernel is absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Module_Registry' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Db_Runtime' ) || ! WP_MCP_AI_Db_Runtime::load() ) {
			$this->markTestSkipped( 'The database toolkit kernel is not available in this environment.' );
		}
	}

	/**
	 * Tear down the shared server so another test's routes cannot leak in.
	 */
	public function tearDown(): void {
		$GLOBALS['wp_rest_server'] = null;

		parent::tearDown();
	}

	/**
	 * Act as an administrator.
	 */
	private function as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Boot the registry and return the registered console routes.
	 *
	 * @return array<string, mixed>
	 */
	private function routes(): array {
		WP_MCP_AI_Pro_Module_Registry::get_instance()->boot();

		$GLOBALS['wp_rest_server'] = null;

		return rest_get_server()->get_routes( 'mcp-ai-pro/v1' );
	}

	/**
	 * Dispatch one console request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Route path.
	 * @param array<string, mixed> $params Query or body params.
	 * @return WP_REST_Response|WP_Error
	 */
	private function dispatch( string $method, string $path, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/mcp-ai-pro/v1/database' . $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * The module has no admin-context gate.
	 */
	public function test_the_console_module_loads_without_admin_context() {
		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();

		$this->assertTrue(
			$registry->is_loaded( 'rest_database_console' ),
			'The rest_database_console module must load outside the admin context.'
		);
	}

	/**
	 * All six route families register on a fresh rest_api_init.
	 */
	public function test_the_console_routes_register_on_rest_api_init() {
		$routes = $this->routes();

		foreach ( array(
			'/mcp-ai-pro/v1/database/overview',
			'/mcp-ai-pro/v1/database/audit',
			'/mcp-ai-pro/v1/database/health',
			'/mcp-ai-pro/v1/database/scopes',
			'/mcp-ai-pro/v1/database/scopes/(?P<id>[a-z0-9_-]+)',
			'/mcp-ai-pro/v1/database/preview',
			'/mcp-ai-pro/v1/database/connections/(?P<id>[a-z0-9_:-]+)/test',
		) as $route ) {
			$this->assertArrayHasKey( $route, $routes, "Database console route \"{$route}\" must register." );
		}
	}

	/**
	 * Overview on a fresh site: an honest empty state, not an error.
	 */
	public function test_the_overview_reports_an_empty_toolkit() {
		$this->as_admin();
		$this->routes();

		$response = $this->dispatch( 'GET', '/overview' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( array(), $data['data']['connections'] );
		$this->assertSame( array(), $data['data']['scopes'] );
		$this->assertTrue( $data['data']['runtime']['available'] );
		$this->assertSame( array( 'pgsql', 'mysql', 'sqlite' ), array_keys( $data['data']['pdo'] ) );
		$this->assertSame( 500, $data['data']['limits']['max_rows'] );
		$this->assertSame( 500, $data['data']['audit']['retained'] );
		$this->assertNotSame( '', $data['data']['admin_url'] );
	}

	/**
	 * The overview follows the toolkit settings rather than hardcoding them.
	 */
	public function test_the_overview_reflects_the_toolkit_settings() {
		$this->as_admin();
		$this->routes();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_database_toolkit'     => true,
				'enable_database_writes'      => true,
				'database_default_max_rows'   => 120,
				'database_default_timeout_ms' => 2500,
			)
		);

		$data = $this->dispatch( 'GET', '/overview' )->get_data()['data'];

		$this->assertTrue( $data['enabled'] );
		$this->assertTrue( $data['write_path'] );
		$this->assertSame( 120, $data['limits']['max_rows'] );
		$this->assertSame( 2500, $data['limits']['timeout_ms'] );
	}

	/**
	 * A sealed credential never reaches the console, and an unvalidatable
	 * record is reported rather than dropped.
	 */
	public function test_connections_are_redacted_and_invalid_records_are_reported() {
		$this->as_admin();
		$this->routes();

		update_option(
			'wp_mcp_ai_database_connections',
			array(
				'warehouse' => array(
					'id'         => 'warehouse',
					'label'      => 'Warehouse',
					'engine'     => 'pgsql',
					'host'       => 'db.internal',
					'port'       => 5432,
					'database'   => 'analytics',
					'sslmode'    => 'require',
					'credential' => 'sealed-secret-blob',
					'role'       => 'nvoos_reader',
				),
				'broken'    => array(
					'id'     => 'broken',
					'label'  => 'Broken',
					'engine' => 'oracle',
				),
			)
		);

		$response = $this->dispatch( 'GET', '/overview' );
		$payload  = wp_json_encode( $response->get_data() );
		$data     = $response->get_data()['data'];

		$this->assertStringNotContainsString( 'sealed-secret-blob', (string) $payload, 'The sealed credential must never be serialized.' );

		$this->assertCount( 2, $data['connections'] );

		$by_id = array();

		foreach ( $data['connections'] as $connection ) {
			$by_id[ $connection['id'] ] = $connection;
		}

		$this->assertSame( 'db.internal', $by_id['warehouse']['host'] );
		$this->assertSame( 'analytics', $by_id['warehouse']['database'] );
		$this->assertTrue( $by_id['warehouse']['has_credential'] );
		$this->assertArrayNotHasKey( 'credential', $by_id['warehouse'] );
		$this->assertArrayHasKey( 'error', $by_id['broken'] );
	}

	/**
	 * A corrupted scope stays visible with its refusal code.
	 */
	public function test_an_invalid_scope_is_reported_rather_than_hidden() {
		$this->as_admin();
		$this->routes();

		update_option(
			'wp_mcp_ai_database_scopes',
			array(
				'analyst'  => array(
					'id'         => 'analyst',
					'label'      => 'Analyst',
					'connection' => 'warehouse',
					'schemas'    => array( 'public' ),
					'tables'     => array( 'public.orders' ),
					'tiers'      => array( 'metadata', 'read' ),
					'principals' => array( 'user:*' ),
				),
				'wildcard' => array(
					'id'         => 'wildcard',
					'connection' => 'warehouse',
					'schemas'    => array( '*' ),
				),
			)
		);

		$scopes = $this->dispatch( 'GET', '/overview' )->get_data()['data']['scopes'];
		$by_id  = array();

		foreach ( $scopes as $scope ) {
			$by_id[ $scope['id'] ] = $scope;
		}

		$this->assertSame( array( 'metadata', 'read' ), $by_id['analyst']['tiers'] );
		$this->assertSame( array( 'public.orders' ), $by_id['analyst']['tables'] );
		$this->assertSame( 'db_scope_not_allowed', $by_id['wildcard']['error'] );
	}

	/**
	 * The audit route filters by refusal code and reports the ring size.
	 */
	public function test_the_audit_route_filters_and_reports_retention() {
		$this->as_admin();
		$this->routes();

		$log = new WP_MCP_AI_Db_Audit_Log();
		$log->append(
			array(
				'connection' => 'warehouse',
				'action'     => 'select',
				'tool'       => 'db_select',
			)
		);
		$log->append(
			array(
				'connection'   => 'warehouse',
				'action'       => 'select',
				'tool'         => 'db_select',
				'refusal_code' => 'db_timeout',
			)
		);

		$data = $this->dispatch( 'GET', '/audit', array( 'refusal_code' => 'db_timeout' ) )->get_data()['data'];

		$this->assertCount( 1, $data['records'] );
		$this->assertSame( 'db_timeout', $data['records'][0]['refusal_code'] );
		$this->assertSame( 500, $data['retained'] );
	}

	/**
	 * Probing an unknown id is a handled 404, not a fatal.
	 */
	public function test_testing_an_unknown_connection_returns_not_found() {
		$this->as_admin();
		$this->routes();

		$response = $this->dispatch( 'POST', '/connections/missing/test' );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'connection_not_found', $data['error']['code'] );
	}

	/**
	 * Every route requires `manage_options`.
	 *
	 * `rest_do_request()` converts a permission `WP_Error` into a response, so
	 * the assertion is the status plus the error code (`error_to_response()`),
	 * never `instanceof WP_Error`.
	 */
	public function test_a_session_without_manage_options_is_refused() {
		$this->routes();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( array(
			array( 'GET', '/overview', array() ),
			array( 'GET', '/audit', array() ),
			array( 'GET', '/health', array() ),
			array( 'POST', '/connections/warehouse/test', array() ),
			// Required args must be present: WordPress validates a request's args
			// before the permission callback runs, so a missing body is a 400 that
			// says nothing about capabilities.
			array( 'POST', '/scopes', array( 'scope' => array( 'id' => 'analyst' ) ) ),
			array( 'DELETE', '/scopes/analyst', array() ),
			array(
				'POST',
				'/preview',
				array(
					'connection' => 'warehouse',
					'scope'      => array( 'id' => 'analyst' ),
				),
			),
		) as $call ) {
			$response = $this->dispatch( $call[0], $call[1], $call[2] );

			$this->assertInstanceOf( WP_REST_Response::class, $response, "{$call[0]} {$call[1]} must be refused." );
			$this->assertContains( $response->get_status(), array( 401, 403 ), "{$call[0]} {$call[1]} must be refused." );

			$body = $response->get_data();

			// A permission refusal is WordPress's own error body (`code`,
			// `message`, `data.status`) — not our `{success, data}` envelope.
			$this->assertIsArray( $body );
			$this->assertArrayHasKey( 'code', $body, "{$call[0]} {$call[1]} must be refused." );
		}
	}

	// ------------------------------------------------------------- editor (P3)

	/**
	 * Seed two parseable connections, each with a successful read-only probe.
	 *
	 * A preview reads the catalogue to resolve identifiers, so the connection
	 * must look servable — but the refusal paths asserted below all happen before
	 * a socket is opened, so no reachable database is required.
	 */
	private function seed_connections(): void {
		$probe = array(
			'reachable'  => true,
			'can_write'  => false,
			'checked_at' => time(),
		);

		update_option(
			'wp_mcp_ai_database_connections',
			array(
				'warehouse' => array(
					'id'       => 'warehouse',
					'label'    => 'Warehouse',
					'engine'   => 'pgsql',
					'host'     => 'db.internal',
					'port'     => 5432,
					'database' => 'analytics',
					'sslmode'  => 'require',
					'role'     => 'nvoos_reader',
					'probe'    => $probe,
				),
				'archive'   => array(
					'id'       => 'archive',
					'label'    => 'Archive',
					'engine'   => 'pgsql',
					'host'     => 'archive.internal',
					'port'     => 5432,
					'database' => 'archive',
					'sslmode'  => 'require',
					'role'     => 'nvoos_reader',
					'probe'    => $probe,
				),
			)
		);
	}

	/**
	 * A valid scope payload pointing at the seeded warehouse.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function scope_payload( array $overrides = array() ): array {
		return array_replace(
			array(
				'id'         => 'analyst',
				'label'      => 'Analyst',
				'connection' => 'warehouse',
				'schemas'    => array( 'public' ),
				'tables'     => array( 'public.orders' ),
				'tiers'      => array( 'metadata', 'read' ),
				'principals' => array( 'user:*' ),
			),
			$overrides
		);
	}

	/**
	 * A save stores the scope and bumps the version only when a grant changes.
	 */
	public function test_a_saved_scope_is_stored_and_its_version_bumps_only_on_a_change() {
		$this->as_admin();
		$this->routes();
		$this->seed_connections();

		$first = $this->dispatch( 'POST', '/scopes', array( 'scope' => $this->scope_payload() ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( $first->get_data()['success'] );
		$this->assertSame( 1, $first->get_data()['data']['scope']['version'] );
		$this->assertSame( array( 'public.orders' ), $first->get_data()['data']['scope']['tables'] );
		$this->assertArrayHasKey( 'analyst', (array) get_option( 'wp_mcp_ai_database_scopes', array() ) );

		// Re-saving the unchanged form must not inflate the grant version.
		$again = $this->dispatch( 'POST', '/scopes', array( 'scope' => $this->scope_payload() ) );

		$this->assertSame( 1, $again->get_data()['data']['scope']['version'] );

		// Widening the allowlist is a real change.
		$widened = $this->dispatch(
			'POST',
			'/scopes',
			array(
				'scope' => $this->scope_payload(
					array( 'tables' => array( 'public.orders', 'public.customers' ) )
				),
			)
		);

		$this->assertSame( 2, $widened->get_data()['data']['scope']['version'] );
	}

	/**
	 * The registry, not the controller, owns the wildcard-schema refusal.
	 */
	public function test_a_scope_with_a_wildcard_schema_is_refused() {
		$this->as_admin();
		$this->routes();
		$this->seed_connections();

		$response = $this->dispatch(
			'POST',
			'/scopes',
			array( 'scope' => $this->scope_payload( array( 'schemas' => array( '*' ) ) ) )
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( 'db_scope_not_allowed', $response->get_data()['error']['code'] );
		$this->assertArrayNotHasKey( 'analyst', (array) get_option( 'wp_mcp_ai_database_scopes', array() ) );
	}

	/**
	 * A scope cannot point at a connection that does not exist.
	 */
	public function test_a_scope_bound_to_an_unknown_connection_is_refused() {
		$this->as_admin();
		$this->routes();
		$this->seed_connections();

		$response = $this->dispatch(
			'POST',
			'/scopes',
			array( 'scope' => $this->scope_payload( array( 'connection' => 'missing' ) ) )
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'unknown_connection', $response->get_data()['error']['code'] );
	}

	/**
	 * A delete removes the grant; an unknown id is a handled 404.
	 */
	public function test_a_scope_can_be_deleted_and_an_unknown_id_is_not_found() {
		$this->as_admin();
		$this->routes();

		update_option( 'wp_mcp_ai_database_scopes', array( 'analyst' => $this->scope_payload() ) );

		$deleted = $this->dispatch( 'DELETE', '/scopes/analyst' );

		$this->assertSame( 200, $deleted->get_status() );
		$this->assertTrue( $deleted->get_data()['data']['deleted'] );
		$this->assertArrayNotHasKey( 'analyst', (array) get_option( 'wp_mcp_ai_database_scopes', array() ) );

		$missing = $this->dispatch( 'DELETE', '/scopes/ghost' );

		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'unknown_scope', $missing->get_data()['error']['code'] );
	}

	/**
	 * A preview without a relation is a field-level 422, never a 500.
	 *
	 * `SelectQuery` refuses an empty relation from its constructor, so the handler
	 * must check the wire shape before constructing it.
	 */
	public function test_preview_requires_a_relation() {
		$this->as_admin();
		$this->routes();
		$this->seed_connections();

		foreach ( array( array(), array( 'columns' => array( 'id' ) ) ) as $query ) {
			$response = $this->dispatch(
				'POST',
				'/preview',
				array(
					'connection' => 'warehouse',
					'scope'      => $this->scope_payload(),
					'query'      => $query,
				)
			);

			$this->assertSame( 422, $response->get_status() );
			$this->assertFalse( $response->get_data()['success'] );
			$this->assertSame( 'relation_required', $response->get_data()['error']['code'] );
		}
	}

	/**
	 * An unknown connection is a 404, matching the tool surface.
	 */
	public function test_preview_refuses_an_unknown_connection() {
		$this->as_admin();
		$this->routes();
		$this->seed_connections();

		$response = $this->dispatch(
			'POST',
			'/preview',
			array(
				'connection' => 'missing',
				'scope'      => $this->scope_payload(),
				'query'      => array( 'relation' => 'public.orders' ),
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'db_unknown_connection', $response->get_data()['error']['code'] );
	}

	/**
	 * A scope that grants another connection cannot be previewed against this one.
	 */
	public function test_preview_refuses_a_scope_bound_to_another_connection() {
		$this->as_admin();
		$this->routes();
		$this->seed_connections();

		$response = $this->dispatch(
			'POST',
			'/preview',
			array(
				'connection' => 'warehouse',
				'scope'      => $this->scope_payload( array( 'connection' => 'archive' ) ),
				'query'      => array( 'relation' => 'public.orders' ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'db_scope_not_allowed', $response->get_data()['error']['code'] );
	}

	/**
	 * The preview runs the real compile order, so the tier gate fires before any socket.
	 */
	public function test_preview_refuses_a_scope_without_the_read_tier() {
		$this->as_admin();
		$this->routes();
		$this->seed_connections();

		$response = $this->dispatch(
			'POST',
			'/preview',
			array(
				'connection' => 'warehouse',
				'scope'      => $this->scope_payload( array( 'tiers' => array( 'metadata' ) ) ),
				'query'      => array( 'relation' => 'public.orders' ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'db_tier_required', $response->get_data()['error']['code'] );
	}
}

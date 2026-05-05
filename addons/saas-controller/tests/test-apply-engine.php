<?php
/**
 * Tests for NVOOS_SaaS_Controller_Apply_Engine.
 *
 * Covers token issuance, single-use enforcement, expiry, plan-shape
 * dispatch, and partial-failure aggregation. All Cloudflare interaction is
 * stubbed via a fake mutating client so the engine is exercised in
 * isolation.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Stub mutating client.
 *
 * Records every call and returns either a canned success row or a
 * `WP_Error` per-method, so each test can assert engine behaviour without
 * touching HTTP.
 */
class NVOOS_SaaS_Stub_Mutating_Client extends NVOOS_SaaS_Controller_Cloudflare_Mutating_Client {
	public $calls       = array();
	public $next_d1     = null;
	public $next_kv     = null;
	public $next_gw     = null;

	public function __construct() { /* no super */ } // phpcs:ignore Generic.Classes.OpeningBraceSameLine

	public function create_d1_database( $name ) {
		$this->calls[] = array( 'd1', $name );
		return null === $this->next_d1
			? array( 'uuid' => 'uuid-' . $name, 'name' => $name )
			: $this->next_d1;
	}

	public function create_kv_namespace( $title ) {
		$this->calls[] = array( 'kv', $title );
		return null === $this->next_kv
			? array( 'id' => 'id-' . $title, 'title' => $title )
			: $this->next_kv;
	}

	public function create_ai_gateway( $slug ) {
		$this->calls[] = array( 'gw', $slug );
		return null === $this->next_gw
			? array( 'id' => 'gw-' . $slug, 'slug' => $slug )
			: $this->next_gw;
	}
}

/**
 * @covers NVOOS_SaaS_Controller_Apply_Engine
 */
class Test_NVOOS_SaaS_Controller_Apply_Engine extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
	}

	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		parent::tearDown();
	}

	private function plan_with_creates() {
		return array(
			'creates' => array(
				array( 'kind' => 'd1', 'name' => 'mcp-oos', 'binding' => 'DB' ),
				array( 'kind' => 'kv', 'title' => 'cache', 'binding' => 'KV' ),
				array( 'kind' => 'ai_gateway', 'slug' => 'mcp-router' ),
				array( 'kind' => 'worker', 'name' => 'mcp-oos-worker' ),
			),
			'updates' => array(),
			'noops'   => array(),
			'orphans' => array(),
			'errors'  => array(),
			'summary' => array( 'creates' => 4, 'updates' => 0, 'noops' => 0, 'orphans' => 0, 'errors' => 0 ),
		);
	}

	public function test_issue_token_returns_plaintext_token_and_caches_plan() {
		$plan   = $this->plan_with_creates();
		$issued = NVOOS_SaaS_Controller_Apply_Engine::issue_token( $plan );

		$this->assertNotEmpty( $issued['token'] );
		$this->assertGreaterThanOrEqual( 32, strlen( $issued['token'] ) );
		$this->assertGreaterThanOrEqual( 60, $issued['expires_in'] );

		// The token should be re-consumable in `consume_token`.
		$consumed = NVOOS_SaaS_Controller_Apply_Engine::consume_token( $issued['token'] );
		$this->assertIsArray( $consumed );
		$this->assertSame( 4, $consumed['summary']['creates'] );
	}

	public function test_consume_token_is_single_use() {
		$plan     = $this->plan_with_creates();
		$issued   = NVOOS_SaaS_Controller_Apply_Engine::issue_token( $plan );
		$first    = NVOOS_SaaS_Controller_Apply_Engine::consume_token( $issued['token'] );
		$second   = NVOOS_SaaS_Controller_Apply_Engine::consume_token( $issued['token'] );

		$this->assertIsArray( $first );
		$this->assertWPError( $second );
		$this->assertSame( 'consumed_apply_token', $second->get_error_code() );
	}

	public function test_consume_token_rejects_unknown_token() {
		$result = NVOOS_SaaS_Controller_Apply_Engine::consume_token( str_repeat( 'a', 43 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'expired_apply_token', $result->get_error_code() );
	}

	public function test_consume_token_rejects_short_token() {
		$result = NVOOS_SaaS_Controller_Apply_Engine::consume_token( 'tooshort' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_apply_token', $result->get_error_code() );
	}

	public function test_apply_dispatches_d1_kv_ai_gateway_and_skips_worker() {
		$stub   = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $stub );
		$result = $engine->apply( $this->plan_with_creates() );

		$this->assertCount( 4, $result['results'] );
		$kinds = array_column( $result['results'], 'kind' );
		$this->assertSame( array( 'd1', 'kv', 'ai_gateway', 'worker' ), $kinds );

		$this->assertSame( 'ok', $result['results'][0]['status'] );
		$this->assertSame( 'ok', $result['results'][1]['status'] );
		$this->assertSame( 'ok', $result['results'][2]['status'] );
		$this->assertSame( 'skipped', $result['results'][3]['status'] );

		$this->assertSame( 3, $result['summary']['ok'] );
		$this->assertSame( 0, $result['summary']['error'] );
		$this->assertSame( 1, $result['summary']['skipped'] );
		$this->assertSame( 3, count( $stub->calls ) );
	}

	public function test_apply_records_partial_failure_when_one_call_errors() {
		$stub          = new NVOOS_SaaS_Stub_Mutating_Client();
		$stub->next_kv = new WP_Error( 'cloudflare_http_403', 'Forbidden' );

		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $stub );
		$result = $engine->apply( $this->plan_with_creates() );

		$this->assertSame( 2, $result['summary']['ok'] );
		$this->assertSame( 1, $result['summary']['error'] );
		$this->assertSame( 1, $result['summary']['skipped'] );

		$kv_row = $result['results'][1];
		$this->assertSame( 'kv', $kv_row['kind'] );
		$this->assertSame( 'error', $kv_row['status'] );
		$this->assertStringContainsString( 'Forbidden', $kv_row['message'] );
	}

	public function test_apply_marks_updates_as_skipped() {
		$plan = array(
			'creates' => array(),
			'updates' => array( array( 'kind' => 'worker', 'name' => 'mcp-oos-worker' ) ),
			'noops'   => array(),
			'orphans' => array(),
			'errors'  => array(),
			'summary' => array( 'creates' => 0, 'updates' => 1, 'noops' => 0, 'orphans' => 0, 'errors' => 0 ),
		);

		$stub   = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $stub );
		$result = $engine->apply( $plan );

		$this->assertCount( 1, $result['results'] );
		$this->assertSame( 'skipped', $result['results'][0]['status'] );
		$this->assertCount( 0, $stub->calls );
	}

	public function test_issue_token_records_internal_audit_entry() {
		NVOOS_SaaS_Controller_Apply_Engine::issue_token( $this->plan_with_creates() );
		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 5 );
		$this->assertNotEmpty( $entries );
		$this->assertSame( 'internal', $entries[0]['channel'] );
		$this->assertSame( 'apply_token_issued', $entries[0]['action'] );
	}
}

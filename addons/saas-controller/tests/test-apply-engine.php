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
	public $next_worker = null;

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

	public $next_delete_d1 = null;
	public $next_delete_kv = null;
	public $next_delete_gw = null;

	public function delete_d1_database( $uuid, $name = '' ) {
		$this->calls[] = array( 'delete_d1', $uuid, $name );
		return null === $this->next_delete_d1
			? array( 'uuid' => $uuid, 'name' => $name )
			: $this->next_delete_d1;
	}

	public function delete_kv_namespace( $namespace_id, $title = '' ) {
		$this->calls[] = array( 'delete_kv', $namespace_id, $title );
		return null === $this->next_delete_kv
			? array( 'id' => $namespace_id, 'title' => $title )
			: $this->next_delete_kv;
	}

	public function delete_ai_gateway( $slug ) {
		$this->calls[] = array( 'delete_gw', $slug );
		return null === $this->next_delete_gw
			? array( 'slug' => $slug )
			: $this->next_delete_gw;
	}

	public function upload_worker_script( $name, $script_body, array $metadata ) {
		$this->calls[] = array( 'worker', $name, strlen( $script_body ), $metadata );
		return null === $this->next_worker
			? array(
				'id'          => $name,
				'etag'        => 'etag-' . substr( hash( 'sha256', $script_body ), 0, 12 ),
				'modified_on' => '2026-05-05T00:00:00Z',
				'size'        => strlen( $script_body ),
			)
			: $this->next_worker;
	}
}

/**
 * @covers NVOOS_SaaS_Controller_Apply_Engine
 */
class Test_NVOOS_SaaS_Controller_Apply_Engine extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		delete_option( NVOOS_SaaS_Controller_Apply_Engine::DEPLOYED_OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
		$this->ensure_worker_dist();
	}

	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		delete_option( NVOOS_SaaS_Controller_Apply_Engine::DEPLOYED_OPTION );
		$this->cleanup_worker_dist();
		parent::tearDown();
	}

	/**
	 * Drop a tiny placeholder `worker/dist/index.js` into the addon
	 * directory so the apply engine has something to read. We track
	 * whether we created the file so tearDown can reverse it cleanly.
	 *
	 * @var bool
	 */
	private $created_dist = false;

	private function worker_dist_path() {
		return NVOOS_SAAS_CONTROLLER_PATH . 'worker/dist/index.js';
	}

	private function ensure_worker_dist() {
		$path = $this->worker_dist_path();
		if ( file_exists( $path ) ) {
			$this->created_dist = false;
			return;
		}
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "export default { fetch() { return new Response('test'); } };\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		$this->created_dist = true;
	}

	private function cleanup_worker_dist() {
		if ( $this->created_dist ) {
			$path = $this->worker_dist_path();
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
			$dir = dirname( $path );
			if ( is_dir( $dir ) && false === ( new \FilesystemIterator( $dir ) )->valid() ) {
				rmdir( $dir );
			}
			$this->created_dist = false;
		}
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

	public function test_apply_dispatches_d1_kv_ai_gateway_and_uploads_worker() {
		$stub   = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $stub );
		$result = $engine->apply( $this->plan_with_creates() );

		$this->assertCount( 4, $result['results'] );
		$kinds = array_column( $result['results'], 'kind' );
		$this->assertSame( array( 'd1', 'kv', 'ai_gateway', 'worker' ), $kinds );

		$this->assertSame( 'ok', $result['results'][0]['status'] );
		$this->assertSame( 'ok', $result['results'][1]['status'] );
		$this->assertSame( 'ok', $result['results'][2]['status'] );
		$this->assertSame( 'ok', $result['results'][3]['status'] );

		$this->assertSame( 4, $result['summary']['ok'] );
		$this->assertSame( 0, $result['summary']['error'] );
		$this->assertArrayNotHasKey( 'skipped', array_filter( $result['summary'] ) ); // No skipped rows now.
		$this->assertSame( 4, count( $stub->calls ) );

		// The Worker upload row should have persisted a deployed-fingerprint option.
		$deployed = get_option( NVOOS_SaaS_Controller_Apply_Engine::DEPLOYED_OPTION );
		$this->assertIsArray( $deployed );
		$this->assertSame( 'mcp-oos-worker', $deployed['worker_name'] );
		$this->assertNotEmpty( $deployed['sha256'] );
		$this->assertNotEmpty( $deployed['etag'] );
	}

	public function test_apply_records_partial_failure_when_one_call_errors() {
		$stub          = new NVOOS_SaaS_Stub_Mutating_Client();
		$stub->next_kv = new WP_Error( 'cloudflare_http_403', 'Forbidden' );

		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $stub );
		$result = $engine->apply( $this->plan_with_creates() );

		$this->assertSame( 3, $result['summary']['ok'] );
		$this->assertSame( 1, $result['summary']['error'] );

		$kv_row = $result['results'][1];
		$this->assertSame( 'kv', $kv_row['kind'] );
		$this->assertSame( 'error', $kv_row['status'] );
		$this->assertStringContainsString( 'Forbidden', $kv_row['message'] );
	}

	public function test_apply_uploads_worker_on_update_row() {
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
		$this->assertSame( 'worker', $result['results'][0]['kind'] );
		$this->assertSame( 'ok', $result['results'][0]['status'] );
		$this->assertStringContainsString( 'updated', $result['results'][0]['message'] );
		$this->assertCount( 1, $stub->calls );
		$this->assertSame( 'worker', $stub->calls[0][0] );
	}

	public function test_apply_records_error_when_worker_dist_missing() {
		// Wipe the placeholder dist created in setUp to simulate a fresh
		// install that has not yet run `npm run build:worker`.
		$this->cleanup_worker_dist();
		$this->created_dist = false;
		if ( file_exists( $this->worker_dist_path() ) ) {
			unlink( $this->worker_dist_path() );
		}
		// Override the dist-path filter to a guaranteed-missing path so we
		// don't depend on the addon already shipping (or not shipping) a
		// real `worker/dist/index.js`.
		$override = function () { return 'worker/dist/__missing-on-purpose__.js'; };
		add_filter( 'nvoos_saas_controller_worker_dist_path', $override );

		$plan = array(
			'creates' => array( array( 'kind' => 'worker', 'name' => 'mcp-oos-worker' ) ),
			'updates' => array(),
			'noops'   => array(),
			'orphans' => array(),
			'errors'  => array(),
			'summary' => array( 'creates' => 1, 'updates' => 0, 'noops' => 0, 'orphans' => 0, 'errors' => 0 ),
		);

		$stub   = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $stub );
		$result = $engine->apply( $plan );

		remove_filter( 'nvoos_saas_controller_worker_dist_path', $override );

		$this->assertSame( 'error', $result['results'][0]['status'] );
		$this->assertStringContainsString( 'build:worker', $result['results'][0]['message'] );
		$this->assertCount( 0, $stub->calls ); // Never reached the client.
	}

	public function test_apply_worker_metadata_carries_d1_and_kv_bindings() {
		// Stash the deployment config so the metadata builder picks it up.
		$cfg = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$cfg->save(
			array(
				'worker_name'     => 'mcp-oos-worker',
				'd1_databases'    => array( array( 'name' => 'mcp-oos', 'binding' => 'DB' ) ),
				'kv_namespaces'   => array( array( 'title' => 'cache', 'binding' => 'CACHE' ) ),
				'ai_gateway_slug' => 'mcp-router',
			)
		);

		$stub   = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $stub );
		$engine->apply(
			array(
				'creates' => array( array( 'kind' => 'worker', 'name' => 'mcp-oos-worker' ) ),
				'updates' => array(),
				'noops'   => array(),
				'orphans' => array(),
				'errors'  => array(),
				'summary' => array( 'creates' => 1, 'updates' => 0, 'noops' => 0, 'orphans' => 0, 'errors' => 0 ),
			)
		);

		$cfg->clear();

		$this->assertCount( 1, $stub->calls );
		$call_meta = $stub->calls[0][3];
		$this->assertSame( 'index.js', $call_meta['main_module'] );
		$bindings = $call_meta['bindings'];
		$types    = array_column( $bindings, 'type' );
		$this->assertContains( 'd1', $types );
		$this->assertContains( 'kv_namespace', $types );
		$this->assertContains( 'plain_text', $types );
	}

	public function test_issue_token_records_internal_audit_entry() {
		NVOOS_SaaS_Controller_Apply_Engine::issue_token( $this->plan_with_creates() );
		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 5 );
		$this->assertNotEmpty( $entries );
		$this->assertSame( 'internal', $entries[0]['channel'] );
		$this->assertSame( 'apply_token_issued', $entries[0]['action'] );
	}

	public function test_stripe_product_row_dispatches_to_stripe_client() {
		$cf     = new NVOOS_SaaS_Stub_Mutating_Client();
		$stripe = new NVOOS_SaaS_Stub_Engine_Stripe_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $cf, $stripe, null );
		$out    = $engine->apply(
			array(
				'creates' => array( array( 'kind' => 'stripe_product', 'id' => 'prod_x', 'name' => 'X' ) ),
				'updates' => array(),
				'noops'   => array(),
				'orphans' => array(),
				'errors'  => array(),
			)
		);
		$this->assertSame( 1, $out['summary']['ok'] );
		$this->assertCount( 1, $stripe->product_calls );
		$this->assertSame( 'prod_x', $stripe->product_calls[0]['id'] );
	}

	public function test_stripe_product_row_skipped_when_no_stripe_client() {
		$cf     = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $cf, null, null );
		$out    = $engine->apply(
			array(
				'creates' => array( array( 'kind' => 'stripe_product', 'id' => 'prod_x', 'name' => 'X' ) ),
				'updates' => array(),
				'noops'   => array(),
				'orphans' => array(),
				'errors'  => array(),
			)
		);
		$this->assertSame( 1, $out['summary']['skipped'] );
		$this->assertSame( 'skipped', $out['results'][0]['status'] );
	}

	public function test_stripe_price_row_dispatches_to_stripe_client() {
		$cf     = new NVOOS_SaaS_Stub_Mutating_Client();
		$stripe = new NVOOS_SaaS_Stub_Engine_Stripe_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $cf, $stripe, null );
		$out    = $engine->apply(
			array(
				'creates' => array(
					array(
						'kind'        => 'stripe_price',
						'lookup_key'  => 'pro_monthly',
						'product_id'  => 'prod_pro',
						'currency'    => 'usd',
						'unit_amount' => 1500,
					),
				),
				'updates' => array(),
				'noops'   => array(),
				'orphans' => array(),
				'errors'  => array(),
			)
		);
		$this->assertSame( 1, $out['summary']['ok'] );
		$this->assertCount( 1, $stripe->price_calls );
		$this->assertSame( 'pro_monthly', $stripe->price_calls[0]['lookup_key'] );
	}

	public function test_openrouter_key_row_dispatches_to_openrouter_client() {
		$cf         = new NVOOS_SaaS_Stub_Mutating_Client();
		$openrouter = new NVOOS_SaaS_Stub_Engine_OpenRouter_Client();
		$engine     = new NVOOS_SaaS_Controller_Apply_Engine( $cf, null, $openrouter );
		$out        = $engine->apply(
			array(
				'creates' => array(
					array( 'kind' => 'openrouter_key', 'label' => 'production', 'limit_usd' => 250.0 ),
				),
				'updates' => array(),
				'noops'   => array(),
				'orphans' => array(),
				'errors'  => array(),
			)
		);
		$this->assertSame( 1, $out['summary']['ok'] );
		$this->assertCount( 1, $openrouter->calls );
		$this->assertSame( 'production', $openrouter->calls[0]['label'] );
		$this->assertSame( 250.0, $openrouter->calls[0]['limit'] );
		// Plaintext key value surfaces in detail.
		$this->assertNotEmpty( $out['results'][0]['detail']['key'] );
	}

	public function test_openrouter_key_row_records_error_status_on_upstream_failure() {
		$cf         = new NVOOS_SaaS_Stub_Mutating_Client();
		$openrouter = new NVOOS_SaaS_Stub_Engine_OpenRouter_Client();
		$openrouter->next_error = new WP_Error( 'openrouter_unauthorized', 'Bad provisioning key.' );
		$engine     = new NVOOS_SaaS_Controller_Apply_Engine( $cf, null, $openrouter );
		$out        = $engine->apply(
			array(
				'creates' => array( array( 'kind' => 'openrouter_key', 'label' => 'foo' ) ),
				'updates' => array(),
				'noops'   => array(),
				'orphans' => array(),
				'errors'  => array(),
			)
		);
		$this->assertSame( 1, $out['summary']['error'] );
		$this->assertSame( 'error', $out['results'][0]['status'] );
		$this->assertStringContainsString( 'Bad provisioning key.', $out['results'][0]['message'] );
	}

	// ============================================================
	// Phase 10 — orphan cleanup (HITL-gated delete) coverage.
	// ============================================================

	public function test_orphan_token_round_trip_is_single_use() {
		$orphans = array(
			array( 'kind' => 'd1', 'name' => 'old', 'uuid' => 'uuid-old' ),
		);
		$issued = NVOOS_SaaS_Controller_Apply_Engine::issue_orphan_token( $orphans );
		$this->assertNotEmpty( $issued['token'] );
		$this->assertGreaterThan( 60, $issued['expires_in'] );

		$first = NVOOS_SaaS_Controller_Apply_Engine::consume_orphan_token( $issued['token'] );
		$this->assertIsArray( $first );
		$this->assertCount( 1, $first );
		$this->assertSame( 'uuid-old', $first[0]['uuid'] );

		$second = NVOOS_SaaS_Controller_Apply_Engine::consume_orphan_token( $issued['token'] );
		$this->assertWPError( $second );
		$this->assertSame( 'consumed_orphan_token', $second->get_error_code() );
	}

	public function test_consume_orphan_token_rejects_malformed_and_unknown() {
		$err = NVOOS_SaaS_Controller_Apply_Engine::consume_orphan_token( 'short' );
		$this->assertWPError( $err );
		$this->assertSame( 'invalid_orphan_token', $err->get_error_code() );

		$err = NVOOS_SaaS_Controller_Apply_Engine::consume_orphan_token( str_repeat( 'a', 64 ) );
		$this->assertWPError( $err );
		$this->assertSame( 'expired_orphan_token', $err->get_error_code() );
	}

	public function test_orphan_and_apply_token_namespaces_are_isolated() {
		// A token issued for orphans must not be accepted by consume_token(),
		// and an apply token must not be accepted by consume_orphan_token().
		$orphan_token = NVOOS_SaaS_Controller_Apply_Engine::issue_orphan_token(
			array( array( 'kind' => 'd1', 'uuid' => 'u1', 'name' => 'old' ) )
		)['token'];
		$apply_token  = NVOOS_SaaS_Controller_Apply_Engine::issue_token(
			array( 'creates' => array(), 'updates' => array() )
		)['token'];

		$this->assertWPError( NVOOS_SaaS_Controller_Apply_Engine::consume_token( $orphan_token ) );
		$this->assertWPError( NVOOS_SaaS_Controller_Apply_Engine::consume_orphan_token( $apply_token ) );
	}

	public function test_apply_orphans_dispatches_per_kind_to_clients() {
		$cf  = new NVOOS_SaaS_Stub_Mutating_Client();
		$str = new NVOOS_SaaS_Stub_Engine_Stripe_Client();
		$or  = new NVOOS_SaaS_Stub_Engine_OpenRouter_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $cf, $str, $or );

		$cached = array(
			array( 'kind' => 'd1', 'name' => 'gone-d1', 'uuid' => 'uuid-d1' ),
			array( 'kind' => 'kv', 'title' => 'gone-kv', 'id' => 'id-kv' ),
			array( 'kind' => 'ai_gateway', 'slug' => 'gone-gw' ),
			array( 'kind' => 'stripe_product', 'id' => 'prod_gone' ),
			array( 'kind' => 'stripe_price', 'id' => 'price_gone' ),
			array( 'kind' => 'openrouter_key', 'label' => 'gone-key', 'hash' => 'h-gone' ),
		);

		// Operator selects every row.
		$out = $engine->apply_orphans( $cached, $cached );

		$this->assertSame( 6, count( $out['results'] ) );
		$this->assertSame( 6, $out['summary']['ok'] );
		$this->assertSame( 0, $out['summary']['error'] );
		$this->assertSame( 0, $out['summary']['rejected'] );

		// CF stub recorded all three deletes.
		$cf_delete_actions = array();
		foreach ( $cf->calls as $call ) {
			if ( 0 === strpos( $call[0], 'delete_' ) ) {
				$cf_delete_actions[] = $call[0];
			}
		}
		$this->assertContains( 'delete_d1', $cf_delete_actions );
		$this->assertContains( 'delete_kv', $cf_delete_actions );
		$this->assertContains( 'delete_gw', $cf_delete_actions );

		$this->assertSame( array( 'prod_gone' ), $str->archive_product_calls );
		$this->assertSame( array( 'price_gone' ), $str->archive_price_calls );
		$this->assertSame( 'h-gone', $or->delete_calls[0]['hash'] );
	}

	public function test_apply_orphans_rejects_selections_not_in_cached_set() {
		$cf     = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $cf );

		$cached = array(
			array( 'kind' => 'd1', 'name' => 'reviewed', 'uuid' => 'uuid-reviewed' ),
		);

		// Forged: the operator's browser submitted a row that was not in
		// the previewed list. Apply_orphans must refuse to dispatch it.
		$selected = array(
			array( 'kind' => 'd1', 'name' => 'evil', 'uuid' => 'uuid-evil' ),
		);

		$out = $engine->apply_orphans( $selected, $cached );

		$this->assertSame( 1, $out['summary']['rejected'] );
		$this->assertSame( 'rejected', $out['results'][0]['status'] );
		// Critically: no delete call was made on the underlying client.
		foreach ( $cf->calls as $call ) {
			$this->assertNotEquals( 'delete_d1', $call[0] );
		}
	}

	public function test_apply_orphans_skips_stripe_when_credential_missing() {
		$cf     = new NVOOS_SaaS_Stub_Mutating_Client();
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $cf ); // no stripe / openrouter

		$cached = array(
			array( 'kind' => 'stripe_product', 'id' => 'prod_x' ),
			array( 'kind' => 'openrouter_key', 'label' => 'k', 'hash' => 'h' ),
		);
		$out = $engine->apply_orphans( $cached, $cached );

		$this->assertSame( 2, $out['summary']['skipped'] );
		$this->assertSame( 0, $out['summary']['ok'] );
		$this->assertSame( 0, $out['summary']['error'] );
	}

	public function test_apply_orphans_records_error_on_client_failure() {
		$cf = new NVOOS_SaaS_Stub_Mutating_Client();
		$cf->next_delete_d1 = new WP_Error( 'cloudflare_http_403', 'Forbidden' );
		$engine = new NVOOS_SaaS_Controller_Apply_Engine( $cf );

		$cached = array( array( 'kind' => 'd1', 'name' => 'gone', 'uuid' => 'u1' ) );
		$out    = $engine->apply_orphans( $cached, $cached );

		$this->assertSame( 1, $out['summary']['error'] );
		$this->assertSame( 'error', $out['results'][0]['status'] );
		$this->assertStringContainsString( 'Forbidden', $out['results'][0]['message'] );
	}
}

/**
 * Stub Stripe client for the apply-engine tests — extends the real class
 * so the engine's `instanceof` check accepts it without HTTP I/O.
 */
class NVOOS_SaaS_Stub_Engine_Stripe_Client extends NVOOS_SaaS_Controller_Stripe_Client {
	public $product_calls = array();
	public $price_calls   = array();
	public $next_product_error = null;
	public $next_price_error   = null;

	public function __construct() { /* no super */ } // phpcs:ignore Generic.Classes.OpeningBraceSameLine

	public function create_product( array $product ) {
		$this->product_calls[] = $product;
		if ( null !== $this->next_product_error ) {
			return $this->next_product_error;
		}
		return array(
			'id'   => $product['id'],
			'name' => $product['name'],
		);
	}

	public function create_price( array $price ) {
		$this->price_calls[] = $price;
		if ( null !== $this->next_price_error ) {
			return $this->next_price_error;
		}
		return array(
			'id'         => 'price_' . substr( hash( 'sha256', $price['lookup_key'] ), 0, 8 ),
			'lookup_key' => $price['lookup_key'],
			'product'    => $price['product_id'],
		);
	}

	public $archive_product_calls   = array();
	public $archive_price_calls     = array();
	public $next_archive_prod_error = null;
	public $next_archive_price_error = null;

	public function archive_product( $id ) {
		$this->archive_product_calls[] = $id;
		if ( null !== $this->next_archive_prod_error ) {
			return $this->next_archive_prod_error;
		}
		return array( 'id' => (string) $id, 'active' => false );
	}

	public function archive_price( $id ) {
		$this->archive_price_calls[] = $id;
		if ( null !== $this->next_archive_price_error ) {
			return $this->next_archive_price_error;
		}
		return array( 'id' => (string) $id, 'active' => false );
	}
}

/**
 * Stub OpenRouter client for the apply-engine tests.
 */
class NVOOS_SaaS_Stub_Engine_OpenRouter_Client extends NVOOS_SaaS_Controller_OpenRouter_Client {
	public $calls      = array();
	public $next_error = null;

	public function __construct() { /* no super */ } // phpcs:ignore Generic.Classes.OpeningBraceSameLine

	public function create_key( $label, $limit_usd = null ) {
		$this->calls[] = array( 'label' => (string) $label, 'limit' => $limit_usd );
		if ( null !== $this->next_error ) {
			return $this->next_error;
		}
		return array(
			'label' => (string) $label,
			'hash'  => 'h-' . substr( hash( 'sha256', (string) $label ), 0, 8 ),
			'key'   => 'sk-or-stub-' . (string) $label,
		);
	}

	public $delete_calls       = array();
	public $next_delete_error  = null;

	public function delete_key( $hash, $label = '' ) {
		$this->delete_calls[] = array( 'hash' => (string) $hash, 'label' => (string) $label );
		if ( null !== $this->next_delete_error ) {
			return $this->next_delete_error;
		}
		return array( 'hash' => (string) $hash, 'label' => (string) $label );
	}
}

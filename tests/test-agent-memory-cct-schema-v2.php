<?php
/**
 * Tests for the Memory Layer 2026 Phase 2 CCT schema additions.
 *
 * Covers:
 *  - The five new meta fields appear in `get_meta_fields()` with unique IDs.
 *  - `build_record_from_event()` populates the new fields with documented
 *    defaults when the event payload omits them (backward compatibility).
 *  - Caller-supplied values override the defaults.
 *  - `confidence_score` is clamped to [0.0, 1.0].
 *  - `content_hash` is computed from normalised content when not supplied.
 *  - `normalise_for_hash()` produces stable hashes across whitespace + case.
 *  - The migrator's idempotence: re-running is a no-op once the option is set.
 *  - The migrator skips gracefully when JetEngine / CCT module is absent.
 *  - Reading the installed/target version helpers.
 *
 * @package WP_MCP_AI
 * @since 1.1.20
 */

if ( ! class_exists( 'WP_MCP_AI_Agent_Memory_CCT_Migrator' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-agent-memory-cct-migrator.php';
}

/**
 * Test case for Phase 2 schema additions.
 *
 * @since 1.1.20
 */
class Test_Agent_Memory_CCT_Schema_V2 extends WP_UnitTestCase {

	/**
	 * Tear down — clear option + filters.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION );
		remove_all_filters( 'wp_mcp_ai_memory_cct_migrator_enabled' );
		remove_all_filters( 'wp_mcp_ai_memory_cct_record' );
		parent::tearDown();
	}

	/* ------------------------------------------------------------------
	 * 1. Meta-field declarations
	 * ------------------------------------------------------------------ */

	/**
	 * The five new fields must appear in the meta-field list with unique IDs
	 * disjoint from the pre-v2 fields.
	 */
	public function test_new_meta_fields_are_declared() {
		$ref         = new ReflectionClass( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' );
		$meta_method = $ref->getMethod( 'get_meta_fields' );
		$meta_method->setAccessible( true );
		$fields = (array) $meta_method->invoke( null );

		$names_seen = array();
		$ids_seen   = array();
		foreach ( $fields as $field ) {
			$names_seen[ $field['name'] ] = true;
			$ids_seen[]                   = (int) $field['id'];
		}

		$this->assertArrayHasKey( 'content_hash', $names_seen );
		$this->assertArrayHasKey( 'confidence_score', $names_seen );
		$this->assertArrayHasKey( 'last_accessed_at', $names_seen );
		$this->assertArrayHasKey( 'superseded_by', $names_seen );
		$this->assertArrayHasKey( 'auto_captured', $names_seen );

		// All IDs must be unique.
		$this->assertCount( count( $ids_seen ), array_unique( $ids_seen ), 'Meta-field IDs must be unique.' );
	}

	/* ------------------------------------------------------------------
	 * 2. build_record_from_event — defaults
	 * ------------------------------------------------------------------ */

	/**
	 * Legacy events that omit the new fields must produce records with
	 * documented defaults (confidence 1.0, last_accessed_at = stored_at,
	 * auto_captured = 0, superseded_by = '').
	 */
	public function test_build_record_applies_defaults_for_legacy_event() {
		$event = array(
			'context_id'     => 'ctx_legacy_1',
			'agent_id'       => 'agent_1',
			'context_type'   => 'fact',
			'content'        => 'Plain content without secrets.',
			'stored_at'      => '2026-01-15 10:00:00',
			'expires_at'     => '2026-02-15 10:00:00',
		);

		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event( $event );

		$this->assertSame( '1.0', $record['confidence_score'] );
		$this->assertSame( '2026-01-15 10:00:00', $record['last_accessed_at'] );
		$this->assertSame( '', $record['superseded_by'] );
		$this->assertSame( 0, $record['auto_captured'] );
		// Content hash must be a 64-char hex SHA-256.
		$this->assertSame( 64, strlen( $record['content_hash'] ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $record['content_hash'] );
	}

	/**
	 * Caller-supplied values must override the defaults.
	 */
	public function test_build_record_uses_caller_supplied_values() {
		$event = array(
			'context_id'       => 'ctx_caller_1',
			'agent_id'         => 'agent_1',
			'context_type'     => 'fact',
			'content'          => 'Some content.',
			'stored_at'        => '2026-01-15 10:00:00',
			'content_hash'     => 'deadbeef' . str_repeat( 'a', 56 ),
			'confidence_score' => 0.75,
			'last_accessed_at' => '2026-01-20 12:00:00',
			'superseded_by'    => 'ctx_newer_1',
			'auto_captured'    => true,
		);

		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event( $event );

		$this->assertSame( 'deadbeef' . str_repeat( 'a', 56 ), $record['content_hash'] );
		$this->assertSame( '0.75', $record['confidence_score'] );
		$this->assertSame( '2026-01-20 12:00:00', $record['last_accessed_at'] );
		$this->assertSame( 'ctx_newer_1', $record['superseded_by'] );
		$this->assertSame( 1, $record['auto_captured'] );
	}

	/**
	 * `confidence_score` must be clamped to [0.0, 1.0].
	 */
	public function test_confidence_score_is_clamped() {
		$record_high = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id'       => 'ctx_clamp_high',
				'agent_id'         => 'agent_1',
				'content'          => 'X',
				'confidence_score' => 1.7,
			)
		);
		$this->assertSame( '1', $record_high['confidence_score'] );

		$record_low = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id'       => 'ctx_clamp_low',
				'agent_id'         => 'agent_1',
				'content'          => 'X',
				'confidence_score' => -0.3,
			)
		);
		$this->assertSame( '0', $record_low['confidence_score'] );
	}

	/**
	 * Non-numeric confidence_score values must fall through to the default of '1.0'.
	 */
	public function test_confidence_score_non_numeric_falls_back_to_default() {
		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id'       => 'ctx_bad_conf',
				'agent_id'         => 'agent_1',
				'content'          => 'X',
				'confidence_score' => 'not a number',
			)
		);
		$this->assertSame( '1.0', $record['confidence_score'] );
	}

	/* ------------------------------------------------------------------
	 * 3. Content hash + normalisation
	 * ------------------------------------------------------------------ */

	/**
	 * Identical normalised content must produce identical hashes regardless of
	 * casing or whitespace.
	 */
	public function test_content_hash_stable_across_normalisation() {
		$a = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id'   => 'ctx_a',
				'agent_id'     => 'agent_1',
				'content'      => 'Hello   WORLD',
			)
		);
		$b = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id'   => 'ctx_b',
				'agent_id'     => 'agent_1',
				'content'      => "hello world",
			)
		);
		$this->assertSame( $a['content_hash'], $b['content_hash'] );
	}

	/**
	 * Different content must produce different hashes.
	 */
	public function test_content_hash_differs_for_different_content() {
		$a = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id' => 'ctx_a',
				'agent_id'   => 'agent_1',
				'content'    => 'apple',
			)
		);
		$b = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id' => 'ctx_b',
				'agent_id'   => 'agent_1',
				'content'    => 'orange',
			)
		);
		$this->assertNotSame( $a['content_hash'], $b['content_hash'] );
	}

	/**
	 * Empty content must produce an empty hash (callers should not key dedup
	 * on a record without content).
	 */
	public function test_empty_content_yields_empty_hash() {
		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id' => 'ctx_empty',
				'agent_id'   => 'agent_1',
				'content'    => '',
			)
		);
		$this->assertSame( '', $record['content_hash'] );
	}

	/**
	 * `normalise_for_hash()` collapses whitespace, lowercases, and trims.
	 */
	public function test_normalise_for_hash_collapses_whitespace_and_case() {
		$n = WP_MCP_AI_Agent_Memory_CCT_Bridge::normalise_for_hash( "  Hello\t  World  \n" );
		$this->assertSame( 'hello world', $n );
	}

	/* ------------------------------------------------------------------
	 * 4. Migrator behaviour
	 * ------------------------------------------------------------------ */

	/**
	 * When the version option is already at CURRENT_VERSION, the migrator is
	 * a no-op (ran=false).
	 */
	public function test_migrator_is_noop_when_already_current() {
		update_option(
			WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION,
			WP_MCP_AI_Agent_Memory_CCT_Migrator::CURRENT_VERSION
		);

		// Promote test user to administrator so the capability gate passes.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = WP_MCP_AI_Agent_Memory_CCT_Migrator::maybe_run();

		$this->assertFalse( $result['ran'], 'Migrator must not re-run when already at current.' );
		$this->assertTrue( $result['succeeded'] );
	}

	/**
	 * Non-admin users skip the upgrade path (data writes work either way).
	 */
	public function test_migrator_skips_when_caller_lacks_manage_options() {
		delete_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION );

		$sub_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$result = WP_MCP_AI_Agent_Memory_CCT_Migrator::maybe_run();

		$this->assertFalse( $result['ran'] );
		$this->assertFalse( $result['succeeded'] );
		$this->assertStringContainsString( 'manage_options', $result['message'] );
	}

	/**
	 * The migrator returns a structured failure (not a fatal) when JetEngine
	 * is unavailable. The version option must NOT advance.
	 */
	public function test_migrator_returns_failure_without_jetengine() {
		delete_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = WP_MCP_AI_Agent_Memory_CCT_Migrator::maybe_run();

		// In the test environment JetEngine is not active, so the migrator
		// should report a graceful failure and leave the version untouched.
		if ( ! function_exists( 'jet_engine' ) ) {
			$this->assertTrue( $result['ran'] );
			$this->assertFalse( $result['succeeded'] );
			$this->assertSame(
				0,
				WP_MCP_AI_Agent_Memory_CCT_Migrator::get_installed_version(),
				'Version option must not advance on failure.'
			);
		} else {
			$this->markTestSkipped( 'JetEngine is active; covered by integration tests instead.' );
		}
	}

	/**
	 * The kill-switch prevents the migrator from registering its admin_init
	 * hook entirely.
	 */
	public function test_migrator_respects_kill_switch() {
		add_filter( 'wp_mcp_ai_memory_cct_migrator_enabled', '__return_false' );
		remove_all_actions( 'admin_init' );

		WP_MCP_AI_Agent_Memory_CCT_Migrator::bootstrap();

		// has_action returns false when the hook is not registered.
		$this->assertFalse(
			has_action( 'admin_init', array( 'WP_MCP_AI_Agent_Memory_CCT_Migrator', 'maybe_run' ) ),
			'Migrator must not register when the kill-switch is engaged.'
		);
	}

	/**
	 * Since v1.1.22 the migrator is disabled by default. With no filter
	 * overrides in place, bootstrap() must NOT register the admin_init hook
	 * — this is the regression test that guards against the historical
	 * sanitize-loop log spam (PRs #5038 / #5039 / #5040 / #5042).
	 */
	public function test_migrator_disabled_by_default_does_not_register_admin_init() {
		remove_all_filters( 'wp_mcp_ai_memory_cct_migrator_enabled' );
		remove_all_actions( 'admin_init' );

		WP_MCP_AI_Agent_Memory_CCT_Migrator::bootstrap();

		$this->assertFalse(
			has_action( 'admin_init', array( 'WP_MCP_AI_Agent_Memory_CCT_Migrator', 'maybe_run' ) ),
			'Migrator must be disabled by default (do not call sanitize_item_request on existing CCTs).'
		);
	}

	/**
	 * When the migrator is disabled (default), bootstrap opportunistically
	 * advances the stored schema version to CURRENT_VERSION so the Memory
	 * Health subtab reads as healthy and downstream consumers short-circuit.
	 */
	public function test_disabled_bootstrap_advances_stored_version_to_current() {
		remove_all_filters( 'wp_mcp_ai_memory_cct_migrator_enabled' );
		delete_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION );

		WP_MCP_AI_Agent_Memory_CCT_Migrator::bootstrap();

		$this->assertSame(
			WP_MCP_AI_Agent_Memory_CCT_Migrator::CURRENT_VERSION,
			WP_MCP_AI_Agent_Memory_CCT_Migrator::get_installed_version(),
			'Disabled bootstrap must bump the stored version so health checks pass.'
		);
	}

	/**
	 * The opportunistic version bump in bootstrap must never roll a higher
	 * stored version backwards (forward-compat guard for future schema bumps
	 * that ship before this bootstrap is updated).
	 */
	public function test_disabled_bootstrap_does_not_roll_back_higher_version() {
		remove_all_filters( 'wp_mcp_ai_memory_cct_migrator_enabled' );

		$future = WP_MCP_AI_Agent_Memory_CCT_Migrator::CURRENT_VERSION + 5;
		update_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION, $future );

		WP_MCP_AI_Agent_Memory_CCT_Migrator::bootstrap();

		$this->assertSame(
			$future,
			WP_MCP_AI_Agent_Memory_CCT_Migrator::get_installed_version(),
			'Disabled bootstrap must never lower a stored version that is already ahead of CURRENT_VERSION.'
		);
	}

	/**
	 * Enabling the filter explicitly (e.g. for development / regression
	 * testing) restores the legacy behaviour: the admin_init hook is wired
	 * and the stored version is NOT auto-bumped in bootstrap.
	 */
	public function test_explicit_enable_restores_admin_init_registration() {
		add_filter( 'wp_mcp_ai_memory_cct_migrator_enabled', '__return_true' );
		delete_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION );
		remove_all_actions( 'admin_init' );

		WP_MCP_AI_Agent_Memory_CCT_Migrator::bootstrap();

		$this->assertNotFalse(
			has_action( 'admin_init', array( 'WP_MCP_AI_Agent_Memory_CCT_Migrator', 'maybe_run' ) ),
			'Explicit enable must wire the admin_init hook.'
		);
		$this->assertSame(
			0,
			WP_MCP_AI_Agent_Memory_CCT_Migrator::get_installed_version(),
			'Explicit enable must NOT auto-advance the stored version; that is maybe_run()\'s job after a real upgrade.'
		);
	}

	/**
	 * Version accessors return the expected integers.
	 */
	public function test_version_accessors_report_expected_integers() {
		delete_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION );

		$this->assertSame( 0, WP_MCP_AI_Agent_Memory_CCT_Migrator::get_installed_version() );
		$this->assertSame( 2, WP_MCP_AI_Agent_Memory_CCT_Migrator::get_target_version() );

		update_option( WP_MCP_AI_Agent_Memory_CCT_Migrator::VERSION_OPTION, 2 );

		$this->assertSame( 2, WP_MCP_AI_Agent_Memory_CCT_Migrator::get_installed_version() );
	}

	/* ------------------------------------------------------------------
	 * 5. Backward compatibility — existing event shapes still work
	 * ------------------------------------------------------------------ */

	/**
	 * A pre-v2 event payload (sensitivity / consent / subject envelope but no
	 * v2 fields) still passes through `build_record_from_event` cleanly.
	 */
	public function test_pre_v2_event_payload_still_works() {
		$event = array(
			'context_id'    => 'ctx_legacy_v1',
			'agent_id'      => 'agent_1',
			'context_type'  => 'fact',
			'content'       => 'Legacy memory.',
			'wing'          => 'client-acme',
			'room'          => 'auth',
			'sensitivity'   => 'confidential',
			'consent_basis' => 'contract',
			'subject_refs'  => array( 'user_42' ),
			'attachments'   => array(),
			'stored_at'     => '2025-12-01 09:00:00',
			'expires_at'    => '2026-12-01 09:00:00',
		);

		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event( $event );

		// Pre-v2 fields still populated correctly.
		$this->assertSame( 'client-acme', $record['wing'] );
		$this->assertSame( 'auth', $record['room'] );
		$this->assertSame( 'confidential', $record['sensitivity'] );
		$this->assertSame( 'contract', $record['consent_basis'] );

		// V2 fields default cleanly.
		$this->assertSame( '1.0', $record['confidence_score'] );
		$this->assertSame( 0, $record['auto_captured'] );
		$this->assertNotEmpty( $record['content_hash'] );
	}

	/**
	 * The `wp_mcp_ai_memory_cct_record` filter still runs (existing
	 * integrations relying on it must continue to work).
	 */
	public function test_cct_record_filter_still_runs() {
		add_filter(
			'wp_mcp_ai_memory_cct_record',
			static function ( $record ) {
				$record['__filter_proof'] = 'present';
				return $record;
			}
		);

		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id' => 'ctx_filter_proof',
				'agent_id'   => 'agent_1',
				'content'    => 'anything',
			)
		);

		$this->assertSame( 'present', $record['__filter_proof'] );
		// And the v2 fields still arrived.
		$this->assertArrayHasKey( 'content_hash', $record );
		$this->assertArrayHasKey( 'confidence_score', $record );
	}
}

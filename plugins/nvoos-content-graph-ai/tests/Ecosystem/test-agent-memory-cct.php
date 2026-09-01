<?php
/**
 * Agent memory CCT trio port tests (Wave D7).
 *
 * Characterization suite for the durable agent-memory store:
 * `AgentMemoriesCct` (JetEngine CCT registration), `AgentMemoryCctBridge`
 * (event → record mapping), `AgentMemoryCctMigrator` (schema version
 * lifecycle), and `AgentMemoryCctReader` (CCT → recall candidate
 * hydration). Assertions mirror the base plugin's memory-CCT tests.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Memory\AgentMemoriesCct;
use NvoosContentGraphAi\Memory\AgentMemoryCctBridge;
use NvoosContentGraphAi\Memory\AgentMemoryCctMigrator;
use NvoosContentGraphAi\Memory\AgentMemoryCctReader;

/**
 * @group memory
 */
class Test_Agent_Memory_Cct extends \WP_UnitTestCase {

	/**
	 * Reflect a protected static method on a class.
	 *
	 * @param string $class  Class name.
	 * @param string $method Method name.
	 * @return \ReflectionMethod
	 */
	private function reflect( string $class, string $method ): \ReflectionMethod {
		$reflection = new \ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );
		return $reflection;
	}

	public function tearDown(): void {
		\delete_option( AgentMemoryCctMigrator::VERSION_OPTION );
		\wp_set_current_user( 0 );

		parent::tearDown();
	}

	// ─── AgentMemoriesCct ───────────────────────────────────────────

	public function test_cct_constants_match_base(): void {
		$this->assertSame( 'ai_agent_memories', AgentMemoriesCct::SLUG );
		$this->assertSame( 'ai_agent_memories', AgentMemoriesCct::get_slug() );
		$this->assertSame( 30000, AgentMemoriesCct::FIELD_ID_BASE );
	}

	public function test_cct_meta_fields_shape(): void {
		$method = $this->reflect( AgentMemoriesCct::class, 'get_meta_fields' );
		$fields = (array) $method->invoke( null );

		$this->assertNotEmpty( $fields );

		// First field is context_id at 30001; IDs are sequential.
		$this->assertSame( 30001, $fields[0]['id'] );
		$this->assertSame( 'context_id', $fields[0]['name'] );
		$this->assertSame( 30001 + count( $fields ) - 1, $fields[ count( $fields ) - 1 ]['id'] );

		foreach ( $fields as $field ) {
			$this->assertTrue( $field['show_in_rest'] );
			$this->assertSame( 'field', $field['object_type'] );
			$this->assertNotEmpty( $field['name'] );
		}

		$names = wp_list_pluck( $fields, 'name' );
		foreach ( array( 'context_id', 'agent_id', 'memory_tier', 'content_hash', 'auto_captured' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	public function test_cct_args_disable_rest_writes(): void {
		$method = $this->reflect( AgentMemoriesCct::class, 'get_cct_args' );
		$args   = (array) $method->invoke( null, 'AI Agent Memories' );

		$this->assertSame( 'manage_options', $args['capability'] );
		$this->assertTrue( $args['rest_get_enabled'] );
		$this->assertFalse( $args['rest_put_enabled'] );
		$this->assertFalse( $args['rest_post_enabled'] );
		$this->assertFalse( $args['rest_delete_enabled'] );
		$this->assertSame( 'ai_agent_memories', $args['slug'] );
		$this->assertArrayHasKey( 'context_id', $args['admin_columns'] );
	}

	public function test_cct_registration_request_shape(): void {
		$method  = $this->reflect( AgentMemoriesCct::class, 'get_registration_request' );
		$request = (array) $method->invoke( null );

		$this->assertSame( 'ai_agent_memories', $request['slug'] );
		$this->assertSame( 'AI Agent Memories', $request['name'] );
		$this->assertArrayHasKey( 'args', $request );
		$this->assertArrayHasKey( 'meta_fields', $request );
	}

	// ─── AgentMemoryCctBridge ───────────────────────────────────────

	public function test_bridge_classify_tier(): void {
		$this->assertSame( 'procedural', AgentMemoryCctBridge::classify_tier( 'tool_call' ) );
		$this->assertSame( 'procedural', AgentMemoryCctBridge::classify_tier( 'workflow' ) );
		$this->assertSame( 'episodic', AgentMemoryCctBridge::classify_tier( 'session' ) );
		$this->assertSame( 'episodic', AgentMemoryCctBridge::classify_tier( 'decision' ) );
		$this->assertSame( 'working', AgentMemoryCctBridge::classify_tier( 'scratchpad' ) );
		$this->assertSame( 'semantic', AgentMemoryCctBridge::classify_tier( 'fact' ) );
		$this->assertSame( 'semantic', AgentMemoryCctBridge::classify_tier( 'unknown_type' ) );
		$this->assertSame( 'semantic', AgentMemoryCctBridge::classify_tier( null ) );
	}

	public function test_bridge_normalise_for_hash(): void {
		$this->assertSame( 'hello world', AgentMemoryCctBridge::normalise_for_hash( "  Hello\t\n WORLD  " ) );
		$this->assertSame( 'café', AgentMemoryCctBridge::normalise_for_hash( '  Café ' ) );
		$this->assertSame( '', AgentMemoryCctBridge::normalise_for_hash( '' ) );
		$this->assertSame( '', AgentMemoryCctBridge::normalise_for_hash( null ) );
	}

	public function test_bridge_record_defaults(): void {
		$record = AgentMemoryCctBridge::build_record_from_event(
			array(
				'context_id'   => 'ctx_1',
				'agent_id'     => 'agent_7',
				'context_type' => 'fact',
				'content'      => 'The site owner is Evan.',
			)
		);

		$this->assertSame( 'publish', $record['cct_status'] );
		$this->assertSame( 'ctx_1', $record['context_id'] );
		$this->assertSame( 'agent_7', $record['agent_id'] );
		$this->assertSame( 'semantic', $record['memory_tier'] );
		$this->assertSame( 'fact', $record['context_type'] );
		$this->assertSame( 'store_agent_context', $record['source'] );
		$this->assertSame( 'medium', $record['importance'] );
		$this->assertSame( 0, $record['verbatim'] );
		$this->assertSame( '[]', $record['tags'] );
		$this->assertSame( 0, $record['ttl_seconds'] );
		$this->assertSame( '1.0', $record['confidence_score'] );
		$this->assertSame( 0, $record['auto_captured'] );

		// Content hash is a computed SHA-256 of normalised content.
		$this->assertSame(
			hash( 'sha256', AgentMemoryCctBridge::normalise_for_hash( 'The site owner is Evan.' ) ),
			$record['content_hash']
		);

		// Bi-temporal validity defaults to stored_at .. expires_at.
		$this->assertSame( $record['transaction_time'], $record['valid_from'] );
		$this->assertSame( $record['expires_at'], $record['valid_until'] );
	}

	public function test_bridge_record_explicit_fields(): void {
		$record = AgentMemoryCctBridge::build_record_from_event(
			array(
				'context_id'       => 'ctx_2',
				'memory_tier'      => 'procedural',
				'tags'             => array( 'one', 'two' ),
				'verbatim'         => true,
				'importance'       => 'critical',
				'content_hash'     => 'abc123',
				'confidence_score' => 2.5, // Clamped to 1.0.
				'auto_captured'    => true,
				'ttl'              => 3600,
				'wing'             => 'client-acme',
				'room'             => 'billing',
			)
		);

		$this->assertSame( 'procedural', $record['memory_tier'] );
		$this->assertSame( '["one","two"]', $record['tags'] );
		$this->assertSame( 1, $record['verbatim'] );
		$this->assertSame( 'critical', $record['importance'] );
		$this->assertSame( 'abc123', $record['content_hash'] );
		// Clamped floats stringify without the trailing .0 (byte-identical
		// to the base — the '1.0' literal only appears as the default).
		$this->assertSame( '1', $record['confidence_score'] );
		$this->assertSame( 1, $record['auto_captured'] );
		$this->assertSame( 3600, $record['ttl_seconds'] );
		$this->assertSame( 'client-acme', $record['wing'] );
		$this->assertSame( 'billing', $record['room'] );
	}

	public function test_bridge_record_filter(): void {
		\add_filter(
			'wp_mcp_ai_memory_cct_record',
			static function ( array $record, array $event ) {
				$record['custom_field'] = $event['custom'] ?? '';
				return $record;
			},
			10,
			2
		);

		$record = AgentMemoryCctBridge::build_record_from_event(
			array(
				'context_id' => 'ctx_3',
				'custom'     => 'yes',
			)
		);

		$this->assertSame( 'yes', $record['custom_field'] );
	}

	public function test_bridge_stored_listener_tolerates_missing_cct(): void {
		AgentMemoryCctBridge::reset_warn_state();

		// No JetEngine in the test env — the listener must no-op without
		// fataling (standalone: the ported CCT class exists but its item
		// handler resolves null without JetEngine).
		AgentMemoryCctBridge::on_memory_stored(
			array(
				'context_id' => 'ctx_4',
				'agent_id'   => 'agent_1',
				'content'    => 'x',
			)
		);

		AgentMemoryCctBridge::on_memory_deleted(
			array(
				'context_id' => 'ctx_4',
			)
		);

		$this->assertTrue( true );
	}

	// ─── AgentMemoryCctMigrator ─────────────────────────────────────

	public function test_migrator_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_memory_cct_schema_version', AgentMemoryCctMigrator::VERSION_OPTION );
		$this->assertSame( 2, AgentMemoryCctMigrator::CURRENT_VERSION );
		$this->assertSame( 2, AgentMemoryCctMigrator::get_target_version() );
	}

	public function test_migrator_maybe_run_current_version_is_noop(): void {
		\update_option( AgentMemoryCctMigrator::VERSION_OPTION, 2 );

		$result = AgentMemoryCctMigrator::maybe_run();

		$this->assertFalse( $result['ran'] );
		$this->assertTrue( $result['succeeded'] );
	}

	public function test_migrator_maybe_run_non_admin_skips(): void {
		\update_option( AgentMemoryCctMigrator::VERSION_OPTION, 0 );
		\wp_set_current_user( 0 );

		$result = AgentMemoryCctMigrator::maybe_run();

		$this->assertFalse( $result['ran'] );
		$this->assertFalse( $result['succeeded'] );
		$this->assertStringContainsString( 'manage_options', $result['message'] );
	}

	public function test_migrator_bootstrap_disabled_advances_version(): void {
		\delete_option( AgentMemoryCctMigrator::VERSION_OPTION );

		AgentMemoryCctMigrator::bootstrap();

		$this->assertSame( 2, AgentMemoryCctMigrator::get_installed_version() );

		// A second bootstrap never rolls a higher value backwards.
		\update_option( AgentMemoryCctMigrator::VERSION_OPTION, 99 );
		AgentMemoryCctMigrator::bootstrap();
		$this->assertSame( 99, AgentMemoryCctMigrator::get_installed_version() );
	}

	// ─── AgentMemoryCctReader ───────────────────────────────────────

	public function test_reader_constant_matches_base(): void {
		$this->assertSame( 500, AgentMemoryCctReader::DEFAULT_LIMIT );
	}

	public function test_reader_preserves_existing_candidates_without_agent(): void {
		$existing = array(
			array(
				'context_id' => 'seed_1',
				'content'    => 'fixture',
			),
		);

		$merged = AgentMemoryCctReader::on_recall_memory_candidates( $existing, array() );
		$this->assertSame( $existing, $merged );

		$merged = AgentMemoryCctReader::on_recall_memory_candidates( $existing, array( 'agent_id' => '' ) );
		$this->assertSame( $existing, $merged );
	}

	public function test_reader_empty_without_cct_table(): void {
		// The JetEngine CCT table does not exist in the test environment —
		// the reader must degrade to the existing (possibly empty) pool.
		$merged = AgentMemoryCctReader::on_recall_memory_candidates(
			array( array( 'context_id' => 'seed_2' ) ),
			array( 'agent_id' => 'agent_9' )
		);

		$this->assertCount( 1, $merged );
		$this->assertSame( 'seed_2', $merged[0]['context_id'] );

		$this->assertSame( array(), AgentMemoryCctReader::get_transient_shaped_records_for_agent( 'agent_9' ) );
		$this->assertSame( array(), AgentMemoryCctReader::get_transient_shaped_records_for_agent( '' ) );
	}

	public function test_reader_decodes_tags_and_metadata(): void {
		$decode_tags = $this->reflect( AgentMemoryCctReader::class, 'decode_tags' );
		$decode_meta = $this->reflect( AgentMemoryCctReader::class, 'decode_metadata' );

		$this->assertSame( array( 'a', 'b' ), $decode_tags->invoke( null, '["a","b"]' ) );
		$this->assertSame( array( 'a' ), $decode_tags->invoke( null, array( 'a', '' ) ) );
		$this->assertSame( array(), $decode_tags->invoke( null, 'not-json' ) );
		$this->assertSame( array(), $decode_tags->invoke( null, '' ) );

		$this->assertSame( array( 'k' => 'v' ), $decode_meta->invoke( null, '{"k":"v"}' ) );
		$this->assertSame( array( 'k' => 'v' ), $decode_meta->invoke( null, array( 'k' => 'v' ) ) );
		$this->assertSame( array(), $decode_meta->invoke( null, 'not-json' ) );
	}

	public function test_reader_row_mappings(): void {
		$map_candidate = $this->reflect( AgentMemoryCctReader::class, 'map_row_to_recall_candidate' );
		$map_transient = $this->reflect( AgentMemoryCctReader::class, 'map_row_to_transient_record' );

		$row = array(
			'context_id'       => 'ctx_9',
			'agent_id'         => 'agent_9',
			'wing'             => 'w',
			'room'             => 'r',
			'title'            => 'T',
			'content'          => 'C',
			'tags'             => '["x"]',
			'memory_tier'      => 'semantic',
			'importance'       => 'high',
			'verbatim'         => '1',
			'valid_from'       => '2026-01-01 00:00:00',
			'valid_until'      => '2026-02-01 00:00:00',
			'expires_at'       => '2026-02-01 00:00:00',
			'transaction_time' => '2026-01-01 00:00:00',
			'source'           => 'tool',
			'context_type'     => 'fact',
			'metadata'         => '{"m":1}',
		);

		$candidate = $map_candidate->invoke( null, $row );
		$this->assertSame( 'ctx_9', $candidate['context_id'] );
		$this->assertSame( 'semantic', $candidate['tier'] );
		$this->assertSame( 'high', $candidate['importance'] );
		$this->assertTrue( $candidate['verbatim'] );
		$this->assertSame( array( 'x' ), $candidate['tags'] );
		$this->assertSame( '2026-01-01 00:00:00', $candidate['stored_at'] );

		$transient = $map_transient->invoke( null, $row );
		$this->assertSame( 'fact', $transient['context_type'] );
		$this->assertSame( 'T', $transient['data']['title'] );
		$this->assertSame( array( 'm' => 1 ), $transient['data']['metadata'] );
	}
}

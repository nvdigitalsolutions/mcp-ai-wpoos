<?php
/**
 * D8 Cluster 3 — harness tool port tests.
 *
 * Standalone matrix: the eight harness tools must surface through the
 * CG-AI core registry and execute with byte-identical envelopes.
 * Monolith matrix: registration is a documented no-op (the base
 * plugin owns the same slugs) but the ported classes must behave
 * identically when invoked directly.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAi\CoreBridge;
use NvoosContentGraphAiPlatform\Harness\Tools\ApplyPromptCueTool;
use NvoosContentGraphAiPlatform\Harness\Tools\EvolveHarnessTool;
use NvoosContentGraphAiPlatform\Harness\Tools\ListPromptCuesTool;
use NvoosContentGraphAiPlatform\Harness\Tools\RecordReflectionTool;
use NvoosContentGraphAiPlatform\Harness\Tools\RetrieveWithProvenanceTool;
use NvoosContentGraphAiPlatform\Harness\Tools\ScopeMemoryTool;
use NvoosContentGraphAiPlatform\Harness\Tools\SelectPromptCueTool;
use NvoosContentGraphAiPlatform\Harness\Tools\SelfConsistencyVoteTool;

/**
 * @group harness
 */
class Test_Harness_Tools extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		delete_option( 'wp_mcp_ai_evolve_harness_log_9999991' );
		delete_option( 'wp_mcp_ai_evolve_harness_log_9999992' );
	}

	/**
	 * Shared tool instances (errors adapter from the CG-AI bridge).
	 *
	 * @return array<string,object>
	 */
	private function tools(): array {
		$errors = CoreBridge::instance()->errors;

		return array(
			'apply_prompt_cue'         => new ApplyPromptCueTool( $errors ),
			'list_prompt_cues'         => new ListPromptCuesTool( $errors ),
			'record_reflection'        => new RecordReflectionTool( $errors ),
			'retrieve_with_provenance' => new RetrieveWithProvenanceTool( $errors ),
			'scope_memory'             => new ScopeMemoryTool( $errors ),
			'select_prompt_cue'        => new SelectPromptCueTool( $errors ),
			'self_consistency_vote'    => new SelfConsistencyVoteTool( $errors ),
			'evolve_harness'           => new EvolveHarnessTool( $errors ),
		);
	}

	public function test_standalone_registry_surfaces_the_eight_slugs(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Standalone-only registration surface.' );
		}

		// The platform test bootstrap requires the plugin file after
		// plugins_loaded has already fired, so the boot-hook wiring never
		// runs here — exercise the registrar directly (it is the unit under
		// test; live boot wiring is covered by the design-stack smoke).
		$registered = \NvoosContentGraphAiPlatform\Harness\HarnessToolRegistrar::register();
		$this->assertSame( 8, $registered );

		// Idempotent: a second call registers nothing new.
		$this->assertSame( 0, \NvoosContentGraphAiPlatform\Harness\HarnessToolRegistrar::register() );

		$bridge = CoreBridge::instance();

		$expected = array(
			'apply_prompt_cue',
			'list_prompt_cues',
			'record_reflection',
			'retrieve_with_provenance',
			'scope_memory',
			'select_prompt_cue',
			'self_consistency_vote',
			'evolve_harness',
		);

		foreach ( $expected as $slug ) {
			$this->assertTrue( $bridge->tools->has( $slug ), "Expected harness tool {$slug} to be registered." );
		}
	}

	public function test_self_consistency_vote_modal_and_agreement(): void {
		$result = $this->tools()['self_consistency_vote']->execute(
			array( 'candidates' => array( 'Paris', 'Paris', 'London' ) ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Paris', $result['result']['answer'] );
		$this->assertSame( 3, $result['result']['total'] );
		$this->assertSame( 0.6667, $result['result']['agreement'] );
	}

	public function test_self_consistency_vote_rejects_empty_candidates(): void {
		$result = $this->tools()['self_consistency_vote']->execute(
			array( 'candidates' => array() ),
			array()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_self_consistency_no_candidates', $result->get_error_code() );
	}

	public function test_scope_memory_reserved_buckets_and_tags(): void {
		$result = $this->tools()['scope_memory']->execute(
			array(
				'assistant_id' => 42,
				'task_class'   => 'code',
				'wing'         => 'client-x',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'code', $result['task_class'] );
		$this->assertTrue( $result['reserved'] );
		$this->assertSame(
			array( 'task_class:code', 'assistant:42', 'wing:client-x' ),
			$result['tags']
		);
		$this->assertIsBool( $result['pii_filter'] );

		// Unrecognised task class is accepted but flagged.
		$custom = $this->tools()['scope_memory']->execute(
			array( 'task_class' => 'bespoke' ),
			array()
		);
		$this->assertFalse( $custom['reserved'] );
	}

	public function test_apply_prompt_cue_unknown_slugs_are_skipped(): void {
		$result = $this->tools()['apply_prompt_cue']->execute(
			array(
				'system_prompt' => 'You are a helpful assistant.',
				'cue_slugs'     => array( 'no_such_cue' ),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $result['applied_cues'] );
		$this->assertSame( array( 'no_such_cue' ), $result['skipped_cues'] );
		$this->assertSame( 'You are a helpful assistant.', $result['system_prompt'] );
	}

	public function test_apply_prompt_cue_requires_slugs(): void {
		$result = $this->tools()['apply_prompt_cue']->execute(
			array( 'system_prompt' => 'prompt' ),
			array()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_apply_prompt_cue_missing_slugs', $result->get_error_code() );
	}

	public function test_list_prompt_cues_returns_catalogue(): void {
		$result = $this->tools()['list_prompt_cues']->execute( array(), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( count( $result['cues'] ), $result['count'] );
	}

	public function test_select_prompt_cue_general_envelope(): void {
		$result = $this->tools()['select_prompt_cue']->execute(
			array( 'task_class' => 'general' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'cue', $result );
	}

	public function test_record_reflection_requires_edit_posts(): void {
		// No ambient user — the execute-time capability check must fire.
		wp_set_current_user( 0 );

		$result = $this->tools()['record_reflection']->execute(
			array( 'reflection' => 'Next time, check Y first.' ),
			array()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_record_reflection_forbidden', $result->get_error_code() );
	}

	public function test_retrieve_with_provenance_rejects_empty_query(): void {
		$result = $this->tools()['retrieve_with_provenance']->execute(
			array( 'query' => '   ' ),
			array()
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_retrieve_with_provenance_empty_query', $result->get_error_code() );
	}

	public function test_evolve_harness_status_empty_log_envelope(): void {
		$result = $this->tools()['evolve_harness']->execute(
			array( 'operation' => 'status' ),
			array( 'assistant_id' => 9999991 )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertSame( 0, $result['data']['count'] );
		$this->assertSame( array(), $result['data']['entries'] );
	}

	public function test_evolve_harness_rejects_invalid_operation_and_component(): void {
		$tool = $this->tools()['evolve_harness'];

		$bad_operation = $tool->execute( array( 'operation' => 'bogus' ), array() );
		$this->assertInstanceOf( \WP_Error::class, $bad_operation );
		$this->assertSame( 'wp_mcp_ai_invalid_operation', $bad_operation->get_error_code() );
		$this->assertSame( 400, $bad_operation->get_error_data()['status'] );

		$bad_component = $tool->execute(
			array(
				'operation' => 'analyze',
				'component' => 'bogus',
			),
			array()
		);
		$this->assertInstanceOf( \WP_Error::class, $bad_component );
		$this->assertSame( 'wp_mcp_ai_invalid_component', $bad_component->get_error_code() );
	}

	public function test_evolve_harness_bootstrap_without_bundles(): void {
		$result = $this->tools()['evolve_harness']->execute(
			array( 'operation' => 'bootstrap' ),
			array( 'assistant_id' => 9999992 )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_no_bundle_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_evolve_harness_sanitize_for_llm_strips_trace_keys(): void {
		$tool = $this->tools()['evolve_harness'];

		$result = array(
			'message'         => 'ok',
			'raw_audit_trail' => 'verbose',
			'data'            => array(
				'full_trace' => 'verbose',
			),
		);

		$sanitized = $tool->sanitize_for_llm( $result );

		$this->assertArrayNotHasKey( 'raw_audit_trail', $sanitized );
		$this->assertArrayNotHasKey( 'full_trace', $sanitized['data'] );
		$this->assertSame( 'ok', $sanitized['message'] );
	}
}

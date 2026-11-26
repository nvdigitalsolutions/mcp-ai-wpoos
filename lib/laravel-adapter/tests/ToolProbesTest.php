<?php
/**
 * Probe gate for promoted tools (Proposal 090-M, G3 "no free success").
 *
 * Readiness is a claim that this runtime can honour the tool. The manifest
 * records the claim; this test proves the claim for every tool promoted in a
 * batch by executing it and checking the payload is its own work rather than a
 * "prepared" acknowledgement.
 *
 * A tool promoted without a probe here is a manifest change without evidence,
 * so add the probe with the row.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

final class ToolProbesTest extends TestCase {

	use RefreshDatabase;

	/**
	 * 090-M Phase 2, batch 2 — read-only tools promoted with a probe each.
	 *
	 * Each case: slug, arguments, expected outcome, data keys to assert.
	 * `expect` is `success` or `error`; an error is a RuntimeException in this
	 * adapter, which is the framework-appropriate signal the registry expects.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>, 2: string, 3: string[]}>
	 */
	public static function probes(): array {
		return array(
			array( 'count_posts', array(), 'success', array( 'total', 'filters' ) ),
			array( 'get_recent_posts', array(), 'success', array() ),
			array( 'search_content', array( 'query' => 'zzz-no-such-content' ), 'success', array() ),
			array( 'get_post_taxonomies', array(), 'error', array() ),
			array( 'get_post_type_schema', array(), 'error', array() ),
			array( 'get_site_summary', array(), 'success', array( 'default_provider' ) ),
			array( 'list_available_models', array(), 'error', array() ),
			array( 'get_setting', array( 'key' => 'nvoos_missing_test_key' ), 'success', array( 'key' ) ),
			array( 'list_settings', array(), 'success', array( 'settings' ) ),

			// 090-M Phase 2, batch 3.
			array( 'get_cache', array( 'key' => 'nvoos_missing_test_key' ), 'success', array( 'key', 'found' ) ),
			array( 'get_file_info', array( 'file_id' => 999999 ), 'error', array() ),
			array( 'get_job_status', array( 'job_id' => 'does-not-exist' ), 'success', array( 'job_id', 'status' ) ),
			array( 'get_post', array( 'post_id' => 999999 ), 'error', array() ),
			array( 'list_batches', array(), 'error', array() ),
			array( 'list_cron_jobs', array(), 'success', array( 'jobs', 'count' ) ),
			array( 'list_jobs', array(), 'success', array( 'jobs', 'total' ) ),
			array( 'list_skills', array(), 'success', array() ),
			array( 'recall_memory', array( 'agent_id' => '7', 'wing' => 'test-wing' ), 'success', array( 'memories', 'count' ) ),
			array( 'search_attachments', array( 'query' => 'zzz-no-such-attachment' ), 'success', array() ),

			// §164.53 — the refusal path: the test host configures no media worker,
			// so the tool must name the absence rather than fail mid-render.
			array( 'capture_webpage_screenshot', array( 'url' => 'https://example.com' ), 'error', array() ),

			// 090-M Phase 2, batch 5 — the pure family.
			array( 'base64', array( 'text' => 'hello' ), 'success', array( '*' ) ),
			array( 'color_convert', array( 'color' => '#ff0000', 'to_format' => 'rgb' ), 'success', array( '*' ) ),
			array( 'count_tokens', array( 'text' => 'hello world' ), 'success', array( '*' ) ),
			array( 'enable_reasoning_mode', array( 'task' => 'What is the capital of France?' ), 'success', array( '*' ) ),
			array( 'extract_domain', array( 'url' => 'https://example.com/path' ), 'success', array( '*' ) ),
			array( 'format_bytes', array( 'bytes' => 1536 ), 'success', array( '*' ) ),
			array( 'format_date', array( 'date' => '2026-01-01T00:00:00Z', 'to_format' => 'Y-m-d' ), 'success', array( '*' ) ),
			array( 'generate_email_template', array( 'components' => array( array( 'type' => 'text', 'content' => 'Hello' ) ), 'output_format' => 'mjml' ), 'success', array( '*' ) ),
			array( 'generate_slug', array( 'text' => 'Hello World' ), 'success', array( '*' ) ),
			array( 'generate_uuid', array(), 'success', array( '*' ) ),
			array( 'hash_string', array( 'text' => 'hello' ), 'success', array( '*' ) ),
			array( 'list_prompt_cues', array( 'task_class' => 'math' ), 'success', array( 'cues' ) ),
			array( 'math_eval', array( 'expression' => '1+1' ), 'success', array( 'result' ) ),
			array( 'merge_arrays', array( 'arrays' => array( array( 1 ), array( 2 ) ) ), 'success', array( 'result' ) ),
			array( 'parse_csv', array( 'csv' => "a,b\n1,2" ), 'success', array( '*' ) ),
			array( 'strip_html', array( 'html' => '<p>hi</p>' ), 'success', array( '*' ) ),
			array( 'suggest_best_model', array( 'priority' => 'cost' ), 'success', array( '*' ) ),
			array( 'time_ago', array( 'date' => '2020-01-01' ), 'success', array( '*' ) ),
			array( 'truncate_text', array( 'text' => 'abcdef', 'max_length' => 3 ), 'success', array( '*' ) ),
			array( 'validate_json', array( 'json' => '{"a":1}' ), 'success', array( '*' ) ),
			array( 'validate_workflow', array( 'workflow' => array( 'steps' => array( array( 'id' => 'a' ) ) ) ), 'success', array( 'valid' ) ),

			// 090-M Phase 2, batch 5 — the delegated family (the browser runs these).
			array( 'client_analyze_sentiment', array( 'text' => 'I love this' ), 'success', array( 'client_side' ) ),
			array( 'client_extract_entities', array( 'text' => 'Ada Lovelace' ), 'success', array( 'client_side' ) ),
			array( 'client_question_answering', array( 'context' => 'Ada Lovelace wrote the first algorithm.', 'question' => 'Who wrote the first algorithm?' ), 'success', array( 'client_side' ) ),
			array( 'client_semantic_search', array( 'query' => 'alpha', 'documents' => array( 'alpha', 'beta' ) ), 'success', array( 'client_side' ) ),
			array( 'client_summarize_text', array( 'text' => 'Some long text' ), 'success', array( 'client_side' ) ),
			array( 'client_translate_text', array( 'text' => 'Hello', 'source_lang' => 'en', 'target_lang' => 'fr' ), 'success', array( 'client_side' ) ),

			// 090-M Phase 2, batch 5 — the service-backed reads. `check_capability`
			// and `get_user_info` are probed in the app suite instead: both read the
			// host `users` table, which the adapter test environment does not carry.
			array( 'content_freshness_checker', array( 'content' => 'Some text' ), 'success', array( 'status', 'needs_review' ) ),
			array( 'get_current_user', array(), 'success', array( 'authenticated' ) ),
			array( 'get_eca', array( 'eca_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_model_information', array( 'model' => 'gpt-4o' ), 'error', array() ),
			array( 'get_post_meta', array( 'post_id' => 999999 ), 'success', array( 'count' ) ),
			array( 'get_profession', array( 'slug' => 'no-such-profession' ), 'error', array() ),
			array( 'get_student', array( 'student_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_task_dependencies', array( 'task_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'list_ecas', array(), 'success', array( 'ecas', 'total' ), array( 'user_id' => 1 ) ),
			array( 'list_professions', array(), 'success', array( 'items', 'total' ) ),
			array( 'list_projects', array(), 'success', array( 'projects' ), array( 'user_id' => 1 ) ),
			array( 'list_students', array(), 'success', array( 'students', 'pagination' ), array( 'user_id' => 1 ) ),
			array( 'list_tasks', array(), 'success', array( 'tasks', 'total' ), array( 'user_id' => 1 ) ),
			array( 'profession_stats', array(), 'success', array( 'total_professions' ) ),
			array( 'scope_memory', array( 'assistant_id' => 7, 'task_class' => 'math', 'wing' => 'test-wing' ), 'success', array( 'tags' ) ),
			array( 'validate_tool_schema', array( 'schema' => array( 'type' => 'object', 'properties' => array() ) ), 'success', array( '*' ) ),
			array( 'verify_information', array( 'claim' => 'The sky is blue.', 'sources' => array( array( 'content' => 'The sky is blue during the day.', 'url' => 'https://example.com', 'credibility' => 'high' ) ) ), 'success', array( 'sources_checked' ) ),

			// 090-M §24-§25 — the content ranking and the local file check. Both read
			// real tables, so they are probed here rather than faked.
			array( 'content_recommendation_engine', array( 'content' => 'Widget installation guide.' ), 'success', array( 'recommendations', 'count' ) ),
			array( 'analyze_file_suitability', array( 'file_id' => 999999 ), 'error', array() ),

			// §164.56 — the deletes refuse an id that is not a record of their type.
			array( 'delete_eca', array( 'eca_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'delete_student', array( 'student_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
		);
	}

	/**
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string               $expect    'success' or 'error'.
	 * @param string[]             $keys      Data keys that must be present on success ('*' = payload must be non-empty).
	 * @param array<string, mixed> $context   Execution context (several reads are auth-gated).
	 */
	#[DataProvider( 'probes' )]
	public function test_promoted_tool_does_its_own_work( string $slug, array $arguments, string $expect, array $keys, array $context = array() ): void {
		$registry = $this->app->make( ToolRegistry::class );

		$this->assertTrue( $registry->has( $slug ), "Promoted tool '{$slug}' is not registered." );

		$result = $registry->get( $slug )->execute( $arguments, $context );

		if ( 'error' === $expect ) {
			$this->assertInstanceOf(
				\Throwable::class,
				$result,
				"'{$slug}' should answer with a typed error, not a success envelope."
			);

			return;
		}

		$this->assertIsArray( $result, "'{$slug}' should answer with the canonical envelope." );
		$this->assertTrue( $result['success'] ?? false, "'{$slug}' should report success." );

		// The exact failure this gate exists for: a tool that answers "…
		// prepared." and leaves the work undone.
		$this->assertDoesNotMatchRegularExpression(
			'/\bprepared\b/i',
			(string) ( $result['message'] ?? '' ),
			"'{$slug}' returned a prepared-style acknowledgement instead of a result."
		);

		foreach ( $keys as $key ) {
			if ( '*' === $key ) {
				$this->assertNotSame(
					array(),
					$result['data'] ?? array(),
					"'{$slug}' returned an empty payload for a valid request."
				);
				continue;
			}

			$this->assertArrayHasKey(
				$key,
				$result['data'] ?? array(),
				"'{$slug}' did not return '{$key}' in its payload."
			);
		}
	}

	/**
	 * Every probed slug must be servable and must say how it was proven.
	 */
	public function test_every_probed_tool_is_servable(): void {
		$manifest = $this->app->make( ToolReadiness::class );

		foreach ( self::probes() as $case ) {
			$slug = $case[0];
			$row  = $manifest->get( $slug );

			$this->assertTrue( $manifest->isServable( $slug ), "'{$slug}' has a probe but is not servable." );
			$this->assertSame(
				'call',
				$row['probe'] ?? '',
				"'{$slug}' has a read probe but does not declare probe: call."
			);
		}
	}
}

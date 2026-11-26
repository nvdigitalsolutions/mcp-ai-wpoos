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

			// §164.57 — the three ECA reads: two refusals and one empty success.
			array( 'get_eca_attendance_report', array( 'eca_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_eca_timetable', array( 'view_type' => 'venue', 'view_id' => 'no-such-venue' ), 'success', array( 'total_sessions', 'timetable' ), array( 'user_id' => 1 ) ),
			array( 'check_eca_conflicts', array( 'check_type' => 'venue', 'venue' => 'nowhere', 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00' ), 'success', array( 'has_conflicts', 'conflicts' ), array( 'user_id' => 1 ) ),
			array( 'generate_eca_analytics', array( 'report_type' => 'participation' ), 'success', array( 'summary', 'rows' ), array( 'user_id' => 1 ) ),
			array( 'generate_eca_participation_report', array( 'scope' => 'school' ), 'success', array( 'columns', 'rows' ), array( 'user_id' => 1 ) ),
			array( 'export_eca_data', array( 'export_type' => 'ecas' ), 'success', array( 'rows', 'file' ), array( 'user_id' => 1 ) ),
			array( 'get_student_participation_summary', array( 'student_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.59 — the five ECA writers: four refusals and one empty list.
			array( 'set_eca_schedule', array( 'eca_id' => 999999, 'sessions' => array( array( 'day' => 'Monday', 'start_time' => '09:00', 'end_time' => '10:00' ) ) ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'manage_eca_term', array( 'action' => 'list' ), 'success', array( 'action', 'total', 'terms' ), array( 'user_id' => 1 ) ),
			array( 'import_ecas_csv', array( 'csv_content' => "name,type
Probe Club,club
", 'dry_run' => true ), 'success', array( 'total_rows', 'created_count', 'dry_run' ), array( 'user_id' => 1 ) ),
			array( 'send_eca_notification', array( 'eca_id' => 999999, 'notification_type' => 'reminder', 'recipients' => 'all_enrolled' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_eca_parent_report', array( 'student_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.60 — the Cloudways reads. No credentials in this environment, so
			// each proves it reaches the client and the client refuses cleanly.
			array( 'cloudways_list_servers', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'cloudways_get_server', array( 'server_id' => 1 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'cloudways_list_apps', array( 'server_id' => 1 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'cloudways_get_app', array( 'server_id' => 1, 'app_id' => 1 ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.61 — services, operations and labels. Same reason: no credentials,
			// so each proves the wiring and the client refusal.
			array( 'cloudways_service_status', array( 'server_id' => 1 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'cloudways_restart_service', array( 'server_id' => 1, 'service' => 'nginx' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'cloudways_get_operation_status', array( 'operation_id' => 'flex-1' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'cloudways_update_server_label', array( 'server_id' => 1, 'label' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.63 — the CRM company pair. The suite refreshes its database per
			// test, so the create leaves nothing behind.
			array( 'create_company', array( 'company_name' => 'Probe Ltd', 'industry' => 'Testing' ), 'success', array( 'company_id', 'company_name' ), array( 'user_id' => 1 ) ),
			array( 'get_companies', array( 'industry' => 'Testing' ), 'success', array( 'companies', 'total_companies' ), array( 'user_id' => 1 ) ),

			// §164.64 — the interaction timeline and the handover bundle. The
			// timeline answers empty for an unknown contact; the handover refuses an
			// unknown deal rather than assembling a bundle with no subject.
			array( 'get_contact_interactions', array( 'contact_id' => 999999 ), 'success', array( 'contact_id', 'total_count', 'interactions' ), array( 'user_id' => 1 ) ),
			array( 'get_crm_handover', array( 'entity' => 'deal', 'entity_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.65 — the contact pair. The manager refuses an id that is not a
			// toolkit contact; the scanner answers on an empty corpus.
			array( 'manage_crm_contact', array( 'action' => 'read', 'contact_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'scan_duplicate_contacts', array(), 'success', array( 'match_field', 'total_contacts', 'duplicate_groups', 'duplicates' ), array( 'user_id' => 1 ) ),

			// §164.66 — the hygiene pair. Both default to a dry run; the archive
			// reports on an empty corpus and the scorer refuses an unknown contact.
			array( 'archive_stale_contacts', array(), 'success', array( 'archived_count', 'skipped_count', 'contacts' ), array( 'user_id' => 1 ) ),
			array( 'recalculate_engagement_scores', array( 'contact_ids' => array( 999999 ) ), 'success', array( 'scored_count', 'failed_count', 'contacts' ), array( 'user_id' => 1 ) ),

			// §164.67 — the lead email search. The working invocation is
			// lead_status=all with a date sort; the defaults find nothing by design.
			array( 'crm_email_search_leads', array( 'lead_status' => 'all', 'orderby' => 'date' ), 'success', array( 'leads', 'total', 'filters', 'scoring' ), array( 'user_id' => 1 ) ),

			// §164.68 — the accounting search. Defaults are benign here: all
			// filters plus a date sort, so an empty corpus is a clean success.
			array( 'crm_email_search_accounting', array(), 'success', array( 'records', 'total', 'filters', 'summary' ), array( 'user_id' => 1 ) ),

			// §164.69 — the correspondence search. The default mode plus the meta
			// sort answers an empty corpus with an empty list.
			array( 'crm_email_search_correspondence', array(), 'success', array( 'contacts', 'total', 'filters' ), array( 'user_id' => 1 ) ),

			// §164.70 — the capture writes through the bound memory store. The
			// wing slug proves the sanitize_key step: Acme Corp is account/acmecorp.
			array( 'crm_capture_interaction', array( 'account_id' => 'Acme Corp', 'room' => 'interactions', 'content' => 'Probe capture.' ), 'success', array( 'wing', 'room', 'tier', 'memory_id', 'expires_at' ), array( 'user_id' => 1 ) ),

			// §164.71 — the F&B table pair. No table is mapped in this
			// environment, so each proves it names the absence instead of
			// fabricating a matrix.
			array( 'fnb_read_table', array( 'table_id' => '3.0' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'fnb_search_table', array( 'table_id' => '3.0' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.72 — the engine. The metric call reaches a table and refuses;
			// the comparison degrades to its two split rows, as WordPress does.
			array( 'fnb_calculate_metric', array( 'metric_id' => 'M-01', 'month' => '2026-09' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'fnb_compare_periods', array( 'month' => '2026-09', 'prev_month' => '2026-08' ), 'success', array( 'month', 'prev_month', 'metrics' ), array( 'user_id' => 1 ) ),
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
		// The ECA export is the one probe that writes through the file store,
		// which refuses bytes it cannot sniff. A host without ext-fileinfo cannot
		// honour that tool, so the claim is skipped rather than failed.
		if ( 'export_eca_data' === $slug && ! \function_exists( 'finfo_open' ) ) {
			$this->markTestSkipped( 'export_eca_data needs ext-fileinfo: the file store cannot sniff without it.' );
		}

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

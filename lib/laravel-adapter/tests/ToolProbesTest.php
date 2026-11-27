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
use Illuminate\Support\Facades\Gate;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

final class ToolProbesTest extends TestCase {

	use RefreshDatabase;

	/**
	 * The probe host allows content reads and writes. These cases prove each
	 * tool does its own work; per-user content policy is exercised in its own
	 * suite (ContentStoreTaxonomyTest, ProjectManagementUpdateTest).
	 */
	protected function setUp(): void {
		parent::setUp();

		foreach ( array( 'read_content', 'edit_content', 'delete_content' ) as $ability ) {
			Gate::define( $ability, static fn ( $user = null ): bool => true );
		}
	}

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

			// §164.73 — the three table-reading tools. M-14 and M-12 answer zeros
			// on an unmapped corpus (the engine does not surface a table error for
			// them); the ledger scan refuses.
			array( 'fnb_days_of_cover', array( 'ingredient_id' => 'ING-1' ), 'success', array( 'ingredient_id', 'shelf_life_days', 'over_ordering_flag', 'value' ), array( 'user_id' => 1 ) ),
			array( 'fnb_weekend_demand', array( 'friday' => '2026-09-25' ), 'success', array( 'weekend', 'per_day', 'supplier_cutoffs' ), array( 'user_id' => 1 ) ),
			array( 'fnb_duplicate_invoice_scan', array(), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.74 — the ten metric wrappers. None surfaces the unmapped table as
			// a tool error: each metric answers a zeroed or empty shape carrying its
			// own `error`, and the wrapper merges it, exactly as WordPress does.
			// The probe therefore asserts the shape the wrapper assembles, which is
			// the wrapper's own work.
			array( 'fnb_budget_variance', array( 'month' => '2026-09' ), 'success', array( 'month', 'rows', 'source' ), array( 'user_id' => 1 ) ),
			array( 'fnb_dish_margin', array( 'item_id' => 'IT-1', 'month' => '2026-09' ), 'success', array( 'item_id', 'value' ), array( 'user_id' => 1 ) ),
			array( 'fnb_expected_use', array( 'ingredient_id' => 'ING-1', 'month' => '2026-09' ), 'success', array( 'ingredient_id', 'month', 'value' ), array( 'user_id' => 1 ) ),
			array( 'fnb_labour_analysis', array( 'month' => '2026-09' ), 'success', array( 'total_payroll', 'm09_overtime_per_100', 'm10_labour_pct' ), array( 'user_id' => 1 ) ),
			array( 'fnb_price_change_impact', array( 'month' => '2026-09' ), 'success', array( 'month', 'value', 'changes' ), array( 'user_id' => 1 ) ),
			array( 'fnb_stock_used', array( 'ingredient_id' => 'ING-1' ), 'success', array( 'ingredient_id', 'value' ), array( 'user_id' => 1 ) ),
			array( 'fnb_stock_variance', array( 'ingredient_id' => 'ING-1' ), 'success', array( 'ingredient_id', 'value' ), array( 'user_id' => 1 ) ),
			array( 'fnb_utilities_per_cover', array( 'month' => '2026-09' ), 'success', array( 'meters', 'costed', 'per_cover', 'covers' ), array( 'user_id' => 1 ) ),
			array( 'fnb_variance_split', array( 'month' => '2026-09', 'prev_month' => '2026-08' ), 'success', array( 'month', 'prev_month', 'total_change', 'lines' ), array( 'user_id' => 1 ) ),
			array( 'fnb_waste_analysis', array( 'month' => '2026-09' ), 'success', array( 'month', 'value', 'waste_value', 'by_reason' ), array( 'user_id' => 1 ) ),

			// §164.75 — the report path. A report and a draft list degrade rather
			// than refuse on an unmapped corpus; CSV writes through the file
			// registry; PDF refuses because no renderer is bound here.
			array( 'fnb_generate_report', array( 'report_id' => 'R-05', 'month' => '2026-09' ), 'success', array( 'report_id', 'sections', 'draft' ), array( 'user_id' => 1 ) ),
			array( 'fnb_export_report', array( 'report_id' => 'R-05', 'format' => 'csv', 'month' => '2026-09' ), 'success', array( 'report_id', 'mode', 'filename' ), array( 'user_id' => 1 ) ),
			array( 'fnb_export_report', array( 'report_id' => 'R-05', 'format' => 'pdf', 'month' => '2026-09' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'fnb_save_draft', array( 'title' => 'F&B probe draft', 'content' => 'Probe body' ), 'success', array( 'location', 'status' ), array( 'user_id' => 1 ) ),
			array( 'fnb_list_drafts', array(), 'success', array( 'drafts', 'count' ), array( 'user_id' => 1 ) ),

			// §164.76 — the audit viewer. The adapter package binds no
			// SystemLogInterface, so the honest "no queryable log" answer is the
			// probe; the entry filter and act-tier flag are pinned in lib/core.
			array( 'fnb_audit_log', array(), 'success', array( 'entries', 'count', 'sources' ), array( 'user_id' => 1 ) ),

			// §164.77 — the orchestration utilities. All four are local and pure,
			// so each probe exercises the real work rather than a degraded path.
			array( 'analyze_data_patterns', array( 'dataset' => array( 1, 2, 3, 4 ), 'analysis_type' => 'trend' ), 'success', array( 'analysis', 'insights' ), array( 'user_id' => 1 ) ),
			array( 'convert_html_to_markdown', array( 'html' => '<h1>Hi</h1><p>There</p>' ), 'success', array( 'markdown', 'original_length', 'markdown_length' ), array( 'user_id' => 1 ) ),
			array( 'extract_structured_data', array( 'content' => '<h1>Title</h1>', 'selectors' => array( 'title' => 'h1' ) ), 'success', array( 'extracted_data', 'fields_extracted' ), array( 'user_id' => 1 ) ),
			array( 'generate_password', array( 'length' => 12 ), 'success', array( 'password', 'strength', 'length' ), array( 'user_id' => 1 ) ),

			// §164.78 — the autonomous loop. `start` writes a session through the
			// bound store; the three reads with an impossible id prove the refusal
			// path. `get_loop_metrics` answers a zeroed payload for that id, because
			// a missing history is not an error. The breaker configuration is read
			// by the same probe user the manage_options gate accepts in the host.
			array( 'manage_autonomous_session', array( 'action' => 'start', 'plan_id' => 1 ), 'success', array( 'session_id', 'status' ), array( 'user_id' => 1 ) ),
			array( 'get_session_status', array( 'session_id' => 'probe-no-such-session' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'check_exit_conditions', array( 'session_id' => 'probe-no-such-session' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'analyze_loop_health', array( 'session_id' => 'probe-no-such-session' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_loop_metrics', array( 'session_id' => 'probe-no-such-session' ), 'success', array( 'metrics' ), array( 'user_id' => 1 ) ),
			array( 'detect_completion_indicators', array( 'text' => 'All requirements met. Objective achieved.' ), 'success', array( 'indicator_count', 'completion_met' ), array( 'user_id' => 1 ) ),
			array( 'configure_circuit_breaker', array( 'action' => 'view' ), 'success', array( 'settings', 'defaults' ), array( 'user_id' => 1 ) ),
			array( 'calculate_orchestration_capacity', array( 'mode' => 'calculate_capacity', 'arrival_rate' => 2, 'service_time' => 1 ), 'success', array( 'littles_law', 'capacity', 'recommendations' ), array( 'user_id' => 1 ) ),

			// §164.79 — the plan and template family. Creates exercise the real
			// content store; reads and updates with impossible ids prove the
			// refusal path. The seeder writes the whole fourteen-template library
			// into its own refreshed database.
			array( 'create_task_plan', array( 'plan_name' => 'Probe plan', 'goal' => 'Probe goal.', 'tasks' => array( array( 'text' => 'First', 'priority' => 'high' ), array( 'text' => 'Second' ) ) ), 'success', array( 'plan_id', 'task_count', 'markdown' ), array( 'user_id' => 1 ) ),
			array( 'get_task_plan', array( 'plan_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'update_task_plan', array( 'plan_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'create_template', array( 'template_name' => 'Probe template', 'description' => 'A template.', 'category' => 'research', 'markdown_template' => "# {{goal}}\n- [ ] Do it\n" ), 'success', array( 'template_id', 'placeholders', 'storage_type' ), array( 'user_id' => 1 ) ),
			array( 'list_templates', array(), 'success', array( 'templates', 'total_count', 'filters' ), array( 'user_id' => 1 ) ),
			array( 'instantiate_template', array( 'template_id' => 999999, 'variables' => array() ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'seed_template_library', array(), 'success', array( 'templates_created', 'created', 'errors' ), array( 'user_id' => 1 ) ),
			array( 'create_execution_prompt', array( 'plan_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.80 — the schedule family. The create writes into the bound
			// ScheduleStoreInterface, the listing reads it back, and the eight
			// id-taking tools are probed with an impossible id to prove the refusal
			// path. The planner runs with dry_run so the suite leaves one schedule
			// behind, not one per item.
			array( 'create_pro_schedule', array( 'hook' => 'probe_schedule_hook', 'name' => 'Probe schedule', 'schedule' => 'daily' ), 'success', array( 'schedule_id', 'schedule', 'next_run' ), array( 'user_id' => 1 ) ),
			array( 'list_pro_schedules', array(), 'success', array( 'schedules', 'total' ), array( 'user_id' => 1 ) ),
			array( 'update_pro_schedule', array( 'schedule_id' => 'probe-no-such-schedule', 'name' => 'x' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'delete_pro_schedule', array( 'schedule_id' => 'probe-no-such-schedule' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'dry_run_pro_schedule', array( 'schedule_id' => 'probe-no-such-schedule' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_schedule_run_history', array( 'schedule_id' => 'probe-no-such-schedule' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_schedule_latest_result', array( 'schedule_id' => 'probe-no-such-schedule' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'render_schedule_result', array( 'schedule_id' => 'probe-no-such-schedule' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'configure_schedule_widget_defaults', array( 'schedule_id' => 'probe-no-such-schedule', 'result_capture' => 'full' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'plan_schedules_from_workflow', array( 'workflow_text' => "## Ops\n- Probe the planner\n", 'dry_run' => true ), 'success', array( 'plan', 'summary' ), array( 'user_id' => 1 ) ),

			// §164.81 — the research and content family, which closes the declared
			// orchestration toolkit at 36/36. The aggregator is pure; the post
			// bridge writes through the bound content store; the broadcast wrapper
			// writes a schedule; the report generator is probed in formatting mode
			// so no search or model is needed. The blueprint exporter is probed as
			// a refusal: this host registers only the package provider, and the
			// assistant export engine is bound by the app skeleton.
			array( 'aggregate_research_data', array( 'topic' => 'Probe topic', 'sources' => array( array( 'url' => 'https://probe.example/a', 'title' => 'A', 'content' => 'Probe content A.' ), array( 'url' => 'https://probe.example/a', 'title' => 'A again', 'content' => 'Duplicate URL.' ) ) ), 'success', array( 'unique_sources', 'duplicates_found', 'formatted_output' ), array( 'user_id' => 1 ) ),
			array( 'create_post_from_research', array( 'data' => array( 'title' => 'Probe research post', 'report' => '# Probe findings', 'description' => 'Probe summary' ) ), 'success', array( 'post_id', 'post_type', 'source' ), array( 'user_id' => 1 ) ),
			array( 'schedule_channel_broadcast', array( 'message' => 'Probe broadcast.', 'channels' => array( 'slack' ), 'credentials' => array( 'slack' => array( 'webhook' => 'https://probe.example/hook' ) ), 'name' => 'Probe broadcast' ), 'success', array( 'schedule_id', 'channels', 'schedule' ), array( 'user_id' => 1 ) ),
			array( 'export_assistant_blueprint', array( 'assistant_id' => 1 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'generate_research_report', array( 'title' => 'Probe report', 'sections' => array( array( 'heading' => 'Findings', 'content' => 'Probe finding.' ), array( 'heading' => 'Next steps', 'content' => 'Probe step.' ) ), 'citations' => array( 'Probe source' ) ), 'success', array( 'report', 'word_count', 'section_count' ), array( 'user_id' => 1 ) ),

			// §164.82 — the appointment core. The create writes through the bound
			// content store and, because the conflict check defaults on, it also
			// proves the three-clause overlap meta query is accepted by the real
			// adapter. The notification is off so the probe does not depend on the
			// mail transport. The four id-taking tools are probed with an impossible
			// id to prove the refusal path.
			array( 'create_appointment', array( 'client_name' => 'Probe client', 'client_email' => 'probe@example.com', 'start_time' => '2099-01-01 09:00:00', 'duration_minutes' => 30, 'send_notification' => false ), 'success', array( 'appointment_id', 'start_time', 'end_time', 'status' ), array( 'user_id' => 1 ) ),
			array( 'get_appointment_details', array( 'appointment_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'update_appointment', array( 'appointment_id' => 999999, 'location' => 'Nowhere' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'cancel_appointment', array( 'appointment_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'reschedule_appointment', array( 'appointment_id' => 999999, 'new_start_time' => '2099-01-02 09:00:00' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.83 — the availability rules. The rule writer proves the settings
			// store upsert; the block writer proves a second content type reaches the
			// same store. Both readers are probed without stored rules, so an empty
			// grid is a success envelope and the schema keys are still pinned.
			array( 'set_availability_rules', array( 'day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '17:00' ), 'success', array( 'day_of_week', 'rules' ), array( 'user_id' => 1 ) ),
			array( 'block_time_slot', array( 'start_time' => '2099-01-01 12:00:00', 'end_time' => '2099-01-01 13:00:00', 'reason' => 'probe' ), 'success', array( 'blocked_id', 'start_time' ), array( 'user_id' => 1 ) ),
			array( 'check_availability', array( 'start_time' => '2099-01-03 10:00:00', 'end_time' => '2099-01-03 11:00:00' ), 'success', array( 'available', 'reasons' ), array( 'user_id' => 1 ) ),
			array( 'get_available_slots', array( 'date' => '2099-01-05', 'duration_minutes' => 60 ), 'success', array( 'available_slots', 'total_slots' ), array( 'user_id' => 1 ) ),

			// §164.84 — the event family. The create writes through the bound
			// content store, the two readers answer an empty store, and the two
			// id-taking writers are probed with an impossible id to prove the
			// refusal path. The ICS export is probed as a refusal because a fresh
			// database has no project to export.
			array( 'create_event', array( 'title' => 'Probe event', 'start_date' => '2099-01-01' ), 'success', array( 'event_id', 'event' ), array( 'user_id' => 1 ) ),
			array( 'update_event', array( 'event_id' => 999999, 'title' => 'Probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'delete_event', array( 'event_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'list_events', array(), 'success', array( 'count', 'total', 'events' ), array( 'user_id' => 1 ) ),
			array( 'get_calendar_view', array( 'start_date' => '2099-01-01', 'end_date' => '2099-01-31' ), 'success', array( 'count', 'calendar' ), array( 'user_id' => 1 ) ),
			array( 'export_calendar_ics', array( 'project_id' => 999999, 'download_file' => false ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.85 — the triage readers. Both answer an empty store, which
			// proves the repaired meta keys are accepted by the real adapter
			// instead of failing the query.
			array( 'get_no_show_appointments', array(), 'success', array( 'count', 'total', 'appointments' ), array( 'user_id' => 1 ) ),
			array( 'get_unconfirmed_bookings', array(), 'success', array( 'count', 'total', 'bookings' ), array( 'user_id' => 1 ) ),

			// §164.86 — the service family. The create writes a record; the import
			// runs its dry-run path so it proves parsing and per-row validation
			// against the real store without adding rows the other probes see.
			array( 'create_service', array( 'name' => 'Probe service', 'duration_minutes' => 60, 'price' => 10 ), 'success', array( 'service_id', 'service' ), array( 'user_id' => 1 ) ),
			array( 'import_services', array( 'source' => 'json_array', 'dry_run' => true, 'data' => array( array( 'name' => 'Probe import A' ), array( 'name' => 'Probe import B' ) ) ), 'success', array( 'created', 'total', 'complete' ), array( 'user_id' => 1 ) ),

			// §164.87 — booking links and optimisation. The link probe writes a
			// record and returns the token once; the analysis probe answers an
			// empty far-future range, so it proves the BETWEEN query and the
			// unconfigured-capacity path against the real store without adding
			// appointments the other probes would see.
			array( 'generate_booking_link', array( 'appointment_type' => 'Probe consultation' ), 'success', array( 'link_id', 'link', 'booking_path', 'token' ), array( 'user_id' => 1 ) ),
			array( 'optimize_schedule', array( 'date_range_start' => '2099-01-01', 'date_range_end' => '2099-01-31' ), 'success', array( 'analysis', 'recommendations', 'optimization_goal' ), array( 'user_id' => 1 ) ),

			// §164.88 — notifications. The two singletons answer the refusal path
			// for an impossible appointment; the two batches run their dry-run
			// default, which proves enumeration and the skip path without sending.
			array( 'send_booking_confirmation', array( 'appointment_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_appointment_reminder', array( 'appointment_id' => 999999 ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_booking_confirmations', array( 'booking_ids' => array( 999999 ) ), 'success', array( 'total', 'skipped', 'recipients' ), array( 'user_id' => 1 ) ),
			array( 'send_reschedule_invitation', array( 'appointment_ids' => array( 999999 ) ), 'success', array( 'total', 'skipped', 'recipients' ), array( 'user_id' => 1 ) ),

			// §164.91 — the five credit-free GTmetrix reads. The probe host binds
			// no API key, so each answers the not-configured refusal; the payload
			// shapes are pinned in lib/core against fixture-shaped responses.
			array( 'gtmetrix_account_status', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_list_locations', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_list_browsers', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_list_simulated_devices', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_list_tests', array(), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.92 — the test lifecycle. The guard runs before any credit work,
			// so both answer not-configured on this keyless probe host; the status
			// probe still passes a valid id so the refusal is the guard and not
			// the id validator.
			array( 'gtmetrix_start_test', array( 'url' => 'https://example.test/' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_get_test_status', array( 'test_id' => 'probe-test' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.93 — the four report readers. Same keyless probe host, so each
			// answers the guard; the report id is valid so the refusal is the
			// guard and not validation.
			array( 'gtmetrix_get_report', array( 'report_id' => 'probe-report' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_get_lighthouse_audits', array( 'report_id' => 'probe-report' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_get_resource_summary', array( 'report_id' => 'probe-report' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_list_report_resources', array( 'report_id' => 'probe-report' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.94 — the score engine. Both read live reports and answer the
			// guard on this keyless host; the ids are valid so the refusal is the
			// guard, not validation.
			array( 'gtmetrix_analyze_report', array( 'report_id' => 'probe-report' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_compare_reports', array( 'report_id_a' => 'probe-a', 'report_id_b' => 'probe-b' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.95 — the snippet emitter. It fetches the report before it can
			// generate anything, so it answers the guard on this keyless host.
			array( 'gtmetrix_generate_wpcode_snippet', array( 'report_id' => 'probe-report' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.96 — the pages. All three answer the guard on this keyless
			// host; retest needs the id because the budget check follows validation.
			array( 'gtmetrix_get_page', array( 'page_id' => 'probe-page' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_list_pages', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_retest_page', array( 'page_id' => 'probe-page' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.97 — monitoring and alerts. All four answer the guard on this
			// keyless host; the write probes pass valid arguments so the refusal is
			// the guard rather than validation.
			array( 'gtmetrix_set_monitoring', array( 'page_id' => 'probe-page', 'frequency' => 'daily' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_get_alert_conditions', array( 'page_id' => 'probe-page' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_set_alert_conditions', array( 'page_id' => 'probe-page', 'conditions' => array() ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'gtmetrix_get_alert_events', array( 'page_id' => 'probe-page' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.98 — tags. The action is valid so the refusal is the guard.
			array( 'gtmetrix_manage_page_tags', array( 'action' => 'list' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.100 — the Slack four. No token is passed, so each answers the
			// credential refusal by name without a network call.
			array( 'get_slack_channels', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_slack_messages', array( 'channel' => 'C1' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_slack_message', array( 'channel' => 'C1', 'text' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'create_slack_channel', array( 'name' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.101 — the five portable Discord tools. No token is passed, so
			// each answers the credential refusal by name without a network call.
			array( 'send_discord_message', array( 'channel_id' => '1', 'content' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_discord_channels', array( 'guild_id' => '1' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_discord_messages', array( 'channel_id' => '1' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'create_discord_channel', array( 'guild_id' => '1', 'name' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'add_discord_message_reaction', array( 'channel_id' => '1', 'message_id' => '2', 'emoji' => '👍' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.102 — the Telegram five. No token is passed, so each answers
			// the credential refusal by name without a network call.
			array( 'send_telegram_message', array( 'chat_id' => '1', 'text' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_telegram_updates', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'add_telegram_message_reaction', array( 'chat_id' => '1', 'message_id' => 2, 'emoji' => '👍' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'manage_telegram_commands', array( 'action' => 'get' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'manage_telegram_webhook', array( 'action' => 'delete' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.103 — the four portable WhatsApp tools. No access token is
			// passed, so each answers the credential refusal by name.
			array( 'send_whatsapp_message', array( 'phone_number_id' => '1', 'to' => '+1', 'text' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_whatsapp_template', array( 'phone_number_id' => '1', 'to' => '+1', 'template_name' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_whatsapp_interactive', array( 'phone_number_id' => '1', 'to' => '+1', 'type' => 'button', 'body' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_whatsapp_media', array( 'phone_number_id' => '1', 'to' => '+1', 'type' => 'image', 'media_id' => '1' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.104 — the Microsoft Graph eight. No access token is passed,
			// so each answers the credential refusal by name.
			array( 'get_teams_channels', array( 'team_id' => '11111111-2222-3333-4444-555555555555' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_teams_messages', array( 'team_id' => '11111111-2222-3333-4444-555555555555', 'channel_id' => '19:abc@thread.tacv2' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_teams_message', array( 'team_id' => '11111111-2222-3333-4444-555555555555', 'channel_id' => '19:abc@thread.tacv2', 'content' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_outlook_messages', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_outlook_mail', array( 'to_email' => 'probe@example.com', 'subject' => 'probe', 'body' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_onedrive_file', array( 'item_id' => '01PROBE' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'list_onedrive_files', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'upload_onedrive_file', array( 'file_path' => 'probe.txt', 'content' => 'cHJvYmU=' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.105 — Messenger and Google Chat. No credential is passed, so
			// each answers the credential refusal by name. The webhook sender has
			// no credential of its own, so the probe uses a URL the guard refuses:
			// no network call either way.
			array( 'send_messenger_message', array( 'recipient_id' => '1', 'message' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_messenger_conversations', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_google_chat_message', array( 'webhook_url' => 'https://localhost/hook', 'text' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_google_chat_spaces', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'create_google_chat_space', array( 'display_name' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_google_chat_messages', array( 'space' => 'spaces/AAAA' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'list_google_chat_space_members', array( 'space' => 'spaces/AAAA' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'add_google_chat_space_member', array( 'space' => 'spaces/AAAA', 'member_name' => 'users/1' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'remove_google_chat_space_member', array( 'membership' => 'spaces/AAAA/members/MMMM' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.107 — the three Twitter/X tools. No credential is passed, so
			// each answers the credential refusal by name. The webhook URL is a
			// loopback host, which the guard refuses before any credential check.
			array( 'send_twitter_dm', array( 'recipient_id' => '2244994945', 'text' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_twitter_dms', array(), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'manage_twitter_webhook', array( 'action' => 'register', 'env_name' => 'production', 'url' => 'https://127.0.0.1/probe' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.108 — the seven gateway tools. No credential is passed, so each
			// answers the credential refusal by name with no network call. The
			// endpoints are public https so the URL guard passes and the refusal
			// under test is the credential one.
			array( 'send_apple_message', array( 'msp_api_url' => 'https://msp.example.com/v1/apple', 'business_id' => 'probe', 'conversation_id' => 'conv', 'message' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_apple_message_group', array( 'msp_api_url' => 'https://msp.example.com/v1/apple', 'business_id' => 'probe', 'participants' => array( 'p-1' ), 'message' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'send_apple_message_interactive', array( 'msp_api_url' => 'https://msp.example.com/v1/apple', 'business_id' => 'probe', 'conversation_id' => 'conv', 'interactive_type' => 'authenticate', 'body_text' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_apple_messages', array( 'msp_api_url' => 'https://msp.example.com/v1/apple', 'business_id' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'list_icloud_drive_files', array( 'gateway_url' => 'https://icloud.example.com/v1/drive' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'get_icloud_drive_file', array( 'gateway_url' => 'https://icloud.example.com/v1/drive', 'file_id' => 'probe' ), 'error', array(), array( 'user_id' => 1 ) ),
			array( 'upload_icloud_drive_file', array( 'gateway_url' => 'https://icloud.example.com/v1/drive', 'file_name' => 'probe.txt', 'content' => 'cHJvYmU=' ), 'error', array(), array( 'user_id' => 1 ) ),

			// §164.109 — the fan-out. An empty credential map is refused before
			// anything is resolved or sent, so the probe makes no network call.
			array( 'unified_channel_broadcast', array( 'message' => 'probe', 'channels' => array( 'telegram' ), 'credentials' => array() ), 'error', array(), array( 'user_id' => 1 ) ),
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
		// honour that tool, so the claim is skipped rather than failed. The F&B
		// CSV export is the same case: the registry sniffs the CSV bytes before
		// storing them, and its own work (the rows) is pinned in lib/core.
		if ( 'export_eca_data' === $slug && ! \function_exists( 'finfo_open' ) ) {
			$this->markTestSkipped( 'export_eca_data needs ext-fileinfo: the file store cannot sniff without it.' );
		}

		if (
			'fnb_export_report' === $slug
			&& 'csv' === ( $arguments['format'] ?? '' )
			&& ! \function_exists( 'finfo_open' )
		) {
			$this->markTestSkipped( 'fnb_export_report (CSV) needs ext-fileinfo: the file store cannot sniff without it.' );
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

<?php
/**
 * ID-handoff contract manifest.
 *
 * Single source of truth for the data-contract (produces / consumes)
 * rollout — Phase 0 of the P3 data-contract rollout plan
 * (docs/project/proposals/P3-data-contract-rollout-plan-2026-09.md).
 *
 * Each family entry maps one identifier key that flows between tools:
 *
 *  - `produces`: tools whose success envelope carries the key (D1: the
 *    contract name IS the exact envelope / parameter key name).
 *  - `consumes`: tools whose parameters schema accepts the key.
 *  - `round_trip`: whether the deterministic L2 round-trip suite
 *    (tests/test-tool-id-handoff-round-trip.php) drives the family.
 *
 * The L1 honesty suite (tests/test-tool-id-handoff-contract.php) checks
 * this manifest in BOTH directions against the live registry:
 *
 *  - every `produces` tool must declare `produces = <key>`;
 *  - every `consumes` tool must declare `consumes` including `<key>` AND
 *    have `<key>` in its parameters schema;
 *  - every registered tool that implements the data-contract interface
 *    must have all of its declared keys present in this manifest.
 *
 * Wave PRs update this file in the same commit as their tool annotations
 * (keeps the reverse drift check green).
 *
 * Pending families (deliberately not yet annotated or listed):
 *  - `room_id` (webchat tools) — registration is gated behind
 *    `enable_webchat_integration`, so annotations must land together with
 *    a manifest entry and a registration-enabled test environment.
 *  - `assistant_ids` (export_assistant) — plural array shape; nothing
 *    produces it yet.
 *  - `product_id` (WooCommerce) — create_woo_product(_validated) produce,
 *    but no registered tool consumes a Woo product_id (only list tools and
 *    FlowHub's external connection domain); producer-only per D2.
 *  - `template_id` (task-plan templates) — create_template is a legacy
 *    wrapper shared across many slugs; one contract cannot be declared
 *    for the wrapper without mislabeling the rest.
	 *  - `session_id`, `workflow_id`, `team_id`, `agent_id`,
	 *    `member_id`, `profession_id` — candidates
	 *    pending per-key verification of a real cross-tool producer→consumer
	 *    chain.
	 *  - `connection_id` domains (composio, flowhub, ezsuite, oauth) — external
	 *    remote-site identifiers; out of scope by design.
	 *  - `attachment_id` / `file_id` media chains — one-way analysis chains
	 *    without a cross-tool identifier contract yet; out of scope.
	 *
	 * Landed with wave 2e: `event_id` (Google Calendar tools) and
	 * `snippet_id` (WPCode tools) — Pro-only, L1 honesty coverage with
	 * round_trip=false (external API / plugin dependency); no CG Pro
	 * mirrors exist for either toolkit, so there is no port obligation.
	 *
	 * @package WP_MCP_AI
	 */

return array(
	'version'  => 1,
	'families' => array(
		'job_id'  => array(
			'produces'   => array( 'create_cron_job', 'create_cron_job_validated' ),
			'consumes'   => array( 'get_cron_job', 'delete_cron_job' ),
			'round_trip' => true,
		),
		'plan_id' => array(
			'produces'   => array( 'create_task_plan' ),
			'consumes'   => array( 'get_task_plan', 'update_task_plan', 'detect_completion_indicators' ),
			'round_trip' => true,
		),
		'post_id' => array(
			'produces'   => array( 'create_post', 'create_post_validated', 'save_post', 'save_post_validated', 'create_post_from_research' ),
			'consumes'   => array( 'get_post', 'save_post', 'save_post_validated', 'delete_post', 'auto_categorize_content', 'content_freshness_checker', 'create_text_embeddings' ),
			'round_trip' => true,
		),
		'term_id' => array(
			'produces'   => array( 'create_term', 'update_term' ),
			'consumes'   => array( 'update_term' ),
			'round_trip' => true,
		),
		'assistant_id' => array(
			'produces'   => array( 'create_assistant', 'create_assistant_validated', 'duplicate_assistant' ),
			'consumes'   => array( 'duplicate_assistant', 'export_assistant_blueprint', 'export_fine_tune_curriculum' ),
			'round_trip' => true,
		),
		'vector_store_id' => array(
			'produces'   => array( 'create_vector_store' ),
			'consumes'   => array( 'get_vector_store', 'manage_vector_store_files' ),
			'round_trip' => false, // External OpenAI API; no deterministic L2 driver yet.
		),
		'batch_id' => array(
			'produces'   => array( 'create_batch' ),
			'consumes'   => array( 'get_batch_status' ),
			'round_trip' => false, // External OpenAI API; no deterministic L2 driver yet.
		),
		'schedule_id' => array(
			'scope'      => 'pro', // Asserted only when the Pro tools are registered.
			'produces'   => array( 'create_pro_schedule', 'update_pro_schedule' ),
			'consumes'   => array( 'update_pro_schedule', 'delete_pro_schedule', 'get_schedule_latest_result', 'get_schedule_run_history', 'dry_run_pro_schedule' ),
			'round_trip' => true,
		),
		'item_id' => array(
			'scope'      => 'pro',
			'produces'   => array( 'toolkit_cpt' ),
			'consumes'   => array( 'toolkit_cpt' ),
			'round_trip' => true,
		),
		'record_id' => array(
			'scope'      => 'pro',
			'produces'   => array( 'create_medical_record' ),
			'consumes'   => array( 'get_medical_record', 'update_medical_record', 'delete_medical_record' ),
			'round_trip' => true,
		),
		'event_id' => array(
			'scope'      => 'pro',
			'produces'   => array( 'create_google_calendar_event', 'update_google_calendar_event', 'quick_add_google_calendar_event' ),
			'consumes'   => array( 'update_google_calendar_event', 'delete_google_calendar_event' ),
			'round_trip' => false, // External Google Calendar API; no deterministic L2 driver yet.
		),
		'snippet_id' => array(
			'scope'      => 'pro',
			'produces'   => array( 'create_wpcode_snippet' ),
			'consumes'   => array( 'create_wpcode_snippet', 'format_code_prettier' ),
			'round_trip' => false, // Requires the WPCode plugin; no deterministic L2 driver yet.
		),
	),
);

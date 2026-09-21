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
		'post_id' => array(
			'produces'   => array( 'create_post', 'create_post_validated', 'save_post', 'save_post_validated' ),
			'consumes'   => array( 'get_post', 'save_post', 'save_post_validated', 'delete_post' ),
			'round_trip' => true,
		),
		'term_id' => array(
			'produces'   => array( 'create_term', 'update_term' ),
			'consumes'   => array( 'update_term' ),
			'round_trip' => true,
		),
		'assistant_id' => array(
			'produces'   => array( 'create_assistant', 'create_assistant_validated', 'duplicate_assistant' ),
			'consumes'   => array( 'duplicate_assistant' ),
			'round_trip' => true,
		),
	),
);

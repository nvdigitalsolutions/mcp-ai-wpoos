<?php
/**
 * Standalone validator for the F&B assistant packs (A1–A4).
 *
 * Runs WITHOUT WordPress: asserts the shipped canonical v1 bundles are
 * structurally valid, spec-faithful (rule IDs, guardrails, tiers) and free
 * of denylisted keys. Complements the runtime path (the seeder imports
 * through WP_MCP_AI_Assistant_Portability::parse_import).
 *
 * Usage: php tests/pro/tools/food-beverage/validate-assistant-packs.php
 * Exit code: 0 = all assertions pass, 1 = failures.
 *
 * @since 1.6.0
 */

$repo_root = dirname( __DIR__, 4 );
$packs_dir = $repo_root . '/addons/pro/presets/assistant-packs/fnb/';

$pack_files = array(
	'a1-manager.json'           => 'A1',
	'a2-kitchen-bar-stock.json' => 'A2',
	'a3-financial.json'         => 'A3',
	'a4-content.json'           => 'A4',
);

// Expected allowlists (keep in sync with the packs README matrix).
$expected_tools = array(
	'A1' => array(
		'fnb_read_table',
		'fnb_search_table',
		'fnb_calculate_metric',
		'fnb_compare_periods',
		'fnb_variance_split',
		'fnb_stock_used',
		'fnb_expected_use',
		'fnb_stock_variance',
		'fnb_waste_analysis',
		'fnb_days_of_cover',
		'fnb_weekend_demand',
		'fnb_price_change_impact',
		'fnb_budget_variance',
		'fnb_dish_margin',
		'fnb_duplicate_invoice_scan',
		'fnb_labour_analysis',
		'fnb_utilities_per_cover',
		'fnb_generate_report',
		'fnb_save_draft',
		'fnb_list_drafts',
		'fnb_audit_log',
	),
	'A2' => array(
		'fnb_read_table',
		'fnb_search_table',
		'fnb_calculate_metric',
		'fnb_compare_periods',
		'fnb_stock_used',
		'fnb_expected_use',
		'fnb_stock_variance',
		'fnb_waste_analysis',
		'fnb_days_of_cover',
		'fnb_weekend_demand',
		'fnb_price_change_impact',
		'fnb_dish_margin',
		'fnb_generate_report',
		'fnb_save_draft',
		'fnb_list_drafts',
	),
	'A3' => array(
		'fnb_read_table',
		'fnb_search_table',
		'fnb_calculate_metric',
		'fnb_compare_periods',
		'fnb_variance_split',
		'fnb_stock_used',
		'fnb_expected_use',
		'fnb_stock_variance',
		'fnb_waste_analysis',
		'fnb_days_of_cover',
		'fnb_weekend_demand',
		'fnb_price_change_impact',
		'fnb_budget_variance',
		'fnb_dish_margin',
		'fnb_duplicate_invoice_scan',
		'fnb_labour_analysis',
		'fnb_utilities_per_cover',
		'fnb_generate_report',
		'fnb_save_draft',
		'fnb_list_drafts',
	),
	'A4' => array(
		'fnb_read_table',
		'fnb_search_table',
		'fnb_calculate_metric',
		'fnb_dish_margin',
		'fnb_generate_report',
		'fnb_save_draft',
		'fnb_list_drafts',
	),
);

// ACT-tier image tools must be absent from every shipped pack (default off).
$image_tools = array( 'generate_image_ai', 'generate_image_variations', 'image_inpainting', 'text_to_image_prompt_optimizer' );

// Keys the portability engine never exports/imports.
$denylist = array(
	'_wp_mcp_ai_credentials',
	'_wp_mcp_ai_external_action_id',
	'_wp_mcp_ai_external_action_type',
	'_edit_lock',
	'_edit_last',
	'_wp_old_slug',
);

// Rule IDs each prompt must embed (verbatim spec coverage).
$expected_rule_ids = array(
	'A1' => array( 'A1-01', 'A1-02', 'A1-03', 'A1-04' ),
	'A2' => array( 'A2-01', 'A2-02', 'A2-03', 'A2-04', 'A2-05', 'A2-06', 'A2-07', 'A2-08' ),
	'A3' => array( 'A3-01', 'A3-02', 'A3-03', 'A3-04', 'A3-05', 'A3-06' ),
	'A4' => array( 'A4-01', 'A4-02', 'A4-03', 'A4-04', 'A4-05' ),
);

$global_rule_ids = array( 'G-01', 'G-02', 'G-03', 'G-04', 'G-05', 'G-06', 'G-07', 'G-08', 'G-09', 'G-10', 'G-11', 'G-12' );

$failures = array();
$checks   = 0;

/**
 * Record a validation failure.
 *
 * @param array  $failures Failure list (by reference).
 * @param string $message  Failure message.
 * @return void
 */
function fail( &$failures, $message ) {
	$failures[] = $message;
}

$seen_slugs = array();

foreach ( $pack_files as $file => $label ) {
	$pack_path = $packs_dir . $file;

	if ( ! is_readable( $pack_path ) ) {
		fail( $failures, "$label: bundle file missing ($file)" );
		continue;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI validator reading local bundle files.
	$json = file_get_contents( $pack_path );
	$data = json_decode( $json, true );

	if ( ! is_array( $data ) ) {
		fail( $failures, "$label: invalid JSON ($file)" );
		continue;
	}

	++$checks;
	if ( 'nvoos-assistant' !== ( isset( $data['format'] ) ? $data['format'] : '' ) ) {
		fail( $failures, "$label: format must be nvoos-assistant" );
	}
	if ( 1 !== ( isset( $data['format_version'] ) ? $data['format_version'] : 0 ) ) {
		fail( $failures, "$label: format_version must be 1" );
	}
	if ( empty( $data['assistants'] ) || ! is_array( $data['assistants'] ) ) {
		fail( $failures, "$label: assistants array missing/empty" );
		continue;
	}
	if ( 1 !== count( $data['assistants'] ) ) {
		fail( $failures, "$label: expected exactly one assistant per bundle, got " . count( $data['assistants'] ) );
	}

	$assistant = $data['assistants'][0];
	$meta      = isset( $assistant['meta'] ) && is_array( $assistant['meta'] ) ? $assistant['meta'] : array();
	$prompt    = isset( $meta['_wp_mcp_ai_system_prompt'] ) ? $meta['_wp_mcp_ai_system_prompt'] : '';
	$tools     = isset( $meta['_wp_mcp_ai_tools'] ) && is_array( $meta['_wp_mcp_ai_tools'] ) ? $meta['_wp_mcp_ai_tools'] : array();

	if ( empty( $assistant['title'] ) || empty( $assistant['slug'] ) ) {
		fail( $failures, "$label: title and slug are required" );
	}
	if ( isset( $seen_slugs[ $assistant['slug'] ] ) ) {
		fail( $failures, "$label: duplicate slug '{$assistant['slug']}' (also in {$seen_slugs[ $assistant['slug'] ]})" );
	}
	$seen_slugs[ $assistant['slug'] ] = $label;

	foreach ( $denylist as $key ) {
		if ( array_key_exists( $key, $meta ) ) {
			fail( $failures, "$label: denylisted meta key present: $key" );
		}
	}

	if ( '' === $prompt ) {
		fail( $failures, "$label: system prompt missing" );
	} else {
		if ( false === stripos( $prompt, 'never send' ) ) {
			fail( $failures, "$label: system prompt missing the never-send guardrail" );
		}
		foreach ( $global_rule_ids as $rid ) {
			if ( false === strpos( $prompt, $rid ) ) {
				fail( $failures, "$label: system prompt missing global rule $rid" );
			}
		}
		foreach ( $expected_rule_ids[ $label ] as $rid ) {
			if ( false === strpos( $prompt, $rid ) ) {
				fail( $failures, "$label: system prompt missing rule $rid" );
			}
		}
	}

	// Tool allowlist must match the documented matrix exactly (order-insensitive).
	$expected = $expected_tools[ $label ];
	sort( $expected );
	$actual = array_values( $tools );
	sort( $actual );

	if ( $expected !== $actual ) {
		$missing = array_values( array_diff( $expected, $actual ) );
		$extra   = array_values( array_diff( $actual, $expected ) );
		$detail  = '';
		if ( $missing ) {
			$detail .= ' missing=' . implode( ',', $missing );
		}
		if ( $extra ) {
			$detail .= ' extra=' . implode( ',', $extra );
		}
		fail( $failures, "$label: tool allowlist differs from documented matrix:$detail" );
	}

	// ACT-tier image tools default off in every shipped pack.
	foreach ( $image_tools as $slug ) {
		if ( in_array( $slug, $actual, true ) ) {
			fail( $failures, "$label: ACT-tier image tool $slug must be absent by default (A4-05)" );
		}
	}

	if ( 'edit_posts' !== ( isset( $meta['mcp_ai_required_capability'] ) ? $meta['mcp_ai_required_capability'] : '' ) ) {
		fail( $failures, "$label: mcp_ai_required_capability must be edit_posts" );
	}
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- standalone CLI validator; output is developer-facing diagnostics.
echo 'Validated ' . count( $pack_files ) . " F&B assistant packs.\n";
if ( $failures ) {
	echo 'FAILURES (' . count( $failures ) . "):\n";
	foreach ( $failures as $failure ) {
		echo "  - $failure\n";
	}
	exit( 1 );
}

echo "OK: all packs structurally valid, allowlists match the documented matrix, ACT tier off, no denylisted keys.\n";
exit( 0 );

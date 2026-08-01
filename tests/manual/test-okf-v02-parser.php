<?php
// phpcs:ignoreFile -- Standalone manual test with WordPress stubs for offline execution.
/**
 * Quick smoke-test for OKF v0.2 parser upgrade.
 * Run: php tests/manual/test-okf-v02-parser.php
 */

define( 'ABSPATH', true );

require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-parser.php';

// Minimal WordPress stubs for standalone execution.
if ( ! function_exists( '__' ) ) {
	function __( $s, $d ) { return $s; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();
		public function __construct( $code = '', $msg = '', $data = '' ) {
			$this->errors[ $code ][]    = $msg;
			$this->error_data[ $code ] = $data;
		}
		public function get_error_message() {
			$keys = array_keys( $this->errors );
			return isset( $this->errors[ $keys[0] ] ) ? $this->errors[ $keys[0] ][0] : '';
		}
	}
}

$parser = new WP_MCP_AI_OKF_Parser();
$passed = 0;
$failed = 0;

// Test 1: v0.1 backward compatibility
$v01  = "---\ntype: Skill\ntitle: Test Skill\ndescription: A simple test\ntags:\n  - security\n  - wordpress\n---\n# Body\n";
$r    = $parser->parse( $v01 );
$ok   = ! is_wp_error( $r ) && isset( $r['frontmatter']['type'] ) && $r['frontmatter']['type'] === 'Skill';
echo 'Test 1 (v0.1 compat): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $r ) ) . "\n";
$ok ? $passed++ : $failed++;

// Test 2: Inline mapping
$v02  = "---\ntype: Metric\ngenerated: { by: reference_agent/gemini-2.5-pro, at: 2026-06-30T14:00:00Z }\ntitle: Revenue\n---\n# Body\n";
$r    = $parser->parse( $v02 );
$fm   = $r['frontmatter'];
$ok   = isset( $fm['generated']['by'] ) && $fm['generated']['by'] === 'reference_agent/gemini-2.5-pro';
echo 'Test 2 (inline mapping): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $fm ) ) . "\n";
$ok ? $passed++ : $failed++;

// Test 3: Array of inline mappings
$v03  = "---\ntype: Metric\nverified:\n  - { by: human:kliu@acme, at: 2026-07-01T16:00:00Z }\n  - { by: nightly-checks, at: 2026-07-02T04:00:00Z }\ntitle: Revenue\n---\n# Body\n";
$r    = $parser->parse( $v03 );
$fm   = $r['frontmatter'];
$ok   = isset( $fm['verified'][0]['by'] ) && $fm['verified'][0]['by'] === 'human:kliu@acme'
	 && isset( $fm['verified'][1]['by'] ) && $fm['verified'][1]['by'] === 'nightly-checks';
echo 'Test 3 (array of inline mappings): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $fm ) ) . "\n";
$ok ? $passed++ : $failed++;

// Test 4: Nested object list (sources with indented sub-keys)
$v04  = "---\ntype: BigQuery Table\nsources:\n  - id: warehouse-schema\n    resource: https://wiki.acme.internal/data/schemas\n    title: Warehouse Schema\n    author: team:data-platform\n    usage_count: 1240\n    last_modified: 2026-06-15\n  - id: revenue-policy\n    resource: policies/revenue-recognition.md\n    title: Revenue Recognition Policy\n    author: human:jsmith@acme\n    last_modified: 2026-06-15\ntitle: Customer Orders\n---\n# Body\n";
$r    = $parser->parse( $v04 );
$fm   = $r['frontmatter'];
$ok   = isset( $fm['sources'][0]['id'] ) && $fm['sources'][0]['id'] === 'warehouse-schema'
	 && $fm['sources'][0]['usage_count'] === 1240
	 && $fm['sources'][1]['id'] === 'revenue-policy';
echo 'Test 4 (nested object list): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $fm ) ) . "\n";
$ok ? $passed++ : $failed++;

// Test 5: status + stale_after
$v05  = "---\ntype: Metric\nstatus: deprecated\nstale_after: 2026-12-31\ntitle: Legacy Metric\n---\n# Body\n";
$r    = $parser->parse( $v05 );
$fm   = $r['frontmatter'];
$ok   = isset( $fm['status'] ) && $fm['status'] === 'deprecated' && $fm['stale_after'] === '2026-12-31';
echo 'Test 5 (status/stale_after): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $fm ) ) . "\n";
$ok ? $passed++ : $failed++;

// Test 6: Full ACME retail example from the blog post
$v06  = "---\n";
$v06 .= "type: BigQuery Table\n";
$v06 .= "title: Customer Orders\n";
$v06 .= "description: One row per completed customer order across web, mobile, and marketplace channels.\n";
$v06 .= "resource: https://bigquery.googleapis.com/v2/projects/acme/datasets/sales/tables/orders\n";
$v06 .= "tags:\n";
$v06 .= "  - sales\n";
$v06 .= "  - orders\n";
$v06 .= "  - revenue\n";
$v06 .= "generated: { by: reference_agent/gemini-2.5-pro, at: 2026-06-30T14:00:00Z }\n";
$v06 .= "verified:\n";
$v06 .= "  - { by: human:kliu@acme, at: 2026-07-01T16:00:00Z }\n";
$v06 .= "status: stable\n";
$v06 .= "stale_after: 2026-12-31\n";
$v06 .= "sources:\n";
$v06 .= "  - id: warehouse-schema\n";
$v06 .= "    resource: https://wiki.acme.internal/data/warehouse/schemas/sales\n";
$v06 .= "    title: Acme Retail warehouse schema - sales dataset\n";
$v06 .= "    author: team:data-platform\n";
$v06 .= "    usage_count: 1240\n";
$v06 .= "    last_modified: 2026-06-15\n";
$v06 .= "  - id: revenue-policy\n";
$v06 .= "    resource: policies/revenue-recognition.md\n";
$v06 .= "    title: Revenue Recognition Policy (FY2026)\n";
$v06 .= "    author: human:jsmith@acme\n";
$v06 .= "    last_modified: 2026-06-15\n";
$v06 .= "---\n";
$v06 .= "# Schema\n\nTest body.\n";

$r  = $parser->parse( $v06 );
$fm = $r['frontmatter'];
$ok = isset( $fm['type'] ) && $fm['type'] === 'BigQuery Table'
   && isset( $fm['generated']['by'] )
   && isset( $fm['verified'][0]['by'] )
   && isset( $fm['sources'][0]['id'] )
   && $fm['sources'][0]['usage_count'] === 1240
   && $fm['status'] === 'stable'
   && $fm['stale_after'] === '2026-12-31';
echo 'Test 6 (full ACME example): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $fm ) ) . "\n";
$ok ? $passed++ : $failed++;

// Test 7: Round-trip serialization
$serialized = $parser->serialize( $fm );
$reparsed   = $parser->parse( $serialized );
$rfm        = $reparsed['frontmatter'];
$ok         = isset( $rfm['generated']['by'] ) && $rfm['generated']['by'] === $fm['generated']['by']
           && $rfm['status'] === 'stable';
echo 'Test 7 (round-trip): ' . ( $ok ? 'PASS' : 'FAIL' ) . "\n";
$ok ? $passed++ : $failed++;

// Test 8: Inline flow sequence [a, b, c]
$v08  = "---\ntype: Attested Computation\nruntime: bigquery\nparameters:\n  - { name: year, type: integer, required: true }\nexecutor:\n  resource: skills/run-on-bq.md\n  receipt: [ job_id, executed_sql, result ]\ntitle: Revenue YTD\n---\n# Body\n";
$r    = $parser->parse( $v08 );
$fm   = $r['frontmatter'];
$ok   = isset( $fm['parameters'][0]['name'] ) && $fm['parameters'][0]['name'] === 'year'
     && isset( $fm['executor']['receipt'] ) && $fm['executor']['receipt'] === array( 'job_id', 'executed_sql', 'result' );
echo 'Test 8 (attested computation): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $fm ) ) . "\n";
$ok ? $passed++ : $failed++;

// Test 9: Nested mapping (not a list)
$v09  = "---\ntype: Metric\nexecutor:\n  resource: skills/run-on-bq.md\n  receipt: [ job_id, executed_sql, result ]\ntitle: Test\n---\n# Body\n";
$r    = $parser->parse( $v09 );
$fm   = $r['frontmatter'];
$ok   = isset( $fm['executor']['resource'] ) && $fm['executor']['resource'] === 'skills/run-on-bq.md'
     && isset( $fm['executor']['receipt'] ) && is_array( $fm['executor']['receipt'] );
echo 'Test 9 (nested mapping): ' . ( $ok ? 'PASS' : 'FAIL: ' . json_encode( $fm ) ) . "\n";
$ok ? $passed++ : $failed++;

echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";

exit( $failed > 0 ? 1 : 0 );

<?php
// phpcs:ignoreFile -- Standalone manual smoke test with WordPress stubs for offline execution.
define( 'ABSPATH', true );
define( 'WP_MCP_AI_PATH', dirname( __DIR__, 2 ) );
define( 'WP_MCP_AI_VERSION', '9.9.9-smoke' );

if ( ! function_exists( '__' ) ) { function __( $s, $d = '' ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( $c = '', $m = '' ) {} } }
if ( ! function_exists( 'untrailingslashit' ) ) { function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); } }
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; } }
if ( ! function_exists( 'wp_normalize_path' ) ) { function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); } }

$GLOBALS['__smoke_options'] = array();
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v ) {
		$GLOBALS['__smoke_options'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		return isset( $GLOBALS['__smoke_options'][ $k ] ) ? $GLOBALS['__smoke_options'][ $k ] : $d;
	}
}
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return array( 'basedir' => sys_get_temp_dir() . '/wp-mcp-ai-okf-smoke' );
	}
}

if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	interface WP_MCP_AI_Tool_Interface {}
}

require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-parser.php';
require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-reader.php';
require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-writer.php';
require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-bundle-manager.php';
require_once __DIR__ . '/../../includes/okf/class-wp-mcp-ai-okf-skill-knowledge-generator.php';
require_once __DIR__ . '/../../includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once __DIR__ . '/../../includes/tools/okf/class-wp-mcp-ai-tool-okf-search.php';

$failed = 0;

function check( $label, $cond ) {
	global $failed;
	echo ( $cond ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $cond ) {
		++$failed;
	}
}

// 1. Fingerprint is non-empty.
$fp = WP_MCP_AI_OKF_Skill_Knowledge_Generator::get_fingerprint();
check( 'fingerprint non-empty', '' !== $fp );

// 2. First generation creates the bundle.
$result = WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();
check( 'generate() reports generated', true === $result['generated'] );
check( 'generate() copies >0 concepts', $result['concepts'] > 0 );
check( 'generate() has no errors', empty( $result['errors'] ) );

$bundle = WP_MCP_AI_OKF_Skill_Knowledge_Generator::get_bundle_root();
check( 'bundle dir exists', is_dir( $bundle ) );
check( 'index.md written', file_exists( $bundle . '/index.md' ) );
check( 'code-reviewer/SKILL.md copied', file_exists( $bundle . '/code-reviewer/SKILL.md' ) );
check( 'companion file copied', file_exists( $bundle . '/wp-security-audit/reference.md' ) );
check( 'site-knowledge skeleton', is_dir( dirname( $bundle ) . '/site-knowledge' ) );
check( 'external-bundles skeleton', is_dir( dirname( $bundle ) . '/external-bundles' ) );
check( 'fingerprint stored', get_option( WP_MCP_AI_OKF_Skill_Knowledge_Generator::GENERATED_OPTION, '' ) === $fp );

// 3. Second non-forced run is a no-op.
$result2 = WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate();
check( 'second generate() is gated', false === $result2['generated'] );

// 4. The OKF reader can search the generated bundle.
$reader   = new WP_MCP_AI_OKF_Reader( $bundle );
$results  = $reader->search( array( 'type' => 'Skill' ) );
$concepts = $reader->search( array() );
check( 'reader search finds Skill concepts', count( $results ) > 0 );
check( 'reader search finds all concepts', count( $concepts ) >= count( $results ) );

// 5. Cross-link companion concepts are readable end-to-end.
$concept = $reader->get_concept( 'code-reviewer/SKILL' );
check( 'get_concept resolves code-reviewer/SKILL', is_array( $concept ) && ! empty( $concept['frontmatter']['name'] ) );
// Companion files without frontmatter are not OKF concepts: the reader must
// degrade gracefully (search skips them) instead of fataling.
$concept2 = $reader->get_concept( 'wp-security-audit/reference' );
check( 'companion without frontmatter degrades gracefully', is_wp_error( $concept2 ) );

// 6. The okf_search MCP tool — the exact call that reported
//    "OKF bundle not found: skill-knowledge" — now succeeds.
$tool = new WP_MCP_AI_Tool_OKF_Search();
$resp = $tool->execute( array( 'bundle' => 'skill-knowledge' ), array() );
check( 'okf_search tool returns success', is_array( $resp ) && ! empty( $resp['success'] ) );
check( 'okf_search tool returns results', ! empty( $resp['results'] ) );
$resp2 = $tool->execute( array( 'bundle' => 'skill-knowledge', 'type' => 'Skill' ), array() );
check(
	'okf_search tool filters by type',
	is_array( $resp2 ) && ! empty( $resp2['results'] ) && 'Skill' === $resp2['results'][0]['type']
);

// 7. Force rebuild removes stale skill dirs.
mkdir( $bundle . '/removed-skill', 0755, true );
file_put_contents( $bundle . '/removed-skill/SKILL.md', "---\ntype: Skill\n---\n" );
$result3 = WP_MCP_AI_OKF_Skill_Knowledge_Generator::generate( true );
check( 'force regenerate reports generated', true === $result3['generated'] );
check( 'force regenerate removes stale dir', ! is_dir( $bundle . '/removed-skill' ) );

echo "\n" . ( 0 === $failed ? 'ALL PASS' : $failed . ' FAILURES' ) . "\n";
exit( 0 === $failed ? 0 : 1 );

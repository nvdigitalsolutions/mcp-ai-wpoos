<?php
/**
 * Regenerates tests/fixtures/reasoning/enable-reasoning-mode.json from WordPress.
 *
 * Run inside the WordPress container:
 *
 *   docker exec -w /var/www/html/wp-content/plugins/mcp-ai-wpoos oos-wp \
 *     php tests/manual/capture-enable-reasoning-mode.php
 *
 * `reasoning_metadata.activated_at` is site-local clock output, so it is
 * recorded as `<timestamp>`; both runtimes normalise it the same way before
 * comparing. Everything else — scores, thresholds, the enhanced config and the
 * system prompt — is compared verbatim.
 *
 * The reasoning-history option is cleared before capture and restored after, so
 * a filled history on a dev site cannot leak into the fixture.
 *
 * Error handling is not in the corpus: WordPress returns `WP_Error` with
 * `missing_parameter` while the core runtime returns its canonical validation
 * failure. Each suite tests its own contract.
 *
 * Regenerating is a deliberate act: it asserts "this behaviour is correct", not
 * "the test is green again".
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

// phpcs:disable WordPress.WP.GlobalVariablesProhibited, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone runner.

if ( ! defined( 'ABSPATH' ) ) {
	$wp_root = __DIR__;

	while ( ! is_file( $wp_root . '/wp-load.php' ) && dirname( $wp_root ) !== $wp_root ) {
		$wp_root = dirname( $wp_root );
	}

	if ( ! is_file( $wp_root . '/wp-load.php' ) ) {
		fwrite( STDERR, 'Could not locate wp-load.php above ' . __DIR__ . "\n" );
		exit( 1 );
	}

	require $wp_root . '/wp-load.php';
}

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-reasoning-controller.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-enable-reasoning-mode.php';

/**
 * Replace clock output so the fixture is stable.
 *
 * @param mixed $value Response or fragment.
 * @return mixed
 */
$normalise = static function ( $value ) use ( &$normalise ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	foreach ( $value as $key => $item ) {
		$value[ $key ] = 'activated_at' === $key && is_string( $item ) ? '<timestamp>' : $normalise( $item );
	}

	return $value;
};

$long_task  = 'Analyze the following requirements and then produce a plan. ';
$long_task .= str_repeat( 'This sentence exists to push the word count comfortably past fifty words. ', 8 );
$mid_task   = str_repeat( 'Another clause to reach thirty one words in total. ', 5 );

// Saturates all five indicators: every keyword family, a numbered step, and a
// task type that pins code generation at 1.0.
$strong_task  = 'First do the following step by step, then next finally second; ';
$strong_task .= 'if unless because therefore however although whereas given that assuming; ';
$strong_task .= 'code function class method script program implement write code develop php javascript python css html; ';
$strong_task .= 'technical specialized expert professional industry compliance regulatory best practice; ';
$strong_task .= 'verify validate check confirm ensure test review double-check accuracy.';

$cases = array(
	'simple'                => array( 'task' => 'What is the capital of France?' ),
	'multi-step-keywords'   => array( 'task' => 'First gather the data, then clean it, finally publish it.' ),
	'multi-step-flag'       => array(
		'task'    => 'Do the thing.',
		'context' => array( 'multi_step' => true ),
	),
	'numbered-steps'        => array( 'task' => '1. Read the file 2. Fix the bug 3. Ship it' ),
	'logical-keywords'      => array( 'task' => 'If A holds because B, therefore C unless D however E.' ),
	'analytical-keywords'   => array( 'task' => 'Analyze and compare the two options, then evaluate and verify each.' ),
	'code-keywords'         => array( 'task' => 'Write code in PHP that implements a function.' ),
	'code-fence'            => array( 'task' => "Refactor this:\n```php\necho 1;\n```" ),
	'code-task-type'        => array(
		'task'    => 'Do the needful.',
		'context' => array( 'task_type' => 'code_generation' ),
	),
	'code-line-count'       => array( 'task' => 'Produce 200 lines of code for the importer.' ),
	'domain-keywords'       => array( 'task' => 'Apply technical best practice for regulatory compliance.' ),
	'domain-profession'     => array(
		'task'    => 'Draft the filing.',
		'context' => array( 'profession_slug' => 'architect' ),
	),
	'domain-acronyms'       => array( 'task' => 'Map the AWS IAM S3 policy.' ),
	'verification-keywords' => array( 'task' => 'Verify, validate, check, confirm and test the result.' ),
	'critical-keywords'     => array( 'task' => 'This is critical production security work.' ),
	'long-task'             => array( 'task' => $long_task ),
	'mid-task'              => array( 'task' => $mid_task ),
	'model-config-cool'     => array(
		'task'         => 'First do A, then B, then C.',
		'model_config' => array(
			'temperature' => 0.2,
			'model'       => 'gpt-4o',
		),
	),
	'model-config-warm'     => array(
		'task'         => 'First do A, then B, then C.',
		'model_config' => array(
			'temperature' => 0.9,
			'model'       => 'gpt-4o',
		),
	),
	'model-config-prompt'   => array(
		'task'         => 'First do A, then B, then C.',
		'model_config' => array( 'system_prompt' => 'You are a careful assistant.' ),
	),
	'script-in-task'        => array( 'task' => 'First <script>alert(1)</script>then publish.' ),
	'newlines-in-task'      => array( 'task' => "First gather.\n\nThen publish." ),

	// Above the threshold: every indicator family saturated.
	'high-all-indicators'   => array( 'task' => $strong_task ),

	// The same task with a model config, so every merge branch is captured.
	'activated-config-warm' => array(
		'task'         => $strong_task,
		'model_config' => array(
			'temperature' => 0.9,
			'model'       => 'gpt-4o',
		),
	),
	'activated-config-cool' => array(
		'task'         => $strong_task,
		'model_config' => array(
			'temperature' => 0.2,
			'model'       => 'gpt-4o',
		),
	),
	'activated-prompt'      => array(
		'task'         => $strong_task,
		'model_config' => array( 'system_prompt' => 'You are a careful assistant.' ),
	),

	// Below the threshold through a realistic combination, despite strong
	// indicators in two families.
	'high-code-review'      => array(
		'task'    => 'First write code in PHP that implements the importer, then validate it, check the tests, and ensure production security.',
		'context' => array( 'task_type' => 'code_generation' ),
	),
	'high-research'         => array(
		'task' => 'Analyze and compare the sources because the evidence conflicts, therefore verify each claim and review the citations carefully.',
	),
);

$tool = new WP_MCP_AI_Tool_Enable_Reasoning_Mode();

$history_key      = WP_MCP_AI_Reasoning_Controller::REASONING_HISTORY_KEY;
$history_snapshot = get_option( $history_key, null );
delete_option( $history_key );

$out = array();

foreach ( $cases as $name => $args ) {
	$result = $tool->execute( $args );

	$out[ $name ] = array(
		'args'     => $args,
		'expected' => $normalise( $result ),
	);
}

if ( null !== $history_snapshot ) {
	update_option( $history_key, $history_snapshot, false );
}

$target = WP_MCP_AI_PATH . 'tests/fixtures/reasoning/enable-reasoning-mode.json';

if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0777, true );
}

$json = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

file_put_contents( $target, $json . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writer.

printf( "Wrote %d cases to %s\n", count( $out ), $target );

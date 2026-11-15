<?php
/**
 * Replays the captured prompt-cue corpus through the WordPress tool.
 *
 * The corpus was recorded from this tool before the port. The library is reset
 * per test, so the seeded catalogue is the whole content and a site that
 * registers its own cues cannot skew the comparison.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behaviour-preservation test for the prompt-cue port.
 */
class Test_List_Prompt_Cues extends WP_UnitTestCase {

	/**
	 * Captured corpus.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$file = __DIR__ . '/fixtures/harness/prompt-cues.json';

		if ( ! is_file( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * One case per captured name.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function corpus_cases(): array {
		$cases = array();

		foreach ( self::corpus() as $name => $case ) {
			$cases[ $name ] = array( $case['args'], $case['expected'] );
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_corpus_is_present() {
		$this->assertGreaterThanOrEqual( 10, count( self::corpus() ) );
	}

	/**
	 * Every captured filter still returns its recorded catalogue.
	 *
	 * @param array<string, mixed> $args     Tool arguments.
	 * @param array<string, mixed> $expected Recorded response.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_execute_matches_the_capture( $args, $expected ) {
		require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-prompt-cue-library.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-list-prompt-cues.php';

		WP_MCP_AI_Prompt_Cue_Library::get_instance()->reset();

		$result = ( new WP_MCP_AI_Tool_List_Prompt_Cues() )->execute( $args );

		$this->assertIsArray( $result );
		$this->assertSame( $expected, $result );
	}

	/**
	 * The seeded catalogue the core port mirrors has not moved.
	 */
	public function test_seeded_cues_are_the_documented_eight() {
		require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-prompt-cue-library.php';

		$library = WP_MCP_AI_Prompt_Cue_Library::get_instance();
		$library->reset();

		$this->assertSame(
			array(
				'chain_of_thought',
				'failure_modes_first',
				'plan_then_solve',
				'cite_or_abstain',
				'tool_or_abstain',
				'clarify_first',
				'state_uncertainty',
				'stay_on_target',
			),
			array_keys( $library->all() )
		);
	}
}

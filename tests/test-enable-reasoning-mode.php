<?php
/**
 * Replays the captured enable_reasoning_mode corpus through the WordPress tool.
 *
 * `reasoning_metadata.activated_at` is normalised to `<timestamp>` on both
 * sides; the corpus was captured that way.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behaviour-preservation test for the reasoning-mode port.
 */
class Test_Enable_Reasoning_Mode extends WP_UnitTestCase {

	/**
	 * Captured corpus.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$file = __DIR__ . '/fixtures/reasoning/enable-reasoning-mode.json';

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
		$this->assertGreaterThanOrEqual( 20, count( self::corpus() ) );
	}

	/**
	 * Every captured case still produces its recorded response.
	 *
	 * @param array<string, mixed> $args     Tool arguments.
	 * @param array<string, mixed> $expected Recorded response.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_execute_matches_the_capture( $args, $expected ) {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-reasoning-controller.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-enable-reasoning-mode.php';

		$result = ( new WP_MCP_AI_Tool_Enable_Reasoning_Mode() )->execute( $args );

		$this->assertIsArray( $result );
		$this->assertSame( $expected, $this->normalise( $result ) );
	}

	/**
	 * The historical contract: a missing task is a `missing_parameter` error.
	 */
	public function test_a_missing_task_is_an_error() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-reasoning-controller.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-enable-reasoning-mode.php';

		$result = ( new WP_MCP_AI_Tool_Enable_Reasoning_Mode() )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_parameter', $result->get_error_code() );
	}

	/**
	 * Replace clock output with a placeholder, recursively.
	 *
	 * @param array $value Response fragment.
	 * @return array
	 */
	private function normalise( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = 'activated_at' === $key && is_string( $item )
				? '<timestamp>'
				: $this->normalise( $item );
		}

		return $value;
	}
}

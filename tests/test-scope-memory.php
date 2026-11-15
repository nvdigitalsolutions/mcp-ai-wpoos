<?php
/**
 * Replays the captured scope_memory corpus through the WordPress tool.
 *
 * The corpus was recorded from this tool before the port, so this pins the
 * behaviour the core port is measured against. Profiles arrive through the
 * documented `wp_mcp_ai_harness_profile` filter, which keeps the storage chain
 * (post meta → option → defaults) out of the comparison.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.6.0
 * @license GPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behaviour-preservation test for the scope_memory port.
 */
class Test_Scope_Memory extends WP_UnitTestCase {

	/**
	 * The captured corpus runs against the documented default profile, so the
	 * site-wide option must not leak in from another test.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( 'wp_mcp_ai_harness_profile_default' );
	}

	/**
	 * Captured corpus.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$file = __DIR__ . '/fixtures/harness/scope-memory.json';

		if ( ! is_file( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * One case per captured name.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>}>
	 */
	public static function corpus_cases(): array {
		$cases = array();

		foreach ( self::corpus() as $name => $case ) {
			$cases[ $name ] = array( $case['args'], $case['profile'], $case['expected'] );
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
	 * @param array<string, mixed>      $args     Tool arguments.
	 * @param array<string, mixed>|null $profile  Profile to inject, or null for the real default resolution.
	 * @param array<string, mixed>      $expected Recorded response.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_execute_matches_the_capture( $args, $profile, $expected ) {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-helper.php';
		require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-harness-profile.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-scope-memory.php';

		$filter   = null;
		$tool     = new WP_MCP_AI_Tool_Scope_Memory();

		if ( null !== $profile ) {
			$filter = static function () use ( $profile ) {
				return $profile;
			};

			add_filter( 'wp_mcp_ai_harness_profile', $filter );
		}

		$result = $tool->execute( $args );

		if ( null !== $filter ) {
			remove_filter( 'wp_mcp_ai_harness_profile', $filter );
		}

		$this->assertIsArray( $result );
		$this->assertSame( $expected, $result );
	}

	/**
	 * The reserved list the core port mirrors has not moved.
	 */
	public function test_reserved_buckets_are_the_documented_set() {
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-schema-helper.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/harness/class-wp-mcp-ai-tool-scope-memory.php';

		$this->assertSame(
			array(
				'general',
				'math',
				'code',
				'qa',
				'rag',
				'research',
				'reasoning',
				'agentic',
				'this-site',
				'this-user',
			),
			WP_MCP_AI_Tool_Scope_Memory::RESERVED_BUCKETS
		);
	}
}

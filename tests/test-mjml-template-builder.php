<?php
/**
 * Replays the captured MJML corpus through the WordPress service.
 *
 * The service now delegates markup building to
 * `Nvoos\Core\Domain\Service\Mjml\MjmlTemplateBuilder`, so this asserts the
 * whole WordPress path — empty-input guard, `wp_kses_post()` sanitisation and
 * the delegated build — still produces the markup captured before the
 * extraction.
 *
 * @package WP_MCP_AI\Tests
 * @since   2.5.0
 * @license GPL-3.0-or-later
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Behaviour-preservation test for the MJML extraction.
 */
class Test_MJML_Template_Builder extends WP_UnitTestCase {

	/**
	 * Captured corpus.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$file = __DIR__ . '/fixtures/email/mjml-templates.json';

		if ( ! is_file( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * One case per captured name.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<string, mixed>, 2: array<string, mixed>|string}>
	 */
	public static function corpus_cases(): array {
		$cases = array();

		foreach ( self::corpus() as $name => $case ) {
			$cases[ $name ] = array( $case['components'], $case['options'], $case['expected'] );
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_corpus_is_present() {
		$this->assertGreaterThanOrEqual( 5, count( self::corpus() ) );
	}

	/**
	 * The service still returns the recorded result for every captured case.
	 *
	 * @param array<int, array<string, mixed>> $components Raw components.
	 * @param array<string, mixed>             $options    Template options.
	 * @param array<string, mixed>|string      $expected   Recorded markup or error shape.
	 */
	#[DataProvider( 'corpus_cases' )]
	public function test_generate_template_matches_the_capture( $components, $options, $expected ) {
		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-media-worker-client.php';
		require_once WP_MCP_AI_PATH . 'addons/pro/includes/services/class-wp-mcp-ai-mjml-service.php';

		$result = ( new WP_MCP_AI_MJML_Service() )->generate_template( $components, $options );

		if ( is_array( $expected ) && isset( $expected['error'] ) ) {
			$this->assertWPError( $result );
			$this->assertSame( $expected['error'], $result->get_error_code() );
			return;
		}

		$this->assertIsString( $result );
		$this->assertSame( $expected, $result );
	}
}

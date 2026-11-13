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

	/**
	 * The decoration corpus: header/footer markup recorded before the port.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function decorations(): array {
		$file = __DIR__ . '/fixtures/email/mjml-decorations.json';

		if ( ! is_file( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * One decoration case per captured name.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, array<string, mixed>>|null, 3: string}>
	 */
	public static function decoration_cases(): array {
		$cases = array();

		foreach ( self::decorations() as $name => $case ) {
			$cases[ $name ] = array(
				$case['mjml'],
				$case['branding'],
				$case['footer_links'],
				$case['expected'],
			);
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_decoration_corpus_is_present() {
		$this->assertGreaterThanOrEqual( 10, count( self::decorations() ) );
	}

	/**
	 * The tool's decoration path still produces the recorded markup.
	 *
	 * The private helpers are reached by reflection on purpose: `execute()`
	 * bails out early when no MJML compiler is installed, and the point here is
	 * that the delegation to `MjmlTemplateBuilder` reproduces the pre-refactor
	 * WordPress output — including `esc_url()` entity encoding and the empty
	 * `href` for a rejected scheme.
	 *
	 * @param string                                $mjml         Base document.
	 * @param array<string, mixed>                  $branding     Branding options.
	 * @param array<int, array<string, mixed>>|null $footer_links Footer links, or null when the case has none.
	 * @param string                                $expected     Recorded markup.
	 */
	#[DataProvider( 'decoration_cases' )]
	public function test_decoration_matches_the_capture( $mjml, $branding, $footer_links, $expected ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Generate_Email_Template' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/tools/document-generation/class-wp-mcp-ai-tool-generate-email-template.php';
		}

		$tool = new WP_MCP_AI_Tool_Generate_Email_Template();

		$branding_method = new ReflectionMethod( $tool, 'add_branding_to_mjml' );
		$footer_method   = new ReflectionMethod( $tool, 'add_footer_to_mjml' );
		$branding_method->setAccessible( true );
		$footer_method->setAccessible( true );

		$actual = $mjml;

		if ( array() !== $branding ) {
			$actual = $branding_method->invoke( $tool, $actual, $branding );
		}

		if ( null !== $footer_links ) {
			$actual = $footer_method->invoke( $tool, $actual, $footer_links, $branding );
		}

		$this->assertSame( $expected, $actual );
	}
}

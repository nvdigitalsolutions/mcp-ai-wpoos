<?php
/**
 * Replays the captured MJML template corpus against the core builder.
 *
 * The fixtures were captured from `WP_MCP_AI_MJML_Service::generate_template()`
 * before the extraction, so this asserts the port reproduces the WordPress
 * service byte for byte.
 *
 * @package Nvoos\Core\Tests
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Mjml;

use Nvoos\Core\Domain\Service\Mjml\MjmlTemplateBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MjmlTemplateBuilderTest extends TestCase {

	/**
	 * Captured corpus, at the repository root.
	 */
	private static function corpusFile(): string {
		return dirname( __DIR__, 7 ) . '/tests/fixtures/email/mjml-templates.json';
	}

	/**
	 * Every JSON case, keyed by name.
	 *
	 * Includes error cases so the corpus stays the single record; the provider
	 * for markup comparisons filters them out.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function corpus(): array {
		$file = self::corpusFile();

		if ( ! is_file( $file ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Markup cases: everything except those whose recorded result is an error.
	 *
	 * The `empty-components` guard stays in the WordPress delegate, so it has
	 * no core equivalent to compare against.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<string, mixed>, 2: string}>
	 */
	public static function markupCases(): array {
		$cases = array();

		foreach ( self::corpus() as $name => $case ) {
			if ( ! is_string( $case['expected'] ) ) {
				continue;
			}

			$cases[ $name ] = array(
				$case['components_sanitised'],
				$case['options'],
				$case['expected'],
			);
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function testTheCorpusIsPresent(): void {
		$this->assertGreaterThanOrEqual(
			5,
			count( self::corpus() ),
			'MJML corpus not found at ' . self::corpusFile()
		);
	}

	/**
	 * Every captured case rebuilds to its recorded markup.
	 *
	 * @param array<int, array<string, mixed>> $components Already-sanitised components.
	 * @param array<string, mixed>             $options    Template options.
	 * @param string                           $expected   Recorded MJML.
	 */
	#[DataProvider( 'markupCases' )]
	public function testItMatchesTheRecordedMarkup( array $components, array $options, string $expected ): void {
		$this->assertSame(
			$expected,
			( new MjmlTemplateBuilder() )->build( $components, $options )
		);
	}

	/**
	 * An empty component list still yields a valid, empty document.
	 *
	 * The WordPress service rejects empty input before it gets here; the
	 * builder's job is markup, not policy.
	 */
	public function testEmptyComponentsBuildAnEmptySkeleton(): void {
		$mjml = ( new MjmlTemplateBuilder() )->build( array() );

		$this->assertStringStartsWith( '<mjml><mj-head>', $mjml );
		$this->assertStringEndsWith( '</mj-column></mj-section></mj-body></mjml>', $mjml );
		$this->assertStringNotContainsString( '<mj-text', $mjml );
	}

	/**
	 * Unknown component types are skipped rather than emitted.
	 */
	public function testUnknownComponentTypesAreSkipped(): void {
		$mjml = ( new MjmlTemplateBuilder() )->build(
			array(
				array( 'type' => 'text', 'content' => 'kept' ),
				array( 'type' => 'not-a-component' ),
			)
		);

		$this->assertStringContainsString( '<mj-text>kept</mj-text>', $mjml );
		$this->assertStringNotContainsString( 'not-a-component', $mjml );
	}

	/**
	 * Content is injected verbatim — sanitisation is the caller's job.
	 *
	 * This is the documented contract: the WordPress delegate runs
	 * `wp_kses_post()` before delegating, and the corpus case
	 * `script-is-stripped` proves that boundary still works.
	 */
	public function testContentIsInjectedVerbatim(): void {
		$mjml = ( new MjmlTemplateBuilder() )->build(
			array(
				array( 'type' => 'text', 'content' => '<em>raw</em>' ),
			)
		);

		$this->assertStringContainsString( '<mj-text><em>raw</em></mj-text>', $mjml );
	}

	/**
	 * Option values are escaped, and existing entities are not double-encoded.
	 */
	public function testOptionsAreEscapedOnce(): void {
		$mjml = ( new MjmlTemplateBuilder() )->build(
			array(),
			array(
				'title' => 'A & B "quoted" &amp; already',
			)
		);

		$this->assertStringContainsString(
			'<mj-title>A &amp; B &quot;quoted&quot; &amp; already</mj-title>',
			$mjml
		);
	}
}

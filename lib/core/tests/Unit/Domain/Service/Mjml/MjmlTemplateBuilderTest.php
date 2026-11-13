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
	 * Captured decoration corpus, at the repository root.
	 *
	 * Branding-header and footer markup, recorded from the WordPress tool before
	 * it was replaced by a call to this builder.
	 */
	private static function decorationsFile(): string {
		return dirname( __DIR__, 7 ) . '/tests/fixtures/email/mjml-decorations.json';
	}

	/**
	 * Decoration cases, keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function decorations(): array {
		$file = self::decorationsFile();

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
	public static function decorationCases(): array {
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
	public function testTheDecorationCorpusIsPresent(): void {
		$this->assertGreaterThanOrEqual(
			10,
			count( self::decorations() ),
			'MJML decoration corpus not found at ' . self::decorationsFile()
		);
	}

	/**
	 * Header and footer decoration reproduces the recorded WordPress markup.
	 *
	 * @param string                                  $mjml        Base document.
	 * @param array<string, mixed>                    $branding    Branding options.
	 * @param array<int, array<string, mixed>>|null   $footerLinks Footer links, or null when the case has none.
	 * @param string                                  $expected    Recorded markup.
	 */
	#[DataProvider( 'decorationCases' )]
	public function testDecorationMatchesTheRecordedMarkup( string $mjml, array $branding, ?array $footerLinks, string $expected ): void {
		$builder = new MjmlTemplateBuilder();
		$actual  = $mjml;

		if ( array() !== $branding ) {
			$actual = $builder->withBrandingHeader( $actual, $branding );
		}

		if ( null !== $footerLinks ) {
			$actual = $builder->withFooter( $actual, $footerLinks, $branding );
		}

		$this->assertSame( $expected, $actual );
	}

	/**
	 * A branding block without a logo leaves the document alone.
	 */
	public function testBrandingWithoutALogoIsANoOp(): void {
		$builder = new MjmlTemplateBuilder();
		$mjml    = $builder->build( array( array( 'type' => 'text', 'content' => 'x' ) ) );

		$this->assertSame( $mjml, $builder->withBrandingHeader( $mjml, array( 'company_name' => 'Acme' ) ) );
	}

	/**
	 * Hostile link schemes are dropped, not emitted.
	 *
	 * `esc_url()` returns an empty string for a bad protocol; the builder keeps
	 * that behaviour so an email cannot carry a `javascript:` payload.
	 */
	public function testHostileLinkSchemesAreDropped(): void {
		$mjml = ( new MjmlTemplateBuilder() )->build( array() );

		foreach ( array( 'javascript:alert(1)', 'data:text/html,<script>', 'vbscript:msgbox' ) as $url ) {
			$out = ( new MjmlTemplateBuilder() )->withFooter(
				$mjml,
				array( array( 'text' => 'Click', 'url' => $url ) )
			);

			$this->assertStringContainsString( 'href=""', $out, $url );
		}
	}

	/**
	 * Non-scalar values are skipped instead of triggering array-to-string notices.
	 */
	public function testNonScalarDecorationValuesAreSkipped(): void {
		$builder = new MjmlTemplateBuilder();
		$mjml    = $builder->build( array( array( 'type' => 'text', 'content' => 'x' ) ) );

		$this->assertSame(
			$mjml,
			$builder->withBrandingHeader( $mjml, array( 'logo_url' => array( 'nope' ) ) )
		);

		$footer = $builder->withFooter(
			$mjml,
			array( array( 'text' => array( 'nope' ), 'url' => 'https://a.test' ) ),
			array( 'company_name' => array( 'nope' ) )
		);

		$this->assertStringContainsString( 'padding="20px">', $footer );
		$this->assertStringNotContainsString( 'nope', $footer );
		$this->assertStringNotContainsString( '<a href=', $footer );
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

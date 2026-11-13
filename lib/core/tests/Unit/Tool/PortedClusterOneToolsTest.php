<?php
/**
 * Tests for the two 090-D cluster-1 ports: VerifyInformationTool and
 * ValidateToolSchemaTool.
 *
 * The fixtures mirror the WordPress implementations so the parity suite in
 * 090-D §6 can compare field-for-field.
 *
 * @package Nvoos\Core\Tests
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MjmlCompilerInterface;
use Nvoos\Core\Tool\GenerateEmailTemplateTool;
use Nvoos\Core\Tool\ValidateToolSchemaTool;
use Nvoos\Core\Tool\VerifyInformationTool;
use PHPUnit\Framework\TestCase;

final class PortedClusterOneToolsTest extends TestCase {

	// ─── VerifyInformationTool ──────────────────────────────────────

	public function testVerifyReportsVerifiedWhenSourcesAgree(): void {
		$result = $this->verify(
			'alpha bravo charlie delta',
			array(
				array( 'content' => 'alpha bravo charlie delta', 'url' => 'https://a.test', 'credibility' => 'high' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'verified', $result['data']['verdict'] );
		$this->assertSame( 100.0, $result['data']['confidence'] );
		$this->assertSame( 1, $result['data']['sources_checked'] );
		$this->assertCount( 1, $result['data']['supporting'] );
		$this->assertSame( 'https://a.test', $result['data']['supporting'][0]['url'] );
		$this->assertSame( 'high', $result['data']['supporting'][0]['credibility'] );
	}

	public function testVerifyReportsPartiallyVerifiedAtHalfConfidence(): void {
		$result = $this->verify(
			'alpha bravo charlie delta',
			array(
				array( 'content' => 'alpha bravo charlie delta' ),
				array( 'content' => 'zulu yankee xray whiskey' ),
			)
		);

		$this->assertSame( 'partially_verified', $result['data']['verdict'] );
		$this->assertSame( 50.0, $result['data']['confidence'] );
		$this->assertCount( 1, $result['data']['supporting'] );
		$this->assertCount( 1, $result['data']['contradicting'] );
	}

	public function testVerifyReportsContradictedWhenMostSourcesDisagree(): void {
		$result = $this->verify(
			'alpha bravo charlie delta',
			array(
				array( 'content' => 'zulu yankee xray whiskey' ),
				array( 'content' => 'india juliet kilo lima' ),
				array( 'content' => 'mike november oscar papa' ),
			)
		);

		$this->assertSame( 'contradicted', $result['data']['verdict'] );
		$this->assertSame( 0.0, $result['data']['confidence'] );
		$this->assertCount( 3, $result['data']['contradicting'] );
	}

	public function testVerifyReportsUnverifiedWhenSourcesAreInconclusive(): void {
		$result = $this->verify(
			'alpha bravo charlie delta',
			array(
				array( 'content' => 'alpha bravo zulu yankee' ),
			)
		);

		$this->assertSame( 'unverified', $result['data']['verdict'] );
		$this->assertSame( 0.0, $result['data']['confidence'] );
		$this->assertCount( 0, $result['data']['supporting'] );
		$this->assertCount( 0, $result['data']['contradicting'] );
	}

	public function testVerifyIgnoresNonStringSourceContent(): void {
		$result = $this->verify(
			'alpha bravo charlie delta',
			array(
				array( 'content' => array( 'unexpected' ), 'url' => 'https://a.test' ),
			)
		);

		$this->assertSame( 'contradicted', $result['data']['verdict'] );
		$this->assertSame( 'https://a.test', $result['data']['contradicting'][0]['url'] );
	}

	public function testVerifyRejectsAnEmptyClaim(): void {
		$result = $this->verify( '', array( array( 'content' => 'anything' ) ) );

		$this->assertSame( 'validation_failed', $result['error'] );
	}

	public function testVerifyRejectsAnEmptySourceList(): void {
		$result = $this->verify( 'alpha bravo', array() );

		$this->assertSame( 'validation_failed', $result['error'] );
	}

	public function testVerifyDeclaresTheContract(): void {
		$tool = new VerifyInformationTool( new FakeErrorFactory() );

		$this->assertSame( 'verify_information', $tool->getSlug() );
		$this->assertSame( 'edit_posts', $tool->getRequiredCapability() );
		$this->assertSame( array( 'claim', 'sources' ), $tool->getParametersSchema()['required'] );
	}

	// ─── ValidateToolSchemaTool ─────────────────────────────────────

	public function testValidateAcceptsAWellFormedSchema(): void {
		$result = $this->validate( $this->cleanSchema() );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['valid'] );
		$this->assertSame( 0, $result['data']['error_count'] );
		$this->assertSame( 0, $result['data']['warning_count'] );
		$this->assertSame( 0, $result['data']['recommendation_count'] );
	}

	public function testValidateFlagsMissingType(): void {
		$result = $this->validate(
			array(
				'type' => 'object',
			)
		);

		$this->assertFalse( $result['data']['valid'] );
		$this->assertContains( 'Schema missing required "properties" field.', $result['data']['errors'] );
	}

	public function testValidateFlagsAnUnsupportedType(): void {
		$result = $this->validate(
			array(
				'type'       => 'object',
				'properties' => array(
					'count' => array( 'type' => 'decimal', 'description' => 'How many items to return' ),
				),
				'required'   => array( 'count' ),
			)
		);

		$this->assertFalse( $result['data']['valid'] );
		$this->assertSame(
			'Parameter "count" has invalid type "decimal".',
			$result['data']['errors'][0]
		);
	}

	public function testValidateFlagsRequiredFieldsMissingFromProperties(): void {
		$result = $this->validate(
			array(
				'type'       => 'object',
				'properties' => array(
					'term' => array( 'type' => 'string', 'description' => 'Search term to match' ),
				),
				'required'   => array( 'term', 'ghost' ),
			)
		);

		$this->assertFalse( $result['data']['valid'] );
		$this->assertSame(
			'Required field "ghost" not defined in properties.',
			$result['data']['errors'][0]
		);
	}

	public function testValidateWarnsAboutAnArrayWithoutItems(): void {
		$result = $this->validate(
			array(
				'type'       => 'object',
				'properties' => array(
					'tags' => array( 'type' => 'array', 'description' => 'Tags to attach to the record' ),
				),
				'required'   => array( 'tags' ),
			)
		);

		$this->assertTrue( $result['data']['valid'] );
		$this->assertSame(
			'Array parameter "tags" should define "items" schema.',
			$result['data']['warnings'][0]
		);
	}

	public function testStrictModeTurnsWarningsIntoInvalidity(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'term' => array( 'type' => 'string', 'description' => 'Search term to match' ),
			),
			// No `required` array: that is a warning, not an error.
		);

		$lenient = $this->validate( $schema );
		$strict  = $this->validate( $schema, array( 'strict_mode' => true ) );

		$this->assertTrue( $lenient['data']['valid'] );
		$this->assertSame( 1, $lenient['data']['warning_count'] );
		$this->assertFalse( $strict['data']['valid'] );
	}

	public function testValidateRecommendsSnakeCaseAndFlagsFileAndSqlParameters(): void {
		$result = $this->validate(
			array(
				'type'       => 'object',
				'properties' => array(
					'FileName'  => array( 'type' => 'string', 'description' => 'Name to display in the listing' ),
					'file_path' => array( 'type' => 'string', 'description' => 'Path to the file to read' ),
					'sql_query' => array( 'type' => 'string', 'description' => 'Statement text to execute' ),
				),
				'required'   => array(),
			)
		);

		$recommendations = implode( "\n", $result['data']['recommendations'] );
		$warnings        = implode( "\n", $result['data']['warnings'] );

		$this->assertStringContainsString( 'should use snake_case naming', $recommendations );
		$this->assertStringContainsString( 'handles file paths', $recommendations );
		$this->assertStringContainsString( 'appears to handle SQL', $warnings );
	}

	public function testValidateRejectsAnEmptyPayload(): void {
		$tool   = new ValidateToolSchemaTool( new FakeErrorFactory() );
		$result = $tool->execute( array() );

		$this->assertSame( 'validation_failed', $result['error'] );
		$this->assertStringContainsString( 'Either schema or tool_file', $result['message'] );
	}

	public function testValidateReportsAMissingToolFile(): void {
		$tool   = new ValidateToolSchemaTool( new FakeErrorFactory() );
		$result = $tool->execute( array( 'tool_file' => '/nonexistent/tool.php' ) );

		$this->assertSame( 'validation_failed', $result['error'] );
		$this->assertSame( 'Tool file not found.', $result['message'] );
	}

	public function testValidateDeclaresTheContract(): void {
		$tool = new ValidateToolSchemaTool( new FakeErrorFactory() );

		$this->assertSame( 'validate_tool_schema', $tool->getSlug() );
		$this->assertSame( 'edit_posts', $tool->getRequiredCapability() );
		$this->assertArrayHasKey( 'schema', $tool->getParametersSchema()['properties'] );
	}

	// ─── GenerateEmailTemplateTool ──────────────────────────────────

	public function testEmailTemplateDeclaresTheContract(): void {
		$tool = new GenerateEmailTemplateTool( new FakeErrorFactory() );

		$this->assertSame( 'generate_email_template', $tool->getSlug() );
		$this->assertSame( 'edit_posts', $tool->getRequiredCapability() );
		$this->assertSame( array( 'components' ), $tool->getParametersSchema()['required'] );
		$this->assertSame(
			array( 'html', 'mjml', 'both' ),
			$tool->getParametersSchema()['properties']['output_format']['enum']
		);
	}

	public function testEmailTemplateRejectsMissingComponents(): void {
		$tool   = new GenerateEmailTemplateTool( new FakeErrorFactory() );
		$result = $tool->execute( array( 'components' => array() ) );

		$this->assertSame( 'validation_failed', $result['error'] );
		$this->assertArrayHasKey( 'components', $result['data']['errors'] );
	}

	public function testEmailTemplateReturnsMjmlWithoutACompiler(): void {
		$result = $this->email( array( 'output_format' => 'mjml' ) );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'mjml', $result['data'] );
		$this->assertArrayNotHasKey( 'html', $result['data'] );
		$this->assertStringContainsString( '<mj-text>Hello</mj-text>', $result['data']['mjml'] );
	}

	public function testEmailTemplateExplainsAMissingCompiler(): void {
		$result = $this->email( array() );

		$this->assertSame( 'mjml_compile_unavailable', $result['error'] );
		$this->assertSame( 501, $result['data']['status'] );
		$this->assertArrayHasKey( 'mjml', $result['data'] );
	}

	public function testEmailTemplateReportsAnUnavailableCompiler(): void {
		$compiler          = new FakeMjmlCompiler();
		$compiler->available = false;

		$result = $this->email( array(), $compiler );

		$this->assertSame( 'mjml_compile_unavailable', $result['error'] );
	}

	public function testEmailTemplateCompilesWhenACompilerIsPresent(): void {
		$compiler = new FakeMjmlCompiler();
		$result   = $this->email( array(), $compiler );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '<html>compiled</html>', $result['data']['html'] );
		$this->assertArrayNotHasKey( 'mjml', $result['data'] );
		$this->assertTrue( $compiler->options['beautify'] );
		$this->assertFalse( $compiler->options['minify'] );
		$this->assertSame( 'soft', $compiler->options['validationLevel'] );
	}

	public function testEmailTemplateMinifyFlipsBeautify(): void {
		$compiler = new FakeMjmlCompiler();

		$this->email( array( 'minify' => true ), $compiler );

		$this->assertTrue( $compiler->options['minify'] );
		$this->assertFalse( $compiler->options['beautify'] );
	}

	public function testEmailTemplateIncludesMjmlWhenBothRequested(): void {
		$result = $this->email( array( 'output_format' => 'both' ), new FakeMjmlCompiler() );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'html', $result['data'] );
		$this->assertArrayHasKey( 'mjml', $result['data'] );
	}

	public function testEmailTemplateSurfacesCompilerFailures(): void {
		$compiler          = new FakeMjmlCompiler();
		$compiler->failure = 'boilerplate exploded';

		$result = $this->email( array(), $compiler );

		$this->assertSame( 'mjml_compilation_failed', $result['error'] );
		$this->assertStringContainsString( 'boilerplate exploded', $result['message'] );
		$this->assertArrayHasKey( 'mjml', $result['data'] );
	}

	public function testEmailTemplateAppliesBrandingAndFooter(): void {
		$result = $this->email(
			array(
				'output_format' => 'mjml',
				'branding'      => array(
					'logo_url'        => 'https://cdn.test/logo.png',
					'company_name'    => 'Acme & Co',
					'company_address' => '1 Main St',
				),
				'footer_links'  => array(
					array( 'text' => 'Unsubscribe', 'url' => 'https://acme.test/u?e=1&t=2' ),
				),
			)
		);

		$mjml = $result['data']['mjml'];

		$this->assertStringContainsString( '<mj-image src="https://cdn.test/logo.png" alt="Acme &amp; Co"', $mjml );
		$this->assertStringContainsString( '<strong>Acme &amp; Co</strong><br />1 Main St', $mjml );
		$this->assertStringContainsString( 'href="https://acme.test/u?e=1&#038;t=2"', $mjml );
		$this->assertStringContainsString( 'Unsubscribe</a>', $mjml );
	}

	public function testEmailTemplateCollapsesHeaderOptions(): void {
		$result = $this->email(
			array(
				'output_format' => 'mjml',
				'subject'       => "  <b>Big</b>\n\n  news  ",
				'preview_text'  => "first\tsecond",
			)
		);

		$this->assertStringContainsString( '<mj-title>Big news</mj-title>', $result['data']['mjml'] );
		$this->assertStringContainsString( '<mj-preview>first second</mj-preview>', $result['data']['mjml'] );

		// The result echoes the parameter as core sanitised it (`stringParam()`
		// trims and strips tags), while the template option is collapsed further
		// to a single line.
		$this->assertSame( "Big\n\n  news", $result['data']['subject'] );
		$this->assertSame( "first\tsecond", $result['data']['preview_text'] );
	}

	/**
	 * @param array<string, mixed> $extra Extra arguments.
	 * @return array<string, mixed>
	 */
	private function email( array $extra, ?MjmlCompilerInterface $compiler = null ): array {
		$tool = new GenerateEmailTemplateTool( new FakeErrorFactory(), $compiler );

		return $tool->execute(
			array_merge(
				array(
					'components' => array(
						array( 'type' => 'text', 'content' => 'Hello' ),
					),
				),
				$extra
			)
		);
	}

	// ─── helpers ────────────────────────────────────────────────────

	/**
	 * @param array<int, array<string, mixed>> $sources Sources.
	 * @return array<string, mixed>
	 */
	private function verify( string $claim, array $sources ): array {
		$tool = new VerifyInformationTool( new FakeErrorFactory() );

		return $tool->execute(
			array(
				'claim'   => $claim,
				'sources' => $sources,
			)
		);
	}

	/**
	 * @param array<string, mixed> $schema Schema.
	 * @param array<string, mixed> $extra  Extra arguments.
	 * @return array<string, mixed>
	 */
	private function validate( array $schema, array $extra = array() ): array {
		$tool = new ValidateToolSchemaTool( new FakeErrorFactory() );

		return $tool->execute( array_merge( array( 'schema' => $schema ), $extra ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cleanSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'term' => array(
					'type'        => 'string',
					'description' => 'Search term to match against records',
				),
			),
			'required'   => array( 'term' ),
		);
	}
}

/**
 * Compiler stub: records the options it was called with and returns fixed HTML.
 */
final class FakeMjmlCompiler implements MjmlCompilerInterface {

	public bool $available = true;

	public ?string $failure = null;

	/** @var array<string, mixed> */
	public array $options = array();

	public function isAvailable(): bool {
		return $this->available;
	}

	public function compile( string $mjml, array $options = array() ): string {
		$this->options = $options;

		if ( null !== $this->failure ) {
			throw new \RuntimeException( $this->failure );
		}

		return '<html>compiled</html>';
	}

	public function validate( string $mjml ): array {
		return array( 'valid' => true, 'errors' => array() );
	}
}

/**
 * Minimal ErrorFactory for the core tests (lib/core ships the contract only;
 * the WordPress adapter supplies the real factory).
 */
final class FakeErrorFactory implements ErrorFactoryInterface {

	public function create( string $code, string $message, array $data = array() ): mixed {
		return array(
			'error'   => $code,
			'message' => $message,
			'data'    => $data,
		);
	}

	public function isError( mixed $value ): bool {
		return is_array( $value ) && isset( $value['error'] );
	}

	public function normalize( mixed $error ): array {
		return (array) $error;
	}

	public function notFound( string $message = 'Resource not found.', array $data = array() ): mixed {
		return $this->create( 'not_found', $message, $data );
	}

	public function forbidden( string $message = 'Access denied.', array $data = array() ): mixed {
		return $this->create( 'forbidden', $message, $data );
	}

	public function validationFailed( string $message, array $errors = array() ): mixed {
		return $this->create( 'validation_failed', $message, array( 'errors' => $errors ) );
	}

	public function rateLimited( string $message, int $retryAfterSeconds = 60 ): mixed {
		return $this->create( 'rate_limited', $message, array( 'retry_after' => $retryAfterSeconds ) );
	}
}

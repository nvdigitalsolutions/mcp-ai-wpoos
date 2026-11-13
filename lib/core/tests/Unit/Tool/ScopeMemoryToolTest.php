<?php
/**
 * Replays the captured scope_memory corpus against the core tool.
 *
 * The fixtures were captured from `WP_MCP_AI_Tool_Scope_Memory` before the
 * port. Each case records the arguments, the harness profile the tool saw, and
 * the response it produced, so this asserts the port reproduces the WordPress
 * tool field for field.
 *
 * @package Nvoos\Core\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Tool\ScopeMemoryTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopeMemoryToolTest extends TestCase {

	/**
	 * Captured corpus, at the repository root.
	 */
	private static function corpusFile(): string {
		return dirname( __DIR__, 5 ) . '/tests/fixtures/harness/scope-memory.json';
	}

	/**
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
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>}>
	 */
	public static function corpusCases(): array {
		$cases = array();

		foreach ( self::corpus() as $name => $case ) {
			$cases[ $name ] = array( $case['args'], $case['profile'], $case['expected'] );
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function testTheCorpusIsPresent(): void {
		$this->assertGreaterThanOrEqual(
			20,
			count( self::corpus() ),
			'scope_memory corpus not found at ' . self::corpusFile()
		);
	}

	/**
	 * Every captured case reproduces its recorded response.
	 *
	 * @param array<string, mixed>      $args     Tool arguments.
	 * @param array<string, mixed>|null $profile  Profile the WordPress tool saw, or null when it resolved the default.
	 * @param array<string, mixed>      $expected Recorded response.
	 */
	#[DataProvider( 'corpusCases' )]
	public function testExecuteMatchesTheCapture( array $args, ?array $profile, array $expected ): void {
		$result = $this->tool( $profile )->execute( $args );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $expected['task_class'], $result['data']['task_class'] );
		$this->assertSame( $expected['reserved'], $result['data']['reserved'] );
		$this->assertSame( $expected['tags'], $result['data']['tags'] );
		$this->assertSame( $expected['pii_filter'], $result['data']['pii_filter'] );
	}

	/**
	 * The reserved list is part of the contract, not an implementation detail.
	 */
	public function testReservedBucketsAreTheDocumentedSet(): void {
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
			ScopeMemoryTool::RESERVED_BUCKETS
		);
	}

	public function testItDeclaresTheContract(): void {
		$tool = new ScopeMemoryTool( $this->createMock( ErrorFactoryInterface::class ) );

		$this->assertSame( 'scope_memory', $tool->getSlug() );
		$this->assertSame( 'edit_posts', $tool->getRequiredCapability() );
		$this->assertSame(
			array( 'assistant_id', 'task_class', 'wing' ),
			array_keys( $tool->getParametersSchema()['properties'] )
		);
		$this->assertSame( 9007199254740991, $tool->getParametersSchema()['properties']['assistant_id']['maximum'] );
	}

	/**
	 * The provider is asked about the assistant the caller named, and only then.
	 */
	public function testProviderIsScopedToTheRequestedAssistant(): void {
		$provider = $this->provider( array( 'memory' => array( 'pii_filter' => true ) ) );
		$tool     = new ScopeMemoryTool( $this->createMock( ErrorFactoryInterface::class ), $provider );

		$tool->execute( array( 'assistant_id' => 314 ) );

		$this->assertSame( array( 314 ), $provider->requested );
	}

	/**
	 * A runtime with no harness storage never asks for a profile.
	 */
	public function testWithoutAProviderNoLookupHappens(): void {
		$tool   = new ScopeMemoryTool( $this->createMock( ErrorFactoryInterface::class ) );
		$result = $tool->execute( array( 'assistant_id' => 314 ) );

		$this->assertTrue( $result['data']['pii_filter'] );
	}

	/**
	 * Non-scalar arguments fall back to the defaults instead of warning.
	 */
	public function testNonScalarArgumentsFallBack(): void {
		$result = $this->tool( null )->execute(
			array(
				'task_class' => array( 'not', 'a', 'string' ),
				'wing'       => array( 'nor', 'this' ),
			)
		);

		$this->assertSame( 'general', $result['data']['task_class'] );
		$this->assertSame( array( 'task_class:general' ), $result['data']['tags'] );
	}

	/**
	 * Scalars are cast like the WordPress tool did: `123` was the bucket "123".
	 */
	public function testScalarArgumentsAreCast(): void {
		$result = $this->tool( null )->execute(
			array(
				'task_class' => 123,
				'wing'       => 456,
			)
		);

		$this->assertSame( '123', $result['data']['task_class'] );
		$this->assertSame( array( 'task_class:123', 'wing:456' ), $result['data']['tags'] );
	}

	/**
	 * Build the tool, binding a provider only when the case had a profile.
	 *
	 * @param array<string, mixed>|null $profile Recorded profile.
	 */
	private function tool( ?array $profile ): ScopeMemoryTool {
		$errors = $this->createMock( ErrorFactoryInterface::class );

		return new ScopeMemoryTool(
			$errors,
			null === $profile ? null : $this->provider( $profile )
		);
	}

	/**
	 * Provider stub that records the assistant ids it was asked about.
	 *
	 * @param array<string, mixed> $profile Profile to return.
	 */
	private function provider( array $profile ): HarnessProfileProviderInterface {
		return new class( $profile ) implements HarnessProfileProviderInterface {

			/** @var array<int, int> */
			public array $requested = array();

			/**
			 * @param array<string, mixed> $profile Profile to return.
			 */
			public function __construct( private readonly array $profile ) {}

			public function getProfile( int $assistantId ): array {
				$this->requested[] = $assistantId;

				return $this->profile;
			}
		};
	}
}

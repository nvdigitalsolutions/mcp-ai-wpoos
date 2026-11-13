<?php
/**
 * Replays the captured enable_reasoning_mode corpus against the core tool.
 *
 * The fixtures were captured from `WP_MCP_AI_Tool_Enable_Reasoning_Mode` before
 * the port. `activated_at` is clock output on both sides, so it is normalised to
 * `<timestamp>` before comparison; every other field — the decision, the
 * enhanced config, the enhancements summary and the message — is compared
 * verbatim.
 *
 * @package Nvoos\Core\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Service\Reasoning\ReasoningController;
use Nvoos\Core\Tool\EnableReasoningModeTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnableReasoningModeToolTest extends TestCase {

	/**
	 * Captured corpus, at the repository root.
	 */
	private static function corpusFile(): string {
		return dirname( __DIR__, 5 ) . '/tests/fixtures/reasoning/enable-reasoning-mode.json';
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
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function corpusCases(): array {
		$cases = array();

		foreach ( self::corpus() as $name => $case ) {
			$cases[ $name ] = array( $case['args'], $case['expected'] );
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
			'reasoning corpus not found at ' . self::corpusFile()
		);
	}

	/**
	 * The corpus covers both branches; without an activated case the port could
	 * drop the enhancement path and still look green.
	 */
	public function testTheCorpusCoversActivation(): void {
		$activated = 0;

		foreach ( self::corpus() as $case ) {
			if ( ! empty( $case['expected']['data']['reasoning_activated'] ) ) {
				++$activated;
			}
		}

		$this->assertGreaterThanOrEqual( 2, $activated );
	}

	/**
	 * Every captured case reproduces its recorded response.
	 *
	 * @param array<string, mixed> $args     Tool arguments.
	 * @param array<string, mixed> $expected Recorded response.
	 */
	#[DataProvider( 'corpusCases' )]
	public function testExecuteMatchesTheCapture( array $args, array $expected ): void {
		$result = ( new EnableReasoningModeTool( $this->createMock( ErrorFactoryInterface::class ) ) )->execute( $args );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $expected['message'], $result['message'] );
		$this->assertSame(
			$this->normalise( $expected['data'] ),
			$this->normalise( $result['data'] )
		);
	}

	public function testItDeclaresTheContract(): void {
		$tool = new EnableReasoningModeTool( $this->createMock( ErrorFactoryInterface::class ) );

		$this->assertSame( 'enable_reasoning_mode', $tool->getSlug() );
		$this->assertSame( 'edit_posts', $tool->getRequiredCapability() );
		$this->assertSame( array( 'task' ), $tool->getParametersSchema()['required'] );
	}

	public function testAnEmptyTaskIsRejected(): void {
		$errors = $this->createMock( ErrorFactoryInterface::class );
		$errors->method( 'validationFailed' )
			->willReturn( array( 'error' => 'validation_failed' ) );

		$result = ( new EnableReasoningModeTool( $errors ) )->execute( array( 'task' => '' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'validation_failed', $result['error'] );
	}

	/**
	 * The clock is the only non-deterministic input, so it is injectable.
	 */
	public function testTheClockIsInjectable(): void {
		$tool = new EnableReasoningModeTool(
			$this->createMock( ErrorFactoryInterface::class ),
			new ReasoningController( static fn (): string => '2026-01-02 03:04:05' )
		);

		$strong = self::corpus()['high-all-indicators']['args'];

		$result = $tool->execute( $strong );

		$this->assertSame(
			'2026-01-02 03:04:05',
			$result['data']['enhanced_config']['reasoning_metadata']['activated_at']
		);
	}

	/**
	 * The default clock still produces a `Y-m-d H:i:s` stamp.
	 */
	public function testTheDefaultClockUsesTheExpectedFormat(): void {
		$result = ( new EnableReasoningModeTool( $this->createMock( ErrorFactoryInterface::class ) ) )
			->execute( self::corpus()['high-all-indicators']['args'] );

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$result['data']['enhanced_config']['reasoning_metadata']['activated_at']
		);
	}

	/**
	 * The scoring helpers are usable on their own.
	 */
	public function testScoreIsExposedForCallers(): void {
		$controller = new ReasoningController();
		$strong     = self::corpus()['high-all-indicators']['args']['task'];

		$this->assertGreaterThan( 0.7, $controller->scoreTask( $strong ) );
		$this->assertSame( 0.0, $controller->scoreTask( 'Hello.' ) );
	}

	/**
	 * Replace clock output with a placeholder, recursively.
	 *
	 * @param array<string, mixed> $value Response fragment.
	 * @return array<string, mixed>
	 */
	private function normalise( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( 'activated_at' === $key && is_string( $item ) ) {
				$value[ $key ] = '<timestamp>';
				continue;
			}

			if ( is_array( $item ) ) {
				$value[ $key ] = $this->normalise( $item );
			}
		}

		return $value;
	}
}

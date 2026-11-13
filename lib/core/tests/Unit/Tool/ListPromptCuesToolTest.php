<?php
/**
 * Replays the captured prompt-cue corpus against the core tool and library.
 *
 * The fixtures were captured from `WP_MCP_AI_Tool_List_Prompt_Cues` before the
 * port, including the full cue text, so this asserts the shared catalogue
 * reproduces the WordPress one rather than merely resembling it.
 *
 * @package Nvoos\Core\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Service\Harness\PromptCueLibrary;
use Nvoos\Core\Tool\ListPromptCuesTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListPromptCuesToolTest extends TestCase {

	/**
	 * Captured corpus, at the repository root.
	 */
	private static function corpusFile(): string {
		return dirname( __DIR__, 5 ) . '/tests/fixtures/harness/prompt-cues.json';
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
			10,
			count( self::corpus() ),
			'prompt cue corpus not found at ' . self::corpusFile()
		);
	}

	/**
	 * Every captured filter reproduces the recorded catalogue.
	 *
	 * @param array<string, mixed> $args     Tool arguments.
	 * @param array<string, mixed> $expected Recorded response.
	 */
	#[DataProvider( 'corpusCases' )]
	public function testExecuteMatchesTheCapture( array $args, array $expected ): void {
		$result = ( new ListPromptCuesTool( $this->createMock( ErrorFactoryInterface::class ) ) )->execute( $args );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $expected['count'], $result['data']['count'] );
		$this->assertSame( $expected['cues'], $result['data']['cues'] );
	}

	public function testItDeclaresTheContract(): void {
		$tool = new ListPromptCuesTool( $this->createMock( ErrorFactoryInterface::class ) );

		$this->assertSame( 'list_prompt_cues', $tool->getSlug() );
		$this->assertSame( 'edit_posts', $tool->getRequiredCapability() );
		$this->assertSame(
			array( 'task_class' ),
			array_keys( $tool->getParametersSchema()['properties'] )
		);
	}

	/**
	 * The library seeds the same eight cues on every instance.
	 */
	public function testTheLibrarySeedsEightCues(): void {
		$this->assertCount( 8, ( new PromptCueLibrary() )->all() );
	}

	/**
	 * A caller can register its own cues and read them back.
	 */
	public function testRegisteredCuesJoinTheCatalogue(): void {
		$library = new PromptCueLibrary();

		$library->register(
			array(
				'slug'     => 'my_cue',
				'label'    => 'My Cue',
				'template' => 'Do the thing.',
			)
		);

		$this->assertCount( 9, $library->all() );
		$this->assertSame( 'My Cue', $library->get( 'my_cue' )['label'] );

		// No task classes supplied: the cue is a general one.
		$this->assertSame( array( 'general' ), $library->get( 'my_cue' )['task_classes'] );
		$this->assertCount( 8, $library->listCues( 'general' ) );
	}

	public function testRegisterRejectsUnusableCues(): void {
		$library = new PromptCueLibrary();

		$this->assertFalse( $library->register( array( 'slug' => '', 'template' => 'x' ) ) );
		$this->assertFalse( $library->register( array( 'slug' => 'ok', 'template' => '   ' ) ) );
		$this->assertNull( $library->get( 'ok' ) );
	}

	/**
	 * Task classes are sanitised and de-duplicated, falling back to `general`.
	 */
	public function testTaskClassesAreSanitised(): void {
		$library = new PromptCueLibrary();

		$library->register(
			array(
				'slug'         => 'tidy',
				'template'     => 'x',
				'task_classes' => array( 'Math Reasoning', 'math reasoning', 'Māth', '' ),
			)
		);

		$this->assertSame( array( 'mathreasoning', 'mth' ), $library->get( 'tidy' )['task_classes'] );
	}

	public function testUnregisterRemovesACue(): void {
		$library = new PromptCueLibrary();

		$library->unregister( 'chain_of_thought' );

		$this->assertNull( $library->get( 'chain_of_thought' ) );
		$this->assertCount( 7, $library->all() );
	}

	public function testAnInjectedLibraryIsUsed(): void {
		$library = new PromptCueLibrary();
		$library->unregister( 'chain_of_thought' );

		$tool   = new ListPromptCuesTool( $this->createMock( ErrorFactoryInterface::class ), $library );
		$result = $tool->execute( array( 'task_class' => 'math' ) );

		$this->assertSame( 0, $result['data']['count'] );
	}
}

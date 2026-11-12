<?php
/**
 * Tests for SaveProfessionTool — the write half of the profession catalogue.
 *
 * The tool must never report a save that did not happen: a runtime without
 * ProfessionWriteInterface does not register it, and a failed write returns an
 * error envelope rather than a success with no persisted data.
 *
 * @package Nvoos\Core\Tests
 * @since   2.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;
use Nvoos\Core\Tool\SaveProfessionTool;
use PHPUnit\Framework\TestCase;

final class SaveProfessionToolTest extends TestCase {

	private const VALIDATION = array( '__validation_failed' => true );

	private const FAILURE = array( '__created_error' => true );

	private ErrorFactoryInterface $errors;

	private ProfessionWriteInterface $writer;

	private SaveProfessionTool $tool;

	protected function setUp(): void {
		$this->errors = $this->createMock( ErrorFactoryInterface::class );
		$this->errors->method( 'validationFailed' )->willReturn( self::VALIDATION );
		$this->errors->method( 'create' )->willReturn( self::FAILURE );

		$this->writer = $this->createMock( ProfessionWriteInterface::class );
		$this->tool   = new SaveProfessionTool( $this->errors, $this->writer );
	}

	public function testIdentity(): void {
		$this->assertSame( 'save_profession', $this->tool->getSlug() );
		$this->assertSame( 'Save Profession', $this->tool->getName() );
		$this->assertSame( 'manage_options', $this->tool->getRequiredCapability() );
	}

	public function testSchemaMatchesTheWordPressTool(): void {
		$schema = $this->tool->getParametersSchema();

		$this->assertSame( array( 'title', 'slug' ), $schema['required'] );
		$this->assertArrayHasKey( 'role_description', $schema['properties'] );
		$this->assertArrayHasKey( 'default_tools', $schema['properties'] );
		// The old core stub advertised `name` / `recommended_tools`, which the
		// WordPress tool does not accept.
		$this->assertArrayNotHasKey( 'name', $schema['properties'] );
		$this->assertArrayNotHasKey( 'recommended_tools', $schema['properties'] );
	}

	public function testCreatesAProfession(): void {
		$this->writer
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					static function ( array $data ): bool {
						return 'marine_biologist' === $data['slug']
							&& 'Marine Biologist' === $data['title']
							&& 'technical' === $data['category']
							&& array( 'Taxonomy', 'Fieldwork' ) === $data['expertise']
							&& array( 'web_search' ) === $data['default_tools'];
					}
				)
			)
			->willReturn(
				array(
					'saved'      => true,
					'action'     => 'created',
					'profession' => array( 'slug' => 'marine_biologist' ),
				)
			);

		$result = $this->tool->execute(
			array(
				'slug'          => 'marine_biologist',
				'title'         => 'Marine Biologist',
				'category'      => 'technical',
				'expertise'     => array( 'Taxonomy', 'Fieldwork' ),
				'default_tools' => array( 'web_search' ),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Profession created.', $result['message'] );
		$this->assertSame( 'created', $result['data']['action'] );
		$this->assertSame( array( 'slug' => 'marine_biologist' ), $result['data']['profession'] );
	}

	public function testReportsAnUpdate(): void {
		$this->writer->method( 'save' )->willReturn(
			array(
				'saved'      => true,
				'action'     => 'updated',
				'profession' => array(),
			)
		);

		$result = $this->tool->execute( array( 'slug' => 'ux_designer', 'title' => 'UX Designer' ) );

		$this->assertSame( 'Profession updated.', $result['message'] );
		$this->assertSame( 'updated', $result['data']['action'] );
	}

	public function testRequiresTitleAndSlug(): void {
		$this->writer->expects( $this->never() )->method( 'save' );

		$this->assertSame( self::VALIDATION, $this->tool->execute( array( 'slug' => 'only_slug' ) ) );
		$this->assertSame( self::VALIDATION, $this->tool->execute( array() ) );
	}

	public function testSurfacesAWriterFailure(): void {
		$this->writer->method( 'save' )->willReturn(
			array(
				'saved' => false,
				'error' => 'The profession could not be stored.',
			)
		);

		$result = $this->tool->execute( array( 'slug' => 'ux_designer', 'title' => 'UX Designer' ) );

		$this->assertSame( self::FAILURE, $result );
	}

	public function testOmitsFieldsTheCallerDidNotSend(): void {
		$this->writer
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					static fn ( array $data ): bool => array( 'slug', 'title' ) === array_keys( $data )
				)
			)
			->willReturn( array( 'saved' => true, 'action' => 'updated' ) );

		$this->tool->execute( array( 'slug' => 'ux_designer', 'title' => 'UX Designer' ) );
	}

	public function testDropsNonStringAndEmptyListEntries(): void {
		$this->writer
			->expects( $this->once() )
			->method( 'save' )
			->with(
				$this->callback(
					static fn ( array $data ): bool => array( 'a', 'b' ) === $data['warnings']
				)
			)
			->willReturn( array( 'saved' => true, 'action' => 'created' ) );

		$this->tool->execute(
			array(
				'slug'     => 'ux_designer',
				'title'    => 'UX Designer',
				'warnings' => array( 'a', '', 7, ' b ' ),
			)
		);
	}
}

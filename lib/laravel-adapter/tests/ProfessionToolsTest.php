<?php
/**
 * Profession tools end-to-end (090-g Phase 2).
 *
 * Registration alone proves nothing: these tests resolve each tool from the
 * seeded registry and execute it against the catalogue repository, so a
 * runtime dependency that is not wired fails here rather than in chat.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Application\Tool\ToolRegistry;

final class ProfessionToolsTest extends TestCase {

	private ToolRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->registry = $this->app->make( ToolRegistry::class );
	}

	public function test_list_professions_returns_the_catalogue(): void {
		$tool = $this->registry->get( 'list_professions' );

		$this->assertNotNull( $tool );

		$result = $tool->execute( array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 9, $result['data']['total'] );
		$this->assertCount( 9, $result['data']['items'] );
	}

	public function test_list_professions_filters_by_category(): void {
		$result = $this->registry->get( 'list_professions' )->execute( array( 'category' => 'technical' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 3, $result['data']['total'] );
		$this->assertSame(
			array( 'Data Scientist', 'DevOps Engineer', 'Software Engineer' ),
			array_values( $result['data']['items'] )
		);
	}

	public function test_get_profession_returns_the_full_record(): void {
		$result = $this->registry->get( 'get_profession' )->execute( array( 'slug' => 'registered_nurse' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'registered_nurse', $result['data']['slug'] );
		$this->assertSame( 'healthcare', $result['data']['category'] );
		$this->assertNotEmpty( $result['data']['knowledge_base'] );
	}

	public function test_get_profession_reports_a_missing_slug_as_not_found(): void {
		$result = $this->registry->get( 'get_profession' )->execute( array( 'slug' => 'does_not_exist' ) );

		$this->assertInstanceOf( \RuntimeException::class, $result );
		$this->assertSame( 404, $result->getCode() );
	}

	public function test_profession_stats_counts_the_grouped_catalogue(): void {
		$result = $this->registry->get( 'profession_stats' )->execute( array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 9, $result['data']['total_professions'] );
		$this->assertSame( 6, $result['data']['category_count'] );
		$this->assertSame(
			array(
				'advisory'   => 1,
				'creative'   => 1,
				'financial'  => 2,
				'healthcare' => 1,
				'legal'      => 1,
				'technical'  => 3,
			),
			$result['data']['categories']
		);
	}
}

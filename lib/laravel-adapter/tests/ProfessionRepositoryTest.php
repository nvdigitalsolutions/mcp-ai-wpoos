<?php
/**
 * Profession repository adapter tests (090-g Phase 2).
 *
 * Pins the shapes WP_MCP_AI_Profession_Service returns, so a tool built on
 * ProfessionRepositoryInterface cannot tell which runtime answered.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Laravel\Adapter\ProfessionRepository;

final class ProfessionRepositoryTest extends TestCase {

	private ProfessionRepositoryInterface $repo;

	protected function setUp(): void {
		parent::setUp();

		$this->repo = $this->app->make( ProfessionRepositoryInterface::class );
	}

	public function test_catalogue_version_is_exposed(): void {
		$repo = $this->app->make( ProfessionRepository::class );

		$this->assertSame( '1.0.0', $repo->catalogueVersion() );
	}

	public function test_get_all_returns_slug_to_title_ordered_by_title(): void {
		$all = $this->repo->getAll();

		$this->assertCount( 9, $all );
		$this->assertSame( 'accountant', array_key_first( $all ) );
		$this->assertSame( 'Accountant', $all['accountant'] );

		$titles = array_values( $all );
		$sorted = $titles;
		sort( $sorted );

		$this->assertSame( $sorted, $titles );
	}

	public function test_get_by_slug_returns_the_wordpress_shape(): void {
		$result = $this->repo->getBySlug( 'software_engineer' );

		$this->assertTrue( $result['found'] );

		$profession = $result['profession'];

		$this->assertSame(
			array(
				'id',
				'slug',
				'name',
				'description',
				'category',
				'role_description',
				'expertise',
				'warnings',
				'knowledge_base',
				'default_tools',
			),
			array_keys( $profession )
		);

		$this->assertSame( 'Software Engineer', $profession['name'] );
		$this->assertSame( 'technical', $profession['category'] );
		$this->assertContains( 'web_search', $profession['default_tools'] );
		$this->assertNotEmpty( $profession['expertise'] );
		$this->assertNotEmpty( $profession['knowledge_base'] );
	}

	public function test_unknown_slug_returns_not_found(): void {
		$this->assertSame( array( 'found' => false ), $this->repo->getBySlug( 'does_not_exist' ) );
		$this->assertSame( array( 'found' => false ), $this->repo->getBySlug( '' ) );
	}

	public function test_get_by_category_returns_slug_to_title(): void {
		$this->assertSame(
			array(
				'accountant'       => 'Accountant',
				'financial_analyst' => 'Financial Analyst',
			),
			$this->repo->getByCategory( 'financial' )
		);

		$this->assertSame( array(), $this->repo->getByCategory( 'does_not_exist' ) );
	}

	public function test_get_categories_groups_professions_by_category(): void {
		$categories = $this->repo->getCategories();

		$this->assertSame(
			array( 'advisory', 'creative', 'financial', 'healthcare', 'legal', 'technical' ),
			array_keys( $categories )
		);
		$this->assertSame( array( 'management_consultant' ), array_keys( $categories['advisory'] ) );
		$this->assertCount( 3, $categories['technical'] );

		$total = 0;
		foreach ( $categories as $professions ) {
			$total += count( $professions );
		}
		$this->assertSame( 9, $total, 'Grouped categories must cover the whole catalogue.' );
	}

	public function test_get_recommended_tools(): void {
		$this->assertContains( 'create_chart', $this->repo->getRecommendedTools( 'data_scientist' ) );
		$this->assertSame( array(), $this->repo->getRecommendedTools( 'does_not_exist' ) );
	}

	public function test_load_knowledge_base_keeps_the_contract_shape(): void {
		$this->assertSame(
			array(
				'knowledge' => array(),
				'playbook'  => array(),
			),
			$this->repo->loadKnowledgeBase( 'accountant' )
		);
	}

	public function test_config_path_override_replaces_the_catalogue(): void {
		$dir = sys_get_temp_dir() . '/nvoos-professions-' . uniqid();
		mkdir( $dir );

		file_put_contents(
			$dir . '/custom.json',
			json_encode(
				array(
					'category'    => 'custom',
					'professions' => array(
						array(
							'title'         => 'Zebra Wrangler',
							'slug'          => 'zebra_wrangler',
							'category'      => 'other',
							'default_tools' => array( 'web_search' ),
						),
					),
				)
			)
		);

		file_put_contents( $dir . '/manifest.json', json_encode( array( 'catalogue_version' => '9.9.9' ) ) );

		$this->app['config']->set( 'oos.professions.path', $dir );

		$repo = $this->app->make( ProfessionRepository::class );

		$this->assertSame( array( 'zebra_wrangler' => 'Zebra Wrangler' ), $repo->getAll() );
		$this->assertSame( '9.9.9', $repo->catalogueVersion() );

		unlink( $dir . '/custom.json' );
		unlink( $dir . '/manifest.json' );
		rmdir( $dir );
	}
}

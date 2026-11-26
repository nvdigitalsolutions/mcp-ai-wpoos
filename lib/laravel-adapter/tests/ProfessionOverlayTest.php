<?php
/**
 * The profession catalogue's tenant tier (Proposal 149 §170).
 *
 * Two layers, tested separately because they fail differently:
 *
 *  - the merge decorator must shadow, move and extend without inventing rows;
 *  - the JSON overlay must seed from the base, keep omitted fields and change
 *    the version on every write — the property the cache key depends on.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\ProfessionOverlayInterface;
use Nvoos\Laravel\Adapter\ProfessionRepository;
use Nvoos\Laravel\Profession\JsonProfessionOverlay;
use Nvoos\Laravel\Profession\OverlayProfessionRepository;

final class ProfessionOverlayTest extends TestCase {

	/**
	 * Directories this test created, removed in tearDown.
	 *
	 * @var list<string>
	 */
	private array $tempDirs = array();

	protected function tearDown(): void {
		foreach ( $this->tempDirs as $dir ) {
			foreach ( glob( $dir . '/*' ) ?: array() as $file ) {
				@unlink( $file );
			}

			@rmdir( $dir );
		}

		$this->tempDirs = array();

		parent::tearDown();
	}

	/**
	 * The decorator around the starter catalogue, with no overlay configured.
	 */
	public function test_a_host_without_an_overlay_reports_the_base_version(): void {
		$base = $this->app->make( ProfessionRepository::class );
		$repo = new OverlayProfessionRepository( $base );

		$this->assertSame( '1.0.0', $repo->catalogueVersion() );
		$this->assertSame( $base->getAll(), $repo->getAll() );
	}

	/**
	 * The overlay is part of the cache epoch, not a separate one.
	 */
	public function test_the_overlay_version_is_part_of_the_catalogue_version(): void {
		$base    = $this->app->make( ProfessionRepository::class );
		$overlay = $this->fakeOverlay();

		$repo = new OverlayProfessionRepository( $base, $overlay );

		$this->assertSame( '1.0.0|ov-test', $repo->catalogueVersion() );

		$overlay->version = 'ov-after-write';

		// A new decorator, as the container builds one per resolution: a bump
		// must be visible without a cache purge.
		$fresh = new OverlayProfessionRepository( $base, $overlay );

		$this->assertSame( '1.0.0|ov-after-write', $fresh->catalogueVersion() );
	}

	/**
	 * A tenant edit must reach every surface, not just the page that wrote it.
	 */
	public function test_an_overlay_row_shadows_the_base_row(): void {
		$base    = $this->app->make( ProfessionRepository::class );
		$overlay = $this->fakeOverlay();

		$overlay->rows['contract_lawyer'] = $this->record(
			'contract_lawyer',
			'Litigation Counsel',
			'legal'
		);

		$repo = new OverlayProfessionRepository( $base, $overlay );

		$this->assertSame( 'Litigation Counsel', $repo->getAll()['contract_lawyer'] );
		$this->assertCount(
			count( $base->getAll() ),
			$repo->getAll(),
			'Shadowing must replace the base row, not add a second one.'
		);

		$found = $repo->getBySlug( 'contract_lawyer' );

		$this->assertTrue( $found['found'] );
		$this->assertSame( 'Litigation Counsel', $found['profession']['name'] );
	}

	/**
	 * An overlay-only slug extends the catalogue.
	 */
	public function test_an_overlay_only_slug_appears_in_the_catalogue(): void {
		$base    = $this->app->make( ProfessionRepository::class );
		$overlay = $this->fakeOverlay();

		$overlay->rows['estate_archivist'] = $this->record(
			'estate_archivist',
			'Estate Archivist',
			'other'
		);

		$repo = new OverlayProfessionRepository( $base, $overlay );

		$this->assertArrayHasKey( 'estate_archivist', $repo->getAll() );
		$this->assertTrue( $repo->getBySlug( 'estate_archivist' )['found'] );
	}

	/**
	 * A slug in neither tier is still absent — the merge cannot invent a row.
	 */
	public function test_a_slug_in_neither_tier_is_absent(): void {
		$base = $this->app->make( ProfessionRepository::class );
		$repo = new OverlayProfessionRepository( $base, $this->fakeOverlay() );

		$this->assertFalse( $repo->getBySlug( 'not_a_profession' )['found'] );
	}

	/**
	 * Moving a row must move it in the category views too, and a category the
	 * shadowing emptied must disappear rather than render an empty filter.
	 */
	public function test_a_shadowed_row_leaves_its_base_category(): void {
		$base    = $this->app->make( ProfessionRepository::class );
		$overlay = $this->fakeOverlay();

		// `registered_nurse` is the starter catalogue's only healthcare row.
		$overlay->rows['registered_nurse'] = $this->record(
			'registered_nurse',
			'Clinical Nurse Advisor',
			'advisory'
		);

		$repo = new OverlayProfessionRepository( $base, $overlay );

		$this->assertArrayNotHasKey( 'registered_nurse', $repo->getByCategory( 'healthcare' ) );
		$this->assertArrayHasKey( 'registered_nurse', $repo->getByCategory( 'advisory' ) );

		$categories = $repo->getCategories();

		$this->assertArrayNotHasKey( 'healthcare', $categories );
		$this->assertArrayHasKey( 'registered_nurse', $categories['advisory'] );
	}

	/**
	 * Recommended tools follow the same shadowing rule as the record.
	 */
	public function test_recommended_tools_prefer_the_overlay(): void {
		$base    = $this->app->make( ProfessionRepository::class );
		$overlay = $this->fakeOverlay();

		$record = $this->record( 'contract_lawyer', 'Contract Lawyer', 'legal' );
		$record['default_tools'] = array( 'web_search', 'get_eca' );

		$overlay->rows['contract_lawyer'] = $record;

		$repo = new OverlayProfessionRepository( $base, $overlay );

		$this->assertSame( array( 'web_search', 'get_eca' ), $repo->getRecommendedTools( 'contract_lawyer' ) );
	}

	/**
	 * A save creates a row, reports `created`, and makes it readable.
	 */
	public function test_a_save_creates_a_row_and_bumps_the_version(): void {
		$overlay = $this->overlay();

		$before = $overlay->overlayVersion();

		$result = $overlay->save(
			array(
				'slug'        => 'Estate_Archivist',
				'title'       => 'Estate Archivist',
				'category'    => 'other',
				'description' => 'Keeps the estate records.',
			)
		);

		$this->assertTrue( $result['saved'] );
		$this->assertSame( 'created', $result['action'] );

		// The slug is case-folded the way WordPress sanitises a key, so a caller
		// sending a display-style slug still lands on the catalogue's key.
		$this->assertArrayHasKey( 'estate_archivist', $overlay->overlays() );
		$this->assertNotSame(
			$before,
			$overlay->overlayVersion(),
			'A write must change the fingerprint the cache key embeds.'
		);
	}

	/**
	 * A title-only update must not blank the rest of a seeded base record.
	 */
	public function test_a_save_keeps_the_fields_it_was_not_given(): void {
		$overlay = $this->overlay();

		$base = $overlay->overlays();

		$this->assertArrayNotHasKey( 'contract_lawyer', $base );

		$overlay->save(
			array(
				'slug'  => 'contract_lawyer',
				'title' => 'Litigation Counsel',
			)
		);

		$merged = $overlay->overlays();

		$this->assertSame( 'Litigation Counsel', $merged['contract_lawyer']['name'] );

		// Seeded from the base tier: the description and tool list survive a
		// save that never mentioned them.
		$this->assertNotSame( '', $merged['contract_lawyer']['description'] );
		$this->assertNotEmpty( $merged['contract_lawyer']['default_tools'] );
	}

	/**
	 * A second save for the same slug is an update, not a second row.
	 */
	public function test_a_second_save_updates_the_same_row(): void {
		$overlay = $this->overlay();

		$overlay->save( array( 'slug' => 'estate_archivist', 'title' => 'Estate Archivist' ) );

		$result = $overlay->save(
			array(
				'slug'        => 'estate_archivist',
				'title'       => 'Estate Archivist',
				'description' => 'Second pass.',
			)
		);

		$this->assertSame( 'updated', $result['action'] );
		$this->assertSame( 'Second pass.', $overlay->overlays()['estate_archivist']['description'] );
		$this->assertCount( 1, $overlay->overlays() );
	}

	/**
	 * The tool's own contract: both fields are required.
	 */
	public function test_a_save_without_a_slug_or_title_fails(): void {
		$overlay = $this->overlay();

		$result = $overlay->save( array( 'slug' => '' ) );

		$this->assertFalse( $result['saved'] );
		$this->assertNotSame( '', $result['error'] );
	}

	/**
	 * A JSON overlay writing to a directory this test owns.
	 */
	private function overlay(): JsonProfessionOverlay {
		$dir = sys_get_temp_dir() . '/nvoos-overlay-' . uniqid();

		$this->tempDirs[] = $dir;

		$this->app['config']->set( 'oos.professions.overlay_path', $dir );

		return new JsonProfessionOverlay(
			$this->app->make( ProfessionRepository::class ),
			$this->app['config']
		);
	}

	/**
	 * An in-memory overlay, so the merge rules are tested without storage.
	 */
	private function fakeOverlay(): object {
		return new class() implements ProfessionOverlayInterface {
			/** @var array<string, array<string, mixed>> */
			public array $rows = array();

			public string $version = 'ov-test';

			public function overlays(): array {
				return $this->rows;
			}

			public function overlayVersion(): string {
				return $this->version;
			}
		};
	}

	/**
	 * A minimal normalised record.
	 *
	 * @return array<string, mixed>
	 */
	private function record( string $slug, string $name, string $category ): array {
		return array(
			'id'               => 0,
			'slug'             => $slug,
			'name'             => $name,
			'description'      => 'Overlay description.',
			'category'         => $category,
			'role_description' => '',
			'expertise'        => array(),
			'warnings'         => array(),
			'knowledge_base'   => '',
			'default_tools'    => array( 'web_search' ),
		);
	}
}

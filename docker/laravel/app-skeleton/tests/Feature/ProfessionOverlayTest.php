<?php

namespace Tests\Feature;

use App\Models\Profession;
use App\Support\Professions\ProfessionOverlayStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ProfessionOverlayInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;
use Nvoos\Core\Tool\SaveProfessionTool;
use Tests\TestCase;

/**
 * `save_profession` and the estate overlay — Proposal 149 §170.
 *
 * The manifest row was `unavailable` for as long as this runtime had no tenant
 * tier for the catalogue. These tests pin the four properties that justify
 * promoting it to `ready`:
 *
 *  - the base catalogue still answers until an estate writes;
 *  - a write is visible through the same repository every read surface uses,
 *    not only through the store that wrote it;
 *  - an update keeps the fields it was not given, because the row is seeded
 *    from the base record first;
 *  - one estate cannot see another's override, which is the whole reason the
 *    row carries `tenant_id`.
 *
 * The last test drives the tool itself rather than the store, because the point
 * of the flip is that the container can build it.
 */
final class ProfessionOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const ESTATE = 'profession-estate-a';

    private const OTHER_ESTATE = 'profession-estate-b';

    /** A base profession with a description and recommended tools. */
    private const BASE_SLUG = 'lawyer';

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::set(self::ESTATE);
    }

    protected function tearDown(): void
    {
        TenantContext::set(null);

        parent::tearDown();
    }

    public function test_the_base_catalogue_answers_before_any_estate_writes(): void
    {
        $repo = $this->app->make(ProfessionRepositoryInterface::class);

        $found = $repo->getBySlug(self::BASE_SLUG);

        $this->assertTrue($found['found']);
        $this->assertSame('Lawyer', $found['profession']['name']);
        $this->assertSame(array(), $this->store()->overlays());
    }

    public function test_the_repository_is_not_a_writer(): void
    {
        $repo = $this->app->make(ProfessionRepositoryInterface::class);

        $this->assertNotInstanceOf(ProfessionWriteInterface::class, $repo);
        $this->assertInstanceOf(ProfessionOverlayStore::class, $this->app->make(ProfessionWriteInterface::class));
    }

    public function test_a_saved_profession_shadows_the_base_row(): void
    {
        $this->store()->save(
            array(
                'slug' => self::BASE_SLUG,
                'title' => 'Litigation Counsel',
            )
        );

        // A fresh resolution, as the next request would build: the write has to
        // be visible through the read path, not just in the store.
        $repo = $this->app->make(ProfessionRepositoryInterface::class);
        $found = $repo->getBySlug(self::BASE_SLUG);

        $this->assertSame('Litigation Counsel', $found['profession']['name']);
        $this->assertSame('Litigation Counsel', $repo->getAll()[self::BASE_SLUG]);
    }

    public function test_an_update_keeps_the_fields_it_was_not_given(): void
    {
        $before = $this->app->make(ProfessionRepositoryInterface::class)
            ->getBySlug(self::BASE_SLUG)['profession'];

        $this->store()->save(
            array(
                'slug' => self::BASE_SLUG,
                'title' => 'Litigation Counsel',
            )
        );

        $after = $this->app->make(ProfessionRepositoryInterface::class)
            ->getBySlug(self::BASE_SLUG)['profession'];

        // Copy-on-write: the row was seeded from the base record, so a
        // title-only save does not blank the description or the tool list.
        $this->assertNotSame('', $before['description']);
        $this->assertSame($before['description'], $after['description']);
        $this->assertSame($before['default_tools'], $after['default_tools']);
        $this->assertSame($before['category'], $after['category']);
    }

    public function test_an_estate_cannot_see_another_estates_override(): void
    {
        $this->store()->save(
            array(
                'slug' => self::BASE_SLUG,
                'title' => 'Litigation Counsel',
            )
        );

        TenantContext::set(self::OTHER_ESTATE);

        $other = $this->app->make(ProfessionRepositoryInterface::class)
            ->getBySlug(self::BASE_SLUG);

        $this->assertSame('Lawyer', $other['profession']['name']);
        $this->assertSame(array(), $this->store()->overlays());
    }

    public function test_a_save_changes_the_catalogue_version(): void
    {
        $repo = $this->app->make(ProfessionRepositoryInterface::class);

        $before = $repo->catalogueVersion();

        $this->store()->save(
            array(
                'slug' => 'estate_archivist',
                'title' => 'Estate Archivist',
                'category' => 'other',
            )
        );

        $after = $this->app->make(ProfessionRepositoryInterface::class)->catalogueVersion();

        $this->assertNotSame($before, $after, 'A write must change the cache epoch.');
        $this->assertStringStartsWith('ov-', (string) substr($after, strrpos($after, '|') + 1));
    }

    public function test_the_container_builds_save_profession_and_it_writes(): void
    {
        $tool = $this->app->make(SaveProfessionTool::class);

        $created = $tool->execute(
            array(
                'slug' => 'estate_archivist',
                'title' => 'Estate Archivist',
                'category' => 'other',
                'description' => 'Keeps the estate records.',
            )
        );

        $this->assertTrue($created['success']);
        $this->assertSame('created', $created['data']['action']);

        $updated = $tool->execute(
            array(
                'slug' => 'estate_archivist',
                'title' => 'Estate Archivist',
                'description' => 'Keeps the estate records, revised.',
            )
        );

        $this->assertTrue($updated['success']);
        $this->assertSame('updated', $updated['data']['action']);

        $row = Profession::query()->where('slug', 'estate_archivist')->first();

        $this->assertNotNull($row);
        $this->assertSame(self::ESTATE, (string) $row->tenant_id);
        $this->assertSame('Keeps the estate records, revised.', (string) $row->description);
    }

    private function store(): ProfessionOverlayStore
    {
        $store = $this->app->make(ProfessionOverlayInterface::class);

        $this->assertInstanceOf(ProfessionOverlayStore::class, $store);

        return $store;
    }
}

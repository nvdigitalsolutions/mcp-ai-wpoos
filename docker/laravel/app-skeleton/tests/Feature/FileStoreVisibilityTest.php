<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Laravel\Adapter\FileStore;
use Tests\TestCase;

/**
 * Proposal 162 — one registry, one identifier, two visibilities.
 *
 * The defects this pins, in the order they broke things:
 *
 *  - `store()` returned `crc32($path)` and inserted no row, so the id could not
 *    be resolved by `getMetadata()` and `upload_file` → `get_file_info` /
 *    `delete_file` never worked;
 *  - every write was public, because `put(..., 'public')` was hard-coded and
 *    there was no visibility column;
 *  - `delete()` removed the bytes and left the row.
 *
 * Each test below is one of those, stated as a property.
 */
final class FileStoreVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 PNG — real bytes, so the sniff has something to find. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $scratch = array();

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config(array(
            'cache.default' => 'array',
            'oos.files.disks.public' => 'public',
            'oos.files.disks.private' => 'local',
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    // ── The store ──────────────────────────────────────────────────────

    public function test_a_public_file_gets_a_row_and_a_url(): void
    {
        $file = $this->store()->storeWithVisibility(
            $this->source('hello world', 'txt'),
            'notes.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            'default'
        );

        $row = DB::table('nvoos_files')->where('id', $file->id)->first();

        $this->assertNotNull($row, 'store() must insert the row, or the id cannot resolve.');
        $this->assertSame('public', $row->visibility);
        $this->assertSame('default', $row->tenant_id);
        $this->assertSame(hash('sha256', 'hello world'), $row->checksum);
        $this->assertNotNull($file->publicUrl);
        $this->assertTrue(Storage::disk('public')->exists((string) $row->path));
    }

    public function test_a_private_file_has_no_url_and_lives_on_the_private_disk(): void
    {
        $file = $this->store()->storeWithVisibility(
            $this->source('secret', 'txt'),
            'secret.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
            'default'
        );

        $row = DB::table('nvoos_files')->where('id', $file->id)->first();

        $this->assertNull($file->publicUrl, 'A private file must never be given a public URL.');
        $this->assertSame('local', $row->disk);
        $this->assertStringStartsWith('default/private/', (string) $row->path);
        $this->assertTrue(Storage::disk('local')->exists((string) $row->path));
        $this->assertFalse(Storage::disk('public')->exists((string) $row->path));
    }

    public function test_the_returned_id_resolves_back_through_get_metadata(): void
    {
        $store = $this->store();
        $file = $store->storeWithVisibility(
            $this->source('round trip', 'txt'),
            'round-trip.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
            'estate-b'
        );

        $meta = $store->getMetadata($file->id);

        $this->assertNotNull($meta, 'The id returned by store() must resolve.');
        $this->assertSame($file->id, $meta->id);
        $this->assertSame('round-trip.txt', $meta->filename);
        $this->assertSame('private', $meta->visibility);
        $this->assertSame('round-trip', $meta->slug);
        $this->assertTrue($meta->isPrivate());
    }

    public function test_a_duplicate_name_gets_the_next_slug_and_keeps_its_case(): void
    {
        // WordPress appends -2, -3 (wp_unique_post_slug) and keeps the title's
        // case while lowercasing the slug. §162.8 does the same.
        $store = $this->store();

        $first = $store->storeWithVisibility(
            $this->source('first notes', 'txt'),
            'Notes.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );

        $second = $store->storeWithVisibility(
            $this->source('second notes', 'txt'),
            'Notes.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );

        $this->assertSame('notes', $first->slug);
        $this->assertSame('notes-2', $second->slug);
        $this->assertSame('Notes.txt', $first->filename, 'The display name keeps its case.');
        $this->assertSame('notes', DB::table('nvoos_files')->where('id', $first->id)->value('slug'));
    }

    public function test_the_slug_is_unique_per_estate_not_globally(): void
    {
        $store = $this->store();

        $a = $store->storeWithVisibility(
            $this->source('estate a notes', 'txt'),
            'Notes.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
            'default'
        );

        $b = $store->storeWithVisibility(
            $this->source('estate b notes', 'txt'),
            'Notes.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
            'estate-b'
        );

        $this->assertSame('notes', $a->slug);
        $this->assertSame('notes', $b->slug, 'A second estate may use the same slug.');
    }

    public function test_delete_removes_the_row_and_the_bytes(): void
    {
        $store = $this->store();
        $file = $store->storeWithVisibility(
            $this->source('delete me', 'txt'),
            'delete-me.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            'default'
        );

        $row = DB::table('nvoos_files')->where('id', $file->id)->first();
        $path = (string) $row->path;

        $store->delete($file->id);

        $this->assertNull(DB::table('nvoos_files')->where('id', $file->id)->first(), 'The row must go.');
        $this->assertFalse(Storage::disk('public')->exists($path), 'The bytes must go.');
    }

    public function test_the_extension_comes_from_the_sniffed_bytes_not_the_filename(): void
    {
        $file = $this->store()->storeWithVisibility(
            $this->source(base64_decode(self::PNG, true), 'txt'),
            'actually-a-png.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );

        $row = DB::table('nvoos_files')->where('id', $file->id)->first();

        $this->assertSame('image/png', $row->mime_type);
        $this->assertStringEndsWith('.png', (string) $row->path);
        $this->assertStringEndsWith('.png', (string) $row->filename);
    }

    public function test_a_type_outside_the_allowlist_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->store()->storeWithVisibility(
            $this->source('<html><body>no</body></html>', 'html'),
            'page.html',
            'text/html',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );
    }

    public function test_a_file_over_its_group_cap_is_refused(): void
    {
        $store = new FileStore('public', 'local', 0, array(), array('document' => 2));

        $this->expectException(ValidationException::class);

        $store->storeWithVisibility(
            $this->source('too big', 'txt'),
            'too-big.txt',
            'text/plain',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );
    }

    public function test_an_unknown_visibility_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->store()->storeWithVisibility(
            $this->source('x', 'txt'),
            'x.txt',
            'text/plain',
            7,
            'world-readable'
        );
    }

    // ── The read path ──────────────────────────────────────────────────

    public function test_the_owner_can_read_a_private_file(): void
    {
        $user = User::factory()->create();

        $file = $this->store()->storeWithVisibility(
            $this->source('owner reads this', 'txt'),
            'mine.txt',
            'text/plain',
            (int) $user->getKey(),
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );

        $response = $this->actingAs($user)->get('/api/v1/files/'.$file->id);

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        // Symfony reorders Cache-Control directives, so assert the directives
        // rather than one string — the order is not part of the contract.
        $cacheControl = (string) $response->headers->get('Cache-Control');

        foreach (array('private', 'no-store', 'max-age=0') as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
    }

    public function test_another_estate_cannot_reach_the_file(): void
    {
        $user = User::factory()->create();

        $file = $this->store()->storeWithVisibility(
            $this->source('estate b only', 'txt'),
            'b.txt',
            'text/plain',
            (int) $user->getKey(),
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
            'estate-b'
        );

        TenantContext::set('default');

        $this->actingAs($user)
            ->get('/api/v1/files/'.$file->id)
            ->assertNotFound();
    }

    public function test_a_non_owner_without_administration_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $file = $this->store()->storeWithVisibility(
            $this->source('not yours', 'txt'),
            'theirs.txt',
            'text/plain',
            (int) $owner->getKey(),
            FileVisibilityStoreInterface::VISIBILITY_PRIVATE
        );

        $this->actingAs($other)
            ->get('/api/v1/files/'.$file->id)
            ->assertForbidden();
    }

    public function test_a_missing_file_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/api/v1/files/999999')
            ->assertNotFound();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function store(): FileStore
    {
        return app(FileStore::class);
    }

    /**
     * Write bytes to a scratch file and return its path.
     */
    private function source(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nvoos-test-').'.'.$extension;

        file_put_contents($path, $contents);

        $this->scratch[] = $path;

        return $path;
    }
}

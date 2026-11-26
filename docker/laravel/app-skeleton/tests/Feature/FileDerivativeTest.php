<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Application\Media\MediaDerivative;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Laravel\Adapter\FileStore;
use Tests\TestCase;

/**
 * Proposal 164 §164.46 — a produced file records what it was made from.
 *
 * The registry could always answer "what is this file"; it could not answer
 * "where did it come from". So a generated poster was stored and then
 * unreachable: the only route back to a video's thumbnail was to guess from its
 * filename, which is not a relationship.
 *
 * XMP calls this `xmpMM:DerivedFrom` — the **immediate** source, so a chain stays
 * walkable one step at a time. The tests below pin the write, the read, and the
 * three refusals that keep a wrong relationship out of the table, because a wrong
 * link is trusted whereas a missing one is merely missed.
 */
final class FileDerivativeTest extends TestCase
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

    // ── The write ────────────────────────────────────────────────────────

    public function test_a_derivative_records_its_source_and_role(): void
    {
        $source = $this->file('source.png');
        $poster = $this->file('poster.png');

        $linked = $this->store()->recordDerivative(
            $poster->id,
            $source->id,
            FileDerivativeStoreInterface::ROLE_POSTER,
        );

        $this->assertSame(
            $source->id,
            $linked->metadata[FileDerivativeStoreInterface::META_SOURCE],
            'The relationship must come back on the entity, not only in the row.'
        );

        $this->assertSame(
            FileDerivativeStoreInterface::ROLE_POSTER,
            $linked->metadata[FileDerivativeStoreInterface::META_ROLE]
        );

        // The source id is stored as an integer, because a string criterion is
        // matched by substring and `'7'` would also match file 17.
        $row = DB::table('nvoos_files')->where('id', $poster->id)->first();

        $this->assertIsInt(
            json_decode((string) $row->metadata, true)[FileDerivativeStoreInterface::META_SOURCE]
        );
    }

    /**
     * Recording the same pair twice is not an error: the link is a fact, and
     * re-asserting a fact is idempotent.
     */
    public function test_recording_the_same_link_twice_is_idempotent(): void
    {
        $source = $this->file('source.png');
        $poster = $this->file('poster.png');

        $this->store()->recordDerivative($poster->id, $source->id, FileDerivativeStoreInterface::ROLE_POSTER);
        $this->store()->recordDerivative($poster->id, $source->id, FileDerivativeStoreInterface::ROLE_POSTER);

        $found = $this->store()->findByMetadata(
            MediaDerivative::criteria($source->id, FileDerivativeStoreInterface::ROLE_POSTER),
            10,
        );

        $this->assertCount(1, $found);
        $this->assertSame($poster->id, $found[0]->id);
    }

    /**
     * The write merges. A file may already carry metadata, and a relationship is
     * not a reason to drop it.
     */
    public function test_a_link_merges_with_existing_metadata(): void
    {
        $source = $this->file('source.png');
        $poster = $this->file('poster.png');

        DB::table('nvoos_files')->where('id', $poster->id)->update(
            array('metadata' => json_encode(array('alt_text' => 'A poster')))
        );

        $linked = $this->store()->recordDerivative(
            $poster->id,
            $source->id,
            FileDerivativeStoreInterface::ROLE_POSTER,
        );

        $this->assertSame('A poster', $linked->metadata['alt_text']);
        $this->assertSame($source->id, $linked->metadata[FileDerivativeStoreInterface::META_SOURCE]);
    }

    // ── The read ─────────────────────────────────────────────────────────

    /**
     * The point of the whole slice: write a link, find the file by it.
     */
    public function test_a_derivative_is_found_by_its_criteria(): void
    {
        $source = $this->file('source.png');
        $poster = $this->file('poster.png');
        $unrelated = $this->file('other.png');

        $this->store()->recordDerivative($poster->id, $source->id, FileDerivativeStoreInterface::ROLE_POSTER);

        $found = $this->store()->findByMetadata(
            MediaDerivative::criteria($source->id, FileDerivativeStoreInterface::ROLE_POSTER),
            10,
        );

        $ids = array_map(static fn (StoredFile $file): int => $file->id, $found);

        $this->assertContains($poster->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    /**
     * Source ids `1` and `10` must not collide. This is the substring trap
     * `findByMetadata()` has for string criteria — `stripos('10', '1')` is 0, so a
     * string criterion would match both — pinned where it can actually hurt: an
     * identifier.
     */
    public function test_nearby_source_ids_do_not_collide(): void
    {
        $sourceOne = $this->file('one.png');
        $sourceTen = $this->file('ten.png');

        // Force the second source to an id whose decimal form contains the first's.
        DB::table('nvoos_files')->where('id', $sourceTen->id)->update(array('id' => 10));

        $posterOne = $this->file('one-poster.png');
        $posterTen = $this->file('ten-poster.png');

        $this->store()->recordDerivative($posterOne->id, $sourceOne->id, FileDerivativeStoreInterface::ROLE_POSTER);
        $this->store()->recordDerivative($posterTen->id, 10, FileDerivativeStoreInterface::ROLE_POSTER);

        $found = $this->store()->findByMetadata(
            MediaDerivative::criteria($sourceOne->id, FileDerivativeStoreInterface::ROLE_POSTER),
            10,
        );

        $ids = array_map(static fn (StoredFile $file): int => $file->id, $found);

        $this->assertSame(
            array($posterOne->id),
            $ids,
            'A query for source 1 must not return the derivative of source 10.'
        );
    }

    // ── The refusals ─────────────────────────────────────────────────────

    public function test_a_file_cannot_derive_from_itself(): void
    {
        $file = $this->file('self.png');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot derive from itself');

        $this->store()->recordDerivative($file->id, $file->id, FileDerivativeStoreInterface::ROLE_POSTER);
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $source = $this->file('source.png');
        $poster = $this->file('poster.png');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown derivative role');

        // `thumbnail` is the tempting spelling; the vocabulary says `poster`.
        $this->store()->recordDerivative($poster->id, $source->id, 'thumbnail');
    }

    public function test_a_missing_source_is_refused(): void
    {
        $poster = $this->file('poster.png');

        $this->expectException(NotFoundException::class);

        $this->store()->recordDerivative($poster->id, 999999, FileDerivativeStoreInterface::ROLE_POSTER);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function store(): FileStore
    {
        return app(FileStore::class);
    }

    /**
     * Store a real PNG and return the registry row.
     */
    private function file(string $name): StoredFile
    {
        return $this->store()->storeWithVisibility(
            $this->source(),
            $name,
            'image/png',
            7,
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            'default',
        );
    }

    private function source(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nvoos-derivative-').'.png';

        file_put_contents($path, (string) base64_decode(self::PNG, true));

        $this->scratch[] = $path;

        return $path;
    }
}

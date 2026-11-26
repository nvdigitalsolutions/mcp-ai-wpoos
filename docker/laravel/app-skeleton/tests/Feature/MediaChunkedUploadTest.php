<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Resumable uploads — Proposal 164 §164.30 (tus 1.0.0 core).
 *
 * Each test is one of the protocol's guarantees rather than one of our
 * conveniences: the offset is the client's to declare and the server's to hold
 * (409, never a guessed append), a session belongs to one caller, and completion
 * is the *same* rule set as the multipart endpoint — sniff, allowlist, registry.
 *
 * The chunk size is four bytes and the ceiling sixty-four so a twelve-byte file
 * is a three-chunk transfer. That is the point of taking the numbers from config:
 * the state machine is what is under test, not the size of a buffer.
 */
final class MediaChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    /** A valid MP3 frame header; three of them are twelve bytes of `audio/mpeg`. */
    private const FRAME = 'fffb9064';

    private const CHUNK = 4;

    private const TOTAL = 12;

    private const CEILING = 64;

    /** Sniffs as `image/svg+xml`, which is not in the upload allowlist. */
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'cache.default' => 'array',
            'oos.files.disks.public' => 'public',
            'oos.files.disks.private' => 'local',
            'oos.media_disk' => 'public',
            'oos.chunked_uploads.disk' => 'local',
            'oos.chunked_uploads.chunk_bytes' => self::CHUNK,
            'oos.chunked_uploads.max_bytes' => self::CEILING,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_a_file_arrives_in_chunks_and_lands_in_the_library(): void
    {
        $path = $this->location($this->create());

        $this->assertSame('0', $this->headRequest($path)->headers->get('Upload-Offset'));

        $bytes = $this->audio();

        $this->append($path, 0, substr($bytes, 0, 4))->assertNoContent();
        $this->append($path, 4, substr($bytes, 4, 4))->assertNoContent();

        $completing = $this->append($path, 8, substr($bytes, 8, 4));

        $completing->assertNoContent();
        $this->assertSame('12', $completing->headers->get('Upload-Offset'));

        $stored = (int) $completing->headers->get('X-Nvoos-Attachment-Id');

        $this->assertGreaterThan(0, $stored);

        $this->getJson('/api/v1/media/'.$stored)
            ->assertOk()
            ->assertJsonPath('mime_type', 'audio/mpeg');

        // Chunks are scratch: once the registry holds the bytes they are gone.
        $this->assertSame([], Storage::disk('local')->allFiles('chunked-uploads'));
    }

    public function test_a_wrong_offset_is_conflict_and_writes_nothing(): void
    {
        $path = $this->location($this->create());

        $refused = $this->append($path, 4, 'abcdefgh');

        $refused->assertStatus(409);
        $this->assertSame('0', $refused->headers->get('Upload-Offset'));

        // The guarantee: the file is where it was, not where the client guessed.
        $this->assertSame('0', $this->headRequest($path)->headers->get('Upload-Offset'));
    }

    public function test_head_says_where_a_dropped_upload_stopped(): void
    {
        $path = $this->location($this->create());

        $this->append($path, 0, substr($this->audio(), 0, 4))->assertNoContent();

        $this->headRequest($path)->assertOk()
            ->assertHeader('Upload-Offset', '4')
            ->assertHeader('Upload-Length', '12')
            ->assertHeader('Tus-Resumable', '1.0.0');
    }

    public function test_an_unknown_upload_is_not_found(): void
    {
        $this->headRequest('/api/v1/media/uploads/'.str_repeat('a', 32))->assertNotFound();

        $this->append('/api/v1/media/uploads/'.str_repeat('a', 32), 0, 'abcd')->assertNotFound();
    }

    public function test_a_session_belongs_to_the_caller_that_opened_it(): void
    {
        $path = $this->location($this->create());

        Sanctum::actingAs(User::factory()->admin()->create());

        // Same answer as an id that does not exist: which ids are real is not
        // something a caller should be able to learn.
        $this->headRequest($path)->assertNotFound();
        $this->append($path, 0, 'abcd')->assertNotFound();
    }

    public function test_a_total_over_the_ceiling_is_refused_before_any_bytes(): void
    {
        $this->create(self::CEILING + 1)
            ->assertStatus(422)
            ->assertJsonPath('code', 'tus_upload_invalid_length');
    }

    public function test_a_chunk_over_the_ceiling_is_refused(): void
    {
        $path = $this->location($this->create());

        $this->append($path, 0, 'abcdefghij')
            ->assertStatus(422)
            ->assertJsonPath('code', 'tus_upload_refused');
    }

    public function test_a_disallowed_type_is_refused_when_the_file_is_assembled(): void
    {
        config([
            'oos.chunked_uploads.chunk_bytes' => 4096,
            'oos.chunked_uploads.max_bytes' => 4096,
        ]);

        $path = $this->location($this->create(strlen(self::SVG), 'icon.svg'));

        $refused = $this->append($path, 0, self::SVG);

        $refused->assertStatus(422);
        $this->assertStringContainsString('is not allowed', (string) $refused->json('message'));

        // The bytes are still there, so the caller can retry or abort rather than
        // re-send a file the network already carried.
        $this->headRequest($path)->assertOk()->assertHeader('Upload-Offset', (string) strlen(self::SVG));
    }

    public function test_abort_discards_the_session_and_its_chunks(): void
    {
        $path = $this->location($this->create());

        $this->append($path, 0, 'abcd')->assertNoContent();

        $this->call('DELETE', $path, [], [], [], $this->tus())->assertNoContent();

        $this->headRequest($path)->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('chunked-uploads'));
    }

    public function test_options_advertises_the_version_and_the_ceiling(): void
    {
        $this->call('OPTIONS', '/api/v1/media/uploads', [], [], [], $this->tus())
            ->assertNoContent()
            ->assertHeader('Tus-Version', '1.0.0')
            ->assertHeader('Tus-Resumable', '1.0.0')
            ->assertHeader('Tus-Max-Size', (string) self::CEILING);
    }

    public function test_an_unsupported_protocol_version_is_412(): void
    {
        $this->call('POST', '/api/v1/media/uploads', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_TUS_RESUMABLE' => '0.2.2',
            'HTTP_UPLOAD_LENGTH' => '12',
        ])->assertStatus(412)->assertHeader('Tus-Version', '1.0.0');
    }

    public function test_creation_needs_a_length(): void
    {
        $this->call('POST', '/api/v1/media/uploads', [], [], [], $this->tus())
            ->assertStatus(400)
            ->assertJsonPath('code', 'tus_upload_length_required');
    }

    public function test_a_patch_without_the_tus_content_type_is_refused(): void
    {
        $path = $this->location($this->create());

        $this->call('PATCH', $path, [], [], [], $this->tus([
            'HTTP_UPLOAD_OFFSET' => '0',
            'CONTENT_TYPE' => 'application/octet-stream',
        ]), 'abcd')->assertStatus(415);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function audio(): string
    {
        return (string) hex2bin(str_repeat(self::FRAME, 3));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function tus(array $extra = []): array
    {
        return $extra + [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ];
    }

    private function create(int $length = self::TOTAL, string $name = 'memo.mp3'): TestResponse
    {
        return $this->call('POST', '/api/v1/media/uploads', [], [], [], $this->tus([
            'HTTP_UPLOAD_LENGTH' => (string) $length,
            'HTTP_UPLOAD_METADATA' => 'filename '.base64_encode($name),
        ]));
    }

    private function location(TestResponse $created): string
    {
        $created->assertCreated()->assertHeader('Upload-Offset', '0');

        $path = parse_url((string) $created->headers->get('Location'), PHP_URL_PATH);

        $this->assertIsString($path);

        return $path;
    }

    private function headRequest(string $path): TestResponse
    {
        return $this->call('HEAD', $path, [], [], [], $this->tus());
    }

    private function append(string $path, int $offset, string $bytes): TestResponse
    {
        return $this->call('PATCH', $path, [], [], [], $this->tus([
            'HTTP_UPLOAD_OFFSET' => (string) $offset,
            'CONTENT_TYPE' => 'application/offset+octet-stream',
        ]), $bytes);
    }
}

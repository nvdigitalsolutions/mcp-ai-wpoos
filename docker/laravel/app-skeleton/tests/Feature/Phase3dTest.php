<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 3d — media library (`wp/v2/media`-shaped).
 *
 * Assertions follow `media.ts` in `addons/pro/assets/spa-v2` and the
 * attachment uploader in `api/tools.ts` (multipart field `file`).
 */
class Phase3dTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Minimal containers that libmagic types correctly.
     *
     * An MP3 frame header and an ISO-BMFF `ftyp` box are what the sniffer keys
     * on, so four bytes and twenty-eight bytes are enough to prove the gate — a
     * recording would prove nothing about the allowlist that these do not.
     */
    private const MP3_HEADER = 'fffb9064';

    private const MP4_FTYP = '0000001c6674797069736f6d0000020069736f6d69736f32';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function actAsUser(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function png(string $name = 'pixel.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            (string) base64_decode(self::ONE_PIXEL_PNG, true),
        );
    }

    public function test_upload_stores_an_image_and_returns_the_wordpress_shape(): void
    {
        $this->actAsUser();

        $response = $this->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('mime_type', 'image/png')
            ->assertJsonPath('type', 'attachment')
            ->assertJsonPath('status', 'inherit')
            ->assertJsonStructure([
                'id',
                'date',
                'slug',
                'title' => ['rendered'],
                'caption' => ['rendered'],
                'alt_text',
                'description' => ['rendered'],
                'mime_type',
                'source_url',
                'media_details' => ['width', 'height', 'filesize', 'sizes'],
                'author',
            ]);

        // `media/` used to prefix the object key and a random suffix used to keep
        // names unique. §162.7 moved the write to `FileStore`, which keys objects
        // by ULID under `{tenant}/{visibility}/{Y}/{m}` — so uniqueness no longer
        // needs to be part of the display name, and the slug is the sanitised stem
        // WordPress would emit rather than `pixel-<random>`.
        $url = (string) $response->json('source_url');

        $this->assertStringStartsWith('/storage/', $url);
        $this->assertStringEndsWith('.png', $url);
        $this->assertStringContainsString('/public/', $url);
        $this->assertSame(1, $response->json('media_details.width'));
        $this->assertSame(1, $response->json('media_details.height'));
        $this->assertSame('pixel', $response->json('slug'));
    }

    public function test_a_second_upload_of_the_same_name_gets_the_next_slug(): void
    {
        // wp_unique_post_slug() appends -2; the media surface now reads the slug
        // column rather than deriving it from the stored filename.
        $this->actAsUser();

        $this->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('slug', 'pixel');

        $this->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('slug', 'pixel-2');
    }

    public function test_upload_accepts_audio_and_video(): void
    {
        $this->actAsUser();

        // Audio is a library asset in its own right: the transcription tools
        // resolve one by id, so a user has to be able to add one.
        $audio = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->createWithContent('memo.mp3', (string) hex2bin(self::MP3_HEADER)),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('mime_type', 'audio/mpeg');

        $this->assertStringEndsWith('.mp3', (string) $audio->json('source_url'));

        $video = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->createWithContent('clip.mp4', (string) hex2bin(self::MP4_FTYP)),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('mime_type', 'video/mp4');

        $this->assertStringEndsWith('.mp4', (string) $video->json('source_url'));

        // Dimensions are an image question; probing a video there would be a
        // `getimagesize()` on bytes it cannot read.
        $this->assertSame(0, $video->json('media_details.width'));
        $this->assertSame(0, $video->json('media_details.height'));
    }

    public function test_list_filters_the_audio_family(): void
    {
        $this->actAsUser();

        $this->post('/api/v1/media', ['file' => $this->png('holiday.png')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->createWithContent('memo.mp3', (string) hex2bin(self::MP3_HEADER)),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->getJson('/api/v1/media?media_type=audio')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.mime_type', 'audio/mpeg');

        $this->getJson('/api/v1/media?media_type=video')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_upload_rejects_a_disallowed_mime_type(): void
    {
        $this->actAsUser();

        $svg = UploadedFile::fake()->createWithContent(
            'icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
        );

        $this->post('/api/v1/media', ['file' => $svg], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'rest_upload_invalid_file');
    }

    public function test_upload_rejects_a_file_over_the_size_cap(): void
    {
        $this->actAsUser();

        $big = UploadedFile::fake()->createWithContent('huge.txt', str_repeat('a', 11 * 1024 * 1024));

        $this->post('/api/v1/media', ['file' => $big], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'rest_upload_invalid_file');
    }

    public function test_upload_requires_a_file_field(): void
    {
        $this->actAsUser();

        $this->post('/api/v1/media', [], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'rest_upload_no_data');
    }

    public function test_list_paginates_with_total_headers(): void
    {
        $this->actAsUser();

        $this->post('/api/v1/media', ['file' => $this->png('one.png')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/media', ['file' => $this->png('two.png')], ['Accept' => 'application/json'])->assertCreated();

        $response = $this->getJson('/api/v1/media?per_page=1&page=1')
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame('2', $response->headers->get('X-WP-Total'));
        $this->assertSame('2', $response->headers->get('X-WP-TotalPages'));
    }

    public function test_list_supports_search_and_media_type_filters(): void
    {
        $this->actAsUser();

        $this->post('/api/v1/media', ['file' => $this->png('holiday.png')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->getJson('/api/v1/media?search=holiday')
            ->assertOk()
            ->assertJsonCount(1);

        $this->getJson('/api/v1/media?media_type=image')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.mime_type', 'image/png');

        $this->getJson('/api/v1/media?media_type=text/plain')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.mime_type', 'text/plain');
    }

    public function test_show_returns_one_item_and_404s_for_unknown_ids(): void
    {
        $this->actAsUser();

        $id = $this->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('id');

        $this->getJson('/api/v1/media/'.$id)
            ->assertOk()
            ->assertJsonPath('id', $id);

        $this->getJson('/api/v1/media/999999')
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_post_invalid_id');
    }

    public function test_media_requires_authentication(): void
    {
        $this->getJson('/api/v1/media')->assertUnauthorized();
        $this->post('/api/v1/media', [], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_upload_accepts_alt_text(): void
    {
        $this->actAsUser();

        $response = $this->post('/api/v1/media', [
            'file' => $this->png('hero.png'),
            'alt_text' => 'A blue square',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('A blue square', $response->json('alt_text'));

        $this->getJson('/api/v1/media/'.$response->json('id'))
            ->assertOk()
            ->assertJsonPath('alt_text', 'A blue square');
    }

    public function test_alt_text_can_be_updated_and_cleared(): void
    {
        $this->actAsUser();

        $id = $this->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('alt_text', '')
            ->json('id');

        $this->patchJson('/api/v1/media/'.$id, ['alt_text' => 'Described'])
            ->assertOk()
            ->assertJsonPath('alt_text', 'Described');

        // An empty string is how a caller clears it.
        $this->patchJson('/api/v1/media/'.$id, ['alt_text' => ''])
            ->assertOk()
            ->assertJsonPath('alt_text', '');
    }

    public function test_the_update_route_requires_alt_text(): void
    {
        $this->actAsUser();

        $id = $this->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('id');

        $this->patchJson('/api/v1/media/'.$id, [])
            ->assertStatus(400)
            ->assertJsonPath('code', 'rest_invalid_param');
    }

    public function test_a_subscriber_cannot_edit_alt_text(): void
    {
        $this->actAsUser();

        $id = $this->post('/api/v1/media', ['file' => $this->png()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('id');

        Sanctum::actingAs(User::factory()->create(['role' => 'subscriber']));

        $this->patchJson('/api/v1/media/'.$id, ['alt_text' => 'Nope'])->assertForbidden();
    }
}

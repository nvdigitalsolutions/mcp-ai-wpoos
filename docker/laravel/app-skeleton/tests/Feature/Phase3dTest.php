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

        $this->assertStringStartsWith('/storage/media/', (string) $response->json('source_url'));
        $this->assertSame(1, $response->json('media_details.width'));
        $this->assertSame(1, $response->json('media_details.height'));
        $this->assertMatchesRegularExpression('/^pixel-[a-z0-9]{10}\.png$/', (string) $response->json('slug').'.png');
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
}

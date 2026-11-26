<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Tool\CreateImageVariationTool;
use Nvoos\Core\Tool\CreateVectorStoreTool;
use Nvoos\Core\Tool\DeleteFileTool;
use Nvoos\Core\Tool\EditGeminiImageTool;
use Nvoos\Core\Tool\EditOpenAIImageTool;
use Nvoos\Core\Tool\GenerateCloudflareAiImageTool;
use Nvoos\Core\Tool\GenerateGeminiImageTool;
use Nvoos\Core\Tool\GenerateHiggsfieldImageTool;
use Nvoos\Core\Tool\GenerateImageAltTextValidatedTool;
use Nvoos\Core\Tool\GenerateImageCaptionValidatedTool;
use Nvoos\Core\Tool\GenerateOpenAIImageTool;
use Nvoos\Core\Tool\GetOpenAIFileDetailsTool;
use Nvoos\Core\Tool\GetVectorStoreTool;
use Nvoos\Core\Tool\ListOpenAIFilesTool;
use Nvoos\Core\Tool\ListVectorStoresTool;
use Nvoos\Core\Tool\UploadFileTool;
use Tests\TestCase;

/**
 * Batch 1 evidence: image transform, image generation and file/vector-store
 * tools (Proposal 160 §6, §12.5).
 *
 * Two facts made these rows classifiable:
 *
 * 1. Every provider tool here goes through `HttpClientInterface`, and the
 *    Laravel adapter is built on the `Http` facade — so `Http::fake()` is the
 *    provider fake the plan asked for, with no network and no API key.
 * 2. The two prompt builders never touch the network at all, so they are
 *    `pure` rather than merely constructible.
 *
 * Assertions follow the MCP annotation vocabulary: a read tool must issue a
 * GET and never a write, a generator must reach the provider, and a
 * destructive tool must actually call through the store contract.
 */
class ImageAndFileToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // getApiKey() reads services.{provider}.key first, so the tools never
        // reach for a real credential.
        config(array(
            'services.openai.key' => 'sk-test',
            'services.gemini.key' => 'gemini-test',
            'services.cloudflare.key' => 'cf-test',
            'oos.cloudflare_account_id' => 'acct-1',
            'services.higgsfield.key' => 'key-id:key-secret',
        ));

        // Every generation tool now persists by default, so a probe would
        // otherwise write real objects into the media disk. Bind the recording
        // store once here; a test that inspects the writes takes a fresh one.
        $this->fakeVisibilityStore();
    }

    /**
     * @param class-string $class
     */
    private function execute(string $class, array $arguments = array(), array $context = array()): array
    {
        $result = $this->app->make($class)->execute($arguments, $context);

        $this->assertIsArray($result, $class . ' returned a non-array result: ' . print_r($result, true));

        return $result;
    }

    // ─── Pure: prompt builders ────────────────────────────────────────────

    public function test_the_alt_text_prompt_builder_needs_no_network(): void
    {
        Http::fake();

        $result = $this->execute(GenerateImageAltTextValidatedTool::class, array(
            'image_url' => 'https://example.com/a.jpg',
            'context' => 'product shot',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/a.jpg', $result['data']['image_url']);
        $this->assertStringContainsString('alt text', $result['data']['prompt']);
        $this->assertStringContainsString('product shot', $result['data']['prompt']);

        Http::assertNothingSent();
    }

    public function test_the_caption_prompt_builder_needs_no_network(): void
    {
        Http::fake();

        $result = $this->execute(GenerateImageCaptionValidatedTool::class, array(
            'image_url' => 'https://example.com/a.jpg',
            'max_length' => 80,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(80, $result['data']['max_length']);
        $this->assertStringContainsString('80', $result['data']['prompt']);

        Http::assertNothingSent();
    }

    // ─── OpenAI vector stores ─────────────────────────────────────────────

    public function test_list_vector_stores_reads_the_collection(): void
    {
        Http::fake(array(
            'api.openai.com/v1/vector_stores*' => Http::response(array(
                'data' => array(array('id' => 'vs_1', 'name' => 'Docs')),
                'has_more' => false,
            )),
        ));

        $result = $this->execute(ListVectorStoresTool::class, array('limit' => 5, 'order' => 'asc'));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('vs_1', $result['data']['items'][0]['id']);

        Http::assertSent(static function ($request): bool {
            return 'GET' === $request->method()
                && str_contains($request->url(), '/v1/vector_stores?')
                && str_contains($request->url(), 'limit=5')
                && str_contains($request->url(), 'order=asc')
                && $request->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    public function test_get_vector_store_reports_the_store(): void
    {
        Http::fake(array(
            'api.openai.com/v1/vector_stores/vs_1' => Http::response(array(
                'id' => 'vs_1',
                'name' => 'Docs',
                'status' => 'completed',
                'file_counts' => array('completed' => 2),
            )),
        ));

        $result = $this->execute(GetVectorStoreTool::class, array('vector_store_id' => 'vs_1'));

        $this->assertTrue($result['success']);
        $this->assertSame('vs_1', $result['data']['id']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame(array('completed' => 2), $result['data']['file_counts']);

        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && str_ends_with($request->url(), '/v1/vector_stores/vs_1'));
    }

    public function test_create_vector_store_returns_the_id(): void
    {
        Http::fake(array(
            'api.openai.com/v1/vector_stores' => Http::response(array(
                'id' => 'vs_new',
                'name' => 'Docs',
                'status' => 'completed',
            )),
        ));

        $result = $this->execute(CreateVectorStoreTool::class, array('name' => 'Docs'));

        $this->assertTrue($result['success']);
        $this->assertSame('vs_new', $result['data']['id']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/vector_stores'));
    }

    // ─── OpenAI files ─────────────────────────────────────────────────────

    public function test_list_openai_files_reads_the_collection(): void
    {
        Http::fake(array(
            'api.openai.com/v1/files' => Http::response(array(
                'data' => array(array('id' => 'file-1', 'filename' => 'a.txt')),
            )),
        ));

        $result = $this->execute(ListOpenAIFilesTool::class);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('file-1', $result['data']['items'][0]['id']);

        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && str_ends_with($request->url(), '/v1/files'));
    }

    public function test_get_openai_file_details_reports_the_file(): void
    {
        Http::fake(array(
            'api.openai.com/v1/files/file-1' => Http::response(array(
                'id' => 'file-1',
                'filename' => 'a.txt',
                'purpose' => 'assistants',
                'bytes' => 12,
                'status' => 'processed',
            )),
        ));

        $result = $this->execute(GetOpenAIFileDetailsTool::class, array('file_id' => 'file-1'));

        $this->assertTrue($result['success']);
        $this->assertSame('a.txt', $result['data']['filename']);
        $this->assertSame('processed', $result['data']['status']);

        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && str_ends_with($request->url(), '/v1/files/file-1'));
    }

    // ─── OpenAI image generation ─────────────────────────────────────────

    public function test_generate_openai_image_returns_the_urls(): void
    {
        Http::fake(array(
            'api.openai.com/v1/images/generations' => Http::response(array(
                'data' => array(array('url' => 'https://img.test/1.png', 'revised_prompt' => 'a cat')),
            )),
        ));

        $result = $this->execute(GenerateOpenAIImageTool::class, array('prompt' => 'a cat'));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['count']);
        $this->assertSame('https://img.test/1.png', $result['data']['images'][0]['url']);
        $this->assertSame('a cat', $result['data']['images'][0]['revised_prompt']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/images/generations'));
    }

    public function test_generate_openai_image_stores_the_bytes_through_the_registry(): void
    {
        $store = $this->fakeVisibilityStore();

        Http::fake(array(
            'api.openai.com/v1/images/generations' => Http::response(array(
                'data' => array(array('b64_json' => base64_encode('PNGDATA'), 'revised_prompt' => 'a cat')),
            )),
        ));

        $result = $this->execute(
            GenerateOpenAIImageTool::class,
            array('prompt' => 'a cat', 'file_name' => 'Pixel'),
            array('user_id' => 7, 'tenant_id' => 'estate-a')
        );

        $this->assertTrue($result['success']);

        // The store received the decoded bytes, the estate, the owner and the
        // visibility — not a URL and not the provider's claim about the type.
        $this->assertCount(1, $store->writes);
        $this->assertSame('PNGDATA', $store->writes[0]['bytes']);
        $this->assertSame('image/png', $store->writes[0]['mime']);
        $this->assertSame('public', $store->writes[0]['visibility']);
        $this->assertSame(7, $store->writes[0]['owner']);
        $this->assertSame('estate-a', $store->writes[0]['tenant']);
        $this->assertStringStartsWith('pixel-', $store->writes[0]['name']);

        $media = $result['data']['media'][0];
        $this->assertSame(101, $media['attachment_id']);
        $this->assertSame('/storage/default/public/x.png', $media['source_url']);
        $this->assertSame('public', $media['visibility']);
        $this->assertArrayNotHasKey('media_failed', $result['data']);

        // Keeping the bytes is the point, so the provider is asked for them even
        // though the caller did not name a format.
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/v1/images/generations')
            && 'b64_json' === $request['response_format']);
    }

    public function test_generate_openai_image_can_skip_storage(): void
    {
        $store = $this->fakeVisibilityStore();

        Http::fake(array(
            'api.openai.com/v1/images/generations' => Http::response(array(
                'data' => array(array('b64_json' => base64_encode('PNGDATA'))),
            )),
        ));

        $result = $this->execute(GenerateOpenAIImageTool::class, array(
            'prompt' => 'a cat',
            'save_to_media' => false,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $store->writes);
        $this->assertArrayNotHasKey('media', $result['data']);
    }

    public function test_a_storage_refusal_is_reported_without_losing_the_image(): void
    {
        $store = $this->fakeVisibilityStore();
        $store->refuse = 'File type is not accepted: image/png.';

        Http::fake(array(
            'api.openai.com/v1/images/generations' => Http::response(array(
                'data' => array(array('b64_json' => base64_encode('PNGDATA'))),
            )),
        ));

        $result = $this->execute(GenerateOpenAIImageTool::class, array('prompt' => 'a cat'));

        // The generation is the expensive part; a refused store must not turn it
        // into a failed call.
        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['media']);
        $this->assertSame('File type is not accepted: image/png.', $result['data']['media_failed'][0]);
        $this->assertSame(base64_encode('PNGDATA'), $result['data']['images'][0]['b64_json']);
    }

    public function test_create_image_variation_stores_the_bytes(): void
    {
        $store = $this->fakeVisibilityStore();

        Http::fake(array(
            'api.openai.com/v1/images/variations' => Http::response(array(
                'data' => array(array('b64_json' => base64_encode('VAR'))),
            )),
        ));

        $result = $this->execute(CreateImageVariationTool::class, array(
            'image_url' => 'https://example.com/a.png',
        ));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->writes);
        $this->assertSame('VAR', $store->writes[0]['bytes']);
        $this->assertStringStartsWith('openai-variation-', $store->writes[0]['name']);
        $this->assertSame(101, $result['data']['media'][0]['attachment_id']);
    }

    public function test_edit_openai_image_stores_the_bytes(): void
    {
        $store = $this->fakeVisibilityStore();

        Http::fake(array(
            'api.openai.com/v1/images/edits' => Http::response(array(
                'data' => array(array('b64_json' => base64_encode('EDIT'))),
            )),
        ));

        $result = $this->execute(EditOpenAIImageTool::class, array(
            'image_url' => 'https://example.com/a.png',
            'prompt' => 'make it blue',
        ));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->writes);
        $this->assertSame('EDIT', $store->writes[0]['bytes']);
        $this->assertStringStartsWith('openai-edited-image-', $store->writes[0]['name']);
        $this->assertSame(101, $result['data']['media'][0]['attachment_id']);
    }

    public function test_generate_gemini_image_stores_the_bytes(): void
    {
        $store = $this->fakeVisibilityStore();

        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array(
                'predictions' => array(
                    array('bytesBase64Encoded' => base64_encode('GEM')),
                ),
            )),
        ));

        $result = $this->execute(GenerateGeminiImageTool::class, array('prompt' => 'a dog'));

        $this->assertTrue($result['success']);

        // Imagen's `bytesBase64Encoded` is understood by the persister, so the
        // tool does not have to translate the dialect itself.
        $this->assertCount(1, $store->writes);
        $this->assertSame('GEM', $store->writes[0]['bytes']);
        $this->assertStringStartsWith('gemini-image-', $store->writes[0]['name']);
        $this->assertSame(101, $result['data']['media'][0]['attachment_id']);
    }

    public function test_edit_gemini_image_stores_the_bytes(): void
    {
        $store = $this->fakeVisibilityStore();

        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array(
                'predictions' => array(
                    array('bytesBase64Encoded' => base64_encode('GEDIT')),
                ),
            )),
        ));

        $result = $this->execute(EditGeminiImageTool::class, array(
            'image_url' => 'https://example.com/a.png',
            'prompt' => 'make it blue',
        ));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->writes);
        $this->assertSame('GEDIT', $store->writes[0]['bytes']);
        $this->assertStringStartsWith('gemini-edited-image-', $store->writes[0]['name']);
    }

    public function test_generate_cloudflare_image_stores_the_bytes(): void
    {
        $store = $this->fakeVisibilityStore();

        Http::fake(array(
            'api.cloudflare.com/*' => Http::response(array(
                'result' => array('image' => base64_encode('CF')),
            )),
        ));

        $result = $this->execute(GenerateCloudflareAiImageTool::class, array(
            'prompt' => 'a lighthouse',
        ));

        $this->assertTrue($result['success']);

        // Workers AI answers with one base64 string; the tool wraps it so the
        // persister sees the same shape as every other provider.
        $this->assertCount(1, $store->writes);
        $this->assertSame('CF', $store->writes[0]['bytes']);
        $this->assertStringStartsWith('cloudflare-image-', $store->writes[0]['name']);
        $this->assertStringStartsWith('/storage/', (string) $result['data']['media'][0]['source_url']);
    }

    public function test_create_image_variation_returns_images(): void
    {
        Http::fake(array(
            'api.openai.com/v1/images/variations' => Http::response(array(
                'data' => array(array('b64_json' => 'AAA')),
            )),
        ));

        $result = $this->execute(CreateImageVariationTool::class, array(
            'image_url' => 'https://example.com/a.png',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['count']);
        $this->assertSame('AAA', $result['data']['images'][0]['b64_json']);
    }

    public function test_edit_openai_image_returns_images(): void
    {
        Http::fake(array(
            'api.openai.com/v1/images/edits' => Http::response(array(
                'data' => array(array('b64_json' => 'BBB')),
            )),
        ));

        $result = $this->execute(EditOpenAIImageTool::class, array(
            'image_url' => 'https://example.com/a.png',
            'prompt' => 'make it blue',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('make it blue', $result['data']['prompt']);
        $this->assertSame('BBB', $result['data']['images'][0]['b64_json']);
    }

    // ─── Gemini image generation ─────────────────────────────────────────

    public function test_generate_gemini_image_returns_predictions(): void
    {
        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array(
                'predictions' => array(array('bytesBase64Encoded' => 'GGG')),
            )),
        ));

        $result = $this->execute(GenerateGeminiImageTool::class, array('prompt' => 'a dog'));

        $this->assertTrue($result['success']);
        $this->assertSame('GGG', $result['data']['images'][0]['bytesBase64Encoded']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_contains($request->url(), 'generativelanguage.googleapis.com')
            && str_contains($request->url(), 'key=gemini-test'));
    }

    public function test_edit_gemini_image_returns_predictions(): void
    {
        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array(
                'predictions' => array(array('bytesBase64Encoded' => 'HHH')),
            )),
        ));

        $result = $this->execute(EditGeminiImageTool::class, array(
            'image_url' => 'https://example.com/a.png',
            'prompt' => 'add a hat',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('HHH', $result['data']['images'][0]['bytesBase64Encoded']);
    }

    // ─── Cloudflare image generation ─────────────────────────────────────

    public function test_generate_cloudflare_image_returns_base64(): void
    {
        Http::fake(array(
            'api.cloudflare.com/*' => Http::response(array(
                'result' => array('image' => 'CCC'),
            )),
        ));

        $result = $this->execute(GenerateCloudflareAiImageTool::class, array(
            'prompt' => 'a lighthouse',
            'width' => 512,
            'height' => 512,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('CCC', $result['data']['image_base64']);
        $this->assertSame(512, $result['data']['width']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_contains($request->url(), '/accounts/acct-1/ai/run/')
            && $request->hasHeader('Authorization', 'Bearer cf-test'));
    }

    public function test_generate_higgsfield_image_returns_the_request_id(): void
    {
        Http::fake(array(
            'api.higgsfield.ai/*' => Http::response(array(
                'request_id' => 'req-9',
                'status_url' => 'https://api.higgsfield.ai/status/req-9',
            )),
        ));

        $result = $this->execute(GenerateHiggsfieldImageTool::class, array('prompt' => 'a fox'));

        $this->assertTrue($result['success']);
        $this->assertSame('req-9', $result['data']['request_id']);
        $this->assertSame('https://api.higgsfield.ai/status/req-9', $result['data']['status_url']);

        // The credential is sent as "Key id:secret", not as a bearer token.
        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_starts_with($request->url(), 'https://api.higgsfield.ai')
            && $request->hasHeader('Authorization', 'Key key-id:key-secret'));
    }

    /**
     * A visibility store that records what it was handed.
     *
     * The tool's job is to hand decoded bytes to the registry; the registry's
     * own behaviour is covered by `FileStoreVisibilityTest`. This fake separates
     * the two so the probe does not need a database.
     */
    private function fakeVisibilityStore(): object
    {
        $fake = new class implements FileVisibilityStoreInterface {
            /** @var array<int, array<string, mixed>> */
            public array $writes = array();

            public ?string $refuse = null;

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                $bytes = (string) file_get_contents($localPath);

                if (null !== $this->refuse) {
                    throw new ValidationException($this->refuse);
                }

                $this->writes[] = array(
                    'name' => $filename,
                    'mime' => $mimeType,
                    'bytes' => $bytes,
                    'owner' => $userId,
                    'visibility' => $visibility,
                    'tenant' => $tenantId,
                );

                return new StoredFile(
                    id: 100 + count($this->writes),
                    filename: $filename . '.png',
                    mimeType: 'image/png',
                    sizeBytes: strlen($bytes),
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                    publicUrl: 'public' === $visibility ? '/storage/default/public/x.png' : null,
                    visibility: $visibility,
                    slug: $filename,
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return in_array($visibility, array('public', 'private'), true);
            }
        };

        $this->app->instance(FileVisibilityStoreInterface::class, $fake);

        return $fake;
    }

    // ─── File store ──────────────────────────────────────────────────────

    private function fakeFileStore(): object
    {
        $fake = new class implements FileStoreInterface {
            /** @var array<int, StoredFile> */
            public array $stored = array();

            /** @var array<int, int> */
            public array $deleted = array();

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                $file = new StoredFile(
                    id: 42,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 11,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                );

                $this->stored[] = $file;

                return $file;
            }

            public function getPath(int $fileId): ?string
            {
                return '/tmp/fake';
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return new StoredFile(
                    id: $fileId,
                    filename: 'gone.txt',
                    mimeType: 'text/plain',
                    sizeBytes: 4,
                    localPath: '/tmp/fake',
                    ownerId: 7,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                );
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return true;
            }

            public function delete(int $fileId): void
            {
                $this->deleted[] = $fileId;
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return array();
            }
        };

        $this->app->instance(FileStoreInterface::class, $fake);

        return $fake;
    }

    public function test_upload_file_stores_through_the_contract(): void
    {
        $store = $this->fakeFileStore();

        $result = $this->execute(UploadFileTool::class, array(
            'base64' => base64_encode('hello world'),
            'filename' => 'hello.txt',
            'mime_type' => 'text/plain',
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['data']['id']);
        $this->assertSame('hello.txt', $result['data']['filename']);
        $this->assertSame(7, $result['data']['owner_id']);
        $this->assertCount(1, $store->stored);
    }

    public function test_delete_file_removes_through_the_contract(): void
    {
        $store = $this->fakeFileStore();

        $result = $this->execute(DeleteFileTool::class, array('file_id' => 9));

        $this->assertTrue($result['success']);
        $this->assertSame(9, $result['data']['file_id']);
        $this->assertSame('gone.txt', $result['data']['filename']);
        $this->assertSame(array(9), $store->deleted);
    }
}

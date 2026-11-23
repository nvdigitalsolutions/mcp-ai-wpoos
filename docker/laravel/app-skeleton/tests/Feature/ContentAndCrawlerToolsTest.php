<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Application\Media\FeaturedImage;
use Nvoos\Core\Tool\CreatePostTool;
use Nvoos\Core\Tool\CreatePostValidatedTool;
use Nvoos\Core\Tool\GetRecentPostsValidatedTool;
use Nvoos\Core\Tool\RunCrawl4AiJobTool;
use Nvoos\Core\Tool\SavePostTool;
use Nvoos\Core\Tool\SavePostValidatedTool;
use Nvoos\Core\Tool\SearchContentValidatedTool;
use Tests\TestCase;

/**
 * Batch 4 evidence, part five: the content wrappers and the crawler job.
 *
 * The content family writes through `ContentStoreInterface`, which this runtime
 * binds to an Eloquent-backed store. The test uses it rather than a fake so the
 * row proves the whole path — wrapper, store, model — and not just that the
 * wrapper delegates.
 */
class ContentAndCrawlerToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param class-string $class
     */
    private function execute(string $class, array $arguments = array(), array $context = array()): mixed
    {
        return $this->app->make($class)->execute($arguments, $context);
    }

    public function test_create_post_validated_creates_a_post(): void
    {
        $result = $this->execute(CreatePostValidatedTool::class, array(
            'title' => 'Batch four',
            'content' => 'Body text',
            'post_type' => 'post',
        ), array('user_id' => 7));

        $this->assertIsArray($result, (string) print_r($result, true));
        $this->assertTrue($result['success']);
    }

    public function test_get_recent_posts_validated_returns_a_list(): void
    {
        $result = $this->execute(GetRecentPostsValidatedTool::class, array(), array('user_id' => 7));

        $this->assertIsArray($result, (string) print_r($result, true));
        $this->assertTrue($result['success']);
    }

    public function test_save_post_links_a_stored_image_as_the_featured_image(): void
    {
        $fileId = $this->storedFile('image/png');

        $result = $this->execute(SavePostTool::class, array(
            'title' => 'With a thumbnail',
            'content' => 'Body text',
            'featured_image_id' => $fileId,
        ), array('user_id' => 7));

        $this->assertIsArray($result, 'save_post did not return an array envelope.');
        $this->assertTrue($result['success']);

        // The link lives in the item's own meta, exactly where WordPress keeps
        // `_thumbnail_id`, so `get_post` and a meta query can both read it.
        $this->assertSame($fileId, $result['data']['meta']['featured_image_id']);
    }

    public function test_save_post_refuses_a_non_image_as_the_featured_image(): void
    {
        $fileId = $this->storedFile('text/plain');

        // This adapter reports a tool error as a RuntimeException *value* whose
        // code is the HTTP status, so the refusal is asserted, not thrown.
        $result = $this->execute(SavePostTool::class, array(
            'title' => 'Bad thumbnail',
            'content' => 'Body text',
            'featured_image_id' => $fileId,
        ), array('user_id' => 7));

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('Only an image', $result->getMessage());
    }

    public function test_the_featured_image_resolver_separates_clear_from_untouched(): void
    {
        // Absent means "leave the current image alone"; 0 means "clear it".
        // Conflating them would make an unrelated post update drop the image.
        $this->assertNull(FeaturedImage::metaFor(array('content' => 'x'), null));
        $this->assertSame(
            array('featured_image_id' => null),
            FeaturedImage::metaFor(array('featured_image_id' => 0), null),
        );

        $this->expectException(\Nvoos\Core\Domain\Error\ValidationException::class);
        FeaturedImage::metaFor(array('featured_image_id' => -1), null);
    }

    public function test_the_featured_image_resolver_refuses_an_unknown_file(): void
    {
        $this->expectException(\Nvoos\Core\Domain\Error\NotFoundException::class);

        FeaturedImage::metaFor(
            array('featured_image_id' => 999999),
            $this->app->make(\Nvoos\Core\Domain\Contract\FileStoreInterface::class),
        );
    }

    /**
     * A real registry row, so the tool resolves through the store it ships with.
     *
     * `getMetadata()` reads the row only, so no disk is involved and the test
     * asserts the integration rather than a fake's opinion.
     */
    private function storedFile(string $mimeType): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('nvoos_files')->insertGetId(array(
            'path' => 'default/public/2026/11/thumb.png',
            'disk' => 'public',
            'filename' => 'thumb.png',
            'mime_type' => $mimeType,
            'size_bytes' => 12,
            'owner_id' => 7,
            'created_at' => '2026-11-23 12:00:00',
            'updated_at' => '2026-11-23 12:00:00',
        ));
    }

    public function test_create_post_embeds_a_stored_public_image(): void
    {
        $fileId = $this->storedImage('image/png');

        $result = $this->execute(CreatePostTool::class, array(
            'title' => 'With a figure',
            'content' => "First paragraph.\n\nSecond paragraph.",
            'content_images' => array(
                array(
                    'source' => $fileId,
                    'alt' => 'A & B',
                    'caption' => '<b>Caption</b>',
                    'position' => 'end',
                ),
            ),
        ), array('user_id' => 7));

        $this->assertIsArray($result, 'create_post did not return an array envelope.');
        $this->assertTrue($result['success']);

        $content = (string) $result['data']['content'];

        // The `wp-image-{id}` class is how the block is tied to the registry.
        $this->assertStringContainsString('class="wp-image-' . $fileId . '"', $content);
        $this->assertStringContainsString('<figure class="wp-block-image">', $content);

        // Intrinsic size, lazy loading and async decoding are emitted here
        // because this runtime has no render-time content filter to add them.
        $this->assertStringContainsString('width="1" height="1"', $content);
        $this->assertStringContainsString('loading="lazy"', $content);
        $this->assertStringContainsString('decoding="async"', $content);

        // Both values are escaped, and the caption loses its markup.
        $this->assertStringContainsString('alt="A &amp; B"', $content);
        $this->assertStringContainsString('<figcaption>Caption</figcaption>', $content);
    }

    public function test_create_post_refuses_a_private_image_in_the_body(): void
    {
        $fileId = $this->storedImage('image/png', 'private');

        $result = $this->execute(CreatePostTool::class, array(
            'title' => 'Private figure',
            'content' => 'Body text',
            'content_images' => array(array('source' => $fileId)),
        ), array('user_id' => 7));

        // A private file has no public URL, so embedding it would ship an <img>
        // that 401s for every anonymous reader.
        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('private', $result->getMessage());
    }

    public function test_create_post_refuses_a_non_http_image_url(): void
    {
        $result = $this->execute(CreatePostTool::class, array(
            'title' => 'Bad scheme',
            'content' => 'Body text',
            'content_images' => array(array('source' => 'javascript:alert(1)')),
        ), array('user_id' => 7));

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('http', $result->getMessage());
    }

    /**
     * A real registry row backed by real bytes, so `getimagesize()` can report
     * the intrinsic size the way it would for an uploaded file.
     */
    private function storedImage(string $mimeType, string $visibility = 'public'): int
    {
        Storage::fake('public');

        $path = 'default/public/2026/11/thumb.png';

        Storage::disk('public')->put(
            $path,
            (string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true,
            ),
        );

        return (int) DB::table('nvoos_files')->insertGetId(array(
            'path' => $path,
            'disk' => 'public',
            'filename' => 'thumb.png',
            'mime_type' => $mimeType,
            'size_bytes' => 68,
            'owner_id' => 7,
            'visibility' => $visibility,
            'created_at' => '2026-11-23 12:00:00',
            'updated_at' => '2026-11-23 12:00:00',
        ));
    }

    public function test_save_post_validated_updates_a_post(): void
    {
        $result = $this->execute(SavePostValidatedTool::class, array(
            'title' => 'Batch four revised',
            'content' => 'Updated body',
            'post_type' => 'post',
        ), array('user_id' => 7));

        $this->assertIsArray($result, (string) print_r($result, true));
        $this->assertTrue($result['success']);
    }

    public function test_search_content_validated_searches_a_taxonomy(): void
    {
        $result = $this->execute(SearchContentValidatedTool::class, array(
            'search_term' => 'news',
            'post_type' => 'post',
        ), array('user_id' => 7));

        $this->assertIsArray($result, (string) print_r($result, true));
        $this->assertTrue($result['success']);
    }

    public function test_run_crawl4ai_job_submits_a_crawl(): void
    {
        Http::fake(array(
            '*' => Http::response(array('task_id' => 'task-1', 'status' => 'queued')),
        ));

        $result = $this->execute(RunCrawl4AiJobTool::class, array(
            'url' => 'https://example.test/page',
        ));

        $this->assertIsArray($result, (string) print_r($result, true));
        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'crawl'));
    }
}

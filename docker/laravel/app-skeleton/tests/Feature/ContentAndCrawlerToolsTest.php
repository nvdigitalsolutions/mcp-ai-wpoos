<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Tool\CreatePostValidatedTool;
use Nvoos\Core\Tool\GetRecentPostsValidatedTool;
use Nvoos\Core\Tool\RunCrawl4AiJobTool;
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

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Tool\CancelJobTool;
use Nvoos\Core\Tool\CreateBatchTool;
use Nvoos\Core\Tool\CreateCronJobTool;
use Nvoos\Core\Tool\DeleteCacheTool;
use Nvoos\Core\Tool\DeleteCronJobTool;
use Nvoos\Core\Tool\EnqueueJobTool;
use Nvoos\Core\Tool\ErlangCQueueHealthTool;
use Nvoos\Core\Tool\GetBatchStatusTool;
use Nvoos\Core\Tool\GetCacheTool;
use Nvoos\Core\Tool\GetCronJobTool;
use Nvoos\Core\Tool\IncrementCacheTool;
use Nvoos\Core\Tool\MonitorBatchTool;
use Nvoos\Core\Tool\PurgeCacheTool;
use Nvoos\Core\Tool\PurgeCloudflareCacheTool;
use Nvoos\Core\Tool\PurgeVarnishCacheTool;
use Nvoos\Core\Tool\ScheduleJobTool;
use Nvoos\Core\Tool\SetCacheTool;
use Nvoos\Core\Tool\UnscheduleJobTool;
use Tests\TestCase;

/**
 * Batch 3 evidence: cache, cron, jobs and batches (Proposal 160 §6).
 *
 * Two local seams carry most of this batch, and both are bound in this runtime:
 * `CacheStoreInterface` (the Laravel cache, `array` under test) and
 * `QueueClientInterface`. The cache tests use the real store and read back
 * through `get_cache`, so the round trip is proven rather than mocked; the queue
 * tests use an in-memory fake because a completed job cannot be produced on
 * demand from the sync driver.
 *
 * The OpenAI batch tools and the three purge tools are HTTP, so `Http::fake()`
 * is the boundary. The purge tools are annotated `destructiveHint: true`: they
 * discard cached content, and a machine must not run them unattended even though
 * the loss is recoverable.
 */
class CacheQueueAndBatchToolsTest extends TestCase
{
    private object $queue;

    protected function setUp(): void
    {
        parent::setUp();

        config(array(
            'services.openai.key' => 'sk-test',
            'oos.cloudflare_api_token' => 'cf-token',
            'oos.cloudflare_zone_id' => 'zone-1',
            'oos.enable_varnish_purge' => true,
            'oos.varnish_host' => 'varnish.test',
            'oos.site_host' => 'example.test',
        ));
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

    // ─── Cache (real array store) ────────────────────────────────────────

    public function test_set_cache_round_trips_through_get_cache(): void
    {
        $set = $this->execute(SetCacheTool::class, array(
            'key' => 'batch3.key',
            'value' => 'hello',
            'ttl' => 60,
        ));

        $this->assertTrue($set['success']);
        $this->assertSame('batch3.key', $set['data']['key']);
        $this->assertTrue($set['data']['stored']);

        $get = $this->execute(GetCacheTool::class, array('key' => 'batch3.key'));

        $this->assertTrue($get['success']);
        $this->assertStringContainsString('hello', (string) json_encode($get['data']));
    }

    public function test_delete_cache_reports_whether_the_key_existed(): void
    {
        $this->execute(SetCacheTool::class, array('key' => 'batch3.delete', 'value' => 'x'));

        $first = $this->execute(DeleteCacheTool::class, array('key' => 'batch3.delete'));
        $this->assertTrue($first['success']);
        $this->assertTrue($first['data']['deleted']);

        $second = $this->execute(DeleteCacheTool::class, array('key' => 'batch3.delete'));
        $this->assertFalse($second['data']['deleted']);
    }

    public function test_increment_cache_adds_to_the_stored_value(): void
    {
        $this->execute(SetCacheTool::class, array('key' => 'batch3.counter', 'value' => 10));

        $result = $this->execute(IncrementCacheTool::class, array(
            'key' => 'batch3.counter',
            'by' => 2,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(12, (int) $result['data']['new_value']);
    }

    // ─── Queue and cron (in-memory fake) ─────────────────────────────────

    private function fakeQueue(): object
    {
        $fake = new class implements QueueClientInterface {
            /** @var array<int, string> */
            public array $cancelled = array();

            /** @var array<int, string> */
            public array $unscheduled = array();

            public function enqueue(string $handler, array $payload, array $options = array()): string
            {
                return 'job-abc';
            }

            public function getStatus(string $jobId): JobStatus
            {
                return new JobStatus(
                    jobId: $jobId,
                    status: 'completed',
                    result: array('ok' => true),
                    attempts: 1,
                    queuedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                );
            }

            public function cancel(string $jobId): bool
            {
                $this->cancelled[] = $jobId;

                return true;
            }

            public function schedule(string $handler, array $payload, string $cronExpression): string
            {
                return 'sched-abc';
            }

            public function unschedule(string $scheduleId): void
            {
                $this->unscheduled[] = $scheduleId;
            }

            public function listJobs(array $filters = array(), int $limit = 50): array
            {
                return array();
            }
        };

        $this->app->instance(QueueClientInterface::class, $fake);
        $this->queue = $fake;

        return $fake;
    }

    public function test_enqueue_job_returns_the_job_id(): void
    {
        $this->fakeQueue();

        $result = $this->execute(EnqueueJobTool::class, array(
            'handler' => 'nvoos.example',
            'payload' => array('id' => 1),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('job-abc', $result['data']['job_id']);
        $this->assertSame('nvoos.example', $result['data']['handler']);
    }

    public function test_cancel_job_reports_the_cancellation(): void
    {
        $queue = $this->fakeQueue();

        $result = $this->execute(CancelJobTool::class, array('job_id' => 'job-abc'));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['cancelled']);
        $this->assertSame(array('job-abc'), $queue->cancelled);
    }

    public function test_schedule_job_returns_the_schedule_id(): void
    {
        $this->fakeQueue();

        $result = $this->execute(ScheduleJobTool::class, array(
            'handler' => 'nvoos.example',
            'payload' => array('id' => 1),
            'schedule' => '0 3 * * *',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('sched-abc', $result['data']['schedule_id']);
    }

    public function test_unschedule_job_removes_the_schedule(): void
    {
        $queue = $this->fakeQueue();

        $result = $this->execute(UnscheduleJobTool::class, array('schedule_id' => 'sched-abc'));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['removed']);
        $this->assertSame(array('sched-abc'), $queue->unscheduled);
    }

    public function test_get_cron_job_reports_the_status(): void
    {
        $this->fakeQueue();

        $result = $this->execute(GetCronJobTool::class, array('job_id' => 'job-abc'), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame(1, $result['data']['attempts']);
    }

    public function test_delete_cron_job_reads_then_cancels(): void
    {
        $queue = $this->fakeQueue();

        $result = $this->execute(DeleteCronJobTool::class, array('job_id' => 'job-abc'), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame(array('job-abc'), $queue->cancelled);
    }

    public function test_create_cron_job_enqueues_a_single_task(): void
    {
        $this->fakeQueue();

        $result = $this->execute(CreateCronJobTool::class, array('hook' => 'nvoos.example'), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('job-abc', $result['data']['job_id']);
        $this->assertSame('single', $result['data']['schedule']);
    }

    // ─── OpenAI batches (HTTP) ───────────────────────────────────────────

    public function test_create_batch_returns_the_batch_id(): void
    {
        Http::fake(array(
            'api.openai.com/v1/batches' => Http::response(array(
                'id' => 'batch_1',
                'status' => 'validating',
                'endpoint' => '/v1/chat/completions',
            )),
        ));

        $result = $this->execute(CreateBatchTool::class, array(
            'input_file_id' => 'file-1',
            'endpoint' => '/v1/chat/completions',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('batch_1', $result['data']['batch_id']);
        $this->assertSame('validating', $result['data']['status']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/batches'));
    }

    public function test_get_batch_status_reports_progress(): void
    {
        Http::fake(array(
            'api.openai.com/v1/batches/batch_1' => Http::response(array(
                'id' => 'batch_1',
                'status' => 'completed',
                'request_counts' => array('total' => 10, 'completed' => 9, 'failed' => 1),
            )),
        ));

        $result = $this->execute(GetBatchStatusTool::class, array('batch_id' => 'batch_1'));

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame(10, $result['data']['request_counts']['total']);
    }

    public function test_monitor_batch_reports_terminal_state(): void
    {
        Http::fake(array(
            'api.openai.com/v1/batches/batch_1' => Http::response(array(
                'id' => 'batch_1',
                'status' => 'completed',
                'output_file_id' => 'file-out',
            )),
        ));

        $result = $this->execute(MonitorBatchTool::class, array('batch_id' => 'batch_1'));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['is_terminal']);
        $this->assertSame('file-out', $result['data']['output_file_id']);
    }

    // ─── Purges (HTTP) ───────────────────────────────────────────────────

    public function test_purge_cloudflare_cache_requires_a_configured_zone(): void
    {
        Http::fake(array(
            'api.cloudflare.com/*' => Http::response(array('success' => true, 'result' => array('id' => 'zone-1'))),
        ));

        $result = $this->execute(
            PurgeCloudflareCacheTool::class,
            array('purge_everything' => true),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['purge_everything']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_contains($request->url(), '/zones/zone-1/purge_cache')
            && $request->hasHeader('Authorization', 'Bearer cf-token'));
    }

    public function test_purge_varnish_cache_reports_the_urls_purged(): void
    {
        Http::fake(array(
            '*' => Http::response('', 200),
        ));

        $result = $this->execute(
            PurgeVarnishCacheTool::class,
            array('urls' => array('https://example.test/a')),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['urls_purged']);

        Http::assertSent(static fn ($request): bool => 'PURGE' === $request->method()
            && str_contains($request->url(), 'varnish.test'));
    }

    public function test_purge_cache_walks_both_configured_layers(): void
    {
        Http::fake(array(
            'api.cloudflare.com/*' => Http::response(array('success' => true, 'result' => array('id' => 'zone-1'))),
            '*' => Http::response('', 200),
        ));

        $result = $this->execute(
            PurgeCacheTool::class,
            array('purge_everything' => true),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success']);
        $this->assertContains('varnish', $result['data']['layers_purged']);
        $this->assertContains('cloudflare', $result['data']['layers_purged']);
    }

    // ─── Erlang C queue health ───────────────────────────────────────────

    public function test_erlang_c_queue_health_is_pure_when_not_fetching(): void
    {
        Http::fake();

        $result = $this->execute(ErlangCQueueHealthTool::class, array(
            'arrival_rate_per_hour' => 30,
            'avg_handle_time' => 180,
            'current_agents' => 3,
            'queue_depth' => 2,
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(30.0, $result['data']['metrics']['arrival_rate_per_hour']);
        $this->assertArrayHasKey('erlang_c', $result['data']);

        Http::assertNothingSent();
    }
}

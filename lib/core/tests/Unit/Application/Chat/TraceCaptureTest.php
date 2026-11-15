<?php

/**
 * Tests for trace capture.
 *
 * The load-bearing properties: capture is off unless the profile asks for it,
 * the retention window comes from the profile and is clamped, a run is closed
 * even when it pruned, and no record carries message text.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Chat;

use Nvoos\Core\Application\Chat\TraceCapture;
use Nvoos\Core\Domain\Contract\TraceStoreInterface;
use Nvoos\Core\Domain\Service\Harness\HarnessProfile;
use PHPUnit\Framework\TestCase;

final class TraceCaptureTest extends TestCase
{
    /**
     * In-memory store that records what it was asked to write.
     */
    private function store(): TraceStoreInterface
    {
        return new class () implements TraceStoreInterface {
            /** @var array<int, array{assistant_id: int, run_id: string, artifact: string, record: array}> */
            public array $appends = array();

            /** @var array<int, array{assistant_id: int, keep: int}> */
            public array $prunes = array();

            public int $prunedRuns = 0;

            public function append(int $assistantId, string $runId, string $artifact, array $record): bool
            {
                $this->appends[] = array(
                    'assistant_id' => $assistantId,
                    'run_id' => $runId,
                    'artifact' => $artifact,
                    'record' => $record,
                );

                return true;
            }

            public function prune(int $assistantId, int $keepRuns): int
            {
                $this->prunes[] = array( 'assistant_id' => $assistantId, 'keep' => $keepRuns );

                return $this->prunedRuns;
            }

            /** @return array<int, array<string, mixed>> */
            public function records(): array
            {
                return array_map(static fn (array $row): array => $row['record'], $this->appends);
            }
        };
    }

    private function enabledProfile(int $retention = 50): array
    {
        return array(
            'enabled' => true,
            'trace_capture' => array( 'enabled' => true, 'retention_runs' => $retention ),
        );
    }

    public function test_capture_is_off_when_the_profile_does_not_ask_for_it(): void
    {
        $store = $this->store();

        $this->assertNull(TraceCapture::start($store, 7, array( 'enabled' => true )));
        $this->assertNull(TraceCapture::start($store, 7, array( 'enabled' => true, 'trace_capture' => array( 'enabled' => false ) )));
        $this->assertNull(TraceCapture::start($store, 7, array()));
        $this->assertSame(array(), $store->appends);
    }

    public function test_provider_calls_are_numbered_by_the_capture(): void
    {
        $store = $this->store();
        $capture = TraceCapture::start($store, 7, $this->enabledProfile());

        $capture->providerCall('stop');
        $capture->providerCall('stop');

        $this->assertSame(2, $capture->providerCalls());
        $this->assertSame(1, $store->records()[1]['iteration']);
        $this->assertSame(2, $store->records()[2]['iteration']);
    }

    public function test_capture_needs_an_assistant(): void
    {
        $store = $this->store();

        $this->assertNull(TraceCapture::start($store, 0, $this->enabledProfile()));
        $this->assertNull(TraceCapture::start($store, -3, $this->enabledProfile()));
    }

    public function test_a_run_writes_a_start_record_first(): void
    {
        $store = $this->store();
        $capture = TraceCapture::start($store, 7, $this->enabledProfile(), array( 'model' => 'gpt-4o', 'provider' => 'openai' ));

        $this->assertInstanceOf(TraceCapture::class, $capture);
        $this->assertSame(1, $capture->records());

        $start = $store->records()[0];

        $this->assertSame(TraceCapture::TYPE_START, $start['type']);
        $this->assertSame('gpt-4o', $start['model']);
        $this->assertSame('openai', $start['provider']);
        $this->assertSame(7, $start['assistant_id']);
        $this->assertSame($capture->runId(), $start['run_id']);
    }

    public function test_every_record_carries_the_run_identity(): void
    {
        $store = $this->store();
        $capture = TraceCapture::start($store, 7, $this->enabledProfile());
        $capture->providerCall('tool_calls');

        foreach ($store->appends as $row) {
            $this->assertSame(TraceCapture::ARTIFACT, $row['artifact']);
            $this->assertSame(7, $row['assistant_id']);
            $this->assertSame($capture->runId(), $row['run_id']);
            $this->assertSame($capture->runId(), $row['record']['run_id']);
            $this->assertArrayHasKey('at', $row['record']);
        }
    }

    public function test_iterations_record_the_finish_reason(): void
    {
        $store = $this->store();
        $capture = TraceCapture::start($store, 7, $this->enabledProfile());

        $capture->providerCall('tool_calls', 2);
        $capture->providerCall('stop');

        $records = $store->records();

        $this->assertSame('tool_calls', $records[1]['finish_reason']);
        $this->assertSame(2, $records[1]['tool_calls']);
        $this->assertSame(2, $records[2]['iteration']);
        $this->assertSame('stop', $records[2]['finish_reason']);
    }

    public function test_finish_writes_the_summary_and_prunes(): void
    {
        $store = $this->store();
        $store->prunedRuns = 3;

        $capture = TraceCapture::start($store, 7, $this->enabledProfile(25));
        $removed = $capture->finish(array( 'iterations' => 2, 'cancelled' => false, 'duration_ms' => 1200 ));

        $this->assertSame(3, $removed, 'The prune count is returned.');

        $end = $store->records()[count($store->records()) - 1];

        $this->assertSame(TraceCapture::TYPE_END, $end['type']);
        $this->assertSame(2, $end['iterations']);
        $this->assertSame(1200, $end['duration_ms']);
        $this->assertArrayHasKey('finished_at', $end);

        $this->assertSame(array( array( 'assistant_id' => 7, 'keep' => 25 ) ), $store->prunes);
    }

    /**
     * The storer clamps, but the capture must hand it the profile's window so
     * the two agree on what "50 runs" means.
     */
    public function test_the_retention_window_comes_from_the_profile(): void
    {
        $store = $this->store();
        $capture = TraceCapture::start($store, 7, $this->enabledProfile(120));

        $capture->finish();

        $this->assertSame(120, $store->prunes[0]['keep']);
    }

    public function test_an_out_of_range_window_is_clamped_before_it_is_stored(): void
    {
        $store = $this->store();
        $capture = TraceCapture::start($store, 7, $this->enabledProfile(100000));

        $capture->finish();

        $this->assertSame(HarnessProfile::MAX_RETENTION_RUNS, $store->prunes[0]['keep']);
    }

    public function test_run_ids_are_unique_and_sortable(): void
    {
        $store = $this->store();

        $first = TraceCapture::start($store, 7, $this->enabledProfile());
        $second = TraceCapture::start($store, 7, $this->enabledProfile());

        $this->assertNotSame($first->runId(), $second->runId());
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}-[0-9a-f]{8}$/', $first->runId());
    }

    /**
     * Records are for auditing what happened, not for reproducing it. Text
     * belongs in the transcript, under its own retention rules.
     */
    public function test_records_carry_no_message_text(): void
    {
        $store = $this->store();
        $capture = TraceCapture::start($store, 7, $this->enabledProfile(), array( 'model' => 'gpt-4o' ));
        $capture->providerCall('stop');
        $capture->finish(array( 'iterations' => 1 ));

        $serialised = json_encode($store->records());

        $this->assertIsString($serialised);
        $this->assertStringNotContainsString('content', $serialised);
        $this->assertStringNotContainsString('prompt', $serialised);
    }

    public function test_a_refused_append_is_not_counted(): void
    {
        $store = new class () implements TraceStoreInterface {
            public function append(int $assistantId, string $runId, string $artifact, array $record): bool
            {
                return false;
            }

            public function prune(int $assistantId, int $keepRuns): int
            {
                return 0;
            }
        };

        $capture = TraceCapture::start($store, 7, $this->enabledProfile());

        $this->assertInstanceOf(TraceCapture::class, $capture);
        $this->assertSame(0, $capture->records(), 'A store that refuses the write leaves the count at zero.');
    }
}

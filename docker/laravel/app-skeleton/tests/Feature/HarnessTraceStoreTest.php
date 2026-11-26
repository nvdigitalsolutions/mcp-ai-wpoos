<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\TraceStoreInterface;
use Nvoos\Core\Domain\Service\Harness\HarnessProfile;
use Nvoos\Laravel\Adapter\DatabaseTraceStore;
use Tests\TestCase;

/**
 * Harness trace storage — the Laravel side of `WP_MCP_AI_Harness_Trace_Store`.
 *
 * WordPress keeps the newest 50 runs per assistant on disk and enforces the
 * window itself rather than trusting the caller. Laravel keeps rows and prunes
 * by run, with the same window rule: a request outside 10–200 is clamped, not
 * honoured. The point of the test is the clamp — an unbounded window is an
 * unbounded table.
 */
class HarnessTraceStoreTest extends TestCase
{
    use RefreshDatabase;

    private const ASSISTANT = 7;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    private function store(): TraceStoreInterface
    {
        return app(TraceStoreInterface::class);
    }

    /**
     * Insert one record per run, oldest first, so ordering is a real date
     * comparison rather than a tie broken by the engine.
     *
     * @param  array<int, string>  $runIds
     */
    private function seedRuns(array $runIds, int $recordsPerRun = 1): void
    {
        $at = 1_700_000_000;

        foreach ($runIds as $runId) {
            for ($i = 0; $i < $recordsPerRun; $i++) {
                $this->store()->append(self::ASSISTANT, $runId, 'refine.jsonl', [
                    'iteration' => $i + 1,
                    'verdict' => 'revise',
                    'feedback' => 'Tighten line two.',
                ]);
                // Re-stamp so runs sort deterministically; `now()` ties at the
                // second and would make the test flaky.
                DB::table(DatabaseTraceStore::TABLE)
                    ->where('assistant_id', self::ASSISTANT)
                    ->where('run_id', $runId)
                    ->update(['created_at' => date('Y-m-d H:i:s', $at++)]);
            }
        }
    }

    private function runIds(): array
    {
        return DB::table(DatabaseTraceStore::TABLE)
            ->where('assistant_id', self::ASSISTANT)
            ->orderBy('created_at')
            ->pluck('run_id')
            ->unique()
            ->values()
            ->all();
    }

    public function test_the_container_binds_the_database_store(): void
    {
        $this->assertInstanceOf(DatabaseTraceStore::class, $this->store());
    }

    public function test_a_record_is_persisted_with_its_run_and_artifact(): void
    {
        $this->assertTrue(
            $this->store()->append(self::ASSISTANT, 'run-a', 'refine.jsonl', ['iteration' => 1])
        );

        $this->assertDatabaseHas(DatabaseTraceStore::TABLE, [
            'assistant_id' => self::ASSISTANT,
            'run_id' => 'run-a',
            'artifact' => 'refine.jsonl',
        ]);
    }

    public function test_a_record_without_an_assistant_or_run_is_refused(): void
    {
        $this->assertFalse($this->store()->append(0, 'run-a', 'refine.jsonl', ['iteration' => 1]));
        $this->assertFalse($this->store()->append(self::ASSISTANT, '   ', 'refine.jsonl', ['iteration' => 1]));
        $this->assertDatabaseCount(DatabaseTraceStore::TABLE, 0);
    }

    public function test_pruning_keeps_the_newest_runs(): void
    {
        $this->seedRuns(['run-1', 'run-2', 'run-3', 'run-4', 'run-5']);

        // The floor is 10, so a request for 2 keeps 10 — which is all of them.
        $this->assertSame(0, $this->store()->prune(self::ASSISTANT, 2));

        $this->assertSame(['run-1', 'run-2', 'run-3', 'run-4', 'run-5'], $this->runIds());
    }

    public function test_a_window_at_the_floor_still_prunes_nothing_below_ten(): void
    {
        $runIds = array_map(static fn (int $i): string => 'run-'.$i, range(1, 11));
        $this->seedRuns($runIds);

        $this->assertSame(1, $this->store()->prune(self::ASSISTANT, HarnessProfile::MIN_RETENTION_RUNS));

        $this->assertSame(
            array_map(static fn (int $i): string => 'run-'.$i, range(2, 11)),
            $this->runIds()
        );
    }

    public function test_pruning_removes_every_record_of_a_stale_run(): void
    {
        $runIds = array_map(static fn (int $i): string => 'run-'.$i, range(1, 12));
        $this->seedRuns($runIds, 3);

        $removed = $this->store()->prune(self::ASSISTANT, 10);

        $this->assertSame(2, $removed, 'Two runs were dropped.');
        $this->assertDatabaseMissing(DatabaseTraceStore::TABLE, ['run_id' => 'run-1']);
        $this->assertDatabaseMissing(DatabaseTraceStore::TABLE, ['run_id' => 'run-2']);
        $this->assertSame(30, DB::table(DatabaseTraceStore::TABLE)->count(), 'Ten runs of three records survive.');
    }

    public function test_an_unbounded_request_is_clamped_to_the_ceiling(): void
    {
        // 201 runs, ask to keep PHP_INT_MAX: the clamp keeps 200, so exactly
        // one run is stale.
        $runIds = array_map(static fn (int $i): string => 'run-'.$i, range(1, 201));
        $this->seedRuns($runIds);

        $this->assertSame(1, $this->store()->prune(self::ASSISTANT, PHP_INT_MAX));
        $this->assertSame(HarnessProfile::MAX_RETENTION_RUNS, count($this->runIds()));
    }

    public function test_pruning_leaves_another_assistants_runs_alone(): void
    {
        $this->seedRuns(array_map(static fn (int $i): string => 'run-'.$i, range(1, 11)));

        $this->assertTrue($this->store()->append(99, 'other-run', 'refine.jsonl', ['iteration' => 1]));

        $this->store()->prune(self::ASSISTANT, 10);

        $this->assertDatabaseHas(DatabaseTraceStore::TABLE, ['assistant_id' => 99, 'run_id' => 'other-run']);
    }

    public function test_pruning_an_unknown_assistant_is_a_no_op(): void
    {
        $this->seedRuns(['run-1']);

        $this->assertSame(0, $this->store()->prune(404, 10));
        $this->assertSame(0, $this->store()->prune(0, 10));
        $this->assertDatabaseCount(DatabaseTraceStore::TABLE, 1);
    }

    public function test_a_record_is_stamped_with_the_assistants_estate(): void
    {
        Tenant::query()->create(array(
            'id' => 'estate-a',
            'name' => 'Estate A',
            'slug' => 'estate-a',
        ));

        Sanctum::actingAs(User::factory()->admin()->inEstate('estate-a', 'owner')->create());

        $assistantId = (int) $this->postJson('/api/v1/assistants', array(
            'title' => 'Traced assistant',
            'system_prompt' => 'Be brief.',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ))->assertCreated()->json('id');

        $this->assertTrue($this->store()->append($assistantId, 'run-estate', 'run.jsonl', array('type' => 'start')));

        $this->assertSame(
            'estate-a',
            (string) DB::table(DatabaseTraceStore::TABLE)->where('run_id', 'run-estate')->value('tenant_id'),
            'The estate is read from the assistant, never from the caller.'
        );
    }

    public function test_the_assistant_table_name_is_read_from_the_oos_namespace(): void
    {
        // The package merges `config/nvoos.php` under `oos`, so a key read as
        // `nvoos.*` would be dead and the store would silently fall back to the
        // hardcoded default. This pins the namespace, not the value.
        $this->assertSame('assistants', config('oos.assistants_table'));
    }

    public function test_an_unknown_assistant_falls_back_to_the_default_estate(): void
    {
        // A trace must not invent an estate it cannot evidence, so a missing
        // assistant row is `default` — the same rule the token rows follow.
        $this->assertTrue($this->store()->append(9999, 'run-orphan', 'run.jsonl', array('type' => 'start')));

        $this->assertSame(
            Tenant::DEFAULT_ID,
            (string) DB::table(DatabaseTraceStore::TABLE)->where('run_id', 'run-orphan')->value('tenant_id')
        );
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\Scheduler;
use App\Models\Schedule;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149, Wave 2 — the `/ops` scheduler liveness surface.
 *
 * The properties worth pinning are not "does the table render":
 *
 *  - **The diagnosis is real.** An enabled row whose `next_run_at` has passed
 *    is *overdue*, and that is the only signal the runtime has that the
 *    `scheduler` container role is not running. `untimed` is the other failure
 *    mode: enabled with no clock at all, which nothing advances.
 *  - **The two recovery operations are the tick's own operations.** *Time
 *    untimed* is `ScheduleRunner::bootstrap()` and must not fire anything; *Run
 *    due now* is `ScheduleRunner::due()` + `run()`, so a catch-up advances the
 *    clock exactly as a normal tick would.
 *  - **A dead tick is not a crash.** Nothing due is a no-op, not an error, and
 *    it writes no audit row — an operator who opens the page and presses the
 *    button has not "done" anything.
 *  - **Every mutation is audited by hand.** Livewire traffic is excluded from
 *    the request-level audit, so without the explicit record row a catch-up of
 *    fifty schedules would leave no trace.
 */
class OperatorSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
    }

    public function test_an_operator_reaches_the_scheduler_surface(): void
    {
        $this->schedule();

        $this->actingAs($this->operator())
            ->get('/ops/scheduler')
            ->assertOk()
            ->assertSee('Nightly report');
    }

    public function test_a_customer_administrator_is_refused(): void
    {
        $this->actingAs($this->customerAdmin())
            ->get('/ops/scheduler')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_operator_login(): void
    {
        $this->get('/ops/scheduler')->assertRedirect('/ops/login');
    }

    public function test_a_viewer_cannot_reach_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$viewer->id => array('role' => 'viewer')]);

        $this->actingAs($viewer)
            ->get('/ops/scheduler')
            ->assertForbidden();
    }

    public function test_the_page_predicate_refuses_a_non_operator(): void
    {
        $this->actingAs($this->customerAdmin());

        $this->assertFalse(Scheduler::canAccess());
    }

    /**
     * The four states the health column reports. `overdue` is the diagnosis the
     * page exists for; `untimed` is the one *Time untimed schedules* fixes.
     */
    public function test_the_health_label_classifies_each_row(): void
    {
        $paused = $this->schedule(array(
            'id' => 'paused-one',
            'enabled' => false,
            'next_run_at' => Carbon::now()->subHour(),
        ));
        $untimed = $this->schedule(array('id' => 'untimed-one'));
        $overdue = $this->schedule(array(
            'id' => 'overdue-one',
            'next_run_at' => Carbon::now()->subHour(),
        ));
        $scheduled = $this->schedule(array(
            'id' => 'scheduled-one',
            'next_run_at' => Carbon::now()->addHour(),
        ));

        $this->assertSame('paused', $this->labelFor($paused));
        $this->assertSame('untimed', $this->labelFor($untimed));
        $this->assertSame('overdue', $this->labelFor($overdue));
        $this->assertSame('scheduled', $this->labelFor($scheduled));
    }

    public function test_an_overdue_schedule_is_surfaced_on_the_page(): void
    {
        $this->schedule(array('next_run_at' => Carbon::now()->subHour()));

        $this->actingAs($this->operator());

        $page = Livewire::test(Scheduler::class);

        $this->assertSame(1, $page->instance()->overdueCount());

        $this->get('/ops/scheduler')
            ->assertOk()
            ->assertSee('overdue');
    }

    public function test_a_disabled_overdue_schedule_is_not_counted_and_never_runs(): void
    {
        $this->schedule(array(
            'enabled' => false,
            'next_run_at' => Carbon::now()->subHour(),
        ));

        $this->actingAs($this->operator());

        $page = Livewire::test(Scheduler::class);

        $this->assertSame(0, $page->instance()->overdueCount());

        $page->callAction('runDue');

        $this->assertSame(0, DB::table('nvoos_schedule_runs')->count());
        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    /**
     * Bootstrapping is not firing: the first occurrence is always in the
     * future, and no run row is written.
     */
    public function test_timing_untimed_schedules_sets_a_clock_without_running_them(): void
    {
        $id = $this->schedule();

        $this->actingAs($this->operator());

        Livewire::test(Scheduler::class)->callAction('timeUntimed');

        $this->assertNotNull(
            DB::table('nvoos_schedules')->where('id', $id)->value('next_run_at')
        );
        $this->assertSame(0, DB::table('nvoos_schedule_runs')->count());

        $row = DB::table('nvoos_admin_audit')->where('resource', 'scheduler')->first();

        $this->assertNotNull($row, 'Timing a backlog must leave a trace.');
        $this->assertSame('timed', $row->action);
        $this->assertSame('untimed', $row->record_id);
        $this->assertSame(1, json_decode((string) $row->after, true)['count']);
        $this->assertNull($row->tenant_id, 'An operator action belongs to no single estate.');
    }

    public function test_running_due_schedules_records_a_run_and_advances_the_clock(): void
    {
        $id = $this->schedule(array('next_run_at' => Carbon::now()->subMinutes(5)));

        $this->actingAs($this->operator());

        Livewire::test(Scheduler::class)->callAction('runDue');

        $this->assertSame(
            1,
            (int) DB::table('nvoos_schedules')->where('id', $id)->value('run_count')
        );
        $this->assertSame(1, DB::table('nvoos_schedule_runs')->count());
        $this->assertSame('success', DB::table('nvoos_schedule_runs')->value('status'));

        // The clock moved, so the next tick finds nothing due — a catch-up must
        // not leave the row due forever.
        $this->assertSame(0, Livewire::test(Scheduler::class)->instance()->overdueCount());

        $row = DB::table('nvoos_admin_audit')->where('resource', 'scheduler')->first();

        $this->assertNotNull($row);
        $this->assertSame('ran', $row->action);
        $this->assertSame('due', $row->record_id);
        $this->assertSame(1, json_decode((string) $row->after, true)['count']);
        $this->assertSame(0, json_decode((string) $row->after, true)['failed']);
    }

    /**
     * A dead handler is recorded as a failed run, counted in the audit row, and
     * does not stop the catch-up.
     */
    public function test_a_failing_schedule_is_counted_but_does_not_wedge_the_catch_up(): void
    {
        $this->schedule(array(
            'id' => 'broken',
            'handler' => 'wp_mcp_ai_pro_schedule_exec',
            'next_run_at' => Carbon::now()->subMinutes(5),
        ));
        $this->schedule(array(
            'id' => 'healthy',
            'next_run_at' => Carbon::now()->subMinutes(5),
        ));

        $this->actingAs($this->operator());

        Livewire::test(Scheduler::class)->callAction('runDue');

        $this->assertSame(2, DB::table('nvoos_schedule_runs')->count());
        $this->assertSame(
            'failure',
            DB::table('nvoos_schedule_runs')->where('schedule_id', 'broken')->value('status')
        );
        $this->assertSame(
            'success',
            DB::table('nvoos_schedule_runs')->where('schedule_id', 'healthy')->value('status')
        );

        $row = DB::table('nvoos_admin_audit')->where('resource', 'scheduler')->first();

        $this->assertSame(2, json_decode((string) $row->after, true)['count']);
        $this->assertSame(1, json_decode((string) $row->after, true)['failed']);
    }

    public function test_nothing_due_is_a_no_op_and_writes_nothing(): void
    {
        // Untimed, so not due: the row exists but there is no work.
        $this->schedule();

        $this->actingAs($this->operator());

        Livewire::test(Scheduler::class)->callAction('runDue');

        $this->assertSame(0, DB::table('nvoos_schedule_runs')->count());
        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    /**
     * An unparseable expression is not a crash: bootstrap skips it, so the row
     * stays `untimed` and no audit row claims otherwise.
     */
    public function test_an_unparseable_cron_is_left_untimed(): void
    {
        $id = $this->schedule(array('cron_expression' => 'not a cron'));

        $this->actingAs($this->operator());

        Livewire::test(Scheduler::class)->callAction('timeUntimed');

        $this->assertNull(
            DB::table('nvoos_schedules')->where('id', $id)->value('next_run_at')
        );
        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    private function labelFor(string $id): string
    {
        return Schedule::query()->findOrFail($id)->healthLabel();
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    private function customerAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    /**
     * Insert a schedule row.
     *
     * `list_projects` is a registered handler, so the default fixture runs
     * successfully; pass `handler` to exercise the failure path.
     *
     * @param array<string, mixed> $overrides
     */
    private function schedule(array $overrides = array()): string
    {
        $id = (string) ($overrides['id'] ?? 'nightly-report');
        $now = now();

        DB::table('nvoos_schedules')->insert(array_merge(array(
            'id' => $id,
            'name' => 'Nightly report',
            'schedule_type' => 'cron',
            'enabled' => true,
            'next_run_at' => null,
            'last_run_at' => null,
            'priority' => 10,
            'tags' => json_encode(array()),
            'run_count' => 0,
            'handler' => 'list_projects',
            'payload' => json_encode(array()),
            'cron_expression' => '0 0 * * *',
            'created_at' => $now,
            'updated_at' => $now,
        ), $overrides));

        return $id;
    }
}

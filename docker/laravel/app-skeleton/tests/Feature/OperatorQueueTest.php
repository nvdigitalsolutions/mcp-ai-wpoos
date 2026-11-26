<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\QueueMonitor;
use App\Models\FailedJob;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149, Wave 2 — the `/ops` failed-jobs surface.
 *
 * The interesting properties are not "does the table render":
 *
 *  - **Retry is a real requeue.** `queue:retry` semantics — the original
 *    payload goes back onto the connection that recorded it, and the failed row
 *    is forgotten. Pinned by asserting the `jobs` table gained a row.
 *  - **Forget and flush never requeue.** Deleting a dead letter must not
 *    resurrect it.
 *  - **Every mutation is audited by hand.** Livewire traffic is excluded from
 *    the request-level audit, so without the explicit record row an operator
 *    could drain the queue and leave no trace.
 *  - **The audit row carries no payload or stack trace.** A failed job's
 *    payload can contain user data, and the log is not the place for it.
 */
class OperatorQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
    }

    public function test_an_operator_reaches_the_queue_surface(): void
    {
        $this->failJob();

        $this->actingAs($this->operator())
            ->get('/ops/queue')
            ->assertOk()
            ->assertSee('OptimizeMediaFile');
    }

    public function test_a_customer_administrator_is_refused(): void
    {
        $this->actingAs($this->customerAdmin())
            ->get('/ops/queue')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_operator_login(): void
    {
        $this->get('/ops/queue')->assertRedirect('/ops/login');
    }

    public function test_retrying_a_job_requeues_it_and_clears_the_failure(): void
    {
        $uuid = $this->failJob();

        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callTableAction('retry', FailedJob::query()->findOrFail($uuid));

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('default', DB::table('jobs')->value('queue'));

        $row = DB::table('nvoos_admin_audit')->where('resource', 'queue')->first();

        $this->assertNotNull($row);
        $this->assertSame('retried', $row->action);
        $this->assertSame($uuid, $row->record_id);
        $this->assertNull($row->tenant_id, 'An operator action belongs to no single estate.');
    }

    public function test_forgetting_a_job_deletes_it_without_requeueing(): void
    {
        $uuid = $this->failJob();

        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callTableAction('forget', FailedJob::query()->findOrFail($uuid));

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());

        $this->assertSame(
            'forgotten',
            DB::table('nvoos_admin_audit')->where('resource', 'queue')->value('action')
        );
    }

    public function test_retry_all_drains_the_queue(): void
    {
        $this->failJob('default');
        $this->failJob('media');

        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)->callAction('retryAll');

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(2, DB::table('jobs')->count());

        $row = DB::table('nvoos_admin_audit')->where('resource', 'queue')->first();

        $this->assertSame('retried', $row->action);
        $this->assertSame('all', $row->record_id);
        $this->assertSame(2, json_decode((string) $row->after, true)['count']);
    }

    public function test_flush_deletes_everything_without_requeueing(): void
    {
        $this->failJob();
        $this->failJob();

        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)->callAction('flush');

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());

        $this->assertSame(
            'flushed',
            DB::table('nvoos_admin_audit')->where('resource', 'queue')->value('action')
        );
    }

    /**
     * A failed job's payload can contain user data. The audit row records the
     * queue and connection, never the payload or the stack trace.
     */
    public function test_the_audit_row_carries_no_payload_or_stack_trace(): void
    {
        $uuid = $this->failJob();

        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callTableAction('retry', FailedJob::query()->findOrFail($uuid));

        $row = DB::table('nvoos_admin_audit')->where('resource', 'queue')->first();

        $serialised = (string) $row->before.(string) $row->after.(string) $row->payload;

        $this->assertStringNotContainsString('RuntimeException: boom', $serialised);
        $this->assertStringNotContainsString('CallQueuedHandler', $serialised);
        $this->assertStringNotContainsString('OptimizeMediaFile', $serialised);
    }

    public function test_the_page_predicate_refuses_a_non_operator(): void
    {
        $this->actingAs($this->customerAdmin());

        $this->assertFalse(QueueMonitor::canAccess());
    }

    public function test_a_viewer_without_operator_membership_cannot_reach_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$viewer->id => array('role' => 'viewer')]);

        $this->actingAs($viewer)
            ->get('/ops/queue')
            ->assertForbidden();
    }

    /**
     * A failed job whose payload names a missing class is still retryable: the
     * panel only pushes the original payload, it never executes it.
     */
    public function test_a_payload_naming_a_missing_class_is_still_retryable(): void
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert(array(
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => (string) json_encode(array(
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\NoLongerExists',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => array(),
            )),
            'exception' => 'Error: class not found',
            'failed_at' => now(),
        ));

        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callTableAction('retry', FailedJob::query()->findOrFail($uuid));

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count());
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
     * Insert a failed job whose payload is inert.
     *
     * `retry` pushes the payload onto the queue; the `database` connection
     * stores it in `jobs` without executing it, so the fixture never has to be
     * a real class.
     */
    private function failJob(string $queue = 'default'): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert(array(
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => (string) json_encode(array(
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\OptimizeMediaFile',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => array('commandName' => 'App\\Jobs\\OptimizeMediaFile'),
            )),
            'exception' => "RuntimeException: boom\n#0 {main}",
            'failed_at' => now(),
        ));

        return $uuid;
    }
}

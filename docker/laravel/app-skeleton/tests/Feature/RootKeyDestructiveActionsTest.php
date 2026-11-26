<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\MachineCredentials;
use App\Filament\Operator\Pages\QueueMonitor;
use App\Models\FailedJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Security\RootKeyGate;
use App\Support\Security\RootKeyRequired;
use App\Support\Security\SecurityEventLogger;
use App\Support\Settings\SettingsService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §189 — the root key on destructive operator actions.
 *
 * §188 gated operator *settings* writes. The field describes something broader —
 * a second password for high-risk actions — and the panel has actions that
 * destroy a credential or a dead letter without going near `SettingsService`.
 * This pins that those actions ask for the key.
 *
 * ## One guard per entry point, not two stacked
 *
 * The first draft put the key in the action's modal *and* a second
 * `assertUnlocked()` inside the page method. That reads like defence in depth and
 * is not: the modal's correct key is exchanged for a session grant, and a request
 * that has no session can never satisfy the second check — so the operator would
 * answer the key, watch it be accepted, and still be refused. A guard belongs at
 * the entry point, once:
 *
 *  - **Behind a modal** — `RootKeyAction::guard()` verifies in `before()` and
 *    `halt()`s before the work's closure is reached. Used where the work lives in
 *    a private method, which only the action can call.
 *  - **Reachable directly** — a public Livewire method can be invoked with
 *    `$wire.revoke(id)` without mounting anything, so those methods call
 *    `RootKeyGate::assertUnlocked()` themselves.
 *
 * The Livewire harness runs model actions with middleware disabled
 * (`withoutMiddleware()` in its `RequestBroker`), so it carries no session and
 * cannot be handed a header. That bounds what these tests can show: the modal
 * path is proven end to end, and the direct-call path is proven to refuse. The
 * grant itself, and `attempt()` → `unlocked()`, are pinned by `RootKeyGateTest`;
 * composing the two is what makes the direct-call path work once proven.
 */
final class RootKeyDestructiveActionsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-very-long-root-security-key-of-64-characters-or-more-0002';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(array(
            'cache.default' => 'array',
            'sanctum.stateful' => array('localhost', '127.0.0.1'),
        ));
    }

    public function test_with_the_gate_off_a_destructive_action_needs_no_key(): void
    {
        $uuid = $this->failJob();

        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callTableAction('forget', FailedJob::query()->findOrFail($uuid));

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_wrong_key_in_the_action_modal_halts_it_and_keeps_the_row(): void
    {
        $uuid = $this->failJob();

        $this->arm();
        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callTableAction('forget', FailedJob::query()->findOrFail($uuid), data: array(
                RootKeyGate::ENTRY_FIELD => 'not-the-key',
            ))
            ->assertActionHalted();

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'A rejected key must not delete anything.');
        $this->assertSame(
            1,
            DB::table('nvoos_security_events')
                ->where('event', SecurityEventLogger::ROOT_KEY_DENIED)
                ->count()
        );
    }

    public function test_the_right_key_in_the_action_modal_performs_it(): void
    {
        $uuid = $this->failJob();

        $this->arm();
        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callTableAction('forget', FailedJob::query()->findOrFail($uuid), data: array(
                RootKeyGate::ENTRY_FIELD => self::SECRET,
            ));

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(
            1,
            DB::table('nvoos_security_events')
                ->where('event', SecurityEventLogger::ROOT_KEY_GRANTED)
                ->count()
        );
    }

    public function test_a_toolbar_action_asks_for_the_key_too(): void
    {
        $this->failJob();
        $this->failJob();

        $this->arm();
        $this->actingAs($this->operator());

        Livewire::test(QueueMonitor::class)
            ->callAction('flush', data: array(RootKeyGate::ENTRY_FIELD => self::SECRET));

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_direct_livewire_call_cannot_skip_the_gate(): void
    {
        // `$wire.revoke(id)` reaches the public method without mounting an action,
        // so the modal guard is not in the path. The method must refuse on its own.
        $user = $this->operator();
        $token = $user->createToken('oos-machine:ci', array('assistant:1'));
        $id = (int) $token->accessToken->getKey();

        $this->arm();
        $this->actingAs($user);

        $this->expectException(RootKeyRequired::class);

        Livewire::test(MachineCredentials::class)->call('revoke', $id);
    }

    /**
     * Switch the control on and store a key, in the order the panel would.
     *
     * Auditing is enabled first: a rejected attempt is only recorded when the
     * security-event stream is switched on, and once the gate is armed this write
     * would itself need a proof.
     */
    private function arm(): void
    {
        app(SettingsService::class)->write(
            array('enable_security_audit_log' => '1'),
            SettingsService::SURFACE_OPS
        );

        app(SettingsService::class)->write(array(
            'enable_root_security_key' => '1',
            'root_security_key' => self::SECRET,
        ), SettingsService::SURFACE_OPS);
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    /**
     * Insert a failed job whose payload is inert, as `OperatorQueueTest` does.
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

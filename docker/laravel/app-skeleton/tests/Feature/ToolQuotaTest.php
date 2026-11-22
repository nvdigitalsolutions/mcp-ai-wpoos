<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\ToolQuota;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\ToolQuota\ToolQuotaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Tests\TestCase;
use Throwable;

/**
 * Per-tool daily quotas — Proposal 149 §176.
 *
 * Every enforcement assertion runs through the real application registry, so it
 * pins the wiring (waterfall listener → guard → store) rather than the guard's
 * unit behaviour.
 *
 * The load-bearing cases:
 *
 * - **unmetered by default** — absence, a disabled row and a zero limit all run,
 *   because a control nobody configured must not refuse anybody;
 * - **per user, not per estate** — one caller at their ceiling must not stop
 *   another caller of the same estate;
 * - **a refusal is not recorded** — otherwise a model could exhaust a quota by
 *   retrying a tool it has already been refused;
 * - **estate scoping** — a quota in one estate must not meter another's traffic.
 */
class ToolQuotaTest extends TestCase
{
    use RefreshDatabase;

    /** A real read-only tool that tolerates a single `color` argument. */
    private const TOOL = 'color_convert';

    private const ARGUMENTS = array('color' => '#ff0000');

    // ── The fail-safe floor ─────────────────────────────────────

    public function test_a_tool_with_no_quota_row_runs(): void
    {
        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($this->user()),
            'A tool nobody metered must run: absence is not a limit.'
        );
    }

    public function test_a_zero_limit_is_unlimited(): void
    {
        $user = $this->user();
        $this->store()->put(self::TOOL, 0);

        $this->store()->record(self::TOOL, (int) $user->getKey(), 999_999);

        $this->assertNotInstanceOf(Throwable::class, $this->execute($user));
    }

    public function test_a_disabled_quota_is_unlimited(): void
    {
        $user = $this->user();
        $this->store()->put(self::TOOL, 10, false);

        $this->store()->record(self::TOOL, (int) $user->getKey(), 999_999);

        $this->assertNotInstanceOf(Throwable::class, $this->execute($user));
    }

    // ── Enforcement ─────────────────────────────────────────────

    public function test_a_quota_at_the_ceiling_denies_the_tool(): void
    {
        $user = $this->user();
        $this->store()->put(self::TOOL, 100);
        $this->store()->record(self::TOOL, (int) $user->getKey(), 100);

        $result = $this->execute($user);

        $this->assertInstanceOf(Throwable::class, $result);
        $this->assertStringContainsString('daily token quota', $result->getMessage());
        $this->assertStringContainsString(self::TOOL, $result->getMessage());
    }

    public function test_a_quota_below_the_ceiling_allows_the_tool(): void
    {
        $user = $this->user();
        $this->store()->put(self::TOOL, 1000);
        $this->store()->record(self::TOOL, (int) $user->getKey(), 999);

        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($user),
            'One token under the ceiling must still run; the band is a ceiling, not a target.'
        );
    }

    public function test_the_limit_is_enforced_per_user(): void
    {
        $spent = $this->user();
        $fresh = $this->user();
        $this->store()->put(self::TOOL, 100);
        $this->store()->record(self::TOOL, (int) $spent->getKey(), 100);

        $this->assertInstanceOf(
            Throwable::class,
            $this->execute($spent),
            'The caller who spent the quota must be refused.'
        );

        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($fresh),
            'Another caller of the same estate must not inherit a refusal.'
        );
    }

    public function test_another_estates_quota_does_not_apply(): void
    {
        $user = $this->user();

        $this->store()->put(self::TOOL, 1);
        $this->store()->record(self::TOOL, (int) $user->getKey(), 1);

        // Prove the quota refuses while the estate is current.
        $this->assertInstanceOf(Throwable::class, $this->execute($user));

        // Then move the caller into an estate that configured nothing.
        TenantContext::set('another-estate');

        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($user),
            'ToolQuota carries the tenant scope, so another estate\'s limit must not be read here.'
        );
    }

    // ── Recording ───────────────────────────────────────────────

    public function test_a_successful_call_is_recorded(): void
    {
        $user = $this->user();

        $this->execute($user);

        $this->assertGreaterThan(
            0,
            $this->store()->usageToday(self::TOOL, (int) $user->getKey()),
            'The recorder must charge the caller for what the tool returned.'
        );
    }

    public function test_a_refused_call_is_not_recorded(): void
    {
        $user = $this->user();
        $this->store()->put(self::TOOL, 100);
        $this->store()->record(self::TOOL, (int) $user->getKey(), 100);

        $before = $this->store()->usageToday(self::TOOL, (int) $user->getKey());

        $this->execute($user);

        $this->assertSame(
            $before,
            $this->store()->usageToday(self::TOOL, (int) $user->getKey()),
            'A refused call costs nothing, so charging it would let a model exhaust a quota by retrying.'
        );
    }

    public function test_the_estimate_matches_the_wordpress_ratio(): void
    {
        $this->assertSame(1, ToolQuotaStore::estimate(''));
        $this->assertSame(2, ToolQuotaStore::estimate('12345678'));
        $this->assertSame(
            max(1, (int) (\strlen((string) \json_encode(array('a' => 'bb'))) / 4)),
            ToolQuotaStore::estimate(array('a' => 'bb'))
        );
        $this->assertSame(1, ToolQuotaStore::estimate(null));
    }

    public function test_the_master_switch_disables_the_check_and_the_recording(): void
    {
        $user = $this->user();
        $this->store()->put(self::TOOL, 1);
        $this->store()->record(self::TOOL, (int) $user->getKey(), 1);

        config(array('oos.tool_quotas.enabled' => false));

        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($user),
            'The master switch must stop the check, not just the page.'
        );

        $this->assertSame(
            1,
            $this->store()->usageToday(self::TOOL, (int) $user->getKey()),
            'With the switch off the per-call upsert stops too.'
        );
    }

    // ── Retention ───────────────────────────────────────────────

    public function test_the_scheduled_prune_spans_every_estate(): void
    {
        $old = now('UTC')->subDays(60)->toDateString();
        $recent = now('UTC')->subDays(1)->toDateString();

        foreach (array('default', 'another-estate') as $tenant) {
            foreach (array($old, $recent) as $day) {
                DB::table('nvoos_tool_quota_usage')->insert(array(
                    'tenant_id' => $tenant,
                    'user_id' => 0,
                    'tool_slug' => self::TOOL,
                    'day' => $day,
                    'tokens' => 10,
                    'requests' => 1,
                ));
            }
        }

        $deleted = $this->store()->pruneAll(30);

        $this->assertSame(2, $deleted, 'One old row per estate must go, and only the old ones.');

        $this->assertSame(
            2,
            DB::table('nvoos_tool_quota_usage')->where('day', $recent)->count(),
            'Yesterday is inside the window for both estates.'
        );

        $this->assertSame(0, DB::table('nvoos_tool_quota_usage')->where('day', $old)->count());
    }

    // ── The page ────────────────────────────────────────────────

    public function test_the_page_lists_a_quota_with_its_usage(): void
    {
        $admin = $this->estateAdmin();
        $this->store()->put(self::TOOL, 2_000);
        $this->store()->record(self::TOOL, (int) $admin->getKey(), 1_600);

        $this->actingAs($admin)
            ->get('/admin/default/tool-quotas')
            ->assertOk()
            ->assertSee('Tool quotas')
            ->assertSee(self::TOOL)
            ->assertSee('2.0k');
    }

    public function test_the_page_is_closed_to_a_viewer(): void
    {
        $user = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'viewer')));

        $this->actingAs($user)
            ->get('/admin/default/tool-quotas')
            ->assertForbidden();
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Execute the probe tool through the real registry.
     */
    private function execute(User $user): mixed
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has(self::TOOL), 'The probe tool must be registered here.');

        return $registry->execute(
            self::TOOL,
            self::ARGUMENTS,
            array(
                'user_id' => (int) $user->getKey(),
                'auth_provider' => $this->app->make(AuthProviderInterface::class),
                'agentic_loop' => true,
            )
        );
    }

    private function store(): ToolQuotaStore
    {
        return $this->app->make(ToolQuotaStore::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}

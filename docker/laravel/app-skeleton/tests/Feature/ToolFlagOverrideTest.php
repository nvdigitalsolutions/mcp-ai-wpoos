<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\ToolFlags\ToolFlagOverrideStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagResolution;
use Tests\TestCase;
use Throwable;

/**
 * Per-tool capability-flag overrides — Proposal 149 §177.
 *
 * Enforcement runs through the real application registry, so these assertions pin
 * the wiring (port → guards) rather than the resolver's unit behaviour.
 *
 * The load-bearing cases:
 *
 * - **an addition can only restrict** — a tool that ran under a read-only
 *   profile stops running once its estate adds a gated flag;
 * - **removal is unrepresentable** — a save naming a non-gated flag writes
 *   nothing, because the store filters to the read-only gate set;
 * - **undeclared stays undeclared** — the resolver ignores additions for a slug
 *   that declares nothing, so an override cannot turn "unknown" into "safe";
 * - **estate scoping** — one estate's override does not tighten another's tools.
 */
class ToolFlagOverrideTest extends TestCase
{
    use RefreshDatabase;

    /** A real tool declaring PURE_LOCAL (`cacheable`, `idempotent`, `local-only`, `read-only`). */
    private const TOOL = 'color_convert';

    // ── The addition actually restricts ─────────────────────────

    public function test_a_tool_runs_under_a_read_only_profile_without_an_override(): void
    {
        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($this->readOnlyUser()),
            'The probe tool is read-only, so the profile must allow it before any override.'
        );
    }

    public function test_adding_a_gated_flag_stops_the_tool_under_a_read_only_profile(): void
    {
        $user = $this->readOnlyUser();

        $this->store()->put(self::TOOL, array('write'));

        $result = $this->execute($user);

        $this->assertInstanceOf(Throwable::class, $result);
        $this->assertStringContainsString('read-only profile', $result->getMessage());
        $this->assertStringContainsString('write', $result->getMessage());
    }

    public function test_a_write_user_is_unaffected_by_the_override(): void
    {
        $admin = $this->admin();

        $this->store()->put(self::TOOL, array('write'));

        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($admin),
            'An override restricts only where a profile or gate already refuses; it does not invent a deployment-wide block.'
        );
    }

    // ── Narrow-only, by construction ────────────────────────────

    public function test_a_flag_outside_the_gate_set_writes_nothing(): void
    {
        $stored = $this->store()->put(self::TOOL, array('read-only', 'cacheable'));

        $this->assertNull($stored, 'No permitted flag was named, so there is no row to write.');
        $this->assertSame(array(), $this->store()->addedFlags(self::TOOL));
    }

    public function test_only_the_permitted_flags_survive_a_mixed_save(): void
    {
        $this->store()->put(self::TOOL, array('write', 'read-only', 'destructive'));

        $this->assertSame(
            array('destructive', 'write'),
            $this->store()->addedFlags(self::TOOL),
            'Permitted flags are kept in vocabulary order; the rest are dropped.'
        );
    }

    public function test_an_undeclared_slug_stays_undeclared_even_with_a_row(): void
    {
        $this->store()->put('a_tool_that_declares_nothing', array('write'));

        $this->assertNull(
            CapabilityFlagResolution::resolve(
                'a_tool_that_declares_nothing',
                null,
                $this->store()
            ),
            'An override must not turn "we could not tell" into "we checked and it is safe".'
        );
    }

    public function test_the_effective_set_is_a_union_not_a_replacement(): void
    {
        $this->store()->put(self::TOOL, array('destructive'));

        $flags = CapabilityFlagResolution::resolve(
            self::TOOL,
            $this->registry()->get(self::TOOL),
            $this->store()
        );

        $this->assertContains('read-only', $flags, 'The tool\'s own declaration must survive.');
        $this->assertContains('local-only', $flags);
        $this->assertContains('destructive', $flags, 'The administrator\'s addition must be present.');
    }

    // ── Estate scoping ──────────────────────────────────────────

    public function test_another_estates_override_does_not_apply(): void
    {
        $user = $this->readOnlyUser();

        $this->store()->put(self::TOOL, array('write'));

        $this->assertInstanceOf(Throwable::class, $this->execute($user));

        TenantContext::set('another-estate');

        $this->assertNotInstanceOf(
            Throwable::class,
            $this->execute($user),
            'ToolFlagOverride carries the tenant scope, so another estate\'s override must not be read here.'
        );
    }

    // ── The page ────────────────────────────────────────────────

    public function test_the_page_lists_an_override(): void
    {
        $admin = $this->admin();

        $this->store()->put(self::TOOL, array('write', 'mass-email'));

        $this->actingAs($admin)
            ->get('/admin/default/tool-overrides')
            ->assertOk()
            ->assertSee('Tool flag overrides')
            ->assertSee(self::TOOL);
    }

    public function test_the_page_is_closed_to_a_viewer(): void
    {
        $user = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'viewer')));

        $this->actingAs($user)
            ->get('/admin/default/tool-overrides')
            ->assertForbidden();
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function execute(User $user): mixed
    {
        $registry = $this->registry();

        return $registry->execute(
            self::TOOL,
            array('color' => '#ff0000'),
            array(
                'user_id' => (int) $user->getKey(),
                'auth_provider' => $this->app->make(AuthProviderInterface::class),
                'agentic_loop' => true,
            )
        );
    }

    private function registry(): ToolRegistry
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has(self::TOOL), 'The probe tool must be registered here.');

        return $registry;
    }

    private function store(): ToolFlagOverrideStore
    {
        return $this->app->make(ToolFlagOverrideStore::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * An administrator carrying the `read-only` chat profile.
     *
     * Administrator because the probe tool requires `read`; the profile is the
     * only variable between the allowed and the refused assertion.
     */
    private function readOnlyUser(): User
    {
        $user = $this->admin();
        $user->forceFill(array('chat_profile' => 'read-only'))->save();

        return $user;
    }
}

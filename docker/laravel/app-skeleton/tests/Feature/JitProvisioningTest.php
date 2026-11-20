<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\AccessRequests;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\EstateDomains;
use App\Support\Auth\JitProvisioner;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §110 — just-in-time provisioning (decisions 158 D2–D4).
 *
 * The tests are organised around the four gates rather than around the code, so a
 * regression names the gate it broke:
 *
 *  1. JIT is off by default                 → records, creates nobody
 *  2. the IdP asserted a *verified* address  → the claim-spelling matrix
 *  3. the domain is verified on the estate   → exact match, no subdomain, no lookalike
 *  4. no account already holds the address   → never attach, never take over
 *
 * Plus the rule the JIT-audit literature names first: **a claim must never decide a
 * role**. The provisioned membership is `viewer`, and nothing on a later login
 * re-elevates it.
 */
final class JitProvisioningTest extends TestCase
{
    use RefreshDatabase;

    // ── Gate 2: the claim ────────────────────────────────────────────────────

    public function test_the_verified_claim_is_read_in_every_spelling_an_idp_uses(): void
    {
        foreach (array(true, 1, '1', 'true', 'TRUE', ' true ') as $claim) {
            $this->assertTrue(
                JitProvisioner::assertsVerified($claim),
                'Expected '.var_export($claim, true).' to count as verified.'
            );
        }

        // `"false"` is the hazard: PHP reads the non-empty string as truthy, so a
        // naive cast would accept exactly the case that must block.
        foreach (array(null, false, 0, '0', 'false', 'FALSE', 'no', '', array()) as $claim) {
            $this->assertFalse(
                JitProvisioner::assertsVerified($claim),
                'Expected '.var_export($claim, true).' to block provisioning.'
            );
        }
    }

    // ── Gate 3: the domain ───────────────────────────────────────────────────

    public function test_a_domain_is_normalised_before_it_is_stored_or_compared(): void
    {
        $this->assertSame('acme.com', EstateDomains::normalise('  ACME.com '));
        $this->assertSame('acme.com', EstateDomains::normalise('@acme.com'));
        $this->assertSame('acme.com', EstateDomains::normalise('acme.com.'));

        // Not domains.
        $this->assertSame('', EstateDomains::normalise('localhost'));
        $this->assertSame('', EstateDomains::normalise('127.0.0.1'));
        $this->assertSame('', EstateDomains::normalise('acme.com/path'));
        $this->assertSame('', EstateDomains::normalise('a@acme.com'));
        $this->assertSame('', EstateDomains::normalise(''));
    }

    public function test_a_lookalike_or_subdomain_never_satisfies_the_list(): void
    {
        $domains = app(EstateDomains::class);
        $domains->add(Tenant::DEFAULT_ID, 'acme.com');
        $domains->setVerified(Tenant::DEFAULT_ID, (int) DB::table('nvoos_estate_domains')->value('id'), true);

        $this->assertTrue($domains->allows(Tenant::DEFAULT_ID, 'person@acme.com'));
        $this->assertTrue($domains->allows(Tenant::DEFAULT_ID, 'PERSON@ACME.COM'));

        $this->assertFalse($domains->allows(Tenant::DEFAULT_ID, 'person@notacme.com'));
        $this->assertFalse($domains->allows(Tenant::DEFAULT_ID, 'person@acme.com.evil.test'));
        // 158 D3: a claim on acme.com does not cover eng.acme.com.
        $this->assertFalse($domains->allows(Tenant::DEFAULT_ID, 'person@eng.acme.com'));
    }

    public function test_an_unverified_domain_provisions_nobody(): void
    {
        $domains = app(EstateDomains::class);
        $domains->add(Tenant::DEFAULT_ID, 'acme.com');

        $this->assertFalse($domains->allows(Tenant::DEFAULT_ID, 'person@acme.com'));
    }

    public function test_adding_the_same_domain_twice_is_refused_in_any_case(): void
    {
        $domains = app(EstateDomains::class);

        $this->assertTrue($domains->add(Tenant::DEFAULT_ID, 'acme.com')['ok']);
        $this->assertFalse($domains->add(Tenant::DEFAULT_ID, 'ACME.com')['ok']);
        $this->assertFalse($domains->add(Tenant::DEFAULT_ID, '@acme.com.')['ok']);
    }

    // ── Gate 1: JIT off ──────────────────────────────────────────────────────

    public function test_jit_off_records_the_attempt_and_creates_nobody(): void
    {
        $result = $this->provision('auth0|new', 'person@acme.com', true);

        $this->assertNull($result['user']);
        $this->assertSame(JitProvisioner::REASON_DISABLED, $result['reason']);

        $this->assertSame(0, User::query()->where('email', 'person@acme.com')->count());
        $this->assertSame(1, DB::table('nvoos_jit_provisioning_requests')->count());
        $this->assertSame('jit_disabled', DB::table('nvoos_jit_provisioning_requests')->value('reason'));
    }

    // ── Gates 2–3 together ───────────────────────────────────────────────────

    public function test_an_unverified_address_is_refused_even_on_an_allowlisted_domain(): void
    {
        $this->allowDomain('acme.com');
        $this->enableJit();

        $result = $this->provision('auth0|new', 'person@acme.com', 'false');

        $this->assertNull($result['user']);
        $this->assertSame(JitProvisioner::REASON_UNVERIFIED, $result['reason']);
    }

    public function test_a_domain_off_the_list_is_refused_even_when_verified(): void
    {
        $this->allowDomain('acme.com');
        $this->enableJit();

        $result = $this->provision('auth0|new', 'person@elsewhere.test', true);

        $this->assertNull($result['user']);
        $this->assertSame(JitProvisioner::REASON_DOMAIN, $result['reason']);
    }

    // ── The provision itself ─────────────────────────────────────────────────

    public function test_an_allowlisted_verified_address_is_provisioned_as_a_viewer(): void
    {
        $this->allowDomain('acme.com');
        $this->enableJit();

        $result = $this->provision('auth0|new', 'person@acme.com', true);

        $this->assertInstanceOf(User::class, $result['user']);
        $this->assertNull($result['reason']);

        $user = $result['user'];

        $this->assertSame('auth0|new', $user->auth0_subject);
        // The site role stays at the least privilege; the membership is the grant.
        $this->assertSame('subscriber', (string) $user->role);

        $membership = DB::table('tenant_user')->where('user_id', $user->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame(JitProvisioner::ROLE, $membership->role);
        $this->assertSame(Tenant::DEFAULT_ID, $membership->tenant_id);

        // Provisioned, so nothing is waiting for a person.
        $this->assertSame(0, DB::table('nvoos_jit_provisioning_requests')->count());
    }

    public function test_an_existing_account_is_never_attached_to_a_subject(): void
    {
        $this->allowDomain('acme.com');
        $this->enableJit();

        // Somebody already has this address — created by another route, with no
        // Auth0 link. Attaching would be the account-takeover primitive 156 D4
        // forbids, and creating a second row is impossible (users.email is unique).
        $existing = User::factory()->create(array('email' => 'person@acme.com'));

        // The factory joins the default estate by design, so "unchanged" has to be
        // measured rather than assumed: the attempt must add no membership and
        // move none.
        $before = DB::table('tenant_user')->where('user_id', $existing->id)->get();

        $result = $this->provision('auth0|attacker', 'person@acme.com', true);

        $this->assertNull($result['user']);
        $this->assertSame(JitProvisioner::REASON_ACCOUNT_EXISTS, $result['reason']);

        $this->assertNull($existing->fresh()->auth0_subject);
        $this->assertSame(1, User::query()->where('email', 'person@acme.com')->count());
        $this->assertEquals($before, DB::table('tenant_user')->where('user_id', $existing->id)->get());
    }

    public function test_repeat_attempts_collapse_into_one_request(): void
    {
        foreach (array(1, 2, 3) as $ignored) {
            $this->provision('auth0|repeat', 'person@acme.com', true);
        }

        $this->assertSame(1, DB::table('nvoos_jit_provisioning_requests')->count());
    }

    public function test_approving_creates_the_account_and_closes_the_request(): void
    {
        $this->provision('auth0|new', 'person@acme.com', true);

        $id = (int) DB::table('nvoos_jit_provisioning_requests')->value('id');
        $result = app(JitProvisioner::class)->approve(Tenant::DEFAULT_ID, $id);

        $this->assertTrue($result['ok']);

        $user = User::query()->where('email', 'person@acme.com')->firstOrFail();
        $this->assertSame('auth0|new', $user->auth0_subject);
        $this->assertSame(
            JitProvisioner::ROLE,
            DB::table('tenant_user')->where('user_id', $user->id)->value('role'),
        );

        $this->assertSame('approved', DB::table('nvoos_jit_provisioning_requests')->value('status'));

        // Not pending any more, so a second approval is a no-op rather than a
        // second account.
        $this->assertFalse(app(JitProvisioner::class)->approve(Tenant::DEFAULT_ID, $id)['ok']);
    }

    // ── The surface ──────────────────────────────────────────────────────────

    public function test_the_page_requires_the_tenant_panel(): void
    {
        $this->actingAs(User::factory()->create(array('role' => 'subscriber')));

        $this->assertFalse(AccessRequests::canAccess());
    }

    public function test_the_page_lists_only_this_estates_requests(): void
    {
        $this->estate('estate-b');

        DB::table('nvoos_jit_provisioning_requests')->insert(array(
            array('tenant_id' => Tenant::DEFAULT_ID, 'subject' => 'auth0|mine', 'email' => 'mine@acme.com', 'reason' => 'jit_disabled', 'status' => 'pending', 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()),
            array('tenant_id' => 'estate-b', 'subject' => 'auth0|theirs', 'email' => 'theirs@acme.com', 'reason' => 'jit_disabled', 'status' => 'pending', 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()),
        ));

        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(AccessRequests::class)
            ->assertSee('mine@acme.com')
            ->assertDontSee('theirs@acme.com');
    }

    public function test_approving_from_the_page_writes_an_audit_row(): void
    {
        $this->actingAsEstate(Tenant::DEFAULT_ID);

        DB::table('nvoos_jit_provisioning_requests')->insert(array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'subject' => 'auth0|new',
            'email' => 'person@acme.com',
            'reason' => 'jit_disabled',
            'status' => 'pending',
            'attempted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ));

        $id = (int) DB::table('nvoos_jit_provisioning_requests')->value('id');

        Livewire::test(AccessRequests::class)->call('approve', $id);

        // Livewire traffic is excluded from the request middleware (§25), so this
        // row exists only because the page called recordResource() itself.
        $this->assertSame(
            1,
            DB::table('nvoos_admin_audit')
                ->where('action', 'like', '%approve%')
                ->count(),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{user: User|null, reason: string|null}
     */
    private function provision(string $subject, string $email, mixed $verified): array
    {
        return app(JitProvisioner::class)->provision(Tenant::DEFAULT_ID, $subject, $email, $verified);
    }

    private function enableJit(bool $on = true): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        app(SettingsService::class)->write(
            array('enable_jit_provisioning' => $on),
            SettingsService::SURFACE_ADMIN
        );

        TenantContext::forget();
    }

    private function allowDomain(string $domain, bool $verified = true): void
    {
        $domains = app(EstateDomains::class);
        $domains->add(Tenant::DEFAULT_ID, $domain);

        if ($verified) {
            $id = (int) DB::table('nvoos_estate_domains')->where('domain', $domain)->value('id');
            $domains->setVerified(Tenant::DEFAULT_ID, $id, true);
        }
    }

    private function actingAsEstate(string $tenantId): void
    {
        $tenant = $this->estate($tenantId);
        $user = User::factory()->admin()->create();

        $tenant->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        $this->actingAs($user);
        Filament::setTenant($tenant);
        TenantContext::set($tenantId);
    }

    private function estate(string $tenantId): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array('name' => 'Estate '.$tenantId, 'slug' => $tenantId, 'is_operator' => false)
        );
    }
}

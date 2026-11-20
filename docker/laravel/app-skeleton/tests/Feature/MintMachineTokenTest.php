<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Minting a machine credential — Proposal 159 slice 3.
 *
 * The rules under test are 159 §6's: an explicit scope, an owner who can pass
 * the routes the scope is for, an assistant scope that lives in the issuing
 * estate, an audit row, and expiry by default.
 *
 * The end-to-end case is the one that matters most. A minting command that
 * prints a token the runtime then refuses is worse than no command at all, so
 * one test takes the printed token and spends it on the real catalogue route.
 */
final class MintMachineTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_mints_a_token_that_reaches_the_route_its_scope_names(): void
    {
        $owner = $this->owner();

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'ci-catalogue',
            '--scope' => array('estate:assistants.list'),
            '--user' => (string) $owner->getKey(),
        ));
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);

        $token = $this->tokenFrom($output);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/assistants')
            ->assertOk();

        // The scope is enforced at the route, so a different one is still refused.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/tools')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'principal_scope_required');
    }

    public function test_the_stored_token_carries_the_scope_and_the_default_expiry(): void
    {
        $owner = $this->owner();

        $this->mint(array('--scope' => array('estate:assistants.list'), '--user' => (string) $owner->getKey()));

        $row = DB::table('personal_access_tokens')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame(array('estate:assistants.list'), json_decode($row->abilities, true));
        $this->assertNotNull($row->expires_at);
        $this->assertTrue(
            Carbon::parse($row->expires_at)->between(Carbon::now()->addDays(89), Carbon::now()->addDays(91)),
            'The default expiry should be 90 days.'
        );
    }

    public function test_it_records_the_grant_in_the_audit_log(): void
    {
        $owner = $this->owner();

        $this->mint(array('--scope' => array('estate:assistants.list'), '--user' => (string) $owner->getKey()));

        $id = (string) DB::table('personal_access_tokens')->latest('id')->value('id');

        $row = DB::table('nvoos_admin_audit')
            ->where('resource', 'machine_token')
            ->where('record_id', $id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($row, 'Minting must leave an audit row naming the credential.');
        $this->assertSame(Tenant::DEFAULT_ID, $row->tenant_id);
        $this->assertStringContainsString('estate:assistants.list', (string) $row->after);
        $this->assertStringContainsString('console', (string) $row->after);
    }

    public function test_days_zero_means_no_expiry(): void
    {
        $owner = $this->owner();

        $this->mint(array(
            '--scope' => array('estate:assistants.list'),
            '--user' => (string) $owner->getKey(),
            '--days' => '0',
        ));

        $this->assertNull(DB::table('personal_access_tokens')->latest('id')->value('expires_at'));
    }

    public function test_it_refuses_a_star_scope(): void
    {
        $owner = $this->owner();

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'bad',
            '--scope' => array('*'),
            '--user' => (string) $owner->getKey(),
        ));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a scope this runtime understands', Artisan::output());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_it_refuses_an_unknown_scope(): void
    {
        $owner = $this->owner();

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'bad',
            '--scope' => array('assistant:abc'),
            '--user' => (string) $owner->getKey(),
        ));

        $this->assertSame(1, $code);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_it_refuses_an_empty_scope_list(): void
    {
        $owner = $this->owner();

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'bad',
            '--user' => (string) $owner->getKey(),
        ));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('never unscoped', Artisan::output());
    }

    public function test_it_refuses_an_assistant_scope_from_another_estate(): void
    {
        $owner = $this->owner();
        $foreign = $this->assistantIn('nvoos');

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'bad',
            '--scope' => array('assistant:' . $foreign->getKey()),
            '--user' => (string) $owner->getKey(),
        ));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No assistant', Artisan::output());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_it_refuses_an_owner_without_manage_options(): void
    {
        $user = User::factory()->role('subscriber')->withoutEstate()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'member')]);

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'bad',
            '--scope' => array('estate:assistants.list'),
            '--user' => (string) $user->getKey(),
        ));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('manage_options', Artisan::output());
    }

    public function test_it_refuses_a_non_member_owner(): void
    {
        $user = User::factory()->admin()->withoutEstate()->create();
        Tenant::query()->findOrFail('nvoos')
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'bad',
            '--scope' => array('estate:assistants.list'),
            '--user' => (string) $user->getKey(),
        ));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a member of estate', Artisan::output());
    }

    /**
     * An estate administrator who is a member of the default estate only.
     */
    private function owner(): User
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    private function assistantIn(string $tenantId): Assistant
    {
        TenantContext::set($tenantId);

        $assistant = app(AssistantWriter::class)->create(array('title' => 'Mint fixture'));

        TenantContext::forget();

        return $assistant;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function mint(array $options): string
    {
        $code = Artisan::call('nvoos:machine-token', $options + array('--name' => 'probe'));
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);

        return $this->tokenFrom($output);
    }

    private function tokenFrom(string $output): string
    {
        $matched = preg_match('/^\s*(\d+\|[A-Za-z0-9]{20,})\s*$/m', $output, $matches);

        $this->assertSame(1, $matched, 'No token in the command output: ' . $output);

        return $matches[1];
    }
}

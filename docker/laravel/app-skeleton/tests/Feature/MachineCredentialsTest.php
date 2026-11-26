<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\MachineCredentials;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/ops/machine-credentials` — Proposal 149 §129.
 *
 * The mint command has always told the operator to revoke "in /ops", and the
 * inventory it named only lists `oos-personal:` tokens. These tests pin the
 * promise: the page lists machine tokens and nothing else, revocation deletes
 * the row (which is the whole revocation under Sanctum), it refuses an id that
 * is not a machine token, and minting stores the prefix the page reads.
 */
final class MachineCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(array('sanctum.stateful' => array('localhost', '127.0.0.1')));
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_machine_tokens_and_not_personal_ones(): void
    {
        $owner = $this->owner();
        $owner->createToken('oos-machine:ci-catalogue', array('assistant:1'), Carbon::now()->addDays(30));
        $owner->createToken('oos-personal:notes', array('*'));

        $this->actingAs($this->operator())
            ->get('/ops/machine-credentials')
            ->assertOk()
            ->assertSee('ci-catalogue')
            ->assertDontSee('notes');
    }

    public function test_revoke_deletes_the_token_and_names_it_in_the_audit(): void
    {
        $owner = $this->owner();
        $token = $owner->createToken('oos-machine:ci', array('assistant:1'), Carbon::now()->addDays(30));

        $this->actingAs($this->operator());

        Livewire::test(MachineCredentials::class)->call('revoke', (int) $token->accessToken->getKey());

        $this->assertSame(0, DB::table('personal_access_tokens')->where('name', 'oos-machine:ci')->count());

        $row = DB::table('nvoos_admin_audit')->where('resource', 'machine_token')->latest('id')->first();

        $this->assertNotNull($row, 'Revocation must leave a record.');
        $this->assertSame('revoked', (string) $row->action);
    }

    public function test_revoke_refuses_a_token_that_is_not_a_machine_token(): void
    {
        $owner = $this->owner();
        $personal = $owner->createToken('oos-personal:mine', array('*'));

        $this->actingAs($this->operator());

        Livewire::test(MachineCredentials::class)->call('revoke', (int) $personal->accessToken->getKey());

        $this->assertSame(1, DB::table('personal_access_tokens')->where('name', 'oos-personal:mine')->count());
    }

    public function test_minting_stores_the_prefix_the_page_reads(): void
    {
        $owner = $this->owner();

        $code = Artisan::call('nvoos:machine-token', array(
            '--name' => 'ci-catalogue',
            '--scope' => array('estate:assistants.list'),
            '--user' => (string) $owner->getKey(),
        ));

        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->where('name', 'oos-machine:ci-catalogue')->count(),
            'A minted machine token must carry the prefix the page lists.'
        );
    }

    public function test_an_estate_administrator_cannot_reach_the_page(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/ops/machine-credentials')
            ->assertForbidden();
    }

    private function owner(): User
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::OPERATOR_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }
}

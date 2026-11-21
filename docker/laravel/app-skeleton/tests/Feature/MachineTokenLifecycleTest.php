<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Rotation and revocation — Proposal 159 slice 3.
 *
 * The distinction under test is the one the guidance insists on: rotation keeps
 * the old credential valid for a grace window (zero downtime), while revocation
 * deletes it immediately (a leak). A test that only checked "the old token stops
 * working" after a rotation would be testing revocation under another name.
 */
final class MachineTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(array('api', 'auth:sanctum', 'scope:estate:assistants.list'))
            ->get('/api/v1/_probe/lifecycle', static fn () => response()->json(array('ok' => true)));
    }

    public function test_rotation_keeps_the_old_token_working_for_the_grace_window(): void
    {
        [$id, $plain] = $this->machineToken();

        $code = Artisan::call('nvoos:machine-token:rotate', array(
            'id' => (string) $id,
            '--grace' => '60',
            '--days' => '30',
        ));
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);

        $replacement = $this->tokenFrom($output);

        // Both credentials work during the overlap — that is the point of a grace
        // window, and the reason rotation cannot double as revocation.
        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/_probe/lifecycle')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $replacement)
            ->getJson('/api/v1/_probe/lifecycle')
            ->assertOk();

        $deadline = Carbon::parse(DB::table('personal_access_tokens')->where('id', $id)->value('expires_at'));

        $this->assertTrue(
            $deadline->between(Carbon::now()->addMinutes(58), Carbon::now()->addMinutes(62)),
            'The old token should expire with the grace window.'
        );
    }

    public function test_rotation_prefixes_a_legacy_name_and_keeps_the_owner_and_scopes(): void
    {
        [$id] = $this->machineToken();
        $before = DB::table('personal_access_tokens')->where('id', $id)->first();

        $this->assertSame(0, Artisan::call('nvoos:machine-token:rotate', array('id' => (string) $id)));

        $new = DB::table('personal_access_tokens')
            ->where('id', '!=', $id)
            ->latest('id')
            ->first();

        $this->assertNotNull($new);
        $this->assertSame('oos-machine:'.$before->name, $new->name);
        $this->assertSame($before->tokenable_id, $new->tokenable_id);
        $this->assertSame(json_decode($before->abilities, true), json_decode($new->abilities, true));
    }

    public function test_rotation_keeps_an_already_prefixed_name(): void
    {
        $owner = $this->owner();
        $token = $owner->createToken('oos-machine:ci', array('estate:assistants.list'), Carbon::now()->addDays(90));
        $id = (int) $token->accessToken->getKey();

        $this->assertSame(0, Artisan::call('nvoos:machine-token:rotate', array('id' => (string) $id)));

        $new = DB::table('personal_access_tokens')
            ->where('id', '!=', $id)
            ->latest('id')
            ->first();

        $this->assertNotNull($new);
        $this->assertSame('oos-machine:ci', $new->name);
    }

    public function test_zero_grace_is_revocation(): void
    {
        [$id, $plain] = $this->machineToken();

        $this->assertSame(0, Artisan::call('nvoos:machine-token:rotate', array(
            'id' => (string) $id,
            '--grace' => '0',
        )));

        $this->assertNull(DB::table('personal_access_tokens')->where('id', $id)->first());

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/_probe/lifecycle')
            ->assertUnauthorized();
    }

    public function test_rotation_refuses_a_user_credential(): void
    {
        $user = $this->owner();
        $id = (int) $user->createToken('personal', array('*'))->accessToken->getKey();
        $count = DB::table('personal_access_tokens')->count();

        $code = Artisan::call('nvoos:machine-token:rotate', array('id' => (string) $id));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not a machine credential', Artisan::output());
        $this->assertSame($count, DB::table('personal_access_tokens')->count());
    }

    public function test_rotation_refuses_a_permanent_replacement(): void
    {
        [$id] = $this->machineToken();

        $code = Artisan::call('nvoos:machine-token:rotate', array('id' => (string) $id, '--days' => '0'));

        $this->assertSame(1, $code);
        $this->assertStringContainsString('at least 1', Artisan::output());
    }

    public function test_revocation_deletes_the_token_immediately(): void
    {
        [$id, $plain] = $this->machineToken();

        $code = Artisan::call('nvoos:machine-token:revoke', array('id' => (string) $id));

        $this->assertSame(0, $code, Artisan::output());
        $this->assertNull(DB::table('personal_access_tokens')->where('id', $id)->first());

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/_probe/lifecycle')
            ->assertUnauthorized();
    }

    public function test_both_actions_write_an_audit_row(): void
    {
        [$id] = $this->machineToken();

        Artisan::call('nvoos:machine-token:rotate', array('id' => (string) $id, '--grace' => '30'));
        $newId = (string) DB::table('personal_access_tokens')->latest('id')->value('id');

        $this->assertDatabaseHas('nvoos_admin_audit', array(
            'resource' => 'machine_token',
            'record_id' => $newId,
            'action' => 'rotated',
        ));

        Artisan::call('nvoos:machine-token:revoke', array('id' => $newId));

        $this->assertDatabaseHas('nvoos_admin_audit', array(
            'resource' => 'machine_token',
            'record_id' => $newId,
            'action' => 'revoked',
        ));
    }

    /**
     * @return array{0:int, 1:string}
     */
    private function machineToken(): array
    {
        $token = $this->owner()->createToken('lifecycle', array('estate:assistants.list'), Carbon::now()->addDays(90));

        return array((int) $token->accessToken->getKey(), $token->plainTextToken);
    }

    private function owner(): User
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    private function tokenFrom(string $output): string
    {
        $matched = preg_match('/^\s*(\d+\|[A-Za-z0-9]{20,})\s*$/m', $output, $matches);

        $this->assertSame(1, $matched, 'No token in the command output: ' . $output);

        return $matches[1];
    }
}

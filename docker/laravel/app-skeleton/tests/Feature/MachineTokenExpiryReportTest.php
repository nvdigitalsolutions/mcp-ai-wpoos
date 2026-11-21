<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The expiry report — Proposal 159 slice 3.
 *
 * It exists because automatic rotation is impossible for a credential that is
 * shown once: nothing can deliver the replacement. So the job is to say what is
 * about to lapse, and the exit code is the machine-readable half of that.
 */
final class MachineTokenExpiryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_token_expiring_inside_the_window(): void
    {
        $user = $this->owner();
        $user->createToken('soon', array('estate:assistants.list'), Carbon::now()->addDays(5));

        $code = Artisan::call('nvoos:machine-token:expiring', array('--days' => '14'));
        $output = Artisan::output();

        $this->assertSame(1, $code, 'A token to rotate must make the command fail loudly.');
        $this->assertStringContainsString('soon', $output);
        $this->assertStringContainsString('estate:assistants.list', $output);
    }

    public function test_it_ignores_a_token_beyond_the_window(): void
    {
        $user = $this->owner();
        $user->createToken('later', array('estate:assistants.list'), Carbon::now()->addDays(60));

        $code = Artisan::call('nvoos:machine-token:expiring', array('--days' => '14'));

        $this->assertSame(0, $code, Artisan::output());
        $this->assertStringNotContainsString('later', Artisan::output());
    }

    public function test_it_ignores_a_user_credential(): void
    {
        $user = $this->owner();
        $user->createToken('personal', array('*'), Carbon::now()->addDays(2));

        $code = Artisan::call('nvoos:machine-token:expiring');

        $this->assertSame(0, $code, Artisan::output());
        $this->assertStringNotContainsString('personal', Artisan::output());
    }

    public function test_it_ignores_a_credential_given_nothing(): void
    {
        $user = $this->owner();
        $user->createToken('empty', array(), Carbon::now()->addDays(2));

        $this->assertSame(0, Artisan::call('nvoos:machine-token:expiring'), Artisan::output());
    }

    public function test_all_also_reports_permanent_credentials(): void
    {
        $user = $this->owner();
        $user->createToken('forever', array('estate:assistants.list'));

        $code = Artisan::call('nvoos:machine-token:expiring', array('--all' => true));
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('never expire', $output);
    }

    public function test_a_bad_window_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('nvoos:machine-token:expiring', array('--days' => '0')));
    }

    private function owner(): User
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }
}

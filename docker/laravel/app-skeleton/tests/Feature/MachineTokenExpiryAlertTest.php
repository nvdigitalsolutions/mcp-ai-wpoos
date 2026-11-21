<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\MachineCredentials;
use App\Mail\MachineTokensExpiring;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The expiry report's delivery channel — Proposal 149 §130.
 *
 * §125.3's finding was that the exit code is "a contract nothing consumes".
 * These tests pin the three properties that make consuming it useful:
 *
 *  - the finding is **recorded**, so a lost mail is recoverable;
 *  - it is **delivered** only to a configured address, and configuration is not
 *    editable from a request;
 *  - it is **deduplicated** for 24 hours on the same set, because an alert that
 *    repeats every morning about the same token is one people learn to ignore.
 */
final class MachineTokenExpiryAlertTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'ops@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(array('oos.machine_alerts.email' => self::ADDRESS));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_emails_the_finding_when_an_address_is_configured(): void
    {
        $this->token('ci-catalogue', 5);

        $code = Artisan::call('nvoos:machine-token:expiring');

        $this->assertSame(1, $code, Artisan::output());

        Mail::assertSent(MachineTokensExpiring::class, static function (MachineTokensExpiring $mail): bool {
            return $mail->hasTo(self::ADDRESS) && 1 === count($mail->rows);
        });
    }

    public function test_it_does_not_email_without_an_address(): void
    {
        config(array('oos.machine_alerts.email' => null));

        $this->token('ci-catalogue', 5);

        $code = Artisan::call('nvoos:machine-token:expiring');
        $output = Artisan::output();

        $this->assertSame(1, $code, 'The exit code is the contract; mail is delivery.');
        $this->assertStringContainsString('no alert address', $output);

        Mail::assertNothingSent();
    }

    public function test_it_records_the_finding_in_the_activity_feed(): void
    {
        $this->token('ci-catalogue', 5);

        Artisan::call('nvoos:machine-token:expiring');

        $row = DB::table('nvoos_activity_events')
            ->where('type', 'machine_token.expiring')
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'The finding must be recoverable without the mail.');

        $context = json_decode((string) $row->context, true);

        $this->assertIsArray($context);
        $this->assertSame(1, count($context['ids'] ?? array()));
        $this->assertNotEmpty($context['signature'] ?? null);
    }

    public function test_it_does_not_repeat_the_same_alert_within_a_day(): void
    {
        $this->token('ci-catalogue', 5);

        Artisan::call('nvoos:machine-token:expiring');
        $second = Artisan::call('nvoos:machine-token:expiring');
        $output = Artisan::output();

        $this->assertSame(1, $second);
        $this->assertStringContainsString('alert suppressed', $output);

        Mail::assertSent(MachineTokensExpiring::class, 1);
    }

    public function test_it_alerts_again_when_the_set_changes(): void
    {
        $this->token('ci-catalogue', 5);

        Artisan::call('nvoos:machine-token:expiring');

        $this->token('ci-deploy', 6);

        Artisan::call('nvoos:machine-token:expiring');

        Mail::assertSent(MachineTokensExpiring::class, 2);
    }

    public function test_it_alerts_again_after_the_dedupe_window(): void
    {
        $this->token('ci-catalogue', 5);

        Artisan::call('nvoos:machine-token:expiring');

        Carbon::setTestNow(Carbon::now()->addHours(25));

        Artisan::call('nvoos:machine-token:expiring');

        Mail::assertSent(MachineTokensExpiring::class, 2);
    }

    public function test_an_empty_window_alerts_nobody(): void
    {
        $code = Artisan::call('nvoos:machine-token:expiring');

        $this->assertSame(0, $code);

        Mail::assertNothingSent();

        $this->assertSame(
            0,
            DB::table('nvoos_activity_events')->where('type', 'machine_token.expiring')->count()
        );
    }

    public function test_it_ignores_a_personal_token_that_expires_soon(): void
    {
        $user = User::factory()->admin()->withoutEstate()->create();
        $user->createToken('oos-personal:notes', array('assistant:1'), Carbon::now()->addDays(5));

        $code = Artisan::call('nvoos:machine-token:expiring');

        $this->assertSame(0, $code, Artisan::output());
        Mail::assertNothingSent();
    }

    public function test_an_acknowledgement_silences_the_same_set(): void
    {
        $this->token('ci-catalogue', 10);

        Artisan::call('nvoos:machine-token:expiring');

        $this->actingAs($this->operator());
        Livewire::test(MachineCredentials::class)->call('acknowledgeExpiryAlerts');

        $code = Artisan::call('nvoos:machine-token:expiring');
        $output = Artisan::output();

        $this->assertSame(1, $code, 'The finding still exists; only the mail is silenced.');
        $this->assertStringContainsString('alert suppressed', $output);

        Mail::assertSent(MachineTokensExpiring::class, 1);
    }

    public function test_an_acknowledgement_ends_at_the_soonest_expiry(): void
    {
        $this->token('ci-catalogue', 2);

        Artisan::call('nvoos:machine-token:expiring');

        $this->actingAs($this->operator());
        Livewire::test(MachineCredentials::class)->call('acknowledgeExpiryAlerts');

        Carbon::setTestNow(Carbon::now()->addDays(3));

        Artisan::call('nvoos:machine-token:expiring');

        $this->assertGreaterThanOrEqual(
            2,
            Mail::sent(MachineTokensExpiring::class)->count(),
            'A credential that has passed its expiry must not stay silenced.'
        );
    }

    public function test_the_acknowledgement_records_its_note(): void
    {
        $this->token('ci-catalogue', 10);

        $this->actingAs($this->operator());
        Livewire::test(MachineCredentials::class)->call('acknowledgeExpiryAlerts', 14, 'Rotating on Thursday.');

        $row = DB::table('nvoos_activity_events')
            ->where('type', 'machine_token.expiry_acknowledged')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);

        $context = json_decode((string) $row->context, true);

        $this->assertSame('Rotating on Thursday.', $context['note']);
    }

    public function test_an_acknowledged_set_resolves_when_the_credentials_go(): void
    {
        $this->token('ci-catalogue', 10);

        Artisan::call('nvoos:machine-token:expiring');

        $this->actingAs($this->operator());
        Livewire::test(MachineCredentials::class)->call('acknowledgeExpiryAlerts');

        DB::table('personal_access_tokens')->where('name', 'ci-catalogue')->delete();

        Artisan::call('nvoos:machine-token:expiring');

        $row = DB::table('nvoos_activity_events')
            ->where('type', 'machine_token.expiry_resolved')
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'Acknowledged, then gone, must be recorded as resolved.');
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

    private function token(string $name, int $days): User
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        $user->createToken($name, array('assistant:1'), Carbon::now()->addDays($days));

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Mail\MachineTokensExpiring;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    private function token(string $name, int $days): User
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        $user->createToken($name, array('assistant:1'), Carbon::now()->addDays($days));

        return $user;
    }
}

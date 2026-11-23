<?php

namespace Tests\Feature;

use App\Support\Security\SecurityEventLogger;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proposal 149 §186 — the security-event stream.
 *
 * `enable_security_audit_log`, `log_successful_auth` and
 * `audit_log_retention_days` were writable on `/ops/settings` and read by
 * nothing. The master switch defaults **off**, so the assertions are as much
 * about "nothing happens by default" as about the rows themselves: an audit
 * stream that writes on every install is a privacy problem wearing a compliance
 * badge.
 */
final class SecurityEventLogTest extends TestCase
{
    use RefreshDatabase;

    private const PROTECTED = '/api/v1/chat-transcripts';

    protected function setUp(): void
    {
        parent::setUp();

        config(array('cache.default' => 'array'));
    }

    public function test_nothing_is_recorded_while_the_master_switch_is_off(): void
    {
        $this->write(array('enable_security_audit_log' => '0'));

        $this->getJson(self::PROTECTED)->assertStatus(401);

        $this->assertSame(0, DB::table('nvoos_security_events')->count());
    }

    public function test_a_failed_authentication_is_recorded_when_enabled(): void
    {
        $this->write(array('enable_security_audit_log' => '1'));

        $this->getJson(self::PROTECTED)->assertStatus(401);

        $row = DB::table('nvoos_security_events')->where('event', SecurityEventLogger::AUTH_FAILURE)->first();

        $this->assertNotNull($row, 'A 401 must produce auth.failure.');
        $this->assertSame('Authentication failed', $row->context);
        $this->assertNotNull($row->ip);
    }

    public function test_the_lockout_is_recorded(): void
    {
        $this->write(array(
            'enable_security_audit_log' => '1',
            'enable_auth_rate_limiting' => '1',
            'auth_rate_limit_threshold' => 3,
            'auth_rate_limit_window' => 300,
        ));

        for ($i = 0; $i < 3; $i++) {
            $this->getJson(self::PROTECTED)->assertStatus(401);
        }

        $this->getJson(self::PROTECTED)->assertStatus(429);

        $this->assertSame(
            1,
            DB::table('nvoos_security_events')->where('event', SecurityEventLogger::AUTH_LOCKOUT)->count(),
            'The block itself is an event: the field names IP blocks explicitly.'
        );
    }

    public function test_pruning_removes_rows_past_the_retention_window(): void
    {
        $this->write(array('audit_log_retention_days' => 30));

        $this->insertEvent('auth.failure', now()->subDays(40));
        $this->insertEvent('auth.failure', now()->subDay());

        $deleted = $this->app->make(SecurityEventLogger::class)->prune();

        $this->assertSame(1, $deleted);
        $this->assertSame(1, DB::table('nvoos_security_events')->count(), 'The recent row must survive.');
    }

    public function test_zero_retention_keeps_everything(): void
    {
        $this->write(array('audit_log_retention_days' => 0));

        $this->insertEvent('auth.failure', now()->subYears(5));

        $this->assertSame(0, $this->app->make(SecurityEventLogger::class)->prune());
        $this->assertSame(1, DB::table('nvoos_security_events')->count(), '0 means unlimited, not "delete all".');
    }

    public function test_successful_sign_ins_are_recorded_only_when_asked(): void
    {
        $logger = $this->app->make(SecurityEventLogger::class);
        $request = Request::create('/', 'GET');

        $this->write(array('enable_security_audit_log' => '1', 'log_successful_auth' => '0'));
        $logger->recordSuccess($request, 42);
        $this->assertSame(0, DB::table('nvoos_security_events')->count());

        $this->write(array('log_successful_auth' => '1'));
        $logger->recordSuccess($request, 42);

        $row = DB::table('nvoos_security_events')->where('event', SecurityEventLogger::AUTH_SUCCESS)->first();

        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row->user_id);
    }

    /**
     * The success path is a single call in each sign-in controller, so the wiring
     * is asserted here: deleting it would otherwise leave every other test green
     * while the control silently stopped working.
     */
    public function test_both_sign_in_paths_record_success(): void
    {
        foreach (array('SessionController.php', 'MagicLoginController.php') as $file) {
            $source = (string) file_get_contents(app_path('Http/Controllers/'.$file));

            $this->assertStringContainsString(
                'recordSuccess',
                $source,
                $file.' must record a successful sign-in; log_successful_auth depends on it.'
            );
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(array $values): void
    {
        $applied = $this->app->make(SettingsService::class)->write(
            $values,
            SettingsService::SURFACE_OPS
        );

        $this->assertSame(array_keys($values), $applied, 'The writer refused a key this test needs.');
    }

    private function insertEvent(string $event, \DateTimeInterface $at): void
    {
        DB::table('nvoos_security_events')->insert(array(
            'tenant_id' => null,
            'event' => $event,
            'user_id' => null,
            'ip' => '10.0.0.1',
            'context' => 'seeded',
            'data' => null,
            'created_at' => $at,
        ));
    }
}

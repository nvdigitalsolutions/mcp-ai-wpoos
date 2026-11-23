<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Security\RootKey;
use App\Support\Security\RootKeyGate;
use App\Support\Security\RootKeyInvalid;
use App\Support\Security\RootKeyRequired;
use App\Support\Security\SecurityEventLogger;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Proposal 149 §188 — the root security key as step-up verification.
 *
 * The field calls the key "a second password for high-risk actions" and requires
 * 32 characters. OWASP ASVS 6.4 asks for re-authentication before sensitive
 * operations, and NIST SP 800-63B-4 asks for the full secret rather than selected
 * characters — both are pinned here: the whole key is verified, and the proof
 * expires.
 *
 * The gate lives in `SettingsService::write()` for the `ops` surface because that
 * is the one chokepoint every operator write passes through; Livewire actions
 * never reach route middleware.
 */
final class RootKeyGateTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-very-long-root-security-key-of-64-characters-or-more-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config(array('cache.default' => 'array'));
    }

    public function test_writes_apply_while_the_control_is_off(): void
    {
        $applied = $this->settings()->write(array('enable_logging' => '1'), SettingsService::SURFACE_OPS);

        $this->assertSame(array('enable_logging'), $applied);
    }

    public function test_the_gate_cannot_be_armed_without_a_key(): void
    {
        // Arming with no key would refuse every later write, with no way back
        // except the CLI. The control protects the operator; it must not be a
        // lock with no handle.
        $this->expectException(RootKeyInvalid::class);

        $this->settings()->write(array('enable_root_security_key' => '1'), SettingsService::SURFACE_OPS);
    }

    public function test_arming_is_refused_when_the_password_field_submits_empty(): void
    {
        // What the form actually sends when the operator ticks the box and leaves
        // the key field untouched: empty means "unchanged", which is no key.
        $this->expectException(RootKeyInvalid::class);

        $this->settings()->write(array(
            'enable_root_security_key' => '1',
            'root_security_key' => '',
        ), SettingsService::SURFACE_OPS);
    }

    public function test_disabling_clears_the_key_and_turns_the_gate_off(): void
    {
        $this->install();

        $key = $this->app->make(RootKey::class);
        $this->assertTrue($key->enabled());
        $this->assertTrue($key->configured());

        $key->disable();

        // Both halves, or "disabled" would mean "refuses every write": the gate
        // fails closed, so a flag left on with no key is a lockout.
        $this->assertFalse($key->enabled());
        $this->assertFalse($key->configured());

        $this->assertSame(
            array('enable_logging'),
            $this->settings()->write(array('enable_logging' => '1'), SettingsService::SURFACE_OPS)
        );
    }

    public function test_the_first_key_may_be_stored_from_the_panel(): void
    {
        $applied = $this->settings()->write(array(
            'enable_root_security_key' => '1',
            'root_security_key' => self::SECRET,
        ), SettingsService::SURFACE_OPS);

        $this->assertSame(array('enable_root_security_key', 'root_security_key'), $applied);
        $this->assertTrue($this->app->make(RootKey::class)->verify(self::SECRET));
    }

    public function test_the_bootstrap_exemption_writes_only_the_credential(): void
    {
        // The first key may not act as a hall pass: a field submitted in the same
        // payload is refused, not written on the strength of the key beside it.
        $applied = $this->settings()->write(array(
            'enable_root_security_key' => '1',
            'root_security_key' => self::SECRET,
            'enable_logging' => '1',
        ), SettingsService::SURFACE_OPS);

        $this->assertSame(array('enable_root_security_key', 'root_security_key'), $applied);
    }

    public function test_a_configured_key_requires_proof_for_later_writes(): void
    {
        $this->install();

        $this->expectException(RootKeyRequired::class);

        $this->settings()->write(array('enable_logging' => '1'), SettingsService::SURFACE_OPS);
    }

    public function test_a_header_proof_allows_the_write(): void
    {
        $this->install();

        $request = Request::create('/', 'POST');
        $request->headers->set(RootKeyGate::HEADER, self::SECRET);
        $this->app->instance('request', $request);

        $applied = $this->settings()->write(array('enable_logging' => '1'), SettingsService::SURFACE_OPS);

        $this->assertSame(array('enable_logging'), $applied);
    }

    public function test_a_session_grant_allows_the_write_until_it_expires(): void
    {
        $this->install();

        $request = Request::create('/', 'POST');
        $request->setLaravelSession($this->app->make('session.store'));

        $gate = $this->app->make(RootKeyGate::class);

        $this->assertTrue($gate->attempt($request, self::SECRET));
        $this->app->instance('request', $request);

        $this->assertSame(
            array('enable_logging'),
            $this->settings()->write(array('enable_logging' => '1'), SettingsService::SURFACE_OPS)
        );

        // Move the grant into the past: the proof has expired and the gate closes.
        $request->session()->put(RootKeyGate::SESSION_KEY, now()->subMinute()->getTimestamp());

        $this->expectException(RootKeyRequired::class);

        $this->settings()->write(array('enable_logging' => '1'), SettingsService::SURFACE_OPS);
    }

    public function test_the_key_is_stored_as_a_hash(): void
    {
        $this->install();

        $stored = (string) $this->app->make('nvoos.settings.platform')->get(RootKey::STORAGE_KEY, '');

        $this->assertNotSame(self::SECRET, $stored, 'The secret must never be readable at rest.');
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertTrue(Hash::check(self::SECRET, $stored));
    }

    public function test_a_key_shorter_than_the_field_minimum_is_rejected(): void
    {
        $this->expectException(RootKeyInvalid::class);

        $this->app->make(RootKey::class)->set('too-short');
    }

    public function test_a_legacy_plaintext_key_verifies_and_is_upgraded(): void
    {
        // A value written before this class existed, or by an import.
        $this->app->make('nvoos.settings.platform')->set(RootKey::STORAGE_KEY, self::SECRET);

        $key = $this->app->make(RootKey::class);

        $this->assertTrue($key->verify(self::SECRET));

        $stored = (string) $this->app->make('nvoos.settings.platform')->get(RootKey::STORAGE_KEY, '');

        $this->assertNotSame(self::SECRET, $stored, 'A verified legacy value must be re-hashed in place.');
        $this->assertTrue(Hash::check(self::SECRET, $stored));
    }

    public function test_an_empty_candidate_never_matches(): void
    {
        $this->app->make('nvoos.settings.platform')->set(RootKey::STORAGE_KEY, '');

        $this->assertFalse($this->app->make(RootKey::class)->verify(''));
    }

    public function test_a_rejected_attempt_is_audited(): void
    {
        // Auditing must be on before the key is installed: once the gate is armed,
        // even this write would need a proof.
        $this->settings()->write(array('enable_security_audit_log' => '1'), SettingsService::SURFACE_OPS);
        $this->install();

        $request = Request::create('/', 'POST');
        $request->setLaravelSession($this->app->make('session.store'));

        $this->assertFalse($this->app->make(RootKeyGate::class)->attempt($request, 'wrong-key'));

        $this->assertSame(
            1,
            DB::table('nvoos_security_events')->where('event', SecurityEventLogger::ROOT_KEY_DENIED)->count()
        );
    }

    public function test_the_command_reports_and_disables(): void
    {
        // The break-glass path is the safety argument for the whole mechanism:
        // it has to be reachable and it has to leave the panel usable.
        $this->install();

        $this->artisan('nvoos:root-key', array('--status' => true))
            ->expectsOutput('Configured: yes')
            ->expectsOutput('Gate enabled: yes')
            ->assertSuccessful();

        $this->artisan('nvoos:root-key', array('--disable' => true))
            ->assertSuccessful();

        $this->assertFalse($this->app->make(RootKey::class)->enabled());
        $this->assertFalse($this->app->make(RootKey::class)->configured());
    }

    public function test_the_unlock_endpoint_accepts_the_key_and_reports_state(): void
    {
        $this->install();
        $this->actingAsOperator();

        $this->postJson('/api/v1/ops/root-key/unlock', array('key' => self::SECRET))
            ->assertOk()
            ->assertJsonPath('unlocked', true);

        $this->getJson('/api/v1/ops/root-key')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('configured', true);
    }

    public function test_the_unlock_endpoint_rejects_a_wrong_key(): void
    {
        $this->install();
        $this->actingAsOperator();

        $this->postJson('/api/v1/ops/root-key/unlock', array('key' => 'not-the-key'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'root_key_invalid');
    }

    /**
     * Enable the control and store a key, in the order the panel would: the flag
     * first (allowed because no key exists yet), then the key (the bootstrap
     * exemption).
     */
    private function install(): void
    {
        $this->settings()->write(array(
            'enable_root_security_key' => '1',
            'root_security_key' => self::SECRET,
        ), SettingsService::SURFACE_OPS);
    }

    private function settings(): SettingsService
    {
        return $this->app->make(SettingsService::class);
    }

    private function actingAsOperator(): void
    {
        Tenant::query()->firstOrCreate(
            array('id' => Tenant::DEFAULT_ID),
            array(
                'name' => 'Estate '.Tenant::DEFAULT_ID,
                'slug' => Tenant::DEFAULT_ID,
                'is_operator' => false,
            )
        );

        $this->actingAs(User::factory()->admin()->create());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Security\InputGuard;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Proposal 149 §183.3 — the input guard must read the controls the admin writes.
 *
 * The guard used to be built from `config( 'oos.security' )` alone. That package
 * config ships both controls **on** (`NVOOS_PROMPT_INJECTION_DETECTOR`,
 * `NVOOS_PII_FILTER`), which is why the defect was quiet rather than loud: the
 * runtime was protected, but the four controls on `/admin/settings` were inert in
 * *both* directions. An operator who disabled the PII filter for a privacy reason
 * still got redaction, and one who set the mode to `block` still got `log`.
 *
 * This suite pins the join:
 *
 *  - the config defaults still build a guard (unchanged behaviour);
 *  - a stored `false` turns a config-enabled control **off** (the fix);
 *  - a stored `true` turns a config-disabled control **on** (the other direction);
 *  - a stored mode wins over the config default;
 *  - an unrecognised mode degrades to `log`, never to something that blocks.
 *
 * The last one is not hypothetical. `InputGuard::inspect()` treats anything that
 * is not `log` as blocking-capable, so an unvalidated stored value would let a
 * typo escalate enforcement. The settings writer validates the select, but a row
 * can also arrive by import, migration or direct write — so the guard normalises
 * on its own rather than trusting the write path.
 *
 * `ChatOrchestrator` has no guard getter, so the property is read by reflection.
 * Adding a public accessor to core for one test would widen the API without a
 * consumer; the reflection is contained here and breaks loudly if it is renamed.
 */
final class InputGuardSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_config_defaults_still_build_a_guard(): void
    {
        $this->actingAsEstate();

        $guard = $this->guard();

        $this->assertInstanceOf(InputGuard::class, $guard, 'The package defaults enable both controls.');
        $this->assertSame(InputGuard::MODE_LOG, $guard->mode());
    }

    public function test_a_stored_false_turns_a_config_enabled_control_off(): void
    {
        $this->actingAsEstate();

        // The deployment default is on. The estate says no.
        $this->settings()->write(
            array(
                'enable_pii_filter' => '0',
                'enable_prompt_injection_detector' => '0',
            ),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertNull(
            $this->guard(),
            'A stored false must beat the config default — before §183 the admin could not switch these off.'
        );
    }

    public function test_a_stored_true_enables_a_config_disabled_control(): void
    {
        $this->actingAsEstate();

        config(array('oos.security' => array('pii_filter' => false, 'prompt_injection_detector' => false)));

        $this->settings()->write(
            array('enable_pii_filter' => '1'),
            SettingsService::SURFACE_ADMIN
        );

        $guard = $this->guard();

        $this->assertInstanceOf(InputGuard::class, $guard, 'The admin must be able to enable what config disables.');

        $result = $guard->redact('call me on 4111-1111-1111-1111');

        $this->assertGreaterThan(0, $result['redactions'], 'The stored flag should make redact() actually scrub.');
    }

    public function test_a_stored_mode_wins_over_the_config_default(): void
    {
        $this->actingAsEstate();

        $this->settings()->write(
            array('prompt_injection_mode' => 'block'),
            SettingsService::SURFACE_ADMIN
        );

        $guard = $this->guard();

        $this->assertInstanceOf(InputGuard::class, $guard);
        $this->assertSame(InputGuard::MODE_BLOCK, $guard->mode(), 'The stored mode must win over config log.');
    }

    public function test_an_unrecognised_mode_degrades_to_log(): void
    {
        $this->actingAsEstate();

        // Bypass the writer's select validation on purpose: the guard has to hold
        // even when a row arrives by import, migration or a direct write.
        DB::table('nvoos_tenant_settings')->insert(array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'key' => 'prompt_injection_mode',
            'value' => 'panic',
            'created_at' => now(),
            'updated_at' => now(),
        ));

        $guard = $this->guard();

        $this->assertInstanceOf(InputGuard::class, $guard);
        $this->assertSame(
            InputGuard::MODE_LOG,
            $guard->mode(),
            'An unknown mode must fail to log, never to a blocking mode.'
        );
    }

    private function guard(): ?InputGuard
    {
        $orchestrator = $this->app->make(ChatOrchestrator::class);

        $property = new ReflectionProperty($orchestrator, 'inputGuard');
        $property->setAccessible(true);

        $guard = $property->getValue($orchestrator);

        return $guard instanceof InputGuard ? $guard : null;
    }

    private function settings(): SettingsService
    {
        return $this->app->make(SettingsService::class);
    }

    private function actingAsEstate(): void
    {
        Tenant::query()->firstOrCreate(
            array('id' => Tenant::DEFAULT_ID),
            array(
                'name' => 'Estate '.Tenant::DEFAULT_ID,
                'slug' => Tenant::DEFAULT_ID,
                'is_operator' => false,
            )
        );

        // The store resolves the estate from the request context; setting it here
        // is what makes a stored row visible to the guard.
        TenantContext::set(Tenant::DEFAULT_ID);
    }
}

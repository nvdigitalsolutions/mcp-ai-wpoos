<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Settings as AdminSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\UnimplementedSettings;
use App\Support\SettingsSchema;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Proposal 156 §6 (I2) — the anti-fiction gate.
 *
 * Proposal 156 §3.1 found nineteen Authentication keys that render on
 * `/admin/settings` and are read by nothing. `inert` is the contract state for
 * that, and `UnimplementedSettings` is the runtime list. This test is what keeps
 * the list from becoming a second lie:
 *
 *  - every listed key must exist in the catalogue (a typo would mark nothing);
 *  - no listed key may appear in the runtime's source, so implementing one and
 *    leaving the entry behind fails loudly;
 *  - the keys must still render on `/admin` (D8: visible, not hidden);
 *  - and they must be refused by the writer, not just disabled on the component,
 *    because a crafted Livewire payload never passes through a component.
 *
 * The source scan is deliberately literal. A semantic check ("does anything read
 * this key?") is not available, and a false negative here is exactly the failure
 * Proposal 156 was written to correct — a negative result from the wrong search.
 */
class UnimplementedSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_inert_key_exists_in_the_catalogue(): void
    {
        foreach (UnimplementedSettings::keys() as $key) {
            $field = SettingsSchema::field($key);

            $this->assertNotNull($field, sprintf('Inert key "%s" is not in the catalogue.', $key));
            $this->assertSame(
                'tenant',
                $field['scope'],
                sprintf('Inert key "%s" should be tenant scope; it renders on /admin.', $key)
            );
            $this->assertSame(
                'hidden',
                $field['console'] ?? 'visible',
                sprintf(
                    'Inert key "%s" must stay hidden from the console — a dead control on the '
                    .'product surface is worse than on the admin one.',
                    $key
                )
            );
        }
    }

    public function test_no_inert_key_is_read_by_the_runtime(): void
    {
        $haystack = $this->runtimeSource();
        $offenders = array();

        foreach (UnimplementedSettings::keys() as $key) {
            foreach ($haystack as $file => $contents) {
                if (str_contains($contents, $key)) {
                    $offenders[] = sprintf('%s in %s', $key, $file);
                }
            }
        }

        $this->assertSame(
            array(),
            $offenders,
            'A key listed in UnimplementedSettings is now referenced by runtime code. '
            .'Implementing it is a two-part act: keep the reader, and remove the '
            .'entry from UnimplementedSettings::REASONS so the field is no longer '
            .'reported as inert.'
        );
    }

    public function test_inert_keys_still_render_on_the_admin_surface(): void
    {
        $rendered = array_column(SettingsSchema::forSurface('admin'), 'key');

        foreach (UnimplementedSettings::keys() as $key) {
            $this->assertContains(
                $key,
                $rendered,
                sprintf('Inert key "%s" should still render, so the administrator can see the capability exists.', $key)
            );
        }
    }

    public function test_inert_keys_are_not_writable_on_any_surface(): void
    {
        foreach (array('admin', 'ops', 'console') as $surface) {
            $writable = array_column(SettingsSchema::writableFor($surface), 'key');

            foreach (UnimplementedSettings::keys() as $key) {
                $this->assertNotContains(
                    $key,
                    $writable,
                    sprintf('Inert key "%s" must not be writable on the %s surface.', $key, $surface)
                );
            }
        }
    }

    public function test_a_crafted_payload_cannot_write_an_inert_key(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        // Bypass the disabled component on purpose — same reasoning as the S-1
        // test in AdminSettingsTest. The disabled state is presentation; the
        // writer is the control.
        Livewire::test(AdminSettings::class)
            ->set('data.auth0_domain', 'crafted.auth0.example')
            ->set('data.enable_magic_login', true)
            ->call('save');

        $this->assertDatabaseMissing('nvoos_tenant_settings', array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'key' => 'auth0_domain',
        ));
        $this->assertDatabaseMissing('nvoos_tenant_settings', array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'key' => 'enable_magic_login',
        ));
        $this->assertDatabaseMissing('nvoos_settings', array('key' => 'auth0_domain'));
    }

    public function test_the_inert_fields_explain_themselves(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/settings')
            ->assertOk()
            ->assertSee(UnimplementedSettings::HINT)
            // The reason is per-key, and it names the slice rather than the owner.
            ->assertSee('slice I3 adds it')
            ->assertSee('slice I4 decides it');

        // The operator-scope hint must not be the explanation for an inert field:
        // two different situations, two different answers to "who do I ask?".
        $this->assertNotSame(
            UnimplementedSettings::HINT,
            AdminSettings::class::OPERATOR_SCOPE_HINT,
            'The inert hint and the operator-scope hint must stay distinct.'
        );
    }

    /**
     * Every PHP file the runtime executes or renders from, keyed by relative path.
     *
     * The list class itself is excluded — it necessarily names every key. The
     * catalogue is JSON, not PHP, and is data rather than a reader.
     *
     * @return array<string, string>
     */
    private function runtimeSource(): array
    {
        $roots = array(
            app_path(),
            config_path(),
            base_path('routes'),
            database_path(),
            resource_path('views'),
        );

        $self = realpath(app_path('Support/Settings/UnimplementedSettings.php')) ?: '';
        $files = array();

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $path = $file->getPathname();

                if ('' !== $self && realpath($path) === $self) {
                    continue;
                }

                $files[ $this->relative($path) ] = (string) file_get_contents($path);
            }
        }

        $this->assertNotEmpty($files, 'The source scan found no files, so it proves nothing.');

        return $files;
    }

    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_replace($base, '', str_replace('\\', '/', $path));
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }
}

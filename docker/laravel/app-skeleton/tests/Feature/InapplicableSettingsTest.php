<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Settings\InapplicableSettings;
use App\Support\Settings\SettingsService;
use App\Support\Settings\UnimplementedSettings;
use App\Support\SettingsSchema;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Settings for software this runtime does not have — Proposal 149 §104.
 *
 * Three properties, and the third is the one that matters most:
 *
 *  - they are not on the admin form at all (not merely disabled);
 *  - they cannot be written, so a crafted payload cannot create config for an
 *    absent feature;
 *  - no runtime code references them. A key with a reader is not inapplicable —
 *    it is implemented, and belongs back on the form. This is the same guard
 *    `UnimplementedSettingsTest` applies to the inert list, and it is the only
 *    thing that stops "inapplicable" from becoming a place to park settings
 *    nobody wants to think about.
 *
 * They are also disjoint from the inert list: a key cannot be both, because the
 * two states make contradictory promises about whether the administrator should
 * see it.
 */
class InapplicableSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_every_inapplicable_key_exists_in_the_catalogue(): void
    {
        foreach (InapplicableSettings::keys() as $key) {
            $field = SettingsSchema::field($key);

            $this->assertNotNull($field, sprintf('Inapplicable key "%s" is not in the catalogue.', $key));
            $this->assertNotSame(
                '',
                InapplicableSettings::reasonFor($key),
                sprintf('Inapplicable key "%s" must say what is absent.', $key)
            );
        }
    }

    public function test_the_two_lists_are_disjoint(): void
    {
        $overlap = array_intersect(InapplicableSettings::keys(), UnimplementedSettings::keys());

        $this->assertSame(array(), array_values($overlap), 'A key cannot be both hidden and marked.');
    }

    public function test_inapplicable_keys_are_not_rendered_or_writable(): void
    {
        $rendered = array_column(SettingsSchema::forSurface('admin'), 'key');

        foreach (InapplicableSettings::keys() as $key) {
            $this->assertNotContains(
                $key,
                $rendered,
                sprintf('Inapplicable key "%s" must not render on /admin.', $key)
            );
        }

        foreach (array('admin', 'ops', 'console') as $surface) {
            $writable = array_column(SettingsSchema::writableFor($surface), 'key');

            foreach (InapplicableSettings::keys() as $key) {
                $this->assertNotContains(
                    $key,
                    $writable,
                    sprintf('Inapplicable key "%s" must not be writable on the %s surface.', $key, $surface)
                );
            }
        }
    }

    public function test_the_page_does_not_render_them(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/settings')
            ->assertOk()
            // Two representative labels from opposite ends of the list: a bridge
            // that resolves WordPress.com identities, and an SSE toggle for an
            // endpoint this runtime does not have.
            ->assertDontSee('WordPress.com/Gravatar')
            ->assertDontSee('Enable POST Method on SSE Endpoint');
    }

    public function test_a_crafted_payload_cannot_write_them(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        app(SettingsService::class)->write(
            array(
                'auth0_management_client_id' => 'crafted',
                'enable_simple_jwt_login' => true,
                'oauth_disable_open_registration' => false,
            ),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertDatabaseMissing('nvoos_tenant_settings', array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'key' => 'auth0_management_client_id',
        ));
        $this->assertDatabaseMissing('nvoos_tenant_settings', array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'key' => 'enable_simple_jwt_login',
        ));
        $this->assertDatabaseMissing('nvoos_settings', array('key' => 'oauth_disable_open_registration'));
    }

    public function test_no_inapplicable_key_is_read_by_the_runtime(): void
    {
        $haystack = $this->runtimeSource();
        $offenders = array();

        foreach (InapplicableSettings::keys() as $key) {
            foreach ($haystack as $file => $contents) {
                if (str_contains($contents, $key)) {
                    $offenders[] = sprintf('%s in %s', $key, $file);
                }
            }
        }

        $this->assertSame(
            array(),
            $offenders,
            'A key listed in InapplicableSettings is now referenced by runtime code. '
            .'Implementing it is a two-part act: keep the reader, and remove the '
            .'entry so the field returns to the form.'
        );
    }

    /**
     * Every PHP file the runtime executes or renders from, keyed by relative path.
     *
     * Both list classes are excluded — they necessarily name every key.
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

        $excluded = array_filter(array(
            realpath(app_path('Support/Settings/InapplicableSettings.php')) ?: '',
            realpath(app_path('Support/Settings/UnimplementedSettings.php')) ?: '',
        ));

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

                if (in_array(realpath($path), $excluded, true)) {
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

    private function estateAdmin(string $tenantId): \App\Models\User
    {
        $user = \App\Models\User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }
}

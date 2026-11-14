<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\HarnessProfileProviderInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Service\Harness\HarnessProfile;
use Tests\TestCase;

/**
 * Harness profile storage — the Laravel side of `WP_MCP_AI_Harness_Profile`.
 *
 * WordPress resolves assistant post meta → a site-wide option → the all-off
 * profile. Laravel resolves a per-assistant setting → a site-wide setting → the
 * same all-off profile, through the same sanitiser in `lib/core`.
 */
class HarnessProfileProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): HarnessProfileProviderInterface
    {
        return app(HarnessProfileProviderInterface::class);
    }

    private function store(): SettingsStoreInterface
    {
        return app(SettingsStoreInterface::class);
    }

    public function test_it_falls_back_to_the_all_off_profile(): void
    {
        $this->assertSame(HarnessProfile::defaults(), $this->provider()->getProfile(0));
        $this->assertSame(HarnessProfile::defaults(), $this->provider()->getProfile(42));
    }

    public function test_a_site_default_is_resolved_and_sanitised(): void
    {
        $this->store()->set('harness_profile_default', json_encode([
            'enabled' => true,
            'retrieval' => ['k' => 99],
        ]));

        $profile = $this->provider()->getProfile(0);

        $this->assertTrue($profile['enabled']);
        $this->assertSame(50, $profile['retrieval']['k'], 'Out-of-range k is clamped.');
    }

    public function test_a_per_assistant_profile_overrides_the_site_default(): void
    {
        $store = $this->store();
        $store->set('harness_profile_default', ['enabled' => true]);
        $store->set('harness_profile:42', ['enabled' => false, 'memory' => ['pii_filter' => false, 'scoped' => true]]);

        $mine = $this->provider()->getProfile(42);
        $other = $this->provider()->getProfile(7);

        $this->assertFalse($mine['enabled']);
        $this->assertFalse($mine['memory']['pii_filter']);
        $this->assertTrue($mine['memory']['scoped']);

        // Another assistant still sees the site default.
        $this->assertTrue($other['enabled']);
        $this->assertTrue($other['memory']['pii_filter']);
    }

    public function test_assistant_zero_ignores_per_assistant_keys(): void
    {
        $this->store()->set('harness_profile:0', ['enabled' => true]);

        $this->assertFalse($this->provider()->getProfile(0)['enabled']);
    }

    public function test_a_malformed_profile_degrades_to_the_defaults(): void
    {
        $this->store()->set('harness_profile:9', '{not json');

        $this->assertSame(HarnessProfile::defaults(), $this->provider()->getProfile(9));
    }

    public function test_the_scope_memory_tool_reports_the_stored_profile(): void
    {
        $tool = app(ToolRegistry::class)->get('scope_memory');
        $this->assertNotNull($tool, 'scope_memory is registered.');

        // Default: the documented "filter on" default, with no storage consulted.
        $default = $tool->execute(['assistant_id' => 5]);
        $this->assertTrue($default['data']['pii_filter']);

        $this->store()->set('harness_profile:5', ['memory' => ['pii_filter' => false]]);

        $resolved = $tool->execute(['assistant_id' => 5]);
        $this->assertFalse($resolved['data']['pii_filter'], 'The tool reads the resolved profile.');
    }
}

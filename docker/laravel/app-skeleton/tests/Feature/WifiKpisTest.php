<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use NvoosWifi\Interfaces\Interface_WiFi_Clock;
use Tests\Support\FrozenClock;
use Tests\TestCase;

/**
 * Proposal 150, T-0.11 — the Laravel half of the dual-runtime parity test.
 *
 * The claim under test is the whole point of Phase 0: one portable service, two
 * hosts, identical output. This file asserts the Laravel payload against
 * `tests/fixtures/wifi/kpis-venue-1-window-1.json`; the WordPress twin
 * (`addons/nvoos-wifi/tests/Integration/KpiParityTest.php`) asserts against its
 * own committed copy of the same bytes.
 *
 * **The duplication is the mechanism, not an accident.** Each runtime pins its
 * own copy, so a change to the portable service fails *both* suites until both
 * fixtures are regenerated — which is precisely the drift a single shared file
 * would let one side update silently.
 *
 * Regenerate with:
 *
 *   php addons/nvoos-wifi/tests/standalone-domain-checks.php --write-fixture
 *   cp addons/nvoos-wifi/tests/fixtures/kpis-venue-1-window-1.json \
 *      docker/laravel/app-skeleton/tests/fixtures/wifi/
 */
class WifiKpisTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../fixtures/wifi/kpis-venue-1-window-1.json';

    private const WINDOW_FROM = 1763337600;

    private const WINDOW_TO = 1763424000;

    private const FROZEN_AT = 1763424100;

    public function test_kpi_payload_matches_the_shared_parity_fixture(): void
    {
        $this->app->instance(Interface_WiFi_Clock::class, new FrozenClock(self::FROZEN_AT));
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->getJson($this->url());

        $response->assertOk();

        $fixture = json_decode(
            (string) file_get_contents(self::FIXTURE),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            $this->canonicalise($fixture),
            $this->canonicalise($response->json()),
            'The Laravel KPI payload drifted from the shared parity fixture. Either the portable '
            .'service changed (regenerate BOTH fixtures) or this host added shaping it must not add.',
        );
    }

    public function test_every_entity_count_in_the_payload_declares_its_method(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $samples = $this->getJson($this->url())->assertOk()->json('samples');

        $this->assertIsArray($samples);
        $this->assertNotEmpty($samples);

        $entityKeys = ['wifi.clients.connected', 'wifi.clients.unique', 'wifi.sessions.started', 'wifi.sessions.completed'];
        $checked = 0;

        foreach ($samples as $sample) {
            if (! in_array($sample['key'], $entityKeys, true)) {
                continue;
            }

            $checked++;
            $this->assertNotNull(
                $sample['method'],
                "KPI {$sample['key']} counts entities and must declare a method.",
            );
        }

        $this->assertGreaterThan(0, $checked, 'The fixture should contain at least one entity-count KPI.');
    }

    public function test_unknown_venue_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson($this->url('venue-does-not-exist'))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'venue_not_found');
    }

    public function test_invalid_window_returns_400(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson($this->url('venue-1', 100, 100))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_window');
    }

    public function test_a_subscriber_cannot_read_venue_kpis(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson($this->url())->assertStatus(403);
    }

    private function url(
        string $venue = 'venue-1',
        int $from = self::WINDOW_FROM,
        int $to = self::WINDOW_TO,
    ): string {
        return sprintf('/api/v1/wifi/venues/%s/kpis?from=%d&to=%d', $venue, $from, $to);
    }

    /**
     * Order-insensitive comparison. The service already fixes key order by
     * construction (`ksort` on canonical keys); this normalises so the assertion
     * is about content and types rather than JSON formatting.
     */
    private function canonicalise(mixed $value): string
    {
        return (string) json_encode(
            $this->sortKeys($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortKeys($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->sortKeys($item), $value);
    }
}

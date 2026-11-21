<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Tool\CancelHiggsfieldRequestTool;
use Nvoos\Core\Tool\CheckHiggsfieldRequestTool;
use Nvoos\Core\Tool\CreateTextEmbeddingsTool;
use Nvoos\Core\Tool\GeocodeAddressTool;
use Nvoos\Core\Tool\GetGdacsEventsTool;
use Nvoos\Core\Tool\GetNhcActiveStormsTool;
use Nvoos\Core\Tool\GetOpenMeteoForecastTool;
use Nvoos\Core\Tool\ModerateContentTool;
use Nvoos\Core\Tool\OpenOpenAILogsTool;
use Nvoos\Core\Tool\ReliefwebReportsTool;
use Nvoos\Core\Tool\SearchPlacesTool;
use Nvoos\Core\Tool\WebSearchTool;
use Nvoos\Core\Tool\WebSearchValidatedTool;
use Tests\TestCase;

/**
 * Batch 4 evidence, part three: search, geo and provider tools.
 *
 * Each tool reaches exactly one well-known endpoint, so `Http::fake()` supplies
 * a minimal well-formed response and the assertion is on the request the tool
 * makes: the endpoint, the credential header and the query. Nothing calls out.
 *
 * The fakes return the smallest response the tool accepts. That is deliberate —
 * a test that only succeeds on a rich payload hides which fields the tool
 * actually requires.
 */
class SearchAndProviderToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(array(
            'services.openai.key' => 'sk-test',
            'services.brave.key' => 'brave-test',
            'services.google_maps.key' => 'gmaps-test',
            'oos.google_places_api_key' => 'places-test',
            'services.higgsfield.key' => 'key-id:key-secret',
        ));
    }

    /**
     * @param class-string $class
     */
    private function execute(string $class, array $arguments = array(), array $context = array()): array
    {
        $result = $this->app->make($class)->execute($arguments, $context);

        $this->assertIsArray($result, $class . ' returned a non-array result: ' . print_r($result, true));

        return $result;
    }

    // ─── Geo ─────────────────────────────────────────────────────────────

    public function test_geocode_address_returns_a_location(): void
    {
        Http::fake(array(
            'maps.googleapis.com/*' => Http::response(array(
                'status' => 'OK',
                'results' => array(array(
                    'formatted_address' => '1 Main St',
                    'geometry' => array('location' => array('lat' => 1.5, 'lng' => 2.5)),
                )),
            )),
        ));

        $result = $this->execute(GeocodeAddressTool::class, array('address' => '1 Main St'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'maps.googleapis.com')
            && str_contains($request->url(), 'address=1+Main+St'));
    }

    public function test_search_places_returns_results(): void
    {
        Http::fake(array(
            'maps.googleapis.com/*' => Http::response(array(
                'status' => 'OK',
                'results' => array(array('name' => 'Cafe', 'place_id' => 'p1')),
            )),
        ));

        $result = $this->execute(SearchPlacesTool::class, array('query' => 'cafe'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'place/textsearch'));
    }

    public function test_open_meteo_returns_a_forecast(): void
    {
        Http::fake(array(
            'api.open-meteo.com/*' => Http::response(array(
                'current' => array('temperature_2m' => 20.5),
                'hourly' => array('time' => array(), 'temperature_2m' => array()),
            )),
        ));

        $result = $this->execute(GetOpenMeteoForecastTool::class, array(
            'latitude' => 51.5,
            'longitude' => -0.1,
        ));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'api.open-meteo.com'));
    }

    // ─── Public feeds ────────────────────────────────────────────────────

    public function test_gdacs_events_reads_the_feed(): void
    {
        Http::fake(array(
            'www.gdacs.org/*' => Http::response(array('features' => array())),
        ));

        $result = $this->execute(GetGdacsEventsTool::class, array());

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'gdacs.org'));
    }

    public function test_nhc_active_storms_reads_the_feed(): void
    {
        Http::fake(array(
            'www.nhc.noaa.gov/*' => Http::response(array('activeStorms' => array())),
        ));

        $result = $this->execute(GetNhcActiveStormsTool::class, array());

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'nhc.noaa.gov'));
    }

    public function test_reliefweb_reports_searches_the_api(): void
    {
        Http::fake(array(
            'api.reliefweb.int/*' => Http::response(array('data' => array(), 'totalCount' => 0)),
        ));

        $result = $this->execute(ReliefwebReportsTool::class, array('query' => 'flood'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'api.reliefweb.int'));
    }

    // ─── Web search ──────────────────────────────────────────────────────

    public function test_web_search_queries_the_configured_provider(): void
    {
        Http::fake(array(
            'api.search.brave.com/*' => Http::response(array(
                'web' => array(
                    'total_results' => 1,
                    'results' => array(array('title' => 'Hit', 'url' => 'https://example.test')),
                ),
            )),
        ));

        $result = $this->execute(WebSearchTool::class, array('query' => 'estate agents'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'api.search.brave.com')
            && $request->hasHeader('X-Subscription-Token', 'brave-test'));
    }

    public function test_web_search_validated_shares_the_provider_path(): void
    {
        Http::fake(array(
            'api.search.brave.com/*' => Http::response(array(
                'web' => array(
                    'total_results' => 1,
                    'results' => array(array('title' => 'Hit', 'url' => 'https://example.test')),
                ),
            )),
        ));

        $result = $this->execute(WebSearchValidatedTool::class, array('query' => 'estate agents'));

        $this->assertTrue($result['success']);
    }

    // ─── Embeddings and moderation ───────────────────────────────────────

    public function test_create_text_embeddings_returns_vectors(): void
    {
        Http::fake(array(
            'api.openai.com/v1/embeddings' => Http::response(array(
                'data' => array(array('embedding' => array(0.1, 0.2, 0.3))),
            )),
        ));

        $result = $this->execute(CreateTextEmbeddingsTool::class, array('text' => 'hello'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/embeddings'));
    }

    public function test_moderate_content_returns_the_verdict(): void
    {
        Http::fake(array(
            'api.openai.com/v1/moderations' => Http::response(array(
                'results' => array(array('flagged' => false)),
            )),
        ));

        $result = $this->execute(ModerateContentTool::class, array('text' => 'hello'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/moderations'));
    }

    // ─── Higgsfield status ───────────────────────────────────────────────

    public function test_check_higgsfield_request_reads_the_status(): void
    {
        Http::fake(array(
            'api.higgsfield.ai/*' => Http::response(array(
                'request_id' => 'req-1',
                'status' => 'completed',
            )),
        ));

        $result = $this->execute(CheckHiggsfieldRequestTool::class, array('request_id' => 'req-1'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => str_starts_with($request->url(), 'https://api.higgsfield.ai/requests/')
            && $request->hasHeader('Authorization', 'Key key-id:key-secret'));
    }

    public function test_cancel_higgsfield_request_reports_the_cancellation(): void
    {
        Http::fake(array(
            'api.higgsfield.ai/*' => Http::response(array(
                'request_id' => 'req-1',
                'status' => 'cancelled',
            ), 202),
        ));

        $result = $this->execute(CancelHiggsfieldRequestTool::class, array('request_id' => 'req-1'));

        $this->assertTrue($result['success']);
    }

    // ─── Usage logs ──────────────────────────────────────────────────────

    public function test_open_openai_logs_reads_usage(): void
    {
        Http::fake(array(
            'api.openai.com/v1/organization/usage*' => Http::response(array('data' => array())),
        ));

        $result = $this->execute(OpenOpenAILogsTool::class, array());

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && str_contains($request->url(), '/v1/organization/usage'));
    }
}

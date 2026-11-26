<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Tool\Crawl4AiPriceLookupTool;
use Nvoos\Core\Tool\GeminiGeospatialQueryTool;
use Nvoos\Core\Tool\GenerateAuth0TokenTool;
use Nvoos\Core\Tool\ProbeRemoteMcpTool;
use Nvoos\Core\Tool\QueryRemoteSiteTool;
use Nvoos\Core\Tool\SearchDriveTool;
use Nvoos\Core\Tool\SearchGmailTool;
use Tests\TestCase;

/**
 * Batch 4 evidence, part four: the remaining bound provider tools.
 *
 * Each needs one settings value and one endpoint, so the test sets the value and
 * fakes the endpoint. `crawl4ai_price_lookup` needs nothing at all: it falls back
 * to the documented default base URL, which the fake intercepts.
 */
class ProviderConnectorToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(array(
            'services.gemini.key' => 'gemini-test',
            'oos.auth0_domain' => 'tenant.example',
            'oos.google_drive_token' => 'drive-token',
            'oos.gmail_token' => 'gmail-token',
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

    public function test_gemini_geospatial_query_calls_gemini(): void
    {
        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array(
                'candidates' => array(array('content' => array('parts' => array(array('text' => 'nearby'))))),
                'predictions' => array(),
            )),
        ));

        $result = $this->execute(GeminiGeospatialQueryTool::class, array(
            'query' => 'estates near the coast',
        ));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_contains($request->url(), 'generativelanguage.googleapis.com')
            && str_contains($request->url(), 'key=gemini-test'));
    }

    public function test_generate_auth0_token_exchanges_client_credentials(): void
    {
        Http::fake(array(
            'tenant.example/oauth/token' => Http::response(array(
                'access_token' => 'auth0-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            )),
        ));

        $result = $this->execute(GenerateAuth0TokenTool::class, array(
            'client_id' => 'id',
            'client_secret' => 'secret',
        ));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && 'https://tenant.example/oauth/token' === $request->url());
    }

    public function test_search_drive_queries_the_files_endpoint(): void
    {
        Http::fake(array(
            'www.googleapis.com/drive/v3/files*' => Http::response(array('files' => array())),
        ));

        $result = $this->execute(SearchDriveTool::class, array('query' => 'contracts'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && str_contains($request->url(), 'drive/v3/files')
            && $request->hasHeader('Authorization', 'Bearer drive-token'));
    }

    public function test_search_gmail_queries_the_messages_endpoint(): void
    {
        Http::fake(array(
            'gmail.googleapis.com/*' => Http::response(array('messages' => array())),
        ));

        $result = $this->execute(SearchGmailTool::class, array('query' => 'invoice'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && str_contains($request->url(), 'gmail/v1/users/me/messages')
            && $request->hasHeader('Authorization', 'Bearer gmail-token'));
    }

    public function test_crawl4ai_price_lookup_posts_to_the_crawler(): void
    {
        Http::fake(array(
            '*' => Http::response(array('results' => array())),
        ));

        $result = $this->execute(Crawl4AiPriceLookupTool::class, array('query' => 'estate agent fees'));

        $this->assertTrue($result['success']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/search'));
    }

    public function test_probe_remote_mcp_reports_reachability(): void
    {
        Http::fake(array(
            'mcp.example.test/*' => Http::response(array('jsonrpc' => '2.0', 'id' => 1)),
        ));

        $result = $this->execute(ProbeRemoteMcpTool::class, array(
            'url' => 'https://mcp.example.test/mcp',
        ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['reachable']);
        $this->assertSame(200, $result['data']['status']);
    }

    public function test_query_remote_site_returns_the_remote_payload(): void
    {
        Http::fake(array(
            'remote.example.test/*' => Http::response(array('id' => 1, 'title' => 'Remote post')),
        ));

        $result = $this->execute(QueryRemoteSiteTool::class, array(
            'site_url' => 'https://remote.example.test',
            'endpoint' => '/wp-json/wp/v2/posts',
            'auth_token' => 'remote-token',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('Remote post', $result['data']['data']['title']);

        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && str_ends_with($request->url(), '/wp-json/wp/v2/posts')
            && $request->hasHeader('Authorization', 'Bearer remote-token'));
    }
}

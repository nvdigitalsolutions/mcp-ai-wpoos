<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantPortability;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * `export_assistant` against the app's real engine — Proposal 090-M §18.
 *
 * The unit test pins the argument rules with a mocked host. This pins the bundle
 * itself: the canonical `nvoos-assistant` v1 shape, the estate boundary, the
 * credential denylist that the engine applies for every surface, and the 404 a
 * foreign id gets so `export_assistant` and `duplicate_assistant` cannot be
 * compared to learn whether a row exists elsewhere.
 */
final class ExportAssistantToolTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('export_assistant'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_exports_the_estate_as_a_v1_bundle(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->writer()->create(array(
            'title'         => 'Ops Helper',
            'system_prompt' => 'You help with operations.',
            'tools'         => array( 'web_search' ),
        ));

        $result = $this->tool()->execute(array());

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['count']);

        $bundle = $result['data']['bundle'];

        $this->assertSame(AssistantPortability::FORMAT, $bundle['format']);
        $this->assertSame(1, $bundle['format_version']);
        $this->assertArrayHasKey('exported_at', $bundle);

        $entry = $bundle['assistants'][0];

        $this->assertSame('Ops Helper', $entry['title']);
        $this->assertSame('You help with operations.', $entry['content']);
        $this->assertSame(array( 'web_search' ), $entry['meta']['tools']);
    }

    public function test_it_exports_only_the_requested_ids(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $keep = $this->writer()->create(array( 'title' => 'Keep Me' ));
        $this->writer()->create(array( 'title' => 'Leave Me' ));

        $result = $this->tool()->execute(array( 'assistant_ids' => array( (int) $keep->getKey() ) ));

        $this->assertSame(1, $result['data']['count']);
        $this->assertSame('Keep Me', $result['data']['bundle']['assistants'][0]['title']);
    }

    public function test_credential_keys_and_mcp_app_tokens_never_leave(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        // A hand-edited WordPress bundle could have imported these: `meta`
        // round-trips verbatim, so the denylist has to run on the way out.
        $assistant = $this->writer()->create(array( 'title' => 'Has Secrets' ));
        $assistant->meta = array(
            '_wp_mcp_ai_credentials' => array( 'cred_abc.SECRET' ),
            '_wp_mcp_ai_mcp_apps'    => array(
                array(
                    'name'        => 'Elementor',
                    'server_url'  => 'https://example.test/mcp',
                    'auth_type'   => 'header',
                    'header_name' => 'X-Api-Key',
                    'token'       => 'super-secret-token',
                    'oauth_data'  => array( 'refresh_token' => 'r3fr3sh' ),
                ),
            ),
            'provider'               => 'openai',
        );
        $assistant->save();

        $encoded = \json_encode($this->tool()->execute(array())['data']['bundle']);

        $this->assertStringNotContainsString('cred_abc.SECRET', (string) $encoded);
        $this->assertStringNotContainsString('super-secret-token', (string) $encoded);
        $this->assertStringNotContainsString('r3fr3sh', (string) $encoded);

        // The connection itself survives, minus its secret: the receiving
        // runtime can still see *what* was connected.
        $app = $this->tool()->execute(array())['data']['bundle']['assistants'][0]['meta']['_wp_mcp_ai_mcp_apps'][0];

        $this->assertSame('https://example.test/mcp', $app['server_url']);
        $this->assertArrayNotHasKey('token', $app);
        $this->assertArrayNotHasKey('oauth_data', $app);
    }

    public function test_another_estates_assistant_is_not_exportable_and_answers_like_a_missing_one(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $foreignId = TenantContext::runAs(
            'other-estate',
            fn (): int => (int) $this->writer()->create(array( 'title' => 'Foreign' ))->getKey()
        );

        $foreign = $this->tool()->execute(array( 'assistant_ids' => array( $foreignId ) ));
        $missing = $this->tool()->execute(array( 'assistant_ids' => array( 999999 ) ));

        // The adapter's ErrorFactory returns the error as a value; code is the
        // HTTP status. The two cases must be indistinguishable.
        $this->assertInstanceOf(RuntimeException::class, $foreign);
        $this->assertInstanceOf(RuntimeException::class, $missing);
        $this->assertSame($missing->getCode(), $foreign->getCode());
        $this->assertSame(404, $foreign->getCode());

        // And nothing leaked from the other estate on the way.
        $this->assertStringNotContainsString('Foreign', $foreign->getMessage());
    }

    public function test_an_unsupported_dialect_is_refused_before_the_engine_runs(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->writer()->create(array( 'title' => 'Any' ));

        $result = $this->tool()->execute(array( 'format' => 'blueprint' ));

        // validationFailed is also returned, not thrown, by this adapter.
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
    }

    private function writer(): AssistantWriter
    {
        return $this->app->make(AssistantWriter::class);
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('export_assistant');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}

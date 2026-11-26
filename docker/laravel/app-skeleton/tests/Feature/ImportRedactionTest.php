<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Services\AssistantPortability;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The credential denylist runs on the way **in**, too — issue #8175.
 *
 * WordPress filters its denylist on import; this engine did not, and its
 * `entryToData()` docblock argued the opposite ("`meta` is carried through
 * verbatim"). That argument protects the round trip, which is a real property,
 * but `meta` round-tripping is also how a hand-edited or third-party bundle gets
 * to plant `_wp_mcp_ai_credentials` and a live MCP App token in the database —
 * the case WordPress's own export redaction never saw.
 *
 * Two named keys are stripped; everything else still round-trips. That balance is
 * what these tests pin: the security property, *and* the fidelity property the
 * old comment was defending.
 */
final class ImportRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_a_bundle_cannot_plant_a_credential_record(): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->portability()->import($this->bundleWith(array(
            '_wp_mcp_ai_credentials' => array( 'cred_abc.SECRET' ),
            'notes'                  => 'keep me',
        )));

        $meta = $this->onlyAssistant()->meta;

        $this->assertArrayNotHasKey('_wp_mcp_ai_credentials', $meta);

        // Only the denylisted key goes. This must not become a meta sanitiser.
        $this->assertSame('keep me', $meta['notes']);
    }

    public function test_mcp_app_tokens_are_stripped_and_the_connection_survives(): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->portability()->import($this->bundleWith(array(
            '_wp_mcp_ai_mcp_apps' => array(
                array(
                    'name'        => 'Elementor',
                    'server_url'  => 'https://example.test/mcp',
                    'auth_type'   => 'header',
                    'header_name' => 'X-Api-Key',
                    'token'       => 'super-secret-token',
                    'oauth_data'  => array( 'refresh_token' => 'r3fr3sh' ),
                ),
            ),
        )));

        $app = $this->onlyAssistant()->meta['_wp_mcp_ai_mcp_apps'][0];

        // The entry stays, so an operator can still see *what* was connected and
        // re-issue a token on this side.
        $this->assertSame('https://example.test/mcp', $app['server_url']);
        $this->assertSame('header', $app['auth_type']);
        $this->assertSame('X-Api-Key', $app['header_name']);

        $this->assertArrayNotHasKey('token', $app);
        $this->assertArrayNotHasKey('oauth_data', $app);
    }

    public function test_ordinary_meta_still_maps_onto_the_columns(): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->portability()->import($this->bundleWith(array(
            'provider'                 => 'openai',
            'model'                    => 'gpt-4o',
            'max_tokens'               => 512,
            'tools'                    => array( 'web_search', 'get_post' ),
            '_wp_mcp_ai_primary_roles' => array( 'consultant' ),
            'custom_note'              => array( 'nested' => true ),
        )));

        $assistant = $this->onlyAssistant();

        $this->assertSame('openai', $assistant->provider);
        $this->assertSame('gpt-4o', $assistant->model);
        $this->assertSame(array( 'web_search', 'get_post' ), $assistant->tools);

        // Redaction runs before the promoted keys are read, so it must not have
        // taken them with it.
        $this->assertSame(array( 'consultant' ), $assistant->meta['_wp_mcp_ai_primary_roles']);
        $this->assertSame(array( 'nested' => true ), $assistant->meta['custom_note']);
    }

    public function test_export_then_import_preserves_ordinary_meta(): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $source = $this->app->make(AssistantWriter::class)->create(array(
            'title'         => 'Round Trip',
            'system_prompt' => 'A prompt.',
            'tools'         => array( 'web_search' ),
        ));

        $source->meta = array_merge($source->meta ?? array(), array( 'custom_note' => 'kept' ));
        $source->save();

        $bundle = $this->portability()->export();
        $this->portability()->import($bundle, 'duplicate');

        $this->assertSame(2, Assistant::query()->count());

        foreach (Assistant::query()->get() as $assistant) {
            $this->assertSame('kept', $assistant->meta['custom_note'] ?? null);
            $this->assertSame(array( 'web_search' ), $assistant->tools);
        }
    }

    /**
     * A one-assistant v1 bundle carrying the given meta.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function bundleWith(array $meta): array
    {
        return array(
            'format'         => AssistantPortability::FORMAT,
            'format_version' => AssistantPortability::FORMAT_VERSION,
            'plugin_version' => '1.2.0',
            'exported_at'    => '2026-11-23T00:00:00+00:00',
            'assistants'     => array(
                array(
                    'title'   => 'Imported',
                    'slug'    => 'imported',
                    'status'  => 'draft',
                    'content' => 'A prompt.',
                    'excerpt' => 'Imported from a bundle.',
                    'meta'    => $meta,
                ),
            ),
        );
    }

    private function onlyAssistant(): Assistant
    {
        $assistant = Assistant::query()->first();

        $this->assertInstanceOf(Assistant::class, $assistant, 'The import created no assistant.');

        return $assistant;
    }

    private function portability(): AssistantPortability
    {
        return $this->app->make(AssistantPortability::class);
    }
}

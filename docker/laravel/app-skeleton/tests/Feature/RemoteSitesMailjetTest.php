<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RemoteSites\RemoteSiteTypes;
use App\Support\RemoteSites\RemoteSiteVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Tests\Support\FakeHttpClient;
use Tests\TestCase;

/**
 * `mailjet_mcp` — the third connection type, and the one that may have no MCP
 * endpoint at all.
 *
 * The rules worth pinning:
 *
 *  1. Both halves of the API key pair are sealed, like every other credential.
 *  2. A credential-only connection tests against the Mailjet API, not against a
 *     missing bridge — the "no URL" guard must not swallow it.
 *  3. The region decides the host. A US key against the EU host is a 401 the
 *     operator would otherwise misread as a bad key.
 *  4. A saved bridge URL switches the test to the MCP handshake.
 */
final class RemoteSitesMailjetTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function createMailjet(array $overrides = []): array
    {
        return $this->postJson('/api/v1/pro/remote-sites', array_merge([
            'name' => 'Mailjet marketing',
            'connection_type' => 'mailjet_mcp',
            'url' => 'https://api.mailjet.com',
            'api_key' => 'mj-key',
            'api_secret' => 'mj-secret',
            'region' => 'eu',
        ], $overrides))->assertCreated()->json('item');
    }

    public function test_the_type_is_registered_with_both_halves_secret(): void
    {
        $definition = RemoteSiteTypes::find('mailjet_mcp');

        $this->assertNotNull($definition);

        $byKey = collect($definition['fields'])->keyBy('key');

        $this->assertTrue($byKey['api_key']['secret']);
        $this->assertTrue($byKey['api_secret']['secret']);
        $this->assertFalse((bool) ($byKey['mailjet_mcp_url']['secret'] ?? false));
    }

    public function test_create_seals_the_key_pair_and_derives_basic_auth(): void
    {
        $this->actAsAdmin();

        $item = $this->createMailjet();

        $this->assertSame('mailjet_mcp', $item['connection_type']);
        $this->assertSame('basic_auth', $item['auth_type']);
        $this->assertTrue($item['has_credentials']);
        $this->assertTrue($item['enabled']);

        // Non-secret settings are flattened to the top level for the edit form.
        $this->assertSame('eu', $item['region']);

        // Nothing secret comes back, in any form.
        $this->assertArrayNotHasKey('api_key', $item);
        $this->assertArrayNotHasKey('api_secret', $item);
        $this->assertStringNotContainsString('mj-secret', (string) json_encode($item));

        $sealed = (string) DB::table('nvoos_remote_sites')->value('credentials');
        $this->assertStringNotContainsString('mj-secret', $sealed);
        $this->assertStringNotContainsString('mj-key', $sealed);
    }

    public function test_a_bridge_url_is_stored_as_config_not_a_credential(): void
    {
        $this->actAsAdmin();

        $item = $this->createMailjet([
            'mailjet_mcp_url' => 'https://bridge.example.test/mcp',
        ]);

        $this->assertSame('https://bridge.example.test/mcp', $item['mailjet_mcp_url']);
        $this->assertStringNotContainsString('mj-secret', (string) json_encode($item));
    }

    public function test_credential_only_connection_probes_the_regional_api_host(): void
    {
        $http = (new FakeHttpClient())->queue(200, ['Data' => [['Email' => 'ops@example.test']]]);

        $result = (new RemoteSiteVerifier($http))->verify('mailjet_mcp', 'https://api.mailjet.com', [
            'api_key' => 'mj-key',
            'api_secret' => 'mj-secret',
            'region' => 'eu',
        ]);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $http->requests);
        $this->assertSame('GET', $http->requests[0]['method']);
        // The region — not the stored url — decides the host.
        $this->assertSame('https://api.eu.mailjet.com/v3/REST/user', $http->requests[0]['url']);
        $this->assertSame(
            'Basic '.base64_encode('mj-key:mj-secret'),
            $http->requests[0]['headers']['Authorization']
        );
    }

    public function test_rejected_credentials_name_the_region(): void
    {
        $http = (new FakeHttpClient())->queue(401, ['ErrorMessage' => 'Unauthorized']);

        $result = (new RemoteSiteVerifier($http))->verify('mailjet_mcp', 'https://api.mailjet.com', [
            'api_key' => 'mj-key',
            'api_secret' => 'wrong',
            'region' => 'us',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('US', $result['message']);
        $this->assertStringContainsString('401', $result['message']);
    }

    public function test_missing_keys_is_a_message_not_a_transport_call(): void
    {
        $http = new FakeHttpClient();

        $result = (new RemoteSiteVerifier($http))->verify('mailjet_mcp', 'https://api.mailjet.com', [
            'region' => 'us',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame([], $http->requests);
    }

    public function test_a_saved_bridge_url_switches_the_check_to_the_bridge(): void
    {
        // The handshake fails, but that is enough: what is being asserted is
        // that the request went to the bridge, not to the Mailjet API.
        $http = (new FakeHttpClient())->queue(500, 'nope');

        $result = (new RemoteSiteVerifier($http))->verify('mailjet_mcp', 'https://api.mailjet.com', [
            'api_key' => 'mj-key',
            'api_secret' => 'mj-secret',
            'mailjet_mcp_url' => 'https://bridge.example.test/mcp',
        ]);

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($http->requests);
        $this->assertSame('bridge.example.test', parse_url($http->requests[0]['url'], PHP_URL_HOST));
    }

    public function test_the_api_surface_accepts_a_mailjet_connection(): void
    {
        $this->actAsAdmin();

        $id = (string) $this->createMailjet()['id'];

        $this->getJson('/api/v1/pro/remote-sites')
            ->assertOk()
            ->assertJsonPath('items.0.connection_type', 'mailjet_mcp')
            ->assertJsonPath('items.0.id', $id);

        $this->deleteJson('/api/v1/pro/remote-sites/'.$id)->assertOk();
    }
}

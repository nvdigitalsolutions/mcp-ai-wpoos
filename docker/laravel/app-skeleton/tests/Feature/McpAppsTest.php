<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assistant MCP app assignments (090-i).
 *
 * The console's `McpAppsClient` reads `success`, `assistant_id`, `apps` and
 * `app_count` straight from the response body, so the assertions are on the
 * top level. A `data` envelope here would render an empty panel live while
 * every unit test still passed.
 */
final class McpAppsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * Create an assistant through the API and return its id.
     */
    private function assistant(string $title = 'Ops'): int
    {
        return (int) $this->postJson('/api/v1/assistants', array('title' => $title))->json('id');
    }

    public function test_an_assistant_with_no_apps_reads_empty(): void
    {
        $id = $this->assistant();

        $this->getJson("/api/v1/mcp-apps/{$id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('assistant_id', $id)
            ->assertJsonPath('apps', array())
            ->assertJsonPath('app_count', 0);
    }

    public function test_a_saved_list_round_trips_with_defaults(): void
    {
        $id = $this->assistant();

        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(
                array(
                    'label' => 'Weather',
                    'server_url' => 'https://mcp.example.com/mcp',
                    'auth_type' => 'bearer',
                    'timeout' => 45,
                ),
            ),
        ))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('app_count', 1)
            ->assertJsonPath('apps.0.label', 'Weather')
            ->assertJsonPath('apps.0.timeout', 45)
            ->assertJsonPath('apps.0.enabled', true)
            ->assertJsonPath('apps.0.verify_ssl', true);

        $this->getJson("/api/v1/mcp-apps/{$id}")
            ->assertOk()
            ->assertJsonPath('app_count', 1)
            ->assertJsonPath('apps.0.server_url', 'https://mcp.example.com/mcp')
            ->assertJsonPath('apps.0.auth_type', 'bearer');
    }

    public function test_null_boolean_fields_fall_back_to_defaults(): void
    {
        $id = $this->assistant();

        // A partial payload (a panel row that never touched a toggle) must not
        // read as "false": null means "not supplied".
        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(array(
                'server_url' => 'https://mcp.example.com/mcp',
                'enabled' => null,
                'verify_ssl' => null,
                'timeout' => null,
            )),
        ))
            ->assertOk()
            ->assertJsonPath('apps.0.enabled', true)
            ->assertJsonPath('apps.0.verify_ssl', true)
            ->assertJsonPath('apps.0.timeout', 30);
    }

    public function test_a_replace_removes_the_previous_list(): void
    {
        $id = $this->assistant();

        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(
                array('server_url' => 'https://a.example.com/mcp'),
                array('server_url' => 'https://b.example.com/mcp'),
            ),
        ))->assertOk()->assertJsonPath('app_count', 2);

        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(array('server_url' => 'https://c.example.com/mcp')),
        ))->assertOk()->assertJsonPath('app_count', 1);

        $this->assertSame(
            'https://c.example.com/mcp',
            Assistant::find($id)->meta['_wp_mcp_ai_mcp_apps'][0]['server_url']
        );
    }

    public function test_a_token_is_sealed_and_projected_as_a_mask(): void
    {
        $id = $this->assistant();

        $put = $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(
                array(
                    'server_url' => 'https://mcp.example.com/mcp',
                    'token' => 'super-secret-token',
                    'oauth_data' => array('refresh_token' => 'nope'),
                ),
            ),
        ))
            ->assertOk()
            ->assertJsonMissingPath('apps.0.oauth_data');

        // The response carries a uniform mask, never a tail and never a value.
        $this->assertSame(
            "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}",
            $put->json('apps.0.token')
        );

        $stored = Assistant::find($id)->meta['_wp_mcp_ai_mcp_apps'];

        $this->assertArrayHasKey('token_sealed', $stored[0]);
        $this->assertArrayNotHasKey('token', $stored[0]);
        $this->assertArrayNotHasKey('oauth_data', $stored[0]);
        $this->assertStringNotContainsString('super-secret-token', (string) json_encode($stored));

        // Sealed, not lost: the store can open it.
        $this->assertSame(
            'super-secret-token',
            app(\App\Support\CredentialStore::class)->decrypt($stored[0]['token_sealed'])['token']
        );
    }

    public function test_a_blank_token_keeps_the_stored_one(): void
    {
        $id = $this->assistant();
        $url = 'https://mcp.example.com/mcp';

        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(array('server_url' => $url, 'token' => 'first-token')),
        ))->assertOk();

        $sealed = Assistant::find($id)->meta['_wp_mcp_ai_mcp_apps'][0]['token_sealed'];

        // The console's update shape omits token entirely.
        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(array('server_url' => $url, 'label' => 'Renamed')),
        ))
            ->assertOk()
            ->assertJsonPath('apps.0.label', 'Renamed')
            ->assertJsonPath('apps.0.token', "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}");

        $this->assertSame(
            $sealed,
            Assistant::find($id)->meta['_wp_mcp_ai_mcp_apps'][0]['token_sealed']
        );
    }

    public function test_a_read_projection_cannot_be_saved_back_as_a_token(): void
    {
        $id = $this->assistant();
        $url = 'https://mcp.example.com/mcp';

        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(array('server_url' => $url, 'token' => 'first-token')),
        ))->assertOk();

        $sealed = Assistant::find($id)->meta['_wp_mcp_ai_mcp_apps'][0]['token_sealed'];

        // A bulk editor that reads the list and writes it straight back sends
        // the mask. Treating it as a value would seal `••••••••` as the token
        // and silently replace a working credential.
        $read = $this->getJson("/api/v1/mcp-apps/{$id}")->assertOk()->json('apps');

        $this->assertSame("\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}", $read[0]['token']);

        $this->putJson("/api/v1/mcp-apps/{$id}", array('apps' => $read))->assertOk();

        $this->assertSame(
            $sealed,
            Assistant::find($id)->meta['_wp_mcp_ai_mcp_apps'][0]['token_sealed'],
            'A round-tripped mask must keep the sealed token.'
        );
    }

    public function test_more_than_ten_apps_is_rejected(): void
    {
        $id = $this->assistant();

        $apps = array();

        for ($i = 0; $i < 11; $i++) {
            $apps[] = array('server_url' => 'https://mcp'.$i.'.example.com/mcp');
        }

        $this->putJson("/api/v1/mcp-apps/{$id}", array('apps' => $apps))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_a_non_http_server_url_is_rejected(): void
    {
        $id = $this->assistant();

        $this->putJson("/api/v1/mcp-apps/{$id}", array(
            'apps' => array(array('server_url' => 'ftp://mcp.example.com/mcp')),
        ))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_an_empty_payload_is_rejected(): void
    {
        $id = $this->assistant();

        $this->putJson("/api/v1/mcp-apps/{$id}", array())->assertStatus(422);
    }

    public function test_a_subscriber_cannot_read_or_write(): void
    {
        $id = $this->assistant();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/mcp-apps/{$id}")->assertForbidden();
        $this->putJson("/api/v1/mcp-apps/{$id}", array('apps' => array()))->assertForbidden();
    }

    public function test_an_unknown_assistant_is_a_404(): void
    {
        $this->getJson('/api/v1/mcp-apps/999')->assertNotFound();
    }

    /**
     * Route-model binding resolved any id: `Assistant` has no global tenant
     * scope, so without this check an estate administrator could read — and
     * overwrite — another estate's assistant configuration.
     */
    public function test_a_foreign_estate_assistant_is_not_readable_or_writable(): void
    {
        Tenant::query()->create(array(
            'id' => 'estate-b',
            'name' => 'Estate B',
            'slug' => 'estate-b',
        ));

        Sanctum::actingAs(User::factory()->admin()->inEstate('estate-b', 'owner')->create());
        $foreignId = $this->assistant('Estate B assistant');

        // A caller whose request resolves to the default estate.
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/mcp-apps/{$foreignId}")->assertNotFound();
        $this->putJson("/api/v1/mcp-apps/{$foreignId}", array('apps' => array()))->assertNotFound();
    }

    public function test_the_endpoint_is_reported_available(): void
    {
        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.mcpApps', true)
            ->assertJsonPath('data.endpoint_reasons.mcpApps', 'route');
    }
}

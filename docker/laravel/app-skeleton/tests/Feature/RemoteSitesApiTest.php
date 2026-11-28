<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RemoteSites\RemoteSiteStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Tests\Support\FakeHttpClient;
use Tests\TestCase;

/**
 * 090-i — the Connections surface.
 *
 * Two contracts are easy to get wrong and are pinned here:
 *
 *  1. **The page speaks flat.** The wizard posts `{name, connection_type,
 *     <field>…}` and reads `connection[field.key]` back. A nested
 *     `config`/`credentials` payload would leave every form blank.
 *  2. **The Test button does real work.** A 401 is "reached, rejected", not
 *     "unreachable", and an unsupported type says so instead of pretending.
 */
final class RemoteSitesApiTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function connect(FakeHttpClient $http): FakeHttpClient
    {
        $this->app->instance(HttpClientInterface::class, $http);

        return $http;
    }

    /**
     * Create a WordPress connection through the API and return its id.
     */
    private function createWordpress(): string
    {
        return (string) $this->postJson('/api/v1/pro/remote-sites', [
            'name' => 'Client site',
            'connection_type' => 'wordpress',
            'url' => 'https://client.test',
            'username' => 'admin',
            'password' => 'hunter2',
        ])->assertCreated()->json('item.id');
    }

    public function test_the_surface_requires_manage_options(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'editor']));

        $this->getJson('/api/v1/pro/remote-sites')->assertForbidden();
        $this->postJson('/api/v1/pro/remote-sites', [])->assertForbidden();
    }

    public function test_types_returns_the_supported_catalogues(): void
    {
        $this->actAsAdmin();

        $items = $this->getJson('/api/v1/pro/remote-sites/types')->assertOk()->json('items');

        $this->assertSame(['wordpress', 'mcp_server', 'mailjet_mcp'], array_column($items, 'id'));

        $passwordField = collect($items[0]['fields'])->firstWhere('key', 'password');
        $this->assertTrue($passwordField['secret']);
    }

    public function test_create_flattens_the_wizard_payload(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/v1/pro/remote-sites', [
            'name' => 'Client site',
            'connection_type' => 'wordpress',
            'url' => 'https://client.test',
            'username' => 'admin',
            'password' => 'hunter2',
        ])->assertCreated();

        $item = $response->json('item');

        $this->assertSame('pro', $item['source']);
        $this->assertSame('wordpress', $item['connection_type']);
        $this->assertSame('https://client.test', $item['url']);
        // Non-secret settings come back at the top level, where the edit form
        // looks for them.
        $this->assertSame('admin', $item['username']);
        $this->assertTrue($item['has_credentials']);
        $this->assertTrue($item['enabled']);

        // The secret is not in the response, in any form.
        $this->assertArrayNotHasKey('password', $item);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($item));
    }

    public function test_the_secret_is_stored_sealed(): void
    {
        $this->actAsAdmin();
        $id = $this->createWordpress();

        $sealed = (string) DB::table('nvoos_remote_sites')->find($id)->credentials;

        $this->assertStringNotContainsString('hunter2', $sealed);
        $this->assertSame(['password' => 'hunter2'], $this->app->make(RemoteSiteStore::class)->credentialsFor($id));
    }

    public function test_an_unknown_connection_type_is_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/remote-sites', [
            'name' => 'Nope',
            'connection_type' => 'gmail',
        ])->assertStatus(400)->assertJsonPath('code', 'rest_invalid_param');
    }

    public function test_update_with_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->actAsAdmin();
        $id = $this->createWordpress();

        $this->postJson('/api/v1/pro/remote-sites/'.$id, [
            'name' => 'Client site renamed',
            'connection_type' => 'wordpress',
            'url' => 'https://client.test',
            'username' => 'admin',
            'password' => '',
        ])->assertOk()->assertJsonPath('item.name', 'Client site renamed');

        $this->assertSame(
            ['password' => 'hunter2'],
            $this->app->make(RemoteSiteStore::class)->credentialsFor($id),
        );
    }

    public function test_a_metadata_only_update_does_not_blank_the_config(): void
    {
        $this->actAsAdmin();
        $id = $this->createWordpress();

        $item = $this->postJson('/api/v1/pro/remote-sites/'.$id, ['enabled' => false])
            ->assertOk()
            ->json('item');

        $this->assertFalse($item['enabled']);
        $this->assertSame('https://client.test', $item['url']);
        $this->assertSame('admin', $item['username']);
        $this->assertTrue($item['has_credentials']);
    }

    public function test_delete_removes_the_connection(): void
    {
        $this->actAsAdmin();
        $id = $this->createWordpress();

        $this->deleteJson('/api/v1/pro/remote-sites/'.$id)->assertOk()->assertJsonPath('deleted', true);
        $this->deleteJson('/api/v1/pro/remote-sites/'.$id)->assertNotFound();
        $this->assertSame(0, DB::table('nvoos_remote_sites')->count());
    }

    public function test_a_wordpress_connection_verifies_against_users_me(): void
    {
        $this->actAsAdmin();
        $id = $this->createWordpress();

        $http = $this->connect(new FakeHttpClient());
        $http->queue(200, ['id' => 1, 'name' => 'Admin']);

        $result = $this->postJson('/api/v1/pro/remote-sites/'.$id.'/test')->assertOk()->json();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Connected to', $result['message']);

        $this->assertSame('/wp-json/wp/v2/users/me', parse_url($http->requests[0]['url'], PHP_URL_PATH));
        $this->assertStringStartsWith('Basic ', $http->requests[0]['headers']['Authorization']);

        $this->assertSame(
            'ok',
            DB::table('nvoos_remote_sites')->find($id)->last_test_status,
        );
    }

    public function test_a_rejected_password_is_reported_distinctly(): void
    {
        $this->actAsAdmin();
        $id = $this->createWordpress();

        $http = $this->connect(new FakeHttpClient());
        $http->queue(401, ['code' => 'rest_forbidden']);

        $result = $this->postJson('/api/v1/pro/remote-sites/'.$id.'/test')->assertOk()->json();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('rejected the credentials', $result['message']);
        // Reached the site, so it is a failure, not an outage.
        $this->assertStringNotContainsString('Could not reach', $result['message']);

        $this->assertSame(
            'failed',
            DB::table('nvoos_remote_sites')->find($id)->last_test_status,
        );
    }

    public function test_an_mcp_server_verifies_through_the_shared_client(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/v1/pro/remote-sites', [
            'name' => 'Tools server',
            'connection_type' => 'mcp_server',
            'url' => 'https://mcp.example.com/mcp',
            'token' => 'token-123',
        ])->assertCreated()->assertJsonPath('item.auth_type', 'bearer')->json('item.id');

        $http = $this->connect(new FakeHttpClient());
        $http->queue(400, 'Bad Request');                                   // discover rejected (legacy dialect)
        $http->queue(200, ['result' => ['protocolVersion' => '2025-03-26']]); // initialize
        $http->queue(200, ['result' => ['tools' => [['name' => 'search']], 'ttlMs' => 900]]); // tools/list

        $result = $this->postJson('/api/v1/pro/remote-sites/'.$id.'/test')->assertOk()->json();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('lists 1 tool', $result['message']);
        $this->assertSame('Bearer token-123', $http->requests[0]['headers']['Authorization']);
    }

    public function test_an_unsupported_type_says_so_instead_of_pretending(): void
    {
        $this->actAsAdmin();

        DB::table('nvoos_remote_sites')->insert([
            'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            'name' => 'Legacy vendor',
            'url' => 'https://vendor.test',
            'connection_type' => 'legacy_vendor',
            'auth_type' => 'bearer',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->postJson('/api/v1/pro/remote-sites/01HZZZZZZZZZZZZZZZZZZZZZZZ/test')
            ->assertOk()
            ->json();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not implemented', $result['message']);
    }

    public function test_testing_an_unknown_id_is_a_404(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/remote-sites/01HZZZZZZZZZZZZZZZZZZZZZZZ/test')
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_not_found');
    }

    public function test_the_manifest_reports_remote_sites_as_route_supplied(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.remoteSites', true)
            ->assertJsonPath('data.endpoint_reasons.remoteSites', 'route')
            ->assertJsonPath('data.endpoint_gaps', []);
    }

    // ── Estate scope (149 §27) ─────────────────────────────────────────
    //
    // `nvoos_remote_sites` shipped without a tenant column, and the store read
    // and wrote it unscoped while the routes already resolved an estate. Any
    // estate administrator could list, edit, delete and TEST every other
    // estate's connections. These four tests are the regression guard.

    public function test_another_estates_connection_is_not_listed(): void
    {
        $this->connectionIn('estate-b', 'Foreign site');

        $this->actAsAdmin();

        $response = $this->getJson('/api/v1/pro/remote-sites')->assertOk();

        $this->assertStringNotContainsString('Foreign site', $response->getContent());
        // The console contract is flat: `items`/`total` at the root.
        $this->assertSame(array(), $response->json('items'));
        $this->assertSame(0, $response->json('total'));
    }

    public function test_another_estates_connection_cannot_be_updated(): void
    {
        $id = $this->connectionIn('estate-b', 'Foreign site');

        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/remote-sites/'.$id, array('name' => 'Hijacked'))
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_not_found');

        $this->assertSame(
            'Foreign site',
            (string) DB::table('nvoos_remote_sites')->where('id', $id)->value('name'),
            'A cross-estate refusal must not change the row.'
        );
    }

    public function test_another_estates_connection_cannot_be_deleted(): void
    {
        $id = $this->connectionIn('estate-b', 'Foreign site');

        $this->actAsAdmin();

        $this->deleteJson('/api/v1/pro/remote-sites/'.$id)
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_not_found');

        $this->assertSame(1, DB::table('nvoos_remote_sites')->where('id', $id)->count());
    }

    /**
     * `test` performs an outbound request with the connection's sealed
     * credentials. Reaching another estate's row here would both leak its
     * endpoint and send its secret to whatever URL the row names.
     */
    public function test_another_estates_connection_cannot_be_tested(): void
    {
        $id = $this->connectionIn('estate-b', 'Foreign site');

        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/remote-sites/'.$id.'/test')
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_not_found');

        $this->assertNull(
            DB::table('nvoos_remote_sites')->where('id', $id)->value('last_tested_at'),
            'A refused test must not record an outcome.'
        );
    }

    /**
     * A created connection records the estate that created it, so the scoping
     * works in the write direction as well as the read one.
     */
    public function test_a_created_connection_records_its_estate(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/remote-sites', array(
            'name' => 'Own site',
            'connection_type' => 'wordpress',
            'url' => 'https://own.test',
        ))->assertCreated();

        $this->assertSame(
            'default',
            (string) DB::table('nvoos_remote_sites')->value('tenant_id')
        );
    }

    /**
     * Insert a connection straight into another estate.
     */
    private function connectionIn(string $tenantId, string $name): string
    {
        $id = (string) \Illuminate\Support\Str::ulid();

        DB::table('nvoos_remote_sites')->insert(array(
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'url' => 'https://foreign.test',
            'connection_type' => 'wordpress',
            'auth_type' => 'application_password',
            'config' => null,
            'credentials' => null,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ));

        return $id;
    }
}

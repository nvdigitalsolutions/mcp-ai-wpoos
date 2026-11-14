<?php

namespace Tests\Feature;

use App\Support\RemoteSites\RemoteSiteStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * 090-i — the remote-site record (`remoteSites` step a).
 *
 * The assertions that matter are about leaks, not CRUD: no read may return a
 * credential, the database column must not contain one, and a secret smuggled
 * into the non-secret `config` bag must be rejected rather than echoed back.
 */
final class RemoteSiteStoreTest extends TestCase
{
    use RefreshDatabase;

    private function store(): RemoteSiteStore
    {
        return $this->app->make(RemoteSiteStore::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function acme(): array
    {
        return [
            'name' => 'Acme',
            'url' => 'https://acme.test/wp-json',
            'connection_type' => 'wordpress',
            'auth_type' => 'basic_auth',
            'config' => ['username' => 'bob'],
            'credentials' => ['password' => 'hunter2'],
            'enabled' => true,
        ];
    }

    public function test_create_returns_a_redacted_record(): void
    {
        $record = $this->store()->create($this->acme());

        $this->assertSame('Acme', $record['name']);
        $this->assertSame('https://acme.test/wp-json', $record['url']);
        $this->assertSame('basic_auth', $record['auth_type']);
        $this->assertTrue($record['enabled']);
        $this->assertSame(['username' => 'bob'], (array) $record['config']);

        $this->assertTrue($record['has_credentials']);
        $this->assertSame('••••••••', $record['credentials_mask']);

        // No read path may carry the secret.
        $this->assertStringNotContainsString('hunter2', (string) json_encode($record));
    }

    public function test_the_stored_credential_is_sealed(): void
    {
        $record = $this->store()->create($this->acme());

        $sealed = (string) DB::table('nvoos_remote_sites')->find($record['id'])->credentials;

        $this->assertStringNotContainsString('hunter2', $sealed);
        $this->assertSame(['password' => 'hunter2'], $this->store()->credentialsFor($record['id']));
    }

    public function test_a_blank_credential_on_update_keeps_the_stored_one(): void
    {
        $record = $this->store()->create($this->acme());

        $this->store()->update($record['id'], ['credentials' => ['password' => '']]);

        $this->assertSame(['password' => 'hunter2'], $this->store()->credentialsFor($record['id']));
    }

    public function test_a_new_credential_replaces_the_stored_one(): void
    {
        $record = $this->store()->create($this->acme());

        $this->store()->update($record['id'], ['credentials' => ['password' => 'correct-horse']]);

        $this->assertSame(['password' => 'correct-horse'], $this->store()->credentialsFor($record['id']));
    }

    public function test_a_partial_update_leaves_other_credentials_intact(): void
    {
        $record = $this->store()->create([
            'name' => 'Acme',
            'credentials' => ['password' => 'hunter2', 'api_key' => 'k-123'],
        ]);

        $this->store()->update($record['id'], ['credentials' => ['api_key' => 'k-456']]);

        $this->assertSame(
            ['password' => 'hunter2', 'api_key' => 'k-456'],
            $this->store()->credentialsFor($record['id']),
        );
    }

    public function test_listing_never_exposes_a_credential(): void
    {
        $this->store()->create($this->acme());

        $listed = $this->store()->all();

        $this->assertCount(1, $listed);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($listed));
        $this->assertArrayNotHasKey('credentials', $listed[0]);
    }

    public function test_a_secret_in_the_config_bag_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->create([
            'name' => 'Acme',
            'config' => ['api_key' => 'k-123'],
        ]);
    }

    public function test_an_unknown_auth_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->create(['name' => 'Acme', 'auth_type' => 'magic']);
    }

    public function test_a_non_http_url_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->create(['name' => 'Acme', 'url' => 'ftp://acme.test']);
    }

    public function test_a_name_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->create(['name' => '   ']);
    }

    public function test_an_optional_url_stays_null(): void
    {
        $record = $this->store()->create(['name' => 'No URL yet']);

        $this->assertSame('', $record['url']);
        $this->assertNull(DB::table('nvoos_remote_sites')->find($record['id'])->url);
    }

    public function test_find_returns_null_for_an_unknown_id(): void
    {
        $this->assertNull($this->store()->find('01HZZZZZZZZZZZZZZZZZZZZZZZ'));
    }

    public function test_update_returns_null_for_an_unknown_id(): void
    {
        $this->assertNull($this->store()->update('01HZZZZZZZZZZZZZZZZZZZZZZZ', ['name' => 'Nope']));
    }

    public function test_delete_removes_the_row(): void
    {
        $record = $this->store()->create($this->acme());

        $this->assertTrue($this->store()->delete($record['id']));
        $this->assertFalse($this->store()->delete($record['id']));
        $this->assertNull($this->store()->find($record['id']));
    }

    public function test_updating_metadata_does_not_touch_the_credentials(): void
    {
        $record = $this->store()->create($this->acme());

        $updated = $this->store()->update($record['id'], [
            'name' => 'Acme Renamed',
            'enabled' => false,
        ]);

        $this->assertSame('Acme Renamed', $updated['name']);
        $this->assertFalse($updated['enabled']);
        $this->assertSame(['password' => 'hunter2'], $this->store()->credentialsFor($record['id']));
    }
}

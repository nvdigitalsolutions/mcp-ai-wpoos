<?php

namespace App\Support\RemoteSites;

use App\Support\CredentialStore;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Outbound connections to other services (090-i, `remoteSites` step a).
 *
 * The WordPress implementation is 6,452 lines because it knows fifteen
 * connection types, their field schemas, per-provider verification and a legacy
 * cipher. This is the part that has to be right before any of that: a record
 * that stores credentials **sealed**, and reads that cannot leak them.
 *
 * Three rules the class enforces rather than documents:
 *
 *  1. **Secrets never round-trip.** No method returns a credential. Reads carry
 *     `has_credentials` and a uniform mask; `credentialsFor()` is the only way
 *     to open them and is for use inside the runtime (job, verifier), never for
 *     a response.
 *  2. **`config` cannot hold a secret.** A caller that puts `api_key` in the
 *     non-secret bag is rejected instead of having it echoed back on a list.
 *  3. **A blank credential keeps the stored one.** The console's update
 *     contract is "leave it blank to keep it"; `CredentialStore` already skips
 *     blanks, so an update merges rather than replaces.
 *  4. **Every read and write is estate-scoped.** `nvoos_remote_sites` grew a
 *     `tenant_id` in 149 §27, because without it every tenant user could list,
 *     edit and delete every estate's connections — a cross-tenant READ and
 *     WRITE, the same class of defect §19 fixed for credentials. The estate is
 *     a resolver closure rather than a constructor value so it is read at query
 *     time: this store can be built before tenant middleware runs.
 *
 * Verification, the type catalogue and the routes belong to steps (b) and (c);
 * `remoteSites` stays unavailable in the capability manifest until (c) lands.
 */
final class RemoteSiteStore
{
    /** Mirrors `WP_MCP_AI_Pro_Remote_Site_Manager::AUTH_TYPES`. */
    public const AUTH_TYPES = [
        'application_password',
        'basic_auth',
        'jwt',
        'woocommerce',
        'custom_header',
        'bearer',
        'oauth',
        'none',
    ];

    /**
     * Field names that must be sealed. Mirrors the WordPress list, so a
     * connection exported from either runtime uses the same vocabulary.
     */
    public const SECRET_FIELDS = [
        'api_key',
        'api_secret',
        'client_secret',
        'bot_token',
        'password',
        'access_token',
        'access_token_secret',
        'refresh_token',
        'webhook_secret',
        'private_key',
        'mesh_inbound_api_key',
        'consumer_key',
        'consumer_secret',
        'app_password',
        'auth_code',
        'bearer_token',
        'signing_secret',
        'public_key',
        'encryption_key',
        'mcp_oauth',
        'verify_token',
        'verification_token',
        'token',
    ];

    /** The uniform read mask — never varies with the credential. */
    private const MASK = "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}";

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly Closure $tenant
    ) {
    }

    /**
     * Every connection in the current estate, redacted, alphabetically.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (! Schema::hasTable('nvoos_remote_sites')) {
            return [];
        }

        return $this->query()
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => $this->redact($row))
            ->all();
    }

    /**
     * One connection, redacted, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $row = $this->row($id);

        return $row === null ? null : $this->redact($row);
    }

    /**
     * Create a connection.
     *
     * @param array<string, mixed> $data name, url, connection_type, auth_type,
     *                                   config, credentials, enabled.
     *
     * @return array<string, mixed> The redacted record.
     */
    public function create(array $data): array
    {
        $name = $this->name($data);
        $id = (string) Str::ulid();

        $this->query()->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId(),
            'name' => $name,
            'url' => $this->url($data),
            'connection_type' => $this->connectionType($data),
            'auth_type' => $this->authType($data),
            'config' => $this->encodeConfig($data['config'] ?? []),
            'credentials' => $this->credentials->encrypt($this->credentialInput($data)),
            'enabled' => ! empty($data['enabled']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (array) $this->find($id);
    }

    /**
     * Update a connection, merging credentials rather than replacing them.
     *
     * @param array<string, mixed> $data Any of the create fields; absent keys
     *                                   are left untouched, blank credentials
     *                                   keep their stored value.
     *
     * @return array<string, mixed>|null The redacted record, or null when unknown.
     */
    public function update(string $id, array $data): ?array
    {
        $row = $this->row($id);

        if ($row === null) {
            return null;
        }

        $changes = ['updated_at' => now()];

        if (array_key_exists('name', $data)) {
            $changes['name'] = $this->name($data);
        }

        if (array_key_exists('url', $data)) {
            $changes['url'] = $this->url($data);
        }

        if (array_key_exists('connection_type', $data)) {
            $changes['connection_type'] = $this->connectionType($data);
        }

        if (array_key_exists('auth_type', $data)) {
            $changes['auth_type'] = $this->authType($data);
        }

        if (array_key_exists('config', $data)) {
            $changes['config'] = $this->encodeConfig($data['config']);
        }

        if (array_key_exists('enabled', $data)) {
            $changes['enabled'] = ! empty($data['enabled']);
        }

        $incoming = $this->credentialInput($data);

        if ([] !== $incoming) {
            // Blank values are dropped *before* the merge, not after: merging
            // first would overwrite the stored value with an empty string and
            // then drop it, silently deleting the credential.
            $changes['credentials'] = $this->credentials->encrypt(
                array_merge(
                    $this->credentials->decrypt((string) $row->credentials),
                    array_filter(
                        $incoming,
                        static fn ($value): bool => is_string($value) && '' !== trim($value),
                    ),
                ),
            );
        }

        $this->query()->where('id', $id)->update($changes);

        return $this->find($id);
    }

    /**
     * Delete a connection.
     */
    public function delete(string $id): bool
    {
        if ($this->row($id) === null) {
            return false;
        }

        $this->query()->where('id', $id)->delete();

        return true;
    }

    /**
     * Open a connection's credentials.
     *
     * **Runtime use only** — a verifier or a job. Never serialise the result
     * into a response.
     *
     * @return array<string, string>
     */
    public function credentialsFor(string $id): array
    {
        $row = $this->row($id);

        return $row === null ? [] : $this->credentials->decrypt((string) $row->credentials);
    }

    /**
     * Record the outcome of a verification.
     *
     * Written by the Test action, not by the store's write methods: a
     * connection can exist before it has ever been tested, and an edit does not
     * make a previous test result fresh again.
     */
    public function recordTest(string $id, bool $success): void
    {
        if (! Schema::hasTable('nvoos_remote_sites')) {
            return;
        }

        $this->query()->where('id', $id)->update([
            'last_tested_at' => now(),
            'last_test_status' => $success ? 'ok' : 'failed',
            'updated_at' => now(),
        ]);
    }

    /**
     * The write shape: everything a caller may see.
     *
     * @return array<string, mixed>
     */
    private function redact(object $row): array
    {
        $sealed = (string) ($row->credentials ?? '');

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'url' => (string) ($row->url ?? ''),
            'connection_type' => (string) $row->connection_type,
            'auth_type' => (string) $row->auth_type,
            'config' => json_decode((string) ($row->config ?? ''), true) ?: new \stdClass(),
            'enabled' => (bool) $row->enabled,
            'has_credentials' => $this->credentials->has($sealed),
            'credentials_mask' => $this->credentials->has($sealed) ? self::MASK : '',
            'last_tested_at' => $row->last_tested_at,
            'last_test_status' => $row->last_test_status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    private function row(string $id): ?object
    {
        if (! Schema::hasTable('nvoos_remote_sites')) {
            return null;
        }

        return $this->query()->find($id);
    }

    /**
     * The estate this store speaks for, read at query time.
     *
     * A resolver rather than a constructor value: the store can be resolved
     * before `ResolveApiTenant` has run, and a value captured then would be the
     * default estate forever.
     */
    private function tenantId(): string
    {
        return (string) ($this->tenant)();
    }

    /**
     * Every query in this class starts here, so no method can forget the scope.
     */
    private function query(): Builder
    {
        return DB::table('nvoos_remote_sites')->where('tenant_id', $this->tenantId());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function name(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ('' === $name) {
            throw new InvalidArgumentException('A connection name is required.');
        }

        return mb_substr($name, 0, 100);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function url(array $data): ?string
    {
        $url = trim((string) ($data['url'] ?? ''));

        if ('' === $url) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('The connection URL must be an http(s) URL.');
        }

        return mb_substr($url, 0, 255);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function connectionType(array $data): string
    {
        $type = strtolower(trim((string) ($data['connection_type'] ?? 'wordpress')));

        return '' === $type ? 'wordpress' : mb_substr($type, 0, 40);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function authType(array $data): string
    {
        $auth = strtolower(trim((string) ($data['auth_type'] ?? 'none')));

        if (! in_array($auth, self::AUTH_TYPES, true)) {
            throw new InvalidArgumentException('Unknown auth type: '.$auth.'.');
        }

        return $auth;
    }

    /**
     * Encode non-secret settings, refusing anything that belongs in the vault.
     *
     * @param mixed $config
     */
    private function encodeConfig(mixed $config): ?string
    {
        if (! is_array($config) || [] === $config) {
            return null;
        }

        foreach (array_keys($config) as $key) {
            if (in_array(strtolower((string) $key), self::SECRET_FIELDS, true)) {
                throw new InvalidArgumentException(
                    sprintf('`%s` is a credential; send it in `credentials`, not `config`.', (string) $key),
                );
            }
        }

        return (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The credential map from a write payload.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function credentialInput(array $data): array
    {
        $credentials = $data['credentials'] ?? [];

        return is_array($credentials) ? $credentials : [];
    }
}

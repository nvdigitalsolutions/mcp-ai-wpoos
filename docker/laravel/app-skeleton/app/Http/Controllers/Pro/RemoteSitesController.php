<?php

namespace App\Http\Controllers\Pro;

use App\Support\RemoteSites\RemoteSiteStore;
use App\Support\RemoteSites\RemoteSiteTypes;
use App\Support\RemoteSites\RemoteSiteVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Remote sites / connections (`/api/v1/pro/remote-sites`).
 *
 * The console contract, which the wizard depends on:
 *
 *   GET    /remote-sites              → { items, total }
 *   GET    /remote-sites/types        → { items }
 *   POST   /remote-sites              → { item }
 *   POST   /remote-sites/{id}         → { item }   (partial; blank secret = keep)
 *   DELETE /remote-sites/{id}
 *   POST   /remote-sites/{id}/test    → { success, message }
 *
 * Two shape rules come from the page rather than from taste:
 *
 *  - **Requests are flat.** The wizard posts `{name, connection_type, <field>…}`
 *    and never nests `config` or `credentials`, so this controller splits the
 *    payload using the type's field definitions.
 *  - **Responses are flat too.** `openEdit()` reads `connection[field.key]`, so
 *    non-secret settings are lifted out of `config` to the top level. Secrets
 *    are never lifted — the record carries `has_credentials` only.
 *
 * Credentials are sealed by {@see RemoteSiteStore}; `credentialsFor()` is only
 * ever called here to hand them to the verifier.
 */
class RemoteSitesController
{
    /**
     * GET /remote-sites — every connection, redacted.
     */
    public function index(RemoteSiteStore $sites): JsonResponse
    {
        $items = array_map([$this, 'present'], $sites->all());

        return response()->json(['items' => $items, 'total' => count($items)]);
    }

    /**
     * GET /remote-sites/types — the wizard catalogue.
     */
    public function types(): JsonResponse
    {
        return response()->json(['items' => RemoteSiteTypes::all()]);
    }

    /**
     * POST /remote-sites — create from the wizard payload.
     */
    public function store(Request $request, RemoteSiteStore $sites): JsonResponse
    {
        $definition = RemoteSiteTypes::find((string) $request->input('connection_type', ''));

        if (null === $definition) {
            return $this->invalidType((string) $request->input('connection_type', ''));
        }

        try {
            $record = $sites->create($this->payload($request, $definition) + [
                // The console wizard has no enable toggle, and a connection the
                // operator deliberately created should be usable. An explicit
                // `enabled` in the payload still wins.
                'enabled' => $request->exists('enabled') ? (bool) $request->input('enabled') : true,
            ]);
        } catch (InvalidArgumentException $error) {
            return $this->invalid($error->getMessage());
        }

        return response()->json(['item' => $this->present($record)], 201);
    }

    /**
     * POST /remote-sites/{id} — partial update.
     */
    public function update(string $id, Request $request, RemoteSiteStore $sites): JsonResponse
    {
        $record = $sites->find($id);

        if (null === $record) {
            return $this->notFound();
        }

        $definition = RemoteSiteTypes::find((string) $record['connection_type']);

        if (null === $definition) {
            return $this->invalidType((string) $record['connection_type']);
        }

        $payload = $this->payload($request, $definition);

        // The type of an existing connection is fixed; everything else is only
        // included when the request touched it, so a metadata-only update
        // cannot blank settings by omission.
        unset($payload['connection_type']);

        $configTouched = $this->touches($request, $definition, false);
        $secretsTouched = $this->touches($request, $definition, true);

        if (! $configTouched) {
            unset($payload['config']);
        }

        if (! $secretsTouched) {
            unset($payload['credentials']);
        }

        if (! $configTouched && ! $secretsTouched) {
            // Auth type is derived from what was supplied; with nothing
            // supplied it must keep its stored value.
            unset($payload['auth_type']);
        }

        if ($request->exists('enabled')) {
            $payload['enabled'] = (bool) $request->input('enabled');
        }

        try {
            $updated = $sites->update($id, $payload);
        } catch (InvalidArgumentException $error) {
            return $this->invalid($error->getMessage());
        }

        return response()->json(['item' => $this->present((array) $updated)]);
    }

    /**
     * DELETE /remote-sites/{id}.
     */
    public function destroy(string $id, RemoteSiteStore $sites): JsonResponse
    {
        return $sites->delete($id)
            ? response()->json(['deleted' => true])
            : $this->notFound();
    }

    /**
     * POST /remote-sites/{id}/test — verify against the provider.
     */
    public function test(string $id, RemoteSiteStore $sites, RemoteSiteVerifier $verifier): JsonResponse
    {
        $record = $sites->find($id);

        if (null === $record) {
            return $this->notFound();
        }

        // Non-secret fields (a WordPress username, say) are needed for the
        // check, so the verifier sees one merged map; credentials win.
        $result = $verifier->verify(
            (string) $record['connection_type'],
            (string) $record['url'],
            array_merge((array) $record['config'], $sites->credentialsFor($id)),
        );

        $sites->recordTest($id, $result['success']);

        return response()->json($result);
    }

    /**
     * Split a flat wizard payload using the type's field definitions.
     *
     * Only keys the request actually carries are included, so an edit does not
     * blank a field the client did not send (and a blank secret never reaches
     * the merge at all — the client omits it, and the store would keep the
     * stored value anyway).
     *
     * @param array{id: string, label: string, category: string, help: string, fields: list<array{key: string, label: string, secret?: bool, required?: bool, help?: string, placeholder?: string}>} $definition
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, array $definition): array
    {
        $payload = [];
        $url = '';
        $config = [];
        $credentials = [];

        foreach ($definition['fields'] as $field) {
            $key = $field['key'];

            if (! $request->exists($key)) {
                continue;
            }

            $value = $request->input($key);

            if (! is_string($value)) {
                continue;
            }

            if ('url' === $key) {
                $url = $value;
                continue;
            }

            if (! empty($field['secret'])) {
                $credentials[$key] = $value;
            } else {
                $config[$key] = $value;
            }
        }

        if ($request->exists('name')) {
            $payload['name'] = (string) $request->input('name');
        }

        if ($request->exists('url')) {
            $payload['url'] = $url;
        }

        $payload['connection_type'] = $definition['id'];
        $payload['auth_type'] = $this->authType($definition['id'], $config, $credentials);
        $payload['config'] = $config;
        $payload['credentials'] = $credentials;

        return $payload;
    }

    /**
     * Did the request touch any field of this kind?
     *
     * @param array{id: string, label: string, category: string, help: string, fields: list<array{key: string, label: string, secret?: bool, required?: bool, help?: string, placeholder?: string}>} $definition
     */
    private function touches(Request $request, array $definition, bool $secret): bool
    {
        foreach ($definition['fields'] as $field) {
            if ((! empty($field['secret'])) !== $secret) {
                continue;
            }

            if ($request->exists($field['key'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The auth type is derived from the type and what was supplied.
     *
     * A WordPress connection always uses an application password; an MCP server
     * uses a bearer token when one is present.
     *
     * @param array<string, string> $config
     * @param array<string, string> $credentials
     */
    private function authType(string $type, array $config, array $credentials): string
    {
        return match ($type) {
            'wordpress' => 'application_password',
            'mcp_server' => '' !== trim((string) ($credentials['token'] ?? '')) ? 'bearer' : 'none',
            default => 'none',
        };
    }

    /**
     * Flatten a stored record for the page.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function present(array $record): array
    {
        $item = [
            'id' => (string) $record['id'],
            'source' => 'pro',
            'name' => (string) $record['name'],
            'connection_type' => (string) $record['connection_type'],
            'auth_type' => (string) $record['auth_type'],
            'url' => (string) $record['url'],
            'enabled' => (bool) $record['enabled'],
            'has_credentials' => (bool) $record['has_credentials'],
            'last_tested_at' => $record['last_tested_at'],
            'last_test_status' => $record['last_test_status'],
        ];

        // Non-secret settings live at the top level: `openEdit()` reads
        // `connection[field.key]`. Secrets are not in `config` by construction.
        foreach ((array) $record['config'] as $key => $value) {
            $item[(string) $key] = $value;
        }

        return $item;
    }

    private function invalidType(string $type): JsonResponse
    {
        return $this->invalid(
            sprintf('The "%s" connection type is not available on this runtime.', $type),
        );
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(
            [
                'code' => 'rest_invalid_param',
                'message' => $message,
                'data' => ['status' => 400],
            ],
            400,
        );
    }

    private function notFound(): JsonResponse
    {
        return response()->json(
            [
                'code' => 'rest_not_found',
                'message' => 'No connection with that id.',
                'data' => ['status' => 404],
            ],
            404,
        );
    }
}

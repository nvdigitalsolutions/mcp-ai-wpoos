<?php

namespace App\Support\RemoteSites;

/**
 * The connection types the console can create and verify (090-i, `remoteSites`).
 *
 * WordPress knows fifteen types, most of them vendor integrations (Gmail,
 * Telegram, WhatsApp, QuickBooks MCP, Upwork, FlowHub…), each with its own
 * OAuth dance and field schema. Listing those here before their integrations
 * exist would put a Test button on screen that can only fail, which is the one
 * thing the plan says is worse than a hidden page.
 *
 * So the catalogue is what the runtime can actually do today:
 *
 *  - **`wordpress`** — another WordPress site, application password, verified
 *    against `wp-json/wp/v2/users/me`.
 *  - **`mcp_server`** — an MCP server over Streamable HTTP, optional bearer
 *    token, verified by the shared handshake (dialect negotiation + tools/list).
 *  - **`mailjet_mcp`** — a Mailjet API key pair with an optional Streamable HTTP
 *    bridge. Verified by an MCP handshake when a bridge URL is saved, and by
 *    `GET /v3/REST/user` otherwise. The upstream Mailjet MCP server is stdio
 *    only and read-only, so the bridge is never assumed to exist.
 *
 * Both of the first two are also the kinds the console's commerce site picker
 * and the assistant editor consume. Vendor types stay in wp-admin until their
 * verification lands, and adding one is a matter of appending here plus a
 * branch in {@see RemoteSiteVerifier}.
 *
 * The field shape matches `RemoteSiteTypeField` in `api/remote-sites.ts`; the
 * page renders the form from it and posts the values **flat**, which is why the
 * controller splits them rather than the client.
 */
final class RemoteSiteTypes
{
    /**
     * Type definitions, keyed by id.
     *
     * `secret` fields are sealed and never echoed; every other field lands in
     * the connection's redacted `config` and comes back at the top level of a
     * list item (the page reads `connection[field.key]`).
     *
     * @var array<string, array{id: string, label: string, category: string, help: string, fields: list<array{key: string, label: string, secret?: bool, required?: bool, help?: string, placeholder?: string}>}>
     */
    private const TYPES = [
        'wordpress' => [
            'id' => 'wordpress',
            'label' => 'WordPress site',
            'category' => 'Website',
            'help' => 'Connect to another WordPress site with an application password.',
            'fields' => [
                [
                    'key' => 'url',
                    'label' => 'Site URL',
                    'required' => true,
                    'placeholder' => 'https://example.com',
                ],
                [
                    'key' => 'username',
                    'label' => 'Username',
                    'required' => true,
                    'help' => 'The WordPress user the application password belongs to.',
                ],
                [
                    'key' => 'password',
                    'label' => 'Application password',
                    'secret' => true,
                    'required' => true,
                    'help' => 'Users → Profile → Application Passwords.',
                ],
            ],
        ],
        'mcp_server' => [
            'id' => 'mcp_server',
            'label' => 'MCP server',
            'category' => 'AI',
            'help' => 'Connect to an MCP server over Streamable HTTP.',
            'fields' => [
                [
                    'key' => 'url',
                    'label' => 'Server URL',
                    'required' => true,
                    'placeholder' => 'https://mcp.example.com/mcp',
                ],
                [
                    'key' => 'token',
                    'label' => 'Bearer token',
                    'secret' => true,
                    'help' => 'Leave blank when the server requires no token.',
                ],
            ],
        ],
        'mailjet_mcp' => [
            'id' => 'mailjet_mcp',
            'label' => 'Mailjet',
            'category' => 'Email',
            'help' => 'A Mailjet API key pair. Add a bridge URL to expose the read-only Mailjet MCP tool surface; leave it blank to use this as a credential source only.',
            'fields' => [
                [
                    'key' => 'url',
                    'label' => 'API base URL',
                    'required' => true,
                    'placeholder' => 'https://api.mailjet.com',
                    'help' => 'Informational. The region below decides which API host is actually used.',
                ],
                [
                    'key' => 'api_key',
                    'label' => 'API key',
                    'secret' => true,
                    'required' => true,
                    'help' => 'Mailjet → Account → API Key Management. Each key is its own subaccount.',
                ],
                [
                    'key' => 'api_secret',
                    'label' => 'API secret',
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'region',
                    'label' => 'Region',
                    'placeholder' => 'us',
                    'help' => 'us or eu. Mailjet runs independent API regions; a key created in one does not authenticate against the other.',
                ],
                [
                    'key' => 'mailjet_mcp_url',
                    'label' => 'MCP bridge URL',
                    'placeholder' => 'https://mailjet-mcp.example.com/mcp',
                    'help' => 'Optional. The official Mailjet server speaks stdio only, so this must be a deployed Streamable HTTP bridge. Blank means credential-only.',
                ],
                [
                    'key' => 'from_email',
                    'label' => 'Default from address',
                    'help' => 'Optional. Sender addresses are validated per API key, so each subaccount may need its own.',
                ],
            ],
        ],
    ];

    /**
     * The catalogue, in a stable order.
     *
     * @return list<array{id: string, label: string, category: string, help: string, fields: list<array{key: string, label: string, secret?: bool, required?: bool, help?: string, placeholder?: string}>}>
     */
    public static function all(): array
    {
        return array_values(self::TYPES);
    }

    /**
     * One definition, or null for a type this runtime does not serve.
     *
     * @return array{id: string, label: string, category: string, help: string, fields: list<array{key: string, label: string, secret?: bool, required?: bool, help?: string, placeholder?: string}>}|null
     */
    public static function find(string $id): ?array
    {
        return self::TYPES[$id] ?? null;
    }
}

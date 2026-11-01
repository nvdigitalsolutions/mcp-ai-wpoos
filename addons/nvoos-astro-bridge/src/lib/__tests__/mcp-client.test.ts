/**
 * Wire-shape conformance for the /mcp client (Proposal 095 §4.1) — the
 * modern 2026-07-28 dialect the storefront handshake tests pin: SEP-2243
 * headers, the params._meta envelope, a per-call UUID id, default
 * (unset) credentials, and the 401 / HTTP-error / JSON-RPC-error /
 * structuredContent / text-content resolution paths.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();

type ClientModule = typeof import('../mcp-client');

let mod: ClientModule;

beforeEach(async () => {
	vi.resetModules();
	vi.stubGlobal('fetch', fetchMock);
	fetchMock.mockReset();
	vi.spyOn(crypto, 'randomUUID').mockReturnValue(
		'11111111-2222-3333-4444-555555555555'
	);
	mod = await import('../mcp-client');
});

afterEach(() => {
	vi.unstubAllGlobals();
	vi.restoreAllMocks();
});

describe('mcpCall wire shape', () => {
	it('posts the modern tools/call envelope to /mcp with default credentials', async () => {
		fetchMock.mockResolvedValue({
			ok: true,
			status: 200,
			statusText: 'OK',
			json: async () => ({
				jsonrpc: '2.0',
				id: '11111111-2222-3333-4444-555555555555',
				result: { structuredContent: { semantic: {} } },
			}),
		});

		await mod.mcpCall('tokens_get', { include_groups: false });

		expect(fetchMock).toHaveBeenCalledTimes(1);
		const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
		expect(url).toBe('/mcp');
		expect(init.method).toBe('POST');
		// The default ('same-origin') carries the /manage session cookie —
		// an explicit credentials key must never appear.
		expect(init.credentials).toBeUndefined();
		expect(init.headers).toEqual({
			accept: 'application/json',
			'content-type': 'application/json',
			'MCP-Protocol-Version': '2026-07-28',
			'Mcp-Method': 'tools/call',
			'Mcp-Name': 'tokens_get',
		});
		expect(JSON.parse(init.body as string)).toEqual({
			jsonrpc: '2.0',
			id: '11111111-2222-3333-4444-555555555555',
			method: 'tools/call',
			params: {
				name: 'tokens_get',
				arguments: { include_groups: false },
				_meta: {
					'io.modelcontextprotocol/protocolVersion': '2026-07-28',
					'io.modelcontextprotocol/clientCapabilities': {},
				},
			},
		});
	});

	it('mirrors the tool name in the Mcp-Name header and params.name', async () => {
		fetchMock.mockResolvedValue({
			ok: true,
			status: 200,
			statusText: 'OK',
			json: async () => ({ jsonrpc: '2.0', id: 'x', result: { structuredContent: {} } }),
		});

		await mod.mcpCall('tokens_set', { changes: { 'color.bg': '#000' } });

		const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
		expect((init.headers as Record<string, string>)['Mcp-Name']).toBe('tokens_set');
		const body = JSON.parse(init.body as string) as {
			params: { name: string; arguments: Record<string, unknown> };
		};
		expect(body.params.name).toBe('tokens_set');
		expect(body.params.arguments).toEqual({ changes: { 'color.bg': '#000' } });
	});
});

describe('mcpCall response handling', () => {
	it('throws the sign-in-again message on 401', async () => {
		fetchMock.mockResolvedValue({ ok: false, status: 401, statusText: 'Unauthorized' });
		await expect(mod.mcpCall('tokens_set', { changes: {} })).rejects.toThrow(
			'write tools require a manager session — sign in again'
		);
	});

	it('throws the status text on other HTTP failures', async () => {
		fetchMock.mockResolvedValue({
			ok: false,
			status: 429,
			statusText: 'Too Many Requests',
		});
		await expect(mod.mcpCall('tokens_get')).rejects.toThrow('Too Many Requests');
	});

	it('throws the JSON-RPC error message when the body carries one', async () => {
		fetchMock.mockResolvedValue({
			ok: true,
			status: 200,
			statusText: 'OK',
			json: async () => ({
				jsonrpc: '2.0',
				id: 'x',
				error: { code: -32602, message: 'invalid args' },
			}),
		});
		await expect(mod.mcpCall('tokens_set', { changes: {} })).rejects.toThrow(
			'invalid args'
		);
	});

	it('resolves structuredContent when present', async () => {
		fetchMock.mockResolvedValue({
			ok: true,
			status: 200,
			statusText: 'OK',
			json: async () => ({
				jsonrpc: '2.0',
				id: 'x',
				result: { structuredContent: { applied: true } },
			}),
		});
		await expect(mod.mcpCall('tokens_set', { changes: {} })).resolves.toEqual({
			applied: true,
		});
	});

	it('falls back to parsing the first text content entry', async () => {
		fetchMock.mockResolvedValue({
			ok: true,
			status: 200,
			statusText: 'OK',
			json: async () => ({
				jsonrpc: '2.0',
				id: 'x',
				result: { content: [{ type: 'text', text: '{"ratio": 4.5}' }] },
			}),
		});
		await expect(
			mod.mcpCall('contrast_check', { fg: '#fff', bg: '#000' })
		).resolves.toEqual({ ratio: 4.5 });
	});
});

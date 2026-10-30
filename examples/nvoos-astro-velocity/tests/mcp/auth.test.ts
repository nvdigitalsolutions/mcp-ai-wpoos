// auth.test.ts — pure auth-helper matrix (Proposal 095 §4.2): bearer
// timing-safe compare, manager-session verification, write-tool detection.
import { describe, expect, it, vi } from 'vitest';
import {
	WRITE_TOOLS,
	authorizeRequest,
	constantTimeEqual,
	isWriteToolCall,
	verifyBearer,
	verifyManagerSession,
} from '../../mcp/auth';

describe('auth helpers', () => {
	it('compares tokens timing-safely', () => {
		expect(constantTimeEqual('abc', 'abc')).toBe(true);
		expect(constantTimeEqual('abc', 'abd')).toBe(false);
		expect(constantTimeEqual('abc', 'abcdef')).toBe(false);
	});

	it('parses bearer headers and requires a configured token', () => {
		expect(verifyBearer('Bearer sekret', 'sekret')).toBe(true);
		expect(verifyBearer('bearer sekret', 'sekret')).toBe(true);
		expect(verifyBearer('Bearer sekret', 'other')).toBe(false);
		expect(verifyBearer('Bearer sekret', undefined)).toBe(false);
		expect(verifyBearer(undefined, 'sekret')).toBe(false);
	});

	it('classifies write tool calls', () => {
		expect(isWriteToolCall({ method: 'tools/call', params: { name: 'tokens_set' } })).toBe(true);
		expect(isWriteToolCall({ method: 'tools/call', params: { name: 'tokens_get' } })).toBe(false);
		expect(isWriteToolCall({ method: 'tools/list', params: {} })).toBe(false);
		expect(isWriteToolCall(undefined)).toBe(false);
		expect(WRITE_TOOLS.has('theme_apply')).toBe(true);
	});

	it('verifies manager sessions via the contract auth endpoint', async () => {
		const fetcher = vi.fn(async () => new Response(JSON.stringify({ canManage: true }), { status: 200 }));
		await expect(verifyManagerSession('auth=1', 'http://localhost:8123', fetcher)).resolves.toBe(true);
		expect(fetcher).toHaveBeenCalledWith('http://localhost:8123/auth/me', expect.objectContaining({ headers: expect.objectContaining({ cookie: 'auth=1' }) }));

		const denied = vi.fn(async () => new Response('{}', { status: 401 }));
		await expect(verifyManagerSession('auth=1', 'http://localhost:8123', denied)).resolves.toBe(false);
		await expect(verifyManagerSession(undefined, 'http://localhost:8123', fetcher)).resolves.toBe(false);
	});

	it('authorizes reads openly, writes via bearer or manager, fails closed otherwise', async () => {
		const env = { NVOOS_MCP_TOKEN: 'tok', NVOOS_SSR_API_ORIGIN: 'http://localhost:8123' };
		expect(await authorizeRequest({ headers: {} }, { method: 'tools/list', params: {} }, env)).toEqual({ ok: true });

		expect(
			await authorizeRequest(
				{ headers: { authorization: 'Bearer tok' } },
				{ method: 'tools/call', params: { name: 'tokens_set' } },
				env
			)
		).toEqual({ ok: true, bearer: true });

		const managerFetcher = vi.fn(async () => new Response(JSON.stringify({ canManage: true }), { status: 200 }));
		expect(
			await authorizeRequest(
				{ headers: { cookie: 'auth=1' } },
				{ method: 'tools/call', params: { name: 'theme_apply' } },
				env,
				managerFetcher
			)
		).toEqual({ ok: true, manager: true });

		const denied = await authorizeRequest(
			{ headers: {} },
			{ method: 'tools/call', params: { name: 'tokens_set' } },
			env,
			vi.fn(async () => new Response('{}', { status: 401 }))
		);
		expect(denied.ok).toBe(false);
		expect(denied.message).toContain('NVOOS_MCP_TOKEN');
	});
});

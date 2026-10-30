/**
 * The cookie-auth request discipline (the P0 regression): every request
 * carries X-WP-Nonce, and a stale nonce (rest_cookie_invalid_nonce) is
 * re-minted from /auth/nonce and retried exactly once — the console's
 * session/nonce retry pattern.
 *
 * The module captures window.AstroAdminConfig (and its nonce holder) at
 * load, so each test re-imports it with vi.resetModules() to keep the
 * module-level nonce state isolated per test.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));

vi.mock('@wordpress/api-fetch', () => ({
	default: (...args: unknown[]) => apiFetchMock(...args),
}));

const fetchMock = vi.fn();

type RequestModule = typeof import('../useCollection');

let mod: RequestModule;

beforeEach(async () => {
	vi.resetModules();
	vi.stubGlobal('fetch', fetchMock);
	apiFetchMock.mockReset();
	fetchMock.mockReset();

	(window as any).AstroAdminConfig = {
		root: '/api/',
		nonce: 'abc123',
		mediaBase: '/media/',
		user: { canManage: true, capabilities: ['manage_astro'], readOnlyCollections: [] },
	};
	(window as any).wpApiSettings = { root: '/api/', nonce: 'abc123' };

	mod = await import('../useCollection');
});

describe('request nonce discipline', () => {
	it('attaches X-WP-Nonce from the host config to every request', async () => {
		apiFetchMock.mockResolvedValue({ docs: [], totalDocs: 0 });

		await mod.request('products?page=1&limit=20');

		expect(apiFetchMock).toHaveBeenCalledTimes(1);
		const call = apiFetchMock.mock.calls[0][0] as {
			path: string;
			headers: Record<string, string>;
		};
		expect(call.path).toBe('/api/products?page=1&limit=20');
		expect(call.headers['X-WP-Nonce']).toBe('abc123');
	});

	it('re-mints the nonce and retries once on rest_cookie_invalid_nonce', async () => {
		apiFetchMock
			.mockRejectedValueOnce({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' })
			.mockResolvedValueOnce({ docs: [], totalDocs: 0 });
		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({ nonce: 'fresh456' }),
		});

		await mod.request('products', { method: 'PUT', data: { name: 'Chair' } });

		expect(apiFetchMock).toHaveBeenCalledTimes(2);
		const retry = apiFetchMock.mock.calls[1][0] as { headers: Record<string, string> };
		expect(retry.headers['X-WP-Nonce']).toBe('fresh456');
		expect(fetchMock).toHaveBeenCalledWith('/api/auth/nonce', {
			credentials: 'include',
			headers: { accept: 'application/json' },
		});
	});

	it('does not retry other errors', async () => {
		apiFetchMock.mockRejectedValueOnce({ code: 'rest_forbidden', message: 'Not allowed' });

		await expect(mod.request('products', { method: 'DELETE' })).rejects.toMatchObject({
			code: 'rest_forbidden',
		});
		expect(apiFetchMock).toHaveBeenCalledTimes(1);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('fails through when the re-mint cannot produce a fresh nonce', async () => {
		apiFetchMock.mockRejectedValueOnce({ code: 'rest_cookie_invalid_nonce' });
		// The mint returns the same nonce the module already holds — no
		// fresh value, so the original error propagates untouched.
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ nonce: 'abc123' }) });

		await expect(mod.request('products')).rejects.toMatchObject({
			code: 'rest_cookie_invalid_nonce',
		});
		expect(apiFetchMock).toHaveBeenCalledTimes(1);
	});
});

describe('mintNonce', () => {
	it('returns the server-minted nonce', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ nonce: 'minted789' }) });
		await expect(mod.mintNonce()).resolves.toBe('minted789');
	});

	it('falls back to the current nonce when the endpoint fails', async () => {
		fetchMock.mockRejectedValue(new Error('network down'));
		// The module booted with nonce abc123 — the fallback keeps it.
		await expect(mod.mintNonce()).resolves.toBe('abc123');
	});
});

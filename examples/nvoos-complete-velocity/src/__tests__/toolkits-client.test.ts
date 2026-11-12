/**
 * ToolkitsClient contract tests (fetch mocked) — Phase 4.2.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { ToolkitsApiError, ToolkitsClient } from '@nvoos/pro-spa-v2/api/toolkits';

const BASE = 'https://example.com/api/v1/pro/toolkits';

function mockFetch(payload: unknown, ok = true, status = 200): void {
	global.fetch = vi.fn(() =>
		Promise.resolve({
			ok,
			status,
			json: () => Promise.resolve(payload),
		} as Response),
	) as unknown as typeof fetch;
}

afterEach(() => {
	vi.restoreAllMocks();
});

describe('ToolkitsClient', () => {
	it('reports availability from the endpoint', () => {
		expect(new ToolkitsClient({ endpoint: '', nonce: 'n' }).available).toBe(false);
		expect(new ToolkitsClient({ endpoint: BASE, nonce: 'n' }).available).toBe(true);
	});

	it('parses the catalogue envelope', async () => {
		mockFetch({
			success: true,
			data: {
				toolkits: [{ slug: 'commerce', name: 'Commerce', version: '1.0.0' }],
				invalid: { 'broken.json': ['slug must match'] },
			},
		});

		const client = new ToolkitsClient({ endpoint: BASE, nonce: 'n' });
		const result = await client.list();

		expect(result.toolkits).toHaveLength(1);
		expect(result.toolkits[0].slug).toBe('commerce');
		expect(result.invalid['broken.json']).toEqual(['slug must match']);
		expect(global.fetch).toHaveBeenCalledWith(BASE, expect.objectContaining({ method: 'GET' }));
	});

	it('returns the manifest and rejects an empty payload', async () => {
		mockFetch({ success: true, data: { toolkit: { slug: 'commerce', screens: ['settings'] } } });

		const client = new ToolkitsClient({ endpoint: BASE, nonce: 'n' });
		const manifest = await client.show('commerce');

		expect(manifest.slug).toBe('commerce');

		mockFetch({ success: true, data: {} });

		await expect(client.show('commerce')).rejects.toBeInstanceOf(ToolkitsApiError);
	});

	it('sends record queries as page/per_page/search/filter params', async () => {
		mockFetch({ success: true, data: { records: [], total: 0, page: 2, per_page: 10 } });

		const client = new ToolkitsClient({ endpoint: BASE, nonce: 'n' });
		await client.records('commerce', 'product', {
			page: 2,
			perPage: 10,
			search: ' tee ',
			filter: { status: 'active', stock: '' },
		});

		const url = String((global.fetch as ReturnType<typeof vi.fn>).mock.calls[0][0]);

		expect(url).toContain('/entities/product?');
		expect(url).toContain('page=2');
		expect(url).toContain('per_page=10');
		expect(url).toContain('search=tee');
		expect(url).toContain('filter%5Bstatus%5D=active');
		// Blank filters are dropped rather than sent as empty matches.
		expect(url).not.toContain('stock');
	});

	it('surfaces typed validation errors with field messages', async () => {
		mockFetch(
			{
				success: false,
				error: {
					code: 'validation_failed',
					message: 'One or more fields are invalid.',
					fields: { sku: 'This field is required.' },
				},
			},
			false,
			422,
		);

		const client = new ToolkitsClient({ endpoint: BASE, nonce: 'n' });

		try {
			await client.createRecord('commerce', 'product', {});
			expect.unreachable('createRecord should have thrown');
		} catch (error) {
			expect(error).toBeInstanceOf(ToolkitsApiError);
			expect((error as ToolkitsApiError).code).toBe('validation_failed');
			expect((error as ToolkitsApiError).fields.sku).toBe('This field is required.');
		}
	});

	it('returns false when a delete is not confirmed', async () => {
		mockFetch({ success: true, data: { deleted: false } });

		const client = new ToolkitsClient({ endpoint: BASE, nonce: 'n' });
		expect(await client.deleteRecord('commerce', 'product', 'A-1')).toBe(false);
	});

	it('refuses to call the API when no endpoint is configured', async () => {
		const client = new ToolkitsClient({ endpoint: '', nonce: '' });

		await expect(client.list()).rejects.toMatchObject({ code: 'unavailable' });
	});
});

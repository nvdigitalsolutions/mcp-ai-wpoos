// ---------------------------------------------------------------------------
// cart-api.test.ts — the bridge cart proxy client (Proposal 108 Phase 2):
// success normalization (zod-validated), typed backend errors, malformed
// responses, and the unreachable-network failure mode.
// ---------------------------------------------------------------------------

import { afterEach, describe, expect, it, vi } from 'vitest';
import { syncServerCart } from '../../src/lib/commerce/cart-api';
import type { CartLine } from '../../src/lib/store/cart';

const LINES: CartLine[] = [
	{
		productId: 1,
		name: 'Aurora 3-Seater Sofa',
		price: 1299,
		image: '',
		qty: 1,
	},
	{
		productId: 3,
		name: 'Halo Lounge Chair',
		price: 849,
		image: '',
		qty: 2,
		variantId: 303,
		attributes: [{ name: 'Size', value: 'Large' }],
	},
];

const OK_BODY = {
	session: 'fixture-session',
	items: [
		{ key: 'a', productId: 1, name: 'Aurora 3-Seater Sofa', qty: 1, price: 1299 },
		{ key: 'b', productId: 3, name: 'Halo Lounge Chair', qty: 2, price: 849 },
	],
	totals: { totalItems: 3, totalPrice: '169800' },
	notices: [],
};

function stubFetch(
	status: number,
	body: unknown,
	implementation?: (input: RequestInfo | URL, init?: RequestInit) => void
): void {
	vi.stubGlobal(
		'fetch',
		vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
			implementation?.(input, init);
			return {
				ok: status >= 200 && status < 300,
				status,
				json: async () => body,
			} as Response;
		})
	);
}

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('syncServerCart', () => {
	it('POSTs every line (variants included) with the session header and parses the cart', async () => {
		let captured: RequestInit | undefined;
		stubFetch(200, OK_BODY, (_input, init) => {
			captured = init;
		});

		const result = await syncServerCart('sess-123', LINES);

		expect(result.ok).toBe(true);
		if (!result.ok) {
			return;
		}
		expect(result.cart.session).toBe('fixture-session');
		expect(result.cart.totals.totalItems).toBe(3);

		const body = JSON.parse(String(captured?.body)) as {
			lines: Array<Record<string, unknown>>;
		};
		expect(body.lines).toHaveLength(2);
		expect(body.lines[1]).toEqual({
			productId: 3,
			qty: 2,
			attributes: [{ name: 'Size', value: 'Large' }],
		});
		expect(captured?.headers).toMatchObject({
			'x-nvoos-cart-session': 'sess-123',
		});
	});

	it('surfaces the backend message on error statuses', async () => {
		stubFetch(400, { code: 'astro_cart_native_mode', message: 'Native mode.' });

		const result = await syncServerCart('sess-123', LINES);

		expect(result.ok).toBe(false);
		if (result.ok) {
			return;
		}
		expect(result.error).toBe('Native mode.');
	});

	it('rejects malformed server responses (loud failure)', async () => {
		stubFetch(200, { session: 'x' });

		const result = await syncServerCart('sess-123', LINES);

		expect(result.ok).toBe(false);
		if (result.ok) {
			return;
		}
		expect(result.error).toContain('malformed');
	});

	it('fails gracefully when the network is unreachable', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => {
				throw new Error('ECONNREFUSED');
			})
		);

		const result = await syncServerCart('sess-123', LINES);

		expect(result.ok).toBe(false);
		if (result.ok) {
			return;
		}
		expect(result.error).toContain('unreachable');
	});
});

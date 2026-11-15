// ---------------------------------------------------------------------------
// cart-api.ts — the bridge cart proxy client (Proposal 108 Phase 2).
//
// In woo/both product modes the cart island reconciles the LOCAL cart into
// the WooCommerce session cart through the bridge's Store API proxy
// (`POST /nvoos-commerce/v1/cart/sync`, same-origin /api) and then hands
// off to the HOSTED checkout with a clean URL — variants included. The
// legacy `?add-to-cart=` URL form remains the native-mode path and the
// fail-open fallback when the proxy errors.
// ---------------------------------------------------------------------------

import { z } from 'zod';
import type { CartLine } from '../store/cart';

/** The normalized server-cart shape the bridge returns (108 §2). */
export const serverCartSchema = z.object({
	session: z.string(),
	items: z.array(
		z.object({
			key: z.string(),
			productId: z.number().int().positive(),
			name: z.string(),
			qty: z.number().int().nonnegative(),
			price: z.number(),
		})
	),
	totals: z.object({
		totalItems: z.number().int().nonnegative(),
		totalPrice: z.string(),
	}),
	notices: z.array(z.object({ type: z.string(), text: z.string() })),
});

export type ServerCart = z.infer<typeof serverCartSchema>;

export type CartSyncResult =
	| { ok: true; cart: ServerCart }
	| { ok: false; error: string };

/** POST the local cart lines to the bridge cart proxy.
 *
 *  @param session The client cart-session id (localStorage UUID).
 *  @param lines   The local cart lines (variants carry attributes).
 *  @param coupon  Optional coupon code to apply server-side.
 */
export async function syncServerCart(
	session: string,
	lines: CartLine[],
	coupon: string | null = null
): Promise<CartSyncResult> {
	let res: Response;
	try {
		res = await fetch('/api/cart/sync', {
			method: 'POST',
			headers: {
				accept: 'application/json',
				'content-type': 'application/json',
				'x-nvoos-cart-session': session,
			},
			body: JSON.stringify({
				lines: lines.map((line) => ({
					productId: line.productId,
					qty: line.qty,
					attributes: line.attributes ?? [],
				})),
				...(coupon ? { coupon } : {}),
			}),
		});
	} catch {
		return { ok: false, error: 'The server cart is unreachable.' };
	}

	const payload = (await res.json().catch(() => null)) as unknown;
	if (!res.ok) {
		const message =
			typeof payload === 'object' &&
			payload !== null &&
			typeof (payload as { message?: unknown }).message === 'string'
				? ((payload as { message: string }).message as string)
				: `Cart sync failed (${res.status}).`;
		return { ok: false, error: message };
	}

	const parsed = serverCartSchema.safeParse(payload);
	if (!parsed.success) {
		return { ok: false, error: 'The server cart response was malformed.' };
	}
	return { ok: true, cart: parsed.data };
}

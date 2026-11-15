// ---------------------------------------------------------------------------
// Checkout handoff — Proposal 106 §4.
//
// The cart hands off to the WooCommerce checkout by redirecting to the shop
// globals' checkoutUrl with the comma-joined simple-product ids
// (`?add-to-cart=1,2` — Woo's comma form, research §4). Variant lines are
// EXCLUDED (honest UX: a note renders in the cart) because Woo's URL cart
// form carries simple product ids only — variant checkout is the next
// release. Pure function: unit-tested without WooCommerce.
// ---------------------------------------------------------------------------

import type { ShopDoc } from './types';
import type { CartLine } from '../store/cart';

export interface CheckoutPlan {
	/** The absolute WooCommerce checkout URL, or null when nothing can hand off. */
	url: string | null;
	/** Simple-product line ids included in the handoff (cart order). */
	simpleIds: number[];
	/** Count of variant lines excluded from the handoff. */
	variantCount: number;
}

/** Build the Woo checkout URL from the cart lines (106 §4). */
export function buildCheckoutUrl(
	shop: Pick<ShopDoc, 'checkoutUrl'>,
	lines: CartLine[]
): CheckoutPlan {
	const simpleIds: number[] = [];
	let variantCount = 0;
	for (const line of lines) {
		if (line.variantId === undefined || line.variantId === null) {
			simpleIds.push(line.productId);
		} else {
			variantCount += 1;
		}
	}
	const base = shop.checkoutUrl ?? '';
	if (!base || simpleIds.length === 0) {
		return { url: null, simpleIds, variantCount };
	}
	const separator = base.includes('?') ? '&' : '?';
	return {
		url: `${base}${separator}add-to-cart=${simpleIds.join(',')}`,
		simpleIds,
		variantCount,
	};
}

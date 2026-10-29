// ---------------------------------------------------------------------------
// Cart store — display-only client state for v1 (no checkout in the
// contract; owner decision Q5 covers auth, not payments). Persisted to
// localStorage; hydrates into the CartBadge island.
// ---------------------------------------------------------------------------

import { atom, map } from 'nanostores';

export interface CartLine {
	productId: number;
	name: string;
	price: number;
	image: string;
	qty: number;
}

const STORAGE_KEY = 'nvoos-cart-v1';

export const cartLines = map<Record<number, CartLine>>({});
export const cartCount = atom<number>(0);

function recalculate(): void {
	let count = 0;
	const lines = cartLines.get();
	for (const line of Object.values(lines)) {
		count += line.qty;
	}
	cartCount.set(count);
}

function persist(): void {
	if (typeof localStorage !== 'undefined') {
		try {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(cartLines.get()));
		} catch {
			// Storage full/blocked — cart survives in memory only.
		}
	}
}

export function addToCart(line: Omit<CartLine, 'qty'>, qty = 1): void {
	const existing = cartLines.get()[line.productId];
	if (existing) {
		cartLines.setKey(line.productId, { ...existing, qty: existing.qty + qty });
	} else {
		cartLines.setKey(line.productId, { ...line, qty });
	}
	recalculate();
	persist();
}

export function removeFromCart(productId: number): void {
	const next = { ...cartLines.get() };
	delete next[productId];
	cartLines.set(next);
	recalculate();
	persist();
}

export function setLineQty(productId: number, qty: number): void {
	const existing = cartLines.get()[productId];
	if (!existing) {
		return;
	}
	if (qty <= 0) {
		removeFromCart(productId);
		return;
	}
	cartLines.setKey(productId, { ...existing, qty });
	recalculate();
	persist();
}

export function clearCart(): void {
	cartLines.set({});
	recalculate();
	persist();
}

/** Restore cart from localStorage — called from islands on mount. */
export function loadCartFromStorage(): void {
	if (typeof localStorage === 'undefined') {
		return;
	}
	try {
		const raw = localStorage.getItem(STORAGE_KEY);
		if (!raw) {
			return;
		}
		const parsed = JSON.parse(raw) as Record<number, CartLine>;
		cartLines.set(parsed);
		recalculate();
	} catch {
		// Corrupt storage — start clean.
		clearCart();
	}
}

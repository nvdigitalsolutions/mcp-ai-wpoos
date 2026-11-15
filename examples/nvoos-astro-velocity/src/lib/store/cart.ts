// ---------------------------------------------------------------------------
// Cart store — display-only client state for v1 (no checkout in the
// contract; owner decision Q5 covers auth, not payments). Persisted to
// localStorage; hydrates into the CartBadge island.
//
// Proposal 106: line items gain the variant identity (Saleor research —
// checkout references VARIANT ids), and the per-product variant selection
// persists alongside so it survives client-side navigation (Defect A class).
// ---------------------------------------------------------------------------

import { atom, map } from 'nanostores';
import type { ProductVariantAttribute } from '../commerce/types';

export interface CartLine {
	productId: number;
	name: string;
	price: number;
	image: string;
	qty: number;
	/** Selected variant id — absent = simple-product line (106 §1.1). */
	variantId?: number;
	/** The variant's ordered attributes (106 §1.1). */
	attributes?: ProductVariantAttribute[];
}

/** The persisted variant selection for one product (keyed by productId). */
export interface SelectedVariant {
	variantId: number;
	price: number;
	attributes: ProductVariantAttribute[];
}

const STORAGE_KEY = 'nvoos-cart-v1';
const SELECTION_STORAGE_KEY = 'nvoos-selected-variants';
const SESSION_STORAGE_KEY = 'nvoos-cart-session';

export const cartLines = map<Record<number, CartLine>>({});
export const cartCount = atom<number>(0);

/** Per-product selected variants — survives client-side navigation. */
export const selectedVariants = atom<Record<number, SelectedVariant>>({});

/** The client cart-session id (108 Phase 2) — an unguessable per-browser
 *  UUID that keys the server-side Woo cart session. Generates once and
 *  persists; the same trust level as the Store API's own guest token. */
export function getCartSessionId(): string {
	if (typeof localStorage === 'undefined') {
		return '';
	}
	try {
		let session = localStorage.getItem(SESSION_STORAGE_KEY);
		if (!session) {
			session =
				typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
					? crypto.randomUUID()
					: `s-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
			localStorage.setItem(SESSION_STORAGE_KEY, session);
		}
		return session;
	} catch {
		return '';
	}
}

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

// ---------------------------------------------------------------------------
// Variant selection (106 §1.1) — the PDP selector persists its pick so the
// AddToCart island and a later client-side return to the PDP see it.
// ---------------------------------------------------------------------------

function persistSelection(): void {
	if (typeof localStorage !== 'undefined') {
		try {
			localStorage.setItem(SELECTION_STORAGE_KEY, JSON.stringify(selectedVariants.get()));
		} catch {
			// Storage full/blocked — selection survives in memory only.
		}
	}
}

export function selectVariant(productId: number, selection: SelectedVariant): void {
	selectedVariants.set({ ...selectedVariants.get(), [productId]: selection });
	persistSelection();
}

export function clearSelectedVariant(productId: number): void {
	const next = { ...selectedVariants.get() };
	delete next[productId];
	selectedVariants.set(next);
	persistSelection();
}

/** Restore variant selections from localStorage — called from islands. */
export function loadSelectedVariantsFromStorage(): void {
	if (typeof localStorage === 'undefined') {
		return;
	}
	try {
		const raw = localStorage.getItem(SELECTION_STORAGE_KEY);
		if (!raw) {
			return;
		}
		selectedVariants.set(JSON.parse(raw) as Record<number, SelectedVariant>);
	} catch {
		// Corrupt storage — start clean.
		selectedVariants.set({});
	}
}

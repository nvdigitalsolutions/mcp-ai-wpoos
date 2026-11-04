// ---------------------------------------------------------------------------
// CartView island — full cart rendering (client-only state). The cart page
// shell is server-rendered; lines hydrate from the nanostores cart.
//
// Proposal 106 §4 — checkout handoff (native mode): simple-product lines
// build the Woo checkout URL (`?add-to-cart=1,2` comma form) and hand off
// with an absolute link (no BFF proxy); variant lines are excluded with an
// honest note; the cart clears on click so the handoff is one-shot.
//
// Proposal 108 Phase 2 — server-cart sync (woo/both modes): the local cart
// is reconciled into the WooCommerce session cart through the bridge Store
// API proxy and the shopper continues to the EMBEDDED checkout
// (`/checkout/`, Proposal 110) — variants included. The hosted URL form
// stays as the fail-open fallback when the proxy errors. INV-1: no payment
// UI ever renders here.
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';
import { useStore } from '@nanostores/react';
import {
	cartLines,
	clearCart,
	getCartSessionId,
	loadCartFromStorage,
	removeFromCart,
	setLineQty,
} from '../lib/store/cart';
import type { CartLine } from '../lib/store/cart';
import { formatPrice } from '../lib/commerce/money';
import { buildCheckoutUrl } from '../lib/commerce/checkout';
import { syncServerCart } from '../lib/commerce/cart-api';
import type { ShopDoc } from '../lib/commerce/types';

interface Props {
	/** The shop globals doc fetched server-side (106 §1.3). */
	shop: ShopDoc;
}

type SyncState = 'idle' | 'syncing' | 'error';

export default function CartView({ shop }: Props) {
	const lines = useStore(cartLines);
	const [syncState, setSyncState] = useState<SyncState>('idle');
	const [syncError, setSyncError] = useState('');

	useEffect(() => {
		loadCartFromStorage();
		// Hydration signal for e2e (under parallel load a seeded cart must be
		// loaded before the handoff assertions run).
		document.querySelector('[data-cart-root]')?.setAttribute('data-hydrated', 'true');
	}, []);

	const entries: CartLine[] = Object.values(lines);
	const plan = buildCheckoutUrl(shop, entries);
	const usesServerCart =
		(shop.productsSource === 'woo' || shop.productsSource === 'both') &&
		typeof shop.checkoutUrl === 'string' &&
		shop.checkoutUrl.length > 0;

	/** Hand the cart to Woo — the 108 §2 analytics seam fires either way. */
	const emitHandoff = (mode: 'embedded' | 'url-form') => {
		window.dispatchEvent(
			new CustomEvent('nvoos:checkout-handoff', {
				detail: {
					mode,
					lines: entries.length,
					variants: entries.filter((line) => line.variantId != null).length,
				},
			})
		);
	};

	const handleCheckout = async (event: React.MouseEvent<HTMLAnchorElement>) => {
		if (!usesServerCart) {
			emitHandoff('url-form');
			clearCart();
			return;
		}

		event.preventDefault();
		if (syncState === 'syncing') {
			return;
		}
		setSyncState('syncing');
		setSyncError('');

		const session = getCartSessionId();
		if (!session) {
			setSyncError('The server cart is unavailable in this browser.');
			setSyncState('error');
			return;
		}

		const result = await syncServerCart(session, entries);
		if (result.ok) {
			emitHandoff('embedded');
			clearCart();
			// The embedded checkout (Proposal 110) — the server session cart
			// now carries every line; /checkout reads it through the proxy.
			window.location.assign('/checkout/');
			return;
		}

		// Fail-open to the legacy URL form — the link below becomes the
		// fallback (simple lines only, the variant note explains the rest).
		setSyncError(result.error);
		setSyncState('error');
	};

	if (entries.length === 0) {
		return (
			<div className="cart" data-cart-root>
				<p>Your cart is empty.</p>
				<button type="button" className="btn" disabled data-cart-checkout>
					Checkout on WooCommerce
				</button>
			</div>
		);
	}

	return (
		<div className="cart" data-cart-root>
			<ul className="cart__lines">
				{entries.map((line) => (
					<li key={line.productId} className="cart__line">
						{line.image && (
							<img src={line.image} alt="" width="72" height="90" loading="lazy" />
						)}
						<div className="cart__line-info">
							<span className="cart__line-name">{line.name}</span>
							{line.attributes && line.attributes.length > 0 && (
								<span className="cart__line-variant">
									{line.attributes.map((attribute) => attribute.value).join(' / ')}
								</span>
							)}
							<span>{formatPrice(line.price)}</span>
						</div>
						<div className="cart__line-qty">
							<label htmlFor={`qty-${line.productId}`} className="sr-only">
								Quantity
							</label>
							<input
								id={`qty-${line.productId}`}
								type="number"
								min="1"
								value={line.qty}
								onChange={(event) =>
									setLineQty(line.productId, Number(event.target.value))
								}
							/>
						</div>
						<button
							type="button"
							className="btn btn--ghost"
							onClick={() => removeFromCart(line.productId)}
						>
							Remove
						</button>
					</li>
				))}
			</ul>
			<p>
				<strong>
					Total:{' '}
					{formatPrice(entries.reduce((sum, line) => sum + line.price * line.qty, 0))}
				</strong>
			</p>
			{usesServerCart && syncState !== 'error' ? (
				<a
					className="btn"
					href="/checkout/"
					data-cart-checkout
					aria-disabled={syncState === 'syncing'}
					onClick={handleCheckout}
				>
					{syncState === 'syncing' ? 'Syncing cart…' : 'Checkout'}
				</a>
			) : plan.url ? (
				<a
					className="btn"
					href={plan.url}
					rel="noopener noreferrer"
					data-cart-checkout
					onClick={() => {
						emitHandoff('url-form');
						clearCart();
					}}
				>
					Checkout on WooCommerce
				</a>
			) : (
				<button type="button" className="btn" disabled data-cart-checkout>
					Checkout on WooCommerce
				</button>
			)}
			{syncState === 'error' && syncError !== '' && (
				<p className="cart__note" data-cart-sync-error>
					Server cart sync failed — {syncError} Falling back to the direct
					checkout link.
				</p>
			)}
			{plan.variantCount > 0 && (!usesServerCart || syncState === 'error') && (
				<p className="cart__note" data-cart-variant-note>
					Variant items are excluded from this checkout — variant checkout opens
					in the next release.
				</p>
			)}
			<button type="button" className="btn btn--ghost" onClick={clearCart}>
				Clear cart
			</button>
		</div>
	);
}

// ---------------------------------------------------------------------------
// CartView island — full cart rendering (client-only state). The cart page
// shell is server-rendered; lines hydrate from the nanostores cart.
// ---------------------------------------------------------------------------

import { useEffect } from 'react';
import { useStore } from '@nanostores/react';
import {
	cartLines,
	clearCart,
	loadCartFromStorage,
	removeFromCart,
	setLineQty,
} from '../lib/store/cart';
import type { CartLine } from '../lib/store/cart';
import { formatPrice } from '../lib/commerce/money';

export default function CartView() {
	const lines = useStore(cartLines);

	useEffect(() => {
		loadCartFromStorage();
	}, []);

	const entries: CartLine[] = Object.values(lines);

	if (entries.length === 0) {
		return <p>Your cart is empty.</p>;
	}

	return (
		<div className="cart">
			<ul className="cart__lines">
				{entries.map((line) => (
					<li key={line.productId} className="cart__line">
						{line.image && (
							<img src={line.image} alt="" width="72" height="90" loading="lazy" />
						)}
						<div className="cart__line-info">
							<span className="cart__line-name">{line.name}</span>
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
			<button type="button" className="btn btn--ghost" onClick={clearCart}>
				Clear cart
			</button>
			<p className="cart__note">
				Checkout is not available in this store yet — the cart is display-only.
			</p>
		</div>
	);
}

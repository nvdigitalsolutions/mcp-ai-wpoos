// ---------------------------------------------------------------------------
// CartBadge island — clean-room React component (D6), hydrates client:idle.
// Reads the nanostores cart; persists across View Transitions via
// transition:persist (set by the host page/layout).
// ---------------------------------------------------------------------------

import { useEffect } from 'react';
import { useStore } from '@nanostores/react';
import { cartCount, loadCartFromStorage } from '../lib/store/cart';

export default function CartBadge() {
	const count = useStore(cartCount);

	useEffect(() => {
		loadCartFromStorage();
	}, []);

	return (
		<a href="/cart/" className="cart-badge" aria-label={`Cart, ${count} item${count === 1 ? '' : 's'}`}>
			<span aria-hidden="true">Cart</span>
			{count > 0 && <span className="cart-badge__count">{count}</span>}
		</a>
	);
}

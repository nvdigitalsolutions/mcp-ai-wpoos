// ---------------------------------------------------------------------------
// AddToCartButton island — clean-room React component (D6), client:visible.
// Writes the display-only cart store (no checkout in the v1 contract).
// ---------------------------------------------------------------------------

import { useState } from 'react';
import { addToCart } from '../lib/store/cart';

interface Props {
	productId: number;
	name: string;
	price: number;
	image: string;
}

export default function AddToCartButton({ productId, name, price, image }: Props) {
	const [added, setAdded] = useState(false);

	const add = (): void => {
		addToCart({ productId, name, price, image });
		setAdded(true);
		window.setTimeout(() => setAdded(false), 2000);
	};

	return (
		<button type="button" className="btn" onClick={add}>
			{added ? 'Added to cart ✓' : 'Add to cart'}
		</button>
	);
}

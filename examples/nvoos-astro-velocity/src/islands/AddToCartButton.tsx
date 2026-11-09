// ---------------------------------------------------------------------------
// AddToCartButton island — clean-room React component (D6), client:load (CSP: no per-page inline bootstrap).
// Writes the display-only cart store (no checkout in the v1 contract).
//
// Proposal 106: when the visitor selected a variant on the PDP, the line
// carries the variant identity + variant price (Saleor semantics — checkout
// references variant ids).
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';
import { addToCart, loadSelectedVariantsFromStorage, selectedVariants } from '../lib/store/cart';

interface Props {
	productId: number;
	name: string;
	price: number;
	image: string;
}

export default function AddToCartButton({ productId, name, price, image }: Props) {
	const [added, setAdded] = useState(false);

	useEffect(() => {
		loadSelectedVariantsFromStorage();
	}, []);

	const add = (): void => {
		const selection = selectedVariants.get()[productId];
		addToCart({
			productId,
			name,
			price: selection ? selection.price : price,
			image,
			...(selection
				? { variantId: selection.variantId, attributes: selection.attributes }
				: {}),
		});
		setAdded(true);
		window.setTimeout(() => setAdded(false), 2000);
	};

	return (
		<button type="button" className="btn" onClick={add}>
			{added ? 'Added to cart ✓' : 'Add to cart'}
		</button>
	);
}

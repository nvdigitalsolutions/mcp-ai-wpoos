// ---------------------------------------------------------------------------
// VariantSelector island — Proposal 106 §1.1. client:load on the PDP.
//
// Attribute groups render as radio-style option buttons (native radio inputs
// inside a fieldset/legend — no ARIA needed, axe-clean). Sold-out options are
// disabled. Selecting a variant:
//   - updates the displayed price (the server-rendered [data-variant-price]
//     block, which lives OUTSIDE this island),
//   - persists { variantId, attributes } via the cart store's selection atom
//     (the AddToCart island picks it up), so the selection survives
//     client-side navigation (Proposal 104 Defect A class),
//   - narrows the Product JSON-LD `offers` to the selected variant.
//
// Nothing is pre-selected: the server HTML shows the base price and the full
// variant Offer[] in JSON-LD until the visitor picks a variant.
// ---------------------------------------------------------------------------

import { useEffect, useState } from 'react';
import type { ProductVariant } from '../lib/commerce/types';
import {
	isVariantValueSoldOut,
	resolveVariant,
	variantGroups,
	variantAttributes,
	type VariantSelection,
} from '../lib/commerce/variants';
import { formatPrice, resolveCurrency } from '../lib/commerce/money';
import {
	loadSelectedVariantsFromStorage,
	selectVariant,
	selectedVariants,
} from '../lib/store/cart';

interface Props {
	productId: number;
	basePrice: number;
	baseOldPrice: number | null;
	variants: ProductVariant[];
}

/** Serialize the JSON-LD block for one variant (the server emits the full
 *  Offer[]; the selector narrows it to the selection). */
function applyJsonLdOffer(productId: number, variant: ProductVariant | null): void {
	if (!variant) {
		return;
	}
	const blocks = Array.from(
		document.querySelectorAll<HTMLScriptElement>('script[type="application/ld+json"]')
	);
	const productBlock = blocks.find((script) =>
		(script.textContent ?? '').includes('"@type":"Product"')
	);
	if (!productBlock) {
		return;
	}
	try {
		const parsed = JSON.parse(productBlock.textContent ?? '{}') as Record<string, unknown>;
		parsed.offers = [
			{
				'@type': 'Offer',
				price: String(variant.price),
				priceCurrency: resolveCurrency(),
				sku: variant.sku,
				availability: `https://schema.org/${(variant.stock ?? 0) > 0 ? 'InStock' : 'OutOfStock'}`,
				url: new URL(`/products/${productId}/?variant=${variant.id}`, window.location.origin).toString(),
			},
		];
		productBlock.textContent = JSON.stringify(parsed);
	} catch {
		// Malformed JSON-LD block — leave it as rendered.
	}
}

/** Update the server-rendered price block outside the island root. */
function applyPrice(
	basePrice: number,
	baseOldPrice: number | null,
	variant: ProductVariant | null
): void {
	const priceNode = document.querySelector('[data-variant-price]');
	const oldPriceNode = document.querySelector('[data-variant-old-price]');
	if (priceNode) {
		priceNode.textContent = formatPrice(variant ? variant.price : basePrice);
	}
	if (oldPriceNode) {
		const oldPrice = variant ? variant.oldPrice : baseOldPrice;
		const price = variant ? variant.price : basePrice;
		if (oldPrice !== null && oldPrice > price) {
			oldPriceNode.textContent = formatPrice(oldPrice);
			oldPriceNode.removeAttribute('hidden');
		} else {
			oldPriceNode.setAttribute('hidden', '');
		}
	}
}

export default function VariantSelector({
	productId,
	basePrice,
	baseOldPrice,
	variants,
}: Props) {
	const [selection, setSelection] = useState<VariantSelection>({});

	const resolved = resolveVariant(variants, selection);

	// Restore the persisted selection on mount (client-side navigation back to
	// the PDP keeps the pick — Defect A discipline).
	useEffect(() => {
		loadSelectedVariantsFromStorage();
		const restored = selectedVariants.get()[productId];
		if (restored) {
			const restoredSelection: VariantSelection = {};
			for (const attribute of restored.attributes) {
				restoredSelection[attribute.name] = attribute.value;
			}
			setSelection(restoredSelection);
		}
		// Hydration signal for e2e (the PromoCodeField pattern) — under
		// parallel load a click before hydration toggles the radio natively
		// but never reaches the React handler.
		const root = document.querySelector(`[data-variant-root="${productId}"]`);
		root?.setAttribute('data-hydrated', 'true');
	}, [productId]);

	// Keep the DOM (price + JSON-LD) in sync with the state.
	useEffect(() => {
		applyPrice(basePrice, baseOldPrice, resolved);
		applyJsonLdOffer(productId, resolved);
	}, [basePrice, baseOldPrice, productId, resolved]);

	const choose = (name: string, value: string): void => {
		const next = { ...selection, [name]: value };
		const variant = resolveVariant(variants, next);
		if (!variant) {
			return;
		}
		setSelection(next);
		selectVariant(productId, {
			variantId: variant.id,
			price: variant.price,
			attributes: variantAttributes(variant),
		});
	};

	return (
		<div className="variant-selector" data-variant-root={productId}>
			{variantGroups(variants).map((group) => (
				<fieldset key={group.name} className="variant-group">
					<legend>{group.name}</legend>
					<div className="variant-options">
						{group.values.map((value) => {
							const soldOut = isVariantValueSoldOut(variants, group.name, value);
							const checked = selection[group.name] === value;
							return (
								<label
									key={value}
									className={`variant-option${checked ? ' is-selected' : ''}${soldOut ? ' is-sold-out' : ''}`}
								>
									<input
										type="radio"
										name={`variant-${productId}-${group.name.toLowerCase()}`}
										value={value}
										checked={checked}
										disabled={soldOut}
										onChange={() => choose(group.name, value)}
									/>
									<span>
										{value}
										{soldOut ? ' (sold out)' : ''}
									</span>
								</label>
							);
						})}
					</div>
				</fieldset>
			))}
		</div>
	);
}

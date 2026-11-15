// ---------------------------------------------------------------------------
// Variant resolution helpers — Proposal 106 §1.1 (variable products).
//
// Pure functions shared by the VariantSelector island, the cart line shape
// and the JSON-LD offers: the selector UI stays a thin view over these.
// Selection semantics (Saleor research): checkout references VARIANT ids, so
// a selection is the set of attribute values that resolves to one variant.
// ---------------------------------------------------------------------------

import type { ProductVariant, ProductVariantAttribute } from './types';

/** One attribute group (ordered values, first-appearance order). */
export interface VariantGroup {
	name: string;
	values: string[];
}

/** Group the variant attributes by name, preserving option order. */
export function variantGroups(variants: ProductVariant[]): VariantGroup[] {
	const groups: VariantGroup[] = [];
	const seen = new Map<string, Set<string>>();
	for (const variant of variants) {
		for (const attribute of variant.attributes) {
			let group = seen.get(attribute.name);
			if (!group) {
				group = new Set<string>();
				seen.set(attribute.name, group);
				groups.push({ name: attribute.name, values: [] });
			}
			if (!group.has(attribute.value)) {
				group.add(attribute.value);
				groups.find((item) => item.name === attribute.name)?.values.push(attribute.value);
			}
		}
	}
	return groups;
}

/** A value is sold out when every variant carrying it has no stock. */
export function isVariantValueSoldOut(
	variants: ProductVariant[],
	name: string,
	value: string
): boolean {
	const carriers = variants.filter((variant) =>
		variant.attributes.some((attribute) => attribute.name === name && attribute.value === value)
	);
	return carriers.length > 0 && carriers.every((variant) => (variant.stock ?? 0) <= 0);
}

/** The selection shape stored in the cart line + localStorage. */
export type VariantSelection = Record<string, string>;

/** Resolve the unique variant matching EVERY selected value, or null when the
 *  selection is incomplete or contradictory. */
export function resolveVariant(
	variants: ProductVariant[],
	selection: VariantSelection
): ProductVariant | null {
	const entries = Object.entries(selection);
	if (entries.length === 0) {
		return null;
	}
	return (
		variants.find((variant) => {
			const map = new Map(
				variant.attributes.map((attribute) => [attribute.name, attribute.value])
			);
			return entries.every(([name, value]) => map.get(name) === value);
		}) ?? null
	);
}

/** Default selection = first in-stock variant per group (post-hydration UX). */
export function defaultSelection(variants: ProductVariant[]): VariantSelection {
	const selection: VariantSelection = {};
	for (const group of variantGroups(variants)) {
		const firstInStock = group.values.find(
			(value) => !isVariantValueSoldOut(variants, group.name, value)
		);
		if (firstInStock) {
			selection[group.name] = firstInStock;
		}
	}
	return selection;
}

/** Serialize the attributes for the cart line-item shape (106 §1.1). */
export function variantAttributes(variant: ProductVariant): ProductVariantAttribute[] {
	return variant.attributes.map((attribute) => ({ ...attribute }));
}

// variants.test.ts — Proposal 106 P1: variant hydrate/normalize/ladder +
// the JSON-LD Offer[] emission.
import { describe, expect, it } from 'vitest';
import {
	defaultSelection,
	isVariantValueSoldOut,
	resolveVariant,
	variantGroups,
} from '../../src/lib/commerce/variants';
import { productJsonLd, variantOfferJsonLd } from '../../src/lib/seo/jsonld';
import { normalizeProductMedia } from '../../src/lib/commerce/normalize';
import type { Product, ProductVariant } from '../../src/lib/commerce/types';

const VARIANTS: ProductVariant[] = [
	{
		id: 301,
		sku: 'HLC-COMP-OAK',
		price: 749,
		oldPrice: 799,
		stock: 4,
		attributes: [{ name: 'Size', value: 'Compact' }],
		image: 'http://localhost:8000/wp-content/uploads/2026/10/halo-chair.jpg',
	},
	{
		id: 302,
		sku: 'HLC-STD-OAK',
		price: 799,
		oldPrice: 899,
		stock: 0,
		attributes: [{ name: 'Size', value: 'Standard' }],
		image: 'http://localhost:8000/wp-content/uploads/2026/10/halo-chair.jpg',
	},
	{
		id: 303,
		sku: 'HLC-LRG-OAK',
		price: 849,
		oldPrice: null,
		stock: 2,
		attributes: [{ name: 'Size', value: 'Large' }],
		image: 'http://localhost:8000/wp-content/uploads/2026/10/halo-chair-2.jpg',
	},
];

const baseProduct = (variants?: ProductVariant[]): Product => ({
	id: 3,
	name: 'Halo Lounge Chair',
	price: 799,
	oldPrice: 899,
	description: '<p>Curved lounge chair with wool upholstery.</p>',
	category: { id: 2, name: 'Chairs' },
	brand: { id: 2, name: 'Craftline' },
	colors: [],
	width: 84,
	height: 88,
	depth: 86,
	rating: 4.2,
	isBestseller: true,
	isFeatured: false,
	image: 'http://localhost:8000/wp-content/uploads/2026/10/halo-chair.jpg',
	images: [],
	imageId: 31,
	imagesIds: [],
	statuses: [],
	promotion: null,
	createdAt: '2026-09-20 10:00:00',
	updatedAt: '2026-10-21 11:00:00',
	variants,
});

describe('variantGroups', () => {
	it('groups attributes by name in first-appearance order', () => {
		const groups = variantGroups(VARIANTS);
		expect(groups).toHaveLength(1);
		expect(groups[0]).toEqual({ name: 'Size', values: ['Compact', 'Standard', 'Large'] });
	});

	it('handles multi-attribute variants in option order', () => {
		const groups = variantGroups([
			{
				id: 1,
				sku: 'x',
				price: 1,
				oldPrice: null,
				stock: 1,
				attributes: [
					{ name: 'Size', value: 'S' },
					{ name: 'Colour', value: 'Oak' },
				],
				image: null,
			},
			{
				id: 2,
				sku: 'y',
				price: 1,
				oldPrice: null,
				stock: 1,
				attributes: [
					{ name: 'Size', value: 'M' },
					{ name: 'Colour', value: 'Oak' },
				],
				image: null,
			},
		]);
		expect(groups.map((group) => group.name)).toEqual(['Size', 'Colour']);
		expect(groups[1].values).toEqual(['Oak']); // deduped
	});
});

describe('isVariantValueSoldOut', () => {
	it('flags a value whose only variants have zero stock', () => {
		expect(isVariantValueSoldOut(VARIANTS, 'Size', 'Standard')).toBe(true);
		expect(isVariantValueSoldOut(VARIANTS, 'Size', 'Compact')).toBe(false);
		expect(isVariantValueSoldOut(VARIANTS, 'Size', 'Large')).toBe(false);
	});

	it('returns false for a value no variant carries', () => {
		expect(isVariantValueSoldOut(VARIANTS, 'Size', 'Nope')).toBe(false);
	});
});

describe('resolveVariant / defaultSelection', () => {
	it('resolves the unique variant for a complete selection', () => {
		expect(resolveVariant(VARIANTS, { Size: 'Large' })?.id).toBe(303);
	});

	it('returns null for an empty or partial selection', () => {
		expect(resolveVariant(VARIANTS, {})).toBeNull();
		expect(resolveVariant(VARIANTS, { Colour: 'Oak' })).toBeNull();
	});

	it('returns null for a contradictory selection', () => {
		expect(resolveVariant(VARIANTS, { Size: 'Mega' })).toBeNull();
	});

	it('default selection picks the first in-stock value per group', () => {
		expect(defaultSelection(VARIANTS)).toEqual({ Size: 'Compact' });
	});
});

describe('productJsonLd with variants', () => {
	it('emits an Offer[] over the variants (sku + availability per stock)', () => {
		const json = productJsonLd(baseProduct(VARIANTS), 'https://store.nvoos.cloud', 'USD') as {
			offers: Array<Record<string, string>>;
		};
		expect(Array.isArray(json.offers)).toBe(true);
		expect(json.offers).toHaveLength(3);
		expect(json.offers[0].sku).toBe('HLC-COMP-OAK');
		expect(json.offers[0].availability).toBe('https://schema.org/InStock');
		expect(json.offers[1].sku).toBe('HLC-STD-OAK');
		expect(json.offers[1].availability).toBe('https://schema.org/OutOfStock');
		expect(json.offers[2].url).toContain('variant=303');
	});

	it('keeps the existing single-Offer shape for simple products', () => {
		const json = productJsonLd(baseProduct(), 'https://store.nvoos.cloud', 'USD') as {
			offers: { '@type': string; price: string };
		};
		expect(Array.isArray(json.offers)).toBe(false);
		expect(json.offers['@type']).toBe('Offer');
		expect(json.offers.price).toBe('799');
	});
});

describe('variantOfferJsonLd', () => {
	it('stamps the sale validity window only when discounted', () => {
		const discounted = variantOfferJsonLd(VARIANTS[0], 'https://x.test/products/3/', 'USD') as Record<string, unknown>;
		expect(discounted.priceValidUntil).toBeTruthy();
		const fullPrice = variantOfferJsonLd(VARIANTS[2], 'https://x.test/products/3/', 'USD') as Record<string, unknown>;
		expect(fullPrice.priceValidUntil).toBeUndefined();
	});
});

describe('normalizeProductMedia variants', () => {
	it('rewrites variant images to the same-origin /media/* paths', () => {
		const normalized = normalizeProductMedia(baseProduct(VARIANTS));
		expect(normalized.variants?.[0]?.image).toBe('/media/2026/10/halo-chair.jpg');
		expect(normalized.variants?.[2]?.image).toBe('/media/2026/10/halo-chair-2.jpg');
	});

	it('leaves simple products untouched beyond the base fields', () => {
		const normalized = normalizeProductMedia(baseProduct());
		expect(normalized.variants).toBeUndefined();
		expect(normalized.image).toBe('/media/2026/10/halo-chair.jpg');
	});
});

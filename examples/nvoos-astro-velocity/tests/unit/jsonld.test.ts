import { describe, expect, it } from 'vitest';
import {
	breadcrumbJsonLd,
	organizationJsonLd,
	productJsonLd,
	webSiteJsonLd,
} from '../../src/lib/seo/jsonld';
import type { Product } from '../../src/lib/commerce/types';

const site = 'https://store.nvoos.cloud';

const product: Product = {
	id: 1,
	name: 'Aurora 3-Seater Sofa',
	price: 1299,
	oldPrice: 1499,
	description: '<p>Cloud-soft three-seater.</p>',
	category: { id: 1, name: 'Sofas' },
	brand: { id: 1, name: 'NV Home' },
	colors: [],
	width: 210,
	height: 85,
	depth: 95,
	rating: 4.8,
	isBestseller: true,
	isFeatured: true,
	image: '/media/2026/10/aurora.jpg',
	images: [],
	imageId: 11,
	imagesIds: [],
	statuses: [],
	promotion: null,
	createdAt: '2026-09-10 10:00:00',
	updatedAt: '2026-10-20 09:30:00',
};

describe('productJsonLd', () => {
	it('emits Product + Offer with the core fields', () => {
		const json = productJsonLd(product, site, 'USD') as {
			'@type': string;
			name: string;
			offers: { '@type': string; price: string; priceCurrency: string };
			aggregateRating: { '@type': string; ratingValue: string };
		};
		expect(json['@type']).toBe('Product');
		expect(json.name).toBe(product.name);
		expect(json.offers['@type']).toBe('Offer');
		expect(json.offers.price).toBe('1299');
		expect(json.offers.priceCurrency).toBe('USD');
		expect(json.aggregateRating['@type']).toBe('AggregateRating');
	});

	it('strips HTML from the description', () => {
		const json = productJsonLd(product, site, 'USD') as { description: string };
		expect(json.description).not.toContain('<p>');
	});
});

describe('breadcrumbJsonLd', () => {
	it('emits a position-ordered BreadcrumbList', () => {
		const json = breadcrumbJsonLd([
			{ name: 'Home', url: `${site}/` },
			{ name: 'Sofas', url: `${site}/categories/1/sofas/` },
		]) as { itemListElement: Array<{ position: number; name: string }> };
		expect(json.itemListElement).toHaveLength(2);
		expect(json.itemListElement[0].position).toBe(1);
		expect(json.itemListElement[1].name).toBe('Sofas');
	});
});

describe('organization + website', () => {
	it('emit the two site-wide types', () => {
		const org = organizationJsonLd('NV Store', site) as { '@type': string };
		const web = webSiteJsonLd('NV Store', site) as { '@type': string };
		expect(org['@type']).toBe('Organization');
		expect(web['@type']).toBe('WebSite');
	});
});

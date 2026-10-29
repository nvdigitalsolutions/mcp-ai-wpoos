// ---------------------------------------------------------------------------
// JSON-LD generators — Proposal 092 §5.3 / D10.
//
// Pure functions returning schema.org-compatible objects. They are rendered
// server-side into each route's initial <head> (never client-injected) and
// unit-tested for structural validity. Industry baseline: Product + Offer +
// BreadcrumbList + Organization are the core four e-commerce types.
// ---------------------------------------------------------------------------

import type { Product, Promotion } from '../commerce/types';
import { absoluteUrl } from '../url';

export interface BreadcrumbItem {
	name: string;
	url: string;
}

/** Product + Offer (+ AggregateRating when the contract provides a rating). */
export function productJsonLd(
	product: Product,
	site: string | URL,
	currency: string
): Record<string, unknown> {
	const canonical = absoluteUrl(site, `/products/${product.id}/`);
	const images = [product.image, ...product.images].filter(Boolean);
	const offer: Record<string, unknown> = {
		'@type': 'Offer',
		price: String(product.price),
		priceCurrency: currency,
		availability: 'https://schema.org/InStock',
		url: canonical,
	};
	if (product.oldPrice !== null && product.oldPrice > product.price) {
		offer.priceValidUntil = new Date(
			Date.now() + 30 * 24 * 60 * 60 * 1000
		).toISOString().slice(0, 10);
	}

	const data: Record<string, unknown> = {
		'@context': 'https://schema.org',
		'@type': 'Product',
		name: product.name,
		description: stripHtml(product.description).slice(0, 300),
		url: canonical,
		offers: offer,
	};
	if (images.length > 0) {
		data.image = images;
	}
	if (product.brand) {
		data.brand = { '@type': 'Brand', name: product.brand.name };
	}
	if (product.rating !== null) {
		// reviewCount is not in the contract — AggregateRating without it.
		data.aggregateRating = {
			'@type': 'AggregateRating',
			ratingValue: String(product.rating),
			bestRating: '5',
		};
	}
	return data;
}

/** BreadcrumbList — Home → (Category) → Product. */
export function breadcrumbJsonLd(items: BreadcrumbItem[]): Record<string, unknown> {
	return {
		'@context': 'https://schema.org',
		'@type': 'BreadcrumbList',
		itemListElement: items.map((item, index) => ({
			'@type': 'ListItem',
			position: index + 1,
			name: item.name,
			item: item.url,
		})),
	};
}

/** Organization — site-wide, rendered once in the base layout. */
export function organizationJsonLd(
	name: string,
	site: string | URL,
	logoUrl?: string
): Record<string, unknown> {
	const data: Record<string, unknown> = {
		'@context': 'https://schema.org',
		'@type': 'Organization',
		name,
		url: absoluteUrl(site, '/'),
	};
	if (logoUrl) {
		data.logo = logoUrl;
	}
	return data;
}

/** WebSite (+ SearchAction) — home page only. */
export function webSiteJsonLd(name: string, site: string | URL): Record<string, unknown> {
	return {
		'@context': 'https://schema.org',
		'@type': 'WebSite',
		name,
		url: absoluteUrl(site, '/'),
		potentialAction: {
			'@type': 'SearchAction',
			target: {
				'@type': 'EntryPoint',
				urlTemplate: `${absoluteUrl(site, '/').replace(/\/+$/, '')}/search?q={search_term_string}`,
			},
			'query-input': 'required name=search_term_string',
		},
	};
}

/** Sale-event JSON-LD for active promotions (optional enhancement). */
export function promotionJsonLd(promotion: Promotion, site: string | URL): Record<string, unknown> {
	return {
		'@context': 'https://schema.org',
		'@type': 'SaleEvent',
		name: promotion.title,
		description: stripHtml(promotion.description).slice(0, 300),
		url: absoluteUrl(site, '/promotions/'),
		startDate: promotion.startDate || undefined,
		endDate: promotion.endDate || undefined,
	};
}

/** Strip tags for schema text fields. */
function stripHtml(html: string): string {
	return html
		.replace(/<[^>]*>/g, ' ')
		.replace(/\s+/g, ' ')
		.trim();
}

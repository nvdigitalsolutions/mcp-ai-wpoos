// ---------------------------------------------------------------------------
// JSON-LD generators — Proposal 092 §5.3 / D10, extended by 104 P3 (D5).
//
// Pure functions returning schema.org-compatible objects. They are rendered
// server-side into each route's initial <head> (never client-injected) and
// unit-tested for structural validity. Industry baseline: Product + Offer +
// BreadcrumbList + Organization are the core four e-commerce types; 104 adds
// the kind-typed builder (ScrewFast metadata.ts pattern) — new code paths
// construct schema-dts-typed blocks so vocabulary drift (offers,
// aggregateRating, breadcrumb) fails `astro check`.
// ---------------------------------------------------------------------------

import type {
	WithContext,
	BlogPosting as SchemaBlogPosting,
	CollectionPage as SchemaCollectionPage,
	Offer as SchemaOffer,
	WebPage as SchemaWebPage,
} from 'schema-dts';
import type { Product, ProductVariant, Promotion } from '../commerce/types';
import { absoluteUrl } from '../url';

export interface BreadcrumbItem {
	name: string;
	url: string;
}

/** The kind union drives the head — no hand-written schema.org at call sites. */
export type StructuredKind =
	| {
			type: 'Product';
			product: Product;
			site: string | URL;
			currency: string;
			breadcrumb: BreadcrumbItem[];
	  }
	| {
			type: 'CollectionPage';
			name: string;
			url: string;
			breadcrumb: BreadcrumbItem[];
	  };

/** The one builder every route calls. */
export function jsonLdFor(kind: StructuredKind): Record<string, unknown>[] {
	if (kind.type === 'Product') {
		// The Product generator is the pinned 092 implementation; the
		// CollectionPage path below carries the schema-dts-typed vocabulary
		// check (D5) that new kinds must follow.
		return [productJsonLd(kind.product, kind.site, kind.currency), breadcrumbJsonLd(kind.breadcrumb)];
	}
	const page: WithContext<SchemaCollectionPage> = {
		'@context': 'https://schema.org',
		'@type': 'CollectionPage',
		name: kind.name,
		url: kind.url,
		breadcrumb: {
			'@type': 'BreadcrumbList',
			itemListElement: kind.breadcrumb.map((item, index) => ({
				'@type': 'ListItem',
				position: index + 1,
				name: item.name,
				item: item.url,
			})),
		},
	};
	return [page as unknown as Record<string, unknown>];
}

/** Thin wrapper for PLP call sites. */
export function collectionPageJsonLd(args: {
	name: string;
	url: string;
	breadcrumb: BreadcrumbItem[];
}): Record<string, unknown> {
	return jsonLdFor({ type: 'CollectionPage', ...args })[0]!;
}

/** Product + Offer (+ AggregateRating when the contract provides a rating).
 *  Variable products (106 §1.1) emit an Offer[] over the variants instead of
 *  the single base-price offer — schema-dts-typed so vocabulary drift fails
 *  `astro check`. */
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
		offers:
			product.variants && product.variants.length > 0
				? product.variants.map((variant) => variantOfferJsonLd(variant, canonical, currency))
				: offer,
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

/** One variant offer (106 §1.1) — schema-dts `Offer` vocabulary. */
export function variantOfferJsonLd(
	variant: ProductVariant,
	canonical: string,
	currency: string
): Record<string, unknown> {
	const typed: SchemaOffer = {
		'@type': 'Offer',
		price: String(variant.price),
		priceCurrency: currency,
		sku: variant.sku,
		availability: `https://schema.org/${(variant.stock ?? 0) > 0 ? 'InStock' : 'OutOfStock'}`,
		url: `${canonical}?variant=${variant.id}`,
	};
	if (variant.oldPrice !== null && variant.oldPrice > variant.price) {
		typed.priceValidUntil = new Date(
			Date.now() + 30 * 24 * 60 * 60 * 1000
		).toISOString().slice(0, 10);
	}
	return typed as unknown as Record<string, unknown>;
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

/** WebPage — CMS pages (106 §2, schema-dts vocabulary like CollectionPage). */
export function webPageJsonLd(args: {
	name: string;
	url: string;
	description?: string;
	breadcrumb: BreadcrumbItem[];
}): Record<string, unknown> {
	const page: WithContext<SchemaWebPage> = {
		'@context': 'https://schema.org',
		'@type': 'WebPage',
		name: args.name,
		url: args.url,
		...(args.description ? { description: args.description } : {}),
		breadcrumb: {
			'@type': 'BreadcrumbList',
			itemListElement: args.breadcrumb.map((item, index) => ({
				'@type': 'ListItem',
				position: index + 1,
				name: item.name,
				item: item.url,
			})),
		},
	};
	return page as unknown as Record<string, unknown>;
}

/** BlogPosting — blog posts (106 §2). Dates are ISO-8601 strings or omitted. */
export function blogPostingJsonLd(args: {
	title: string;
	url: string;
	description?: string;
	datePublished?: string;
	dateModified?: string;
	image?: string;
}): Record<string, unknown> {
	const post: WithContext<SchemaBlogPosting> = {
		'@context': 'https://schema.org',
		'@type': 'BlogPosting',
		headline: args.title,
		url: args.url,
		mainEntityOfPage: args.url,
		...(args.description ? { description: args.description } : {}),
		...(args.datePublished ? { datePublished: args.datePublished } : {}),
		...(args.dateModified ? { dateModified: args.dateModified } : {}),
		...(args.image ? { image: args.image } : {}),
		author: { '@type': 'Organization', name: 'NV Store' },
	};
	return post as unknown as Record<string, unknown>;
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

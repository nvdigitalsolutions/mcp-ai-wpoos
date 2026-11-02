// ---------------------------------------------------------------------------
// seo.ts — kind-typed JSON-LD builder (Proposal 104 §4.6 / D5), vendored
// into the template like sanitize.ts. One `jsonLdFor(kind, input)` covers
// WebPage, BlogPosting and Product; `breadcrumbList()` covers breadcrumbs.
// Typed with schema-dts, so a malformed document fails `astro check` in the
// generated project instead of silently shipping. The `<` → \u003c escape
// (renderJsonLd) keeps the payload inert inside <script> tags — the same
// rule the template pages applied by hand before this module existed.
// ---------------------------------------------------------------------------

import type {
	BlogPosting,
	BreadcrumbList,
	Offer,
	Product,
	WebPage,
	WithContext,
} from 'schema-dts';

export type StructuredKind = 'WebPage' | 'BlogPosting' | 'Product';

/** One schema.org Offer — the only offer shape the template renders. */
export interface OfferInput {
	price: string;
	priceCurrency?: string;
	availability?: 'https://schema.org/InStock' | 'https://schema.org/OutOfStock';
	url?: string;
}

export interface WebPageInput {
	url?: string;
	title?: string;
	description?: string;
}

export interface BlogPostingInput {
	url?: string;
	headline?: string;
	datePublished?: string;
	dateModified?: string;
	author?: string;
	image?: string;
}

export interface ProductInput {
	name?: string;
	url?: string;
	image?: string;
	description?: string;
	sku?: string;
	offers?: OfferInput;
}

export function jsonLdFor(kind: 'WebPage', input: WebPageInput): WithContext<WebPage>;
export function jsonLdFor(kind: 'BlogPosting', input: BlogPostingInput): WithContext<BlogPosting>;
export function jsonLdFor(kind: 'Product', input: ProductInput): WithContext<Product>;
export function jsonLdFor(
	kind: StructuredKind,
	input: WebPageInput | BlogPostingInput | ProductInput
): WithContext<WebPage | BlogPosting | Product> {
	switch (kind) {
		case 'WebPage': {
			const page = input as WebPageInput;
			const doc: WithContext<WebPage> = {
				'@context': 'https://schema.org',
				'@type': 'WebPage',
				...(page.url ? { url: page.url } : {}),
				...(page.title ? { name: page.title } : {}),
				...(page.description ? { description: page.description } : {}),
			};
			return doc;
		}
		case 'BlogPosting': {
			const post = input as BlogPostingInput;
			const doc: WithContext<BlogPosting> = {
				'@context': 'https://schema.org',
				'@type': 'BlogPosting',
				...(post.headline ? { headline: post.headline } : {}),
				...(post.datePublished ? { datePublished: post.datePublished } : {}),
				...(post.dateModified ? { dateModified: post.dateModified } : {}),
				...(post.url ? { url: post.url } : {}),
				...(post.author ? { author: { '@type': 'Person', name: post.author } } : {}),
				...(post.image ? { image: post.image } : {}),
			};
			return doc;
		}
		case 'Product': {
			const product = input as ProductInput;
			const doc: WithContext<Product> = {
				'@context': 'https://schema.org',
				'@type': 'Product',
				...(product.name ? { name: product.name } : {}),
				...(product.image ? { image: product.image } : {}),
				...(product.description ? { description: product.description } : {}),
				...(product.sku ? { sku: product.sku } : {}),
				...(product.url ? { url: product.url } : {}),
				...(product.offers ? { offers: offerFrom(product.offers) } : {}),
			};
			return doc;
		}
	}
}

/** Build one typed Offer from the template's flat commerce fields. */
function offerFrom(input: OfferInput): Offer {
	return {
		'@type': 'Offer',
		price: input.price,
		...(input.priceCurrency ? { priceCurrency: input.priceCurrency } : {}),
		...(input.availability ? { availability: input.availability } : {}),
		...(input.url ? { url: input.url } : {}),
	};
}

/** A BreadcrumbList over a flat trail of { name, url } steps. */
export function breadcrumbList(items: Array<{ name: string; url: string }>): WithContext<BreadcrumbList> {
	return {
		'@context': 'https://schema.org',
		'@type': 'BreadcrumbList',
		itemListElement: items.map((item, index) => ({
			'@type': 'ListItem',
			position: index + 1,
			name: item.name,
			item: { '@id': item.url },
		})),
	};
}

/**
 * Serialize a document for a `<script type="application/ld+json">` tag.
 * The `<` escape keeps the JSON inert inside the script element.
 */
export function renderJsonLd(document: object): string {
	return JSON.stringify(document).replace(/</g, '\\u003c');
}

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
	Article,
	BlogPosting,
	BreadcrumbList,
	Offer,
	Product,
	WebPage,
	WithContext,
} from 'schema-dts';

export type StructuredKind = 'WebPage' | 'BlogPosting' | 'Article' | 'Product';

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
	description?: string;
	datePublished?: string;
	dateModified?: string;
	author?: string;
	image?: string;
}

/** The Article base shape (BlogPosting is the article subtype posts use). */
export interface ArticleInput {
	url?: string;
	headline?: string;
	description?: string;
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
export function jsonLdFor(kind: 'Article', input: ArticleInput): WithContext<Article>;
export function jsonLdFor(kind: 'Product', input: ProductInput): WithContext<Product>;
export function jsonLdFor(
	kind: StructuredKind,
	input: WebPageInput | BlogPostingInput | ArticleInput | ProductInput
): WithContext<WebPage | BlogPosting | Article | Product> {
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
				...(post.description ? { description: post.description } : {}),
				...(post.datePublished ? { datePublished: post.datePublished } : {}),
				...(post.dateModified ? { dateModified: post.dateModified } : {}),
				...(post.url ? { url: post.url } : {}),
				...(post.author ? { author: { '@type': 'Person', name: post.author } } : {}),
				...(post.image ? { image: post.image } : {}),
			};
			return doc;
		}
		case 'Article': {
			const article = input as ArticleInput;
			const doc: WithContext<Article> = {
				'@context': 'https://schema.org',
				'@type': 'Article',
				...(article.headline ? { headline: article.headline } : {}),
				...(article.description ? { description: article.description } : {}),
				...(article.datePublished ? { datePublished: article.datePublished } : {}),
				...(article.dateModified ? { dateModified: article.dateModified } : {}),
				...(article.url ? { url: article.url } : {}),
				...(article.author ? { author: { '@type': 'Person', name: article.author } } : {}),
				...(article.image ? { image: article.image } : {}),
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
 * Compose the document title (Proposal 131 §2.1).
 *
 * Rank Math's `rank_math_title` and Yoast's `yoast_head_json.title` are the
 * FINAL rendered titles and usually already carry the site name. Appending
 * the site name unconditionally produced `${seoTitle} — ${site} — ${site}`
 * on every real source. Rules: empty → site name; already ends with the site
 * name (optionally after a separator) → leave it; otherwise append.
 */
export function composeDocumentTitle( pageTitle: string, siteTitle: string ): string {
	const title = pageTitle.trim();
	const site = siteTitle.trim();
	if ( ! title ) {
		return site;
	}
	if ( ! site || title.toLowerCase() === site.toLowerCase() ) {
		return title;
	}
	const escapedSite = site.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	// `Site — Site` / `Site - Site` / `Site | Site` / `Site: Site` / bare `Site`.
	const alreadyBranded = new RegExp( `(?:\\s[—–|·:]\\s|\\s-\\s)?${ escapedSite }\\s*$`, 'i' ).test( title );
	return alreadyBranded ? title : `${ title } — ${ site }`;
}

/** The site-level identity the JSON-LD graph needs. */
export interface SiteIdentity {
	/** Absolute site origin, no trailing slash. */
	url: string;
	name: string;
	logo?: string;
	sameAs?: string[];
	/** Absolute search URL (`/search/`) — enables the sitelinks search box. */
	searchUrl?: string;
}

export interface JsonLdGraph {
	'@context': 'https://schema.org';
	'@graph': Array< Record< string, unknown > >;
}

/**
 * Build the site-level graph around one page node (Proposal 131 §2.3):
 * `Organization` + `WebSite` (+ `SearchAction`) + an optional
 * `BreadcrumbList` + the page itself, wired with `@id` references. The page
 * node arrives from `jsonLdFor`; its `@context` is dropped (the graph owns
 * it). This is a REBUILD of the entity graph, never Yoast's `schema.@graph`.
 */
export function jsonLdGraph( input: {
	site: SiteIdentity;
	page: object;
	breadcrumbs?: Array< { name: string; url: string } >;
	/** Extra graph nodes (Proposal 136 §4.5 band nodes: OfferCatalog, Person).
	 * They are wired to the WebSite/Organization before the page node. */
	extraNodes?: Array< Record< string, unknown > >;
} ): JsonLdGraph {
	const origin = input.site.url.replace( /\/+$/, '' );
	const organizationId = `${ origin }/#organization`;
	const websiteId = `${ origin }/#website`;
	const pageNode: Record< string, unknown > = { ...( input.page as Record< string, unknown > ) };
	delete pageNode[ '@context' ];

	const organization: Record< string, unknown > = {
		'@type': 'Organization',
		'@id': organizationId,
		name: input.site.name,
		url: `${ origin }/`,
		...( input.site.logo ? { logo: { '@type': 'ImageObject', url: input.site.logo } } : {} ),
		...( input.site.sameAs && input.site.sameAs.length > 0 ? { sameAs: input.site.sameAs } : {} ),
	};

	const website: Record< string, unknown > = {
		'@type': 'WebSite',
		'@id': websiteId,
		url: `${ origin }/`,
		name: input.site.name,
		publisher: { '@id': organizationId },
		...( input.site.searchUrl
			? {
					potentialAction: {
						'@type': 'SearchAction',
						target: { '@type': 'EntryPoint', urlTemplate: `${ input.site.searchUrl }?q={search_term_string}` },
						'query-input': 'required name=search_term_string',
					},
			  }
			: {} ),
	};

	const graph: Array< Record< string, unknown > > = [ organization, website ];
	if ( input.breadcrumbs && input.breadcrumbs.length > 0 ) {
		const crumbNode: Record< string, unknown > = {
			...( breadcrumbList( input.breadcrumbs ) as unknown as Record< string, unknown > ),
		};
		delete crumbNode[ '@context' ];
		graph.push( crumbNode );
	}
	// Band nodes sit between the site entities and the page node, so the graph
	// reads site → bands → page.
	if ( input.extraNodes && input.extraNodes.length > 0 ) {
		graph.push( ...input.extraNodes );
	}
	graph.push( {
		...pageNode,
		isPartOf: { '@id': websiteId },
		...( pageNode.publisher ? {} : { publisher: { '@id': organizationId } } ),
	} );
	return { '@context': 'https://schema.org', '@graph': graph };
}

/**
 * Serialize a document for a `<script type="application/ld+json">` tag.
 * The `<` escape keeps the JSON inert inside the script element.
 */
export function renderJsonLd(document: object): string {
	return JSON.stringify(document).replace(/</g, '\\u003c');
}

// ---------------------------------------------------------------------------
// Band-level nodes (Proposal 136 §4.5).
//
// The forge emits JSON-LD per POST TYPE today, so a rebuilt pricing band
// shipped no Offer at all. These builders turn the classified bands into graph
// nodes — but only from facts the page actually shows: an Offer is emitted only
// when EVERY plan carries a visible price string, and a Person only when the
// name is real text.
//
// Deliberately absent: FAQPage and HowTo. Google removed the FAQ rich result
// on 2026-05-07 (docs deleted 2026-06-15) and the HowTo rich result in
// September 2023, so emitting either would be markup for a feature that does
// not exist. The visible <details> accordion and <ol> stay exactly as they are.
// ---------------------------------------------------------------------------

/** One pricing plan fact, as the classifier extracts it. */
export interface BandPlan {
	name: string;
	price: string;
	salePrice?: string;
}

/** One team member fact, as the classifier extracts it. */
export interface BandPerson {
	name: string;
	role?: string;
	sameAs?: string;
}

/** The band facts the node builders need (structural typing — no semantic
 * import, so this stays standalone like the rest of the template libs). */
export interface BandInput {
	id?: string;
	kind?: string;
	pricing?: { plans: BandPlan[] };
	team?: { people: BandPerson[] };
}

/**
 * The OfferCatalog for one pricing band, or undefined when any plan lacks a
 * price. A partial catalogue would describe an offer the page does not show.
 */
export function offerCatalogNode( band: BandInput ): Record< string, unknown > | undefined {
	const plans = band.pricing?.plans ?? [];
	if ( plans.length === 0 || plans.some( ( plan ) => ! plan.price ) ) {
		return undefined;
	}
	return {
		'@type': 'OfferCatalog',
		'@id': `#/pricing${ band.id ? `-${ band.id }` : '' }`,
		name: 'Plans',
		itemListElement: plans.map( ( plan, index ) => ( {
			'@type': 'Offer',
			'@id': `#/pricing${ band.id ? `-${ band.id }` : '' }/offer-${ index + 1 }`,
			name: plan.name,
			price: plan.price,
		} ) ),
	};
}

/** One Person node per team member. `worksFor` is wired by the caller. */
export function personNodes( band: BandInput, organizationId: string ): Array< Record< string, unknown > > {
	return ( band.team?.people ?? [] ).map( ( person ) => ( {
		'@type': 'Person',
		name: person.name,
		...( person.role ? { jobTitle: person.role } : {} ),
		...( person.sameAs ? { url: person.sameAs } : {} ),
		worksFor: { '@id': organizationId },
	} ) );
}

/** Every band-level node a set of classified bands contributes. */
export function bandNodes( bands: BandInput[], organizationId: string ): Array< Record< string, unknown > > {
	const nodes: Array< Record< string, unknown > > = [];
	for ( const band of bands ) {
		const catalog = offerCatalogNode( band );
		if ( catalog ) {
			nodes.push( catalog );
		}
		nodes.push( ...personNodes( band, organizationId ) );
	}
	return nodes;
}

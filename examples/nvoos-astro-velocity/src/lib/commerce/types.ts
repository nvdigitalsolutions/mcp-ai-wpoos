// ---------------------------------------------------------------------------
// Commerce contract types — Proposal 092 §3.
//
// These mirror the Payload-shaped envelope served under the
// `nvoos-commerce/v1` namespace. The storefront binds to THIS contract only —
// never to backend-specific endpoints or fields (D3 gate).
// ---------------------------------------------------------------------------

/** Paginated collection envelope — the contract invariant (085 appendix §2). */
export interface PaginatedCollection<T> {
	docs: T[];
	totalDocs: number;
	limit: number;
	totalPages: number;
	page: number;
	hasNextPage: boolean;
	hasPrevPage: boolean;
}

/** Single-term reference (category/brand). */
export interface TermRef {
	id: number;
	name: string;
}

/** Color term — TermRef + hex swatch. */
export interface ColorRef extends TermRef {
	hexCode?: string;
}

/** Promotion reference embedded in products. */
export interface Promotion {
	id: number;
	title: string;
	description: string;
	startDate: string;
	endDate: string;
	active: boolean;
}

/** Product document (mirrors the bridge ProductTransformer output). */
export interface Product {
	id: number;
	name: string;
	price: number;
	oldPrice: number | null;
	description: string;
	category: TermRef | null;
	brand: TermRef | null;
	colors: ColorRef[];
	width: number;
	height: number;
	depth: number;
	rating: number | null;
	isBestseller: boolean;
	isFeatured: boolean;
	image: string;
	images: string[];
	imageId: number | null;
	imagesIds: number[];
	statuses: TermRef[];
	promotion: Promotion | null;
	createdAt: string;
	updatedAt: string;
	/** Publication status — 'publish' | 'draft' (native) or any WP post status (woo). */
	status?: string;
	/** Variable-product children (106 §1.1) — single-doc GETs only, listings stay flat. */
	variants?: ProductVariant[];
}

/** One attribute/value pair on a variant (ordered as the option order). */
export interface ProductVariantAttribute {
	name: string;
	value: string;
}

/** Product variant (106 §1.1) — absent `variants` = simple product. */
export interface ProductVariant {
	id: number;
	sku: string;
	price: number;
	oldPrice: number | null;
	stock: number | null;
	attributes: ProductVariantAttribute[];
	image: string | null;
}

/** Category document (mirrors the bridge CategoryTransformer output). */
export interface Category {
	id: number;
	name: string;
	image: string;
	productsCount: number;
}

/** Brand document. */
export type Brand = TermRef;

/** Status document. */
export type Status = TermRef;

/** Promocode document (mirrors the bridge PromocodeTransformer output). */
export interface Promocode {
	id: number;
	name: string;
	title: string;
	code: string;
	discount: number;
	expiresAt: string;
	isActive: boolean;
	logo: string;
}

/** Global banner singleton. */
export interface Banner {
	image: string;
	promotion: Promotion | null;
}

/** Global carousel singleton. */
export interface Carousel {
	carousel: Array<{
		image: string;
		promotion: Promotion | null;
	}>;
}

/** CMS content document (106 §1.2 — content/pages + content/posts). */
export interface ContentDoc {
	id: number;
	slug: string;
	title: string;
	excerpt: string;
	/** Raw post content (as stored upstream). */
	content: string;
	/** `the_content`-filtered HTML (falls back to `content` when empty). */
	contentRendered: string;
	type: 'page' | 'post';
	featuredImage: string | null;
	seoTitle: string;
	seoDescription: string;
	category: TermRef | null;
	createdAt: string;
	updatedAt: string;
	status?: string;
}

/** Shop globals document (106 §1.3 — globals/shop). */
export interface ShopDoc {
	checkoutUrl: string;
	cartUrl: string;
	currency: string;
	currencySymbol: string;
	updatedAt: string;
}

/** Auth payloads (bridge B4 — owner decision Q5: accounts in v1). */
export interface Customer {
	id: number;
	email: string;
	name: string;
	/** True when the session may manage the store (dashboard access). */
	canManage?: boolean;
	/** Backend-owned dashboard URL — re-rooted onto the storefront origin. */
	adminUrl?: string;
	/** Granted WordPress capability slugs (server-authored, sorted). */
	capabilities?: string[];
	/** Collections the backend mode marks read-only (e.g. woo statuses). */
	readOnlyCollections?: string[];
}

export interface NonceResponse {
	nonce: string;
}

/** Where-clause operators supported by the contract (bridge B2). */
export type WhereOperator = 'equals' | 'in' | 'like';

export type WhereClause = Record<
	string,
	Partial<Record<WhereOperator, string | number | Array<string | number>>>
>;

/** Sort values supported by the contract (bridge B2). */
export type SortValue =
	| 'date'
	| '-date'
	| 'price'
	| '-price'
	| 'rating'
	| '-rating'
	| 'name'
	| '-name';

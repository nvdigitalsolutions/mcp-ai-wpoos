// ---------------------------------------------------------------------------
// types.ts — the forge site profile + collection contracts (Proposal 096).
//
// A site profile is operator-configured data (allowlisted URLs only — the
// forge never accepts arbitrary fetch targets; Proposal 096 §2.4). A
// collection maps one WordPress REST base onto one Astro content collection.
// ---------------------------------------------------------------------------

/** One WordPress REST collection (e.g. posts, pages, a CPT). */
export interface WpCollection {
	/** Collection slug in the generated Astro project (posts, pages, …). */
	slug: string;
	/** REST base path under /wp-json (e.g. 'wp/v2/posts'). */
	restBase: string;
	/** `_fields` pruning list — the anti-N+1 discipline (096 research §2.2). */
	fields: string[];
	/** Fetch `_embed` for related resources (featured media, authors). */
	embed?: boolean;
	/** Page size for pagination. Default 100. */
	perPage?: number;
	/** Hard cap on pages per build (defense against runaway catalogs). */
	maxPages?: number;
}

/** One operator-configured WordPress site. */
export interface SiteProfile {
	/** Root URL (https://…). No trailing slash. */
	url: string;
	/** Display name (discovery fills it when absent). */
	name?: string;
	/** Collections to wire into the generated project. */
	collections: WpCollection[];
}

/** Auth for private endpoints — never stored in the scaffolded repo. */
export type WpAuth =
	| { type: 'basic'; username: string; password: string }
	| { type: 'bearer'; token: string };

/** A single fetched entry (the raw WP REST document). */
export type WpEntry = Record<string, unknown>;

/** A paginated fetch result — truncation is explicit, never silent. */
export interface FetchAllResult<T> {
	entries: T[];
	/** True when the page cap stopped the fetch before the last page. */
	truncated: boolean;
	pages: number;
}

/** The WP root document (site discovery). */
export interface WpRoot {
	name: string;
	description: string;
	url: string;
	namespaces: string[];
}

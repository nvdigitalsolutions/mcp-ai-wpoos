// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// wp-loader.ts — the Astro content-layer loader for WordPress (Proposal 096).
//
// Content-layer loaders, not materialized files: WordPress stays the CMS,
// Astro pulls on every build (and on every dev reload). A webhook-triggered
// rebuild keeps freshness (096 §2.3). The loader:
//   - fetches via WpClient (pagination, `_fields` pruning, typed errors)
//   - sanitizes rendered HTML through the versioned allowlist before it
//     ever reaches `set:html` (the XSS gate is mandatory — 096 §2.4)
//   - keys entries by the WP post ID (stable across slug edits)
//   - digests the `modified_gmt` so unchanged entries skip re-render work
//   - clears the store up front so deleted posts disappear from the build
//   - supports WooCommerce sources: wc/v3 flat documents (price/stock/sku/
//     images/categories) via `kind: 'product'`, or wp/v2 CPTs (no commerce
//     fields, featured media resolved by ID when `_embed` has none)
// ---------------------------------------------------------------------------

import { existsSync, readFileSync } from 'node:fs';
import { basename, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { glob } from 'astro/loaders';
import type { Loader, LoaderContext } from 'astro/loaders';
import { z } from 'astro/zod';
import limax from 'limax';

import { WpClient, WpClientError } from './wp-client.ts';
import type { SiteProfile, WpAuth, WpCollection } from './types.ts';
import { sanitizeHtml } from './sanitize.ts';

export interface WpLoaderOptions {
	/** Site profile (allowlisted URL — the forge never takes arbitrary hosts). */
	site: SiteProfile;
	/** The collection this loader instance serves. */
	collection: WpCollection;
	/** Auth for private sources — never written into the scaffolded project. */
	auth?: WpAuth;
	/**
	 * User-Agent for WP requests. Defaults to a browser-like UA in WpClient
	 * — bot protection (Imunify360/Cloudflare et al.) frequently 403s
	 * UA-less or tool-default requests from datacenter IPs. Override via
	 * the WP_USER_AGENT env (the scaffolded content.config wires it).
	 */
	userAgent?: string;
	/**
	 * Per-request transport cap in ms. Defaults to `WpClient`'s 15s. Raise it
	 * for a source whose REST payload is large: eca.directory serves 19
	 * Elementor pages as a single 1.2 MB response that takes ~30s to
	 * serialise, so a 15s cap aborted the whole build. The scaffolded
	 * `content.config.ts` reads `WP_FETCH_TIMEOUT_MS`.
	 */
	timeoutMs?: number;
	/**
	 * Hybrid local content (Proposal 106 P2): a local MDX glob merged under
	 * the WP entries. Local fills the gaps; WP wins on store-id collision. A
	 * network-level WP failure (no connection at all) degrades to local-only
	 * with a loud warning — HTTP-level errors still fail the build (096:
	 * never silent truncation).
	 */
	local?: HybridLocalSource;
	/**
	 * The profile declares the CMS GONE (`content.source: 'materialized'`),
	 * so the snapshot in `local` is the ONLY source.
	 *
	 * The loader must not touch the network at all: a dead origin answers 404,
	 * 200 with whatever now serves the domain, or hangs — every one of those
	 * is "not a source", and run #102 (2026-11-15) died on exactly that 404
	 * after `forge materialize` had already recovered the content. A
	 * materialized collection without a local source fails with a message
	 * that names the misconfiguration instead of blaming the dead server.
	 */
	localOnly?: boolean;
	/** Test seam — the local loader factory (defaults to astro's glob()). */
	localLoaderFactory?: (
		source: HybridLocalSource,
		options?: { preferWpId?: boolean }
	) => Loader;
}

/** A local MDX glob merged under the WP entries (106 P2 hybrid local). */
export interface HybridLocalSource {
	/** Glob pattern relative to `base` (the standard astro glob() shape). */
	pattern: string;
	/** Base directory, resolved against the Astro project root (e.g. './src/content/posts'). */
	base: string;
}

/**
 * The canonical content-layer schema — one schema serves the WP loader and
 * the local MDX glob (Proposal 106 P2): WP documents and local frontmatter
 * parse into the SAME stable shape. Local frontmatter (`title`,
 * `description`, `date`, `draft`) is normalized in the transform — `date`
 * accepts YAML Date instances and strings, `slug` falls back to the entry
 * id (the filename slug), `description` fills `excerpt` when the source has
 * none. Unknown keys are stripped; the transform output is what template
 * pages type against, so their fields stay `string` (no page churn).
 */
export const contentSchema = z
	.object({
		id: z.union([z.number(), z.string()]).optional(),
		title: z.string().optional(),
		slug: z.string().optional(),
		date: z.union([z.string(), z.date()]).optional(),
		modified: z.string().optional(),
		excerpt: z.string().optional(),
		description: z.string().optional(),
		draft: z.boolean().optional(),
		/**
		 * Hybrid local only — replace the WordPress document that publishes this
		 * slug instead of being dropped by it (Proposal 106 P2).
		 *
		 * An override must keep the CMS's route: `id` is REQUIRED for the
		 * collections routed `/<base>/<id>/<slug>/` (posts, products, CPTs), and
		 * the WordPress slug always wins for the URL. Overriding a page needs no
		 * id — `/[slug]/` is the page's own slug. Meaningless in a materialized
		 * snapshot (there is no CMS to override).
		 */
		override: z.boolean().optional(),
		/**
		 * `lib/materialize.ts` writes this when two source documents shared a slug:
		 * the snapshot publishes the suffixed slug (`team-3`) and keeps the source's
		 * own slug here. A HYBRID load merges on it, so the fallback still yields to
		 * WordPress's `team` instead of appearing as a second page; a materialized
		 * load has no CMS to merge with and ignores it.
		 */
		slugRenamedFrom: z.string().optional(),
		link: z.string().optional(),
		author: z.string().optional(),
		featuredImage: z.string().optional(),
		/** Rank Math title (rank_math_title) or the Yoast fallback (yoast_head_json.title). */
		seoTitle: z.string().optional(),
		/** Rank Math description or the Yoast fallback (yoast_head_json.description). */
		seoDescription: z.string().optional(),
		/**
		 * The normalized SEO head facts (Proposal 131) — robots, canonical, OG and
		 * Twitter values from Rank Math's flat fields + Yoast's `yoast_head_json`.
		 * Absent when the source carries no SEO plugin (the template falls back).
		 */
		seo: z
			.object({
				title: z.string().optional(),
				description: z.string().optional(),
				canonical: z.string().optional(),
				robots: z.string().optional(),
				ogTitle: z.string().optional(),
				ogDescription: z.string().optional(),
				ogImage: z.string().optional(),
				ogType: z.string().optional(),
				ogSiteName: z.string().optional(),
				twitterCard: z.string().optional(),
				twitterTitle: z.string().optional(),
				twitterDescription: z.string().optional(),
				twitterImage: z.string().optional(),
			})
			.optional(),
		price: z.string().optional(),
		regularPrice: z.string().optional(),
		salePrice: z.string().optional(),
		stockStatus: z.string().optional(),
		sku: z.string().optional(),
		categories: z.array(z.string()).optional(),
		images: z.array(z.string()).optional(),
		/** True when the page cap stopped the fetch before the end (096: never silent truncation). */
		truncated: z.boolean().optional(),
	})
	.transform((raw) => {
		const date = raw.date instanceof Date ? raw.date.toISOString() : (raw.date ?? '');
		const id = raw.id;
		return {
			id,
			title: raw.title ?? '',
			slug: raw.slug ?? (typeof id === 'number' ? String(id) : id) ?? '',
			date,
			modified: raw.modified ?? date,
			excerpt: raw.excerpt ?? raw.description,
			description: raw.description,
			draft: raw.draft,
			link: raw.link,
			slugRenamedFrom: raw.slugRenamedFrom,
			author: raw.author,
			featuredImage: raw.featuredImage,
			seoTitle: raw.seoTitle,
			seoDescription: raw.seoDescription,
			seo: raw.seo,
			price: raw.price,
			regularPrice: raw.regularPrice,
			salePrice: raw.salePrice,
			stockStatus: raw.stockStatus,
			sku: raw.sku,
			categories: raw.categories,
			images: raw.images,
			truncated: raw.truncated,
		};
	});

/** Run an async map over items with bounded concurrency (politeness). */
async function mapLimit<T, R>(items: T[], limit: number, fn: (item: T) => Promise<R>): Promise<R[]> {
	const out: R[] = new Array(items.length);
	let next = 0;
	const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
		while (next < items.length) {
			const index = next++;
			out[index] = await fn(items[index]);
		}
	});
	await Promise.all(workers);
	return out;
}

/**
 * Origins that must be relativised in shipped HTML: the CMS itself, so a
 * hard-coded absolute link still resolves from a staging host.
 */
function internalOriginsOf(site: SiteProfile): string[] {
	return [site.url];
}

/**
 * Astro content-layer loader over one WordPress/WooCommerce REST collection.
 */
export function createWpLoader(options: WpLoaderOptions): Loader {
	const { site, collection, auth, local, localOnly } = options;
	const internalOrigins = internalOriginsOf(site);

	const wpLoader: Loader = {
		name: 'nvoos-wp',
		schema: contentSchema,
		load: async (context: LoaderContext) => {
			const client = WpClient.fromProfile(site, { auth, userAgent: options.userAgent, timeoutMs: options.timeoutMs });

			if (collection.source === 'payload' || collection.source === 'local') {
				await loadPayloadEntries(context, client, collection, internalOrigins);
				return;
			}

			if ( !collection.restBase ) {
				// Fail loud and legible: a silent `/wp-json/undefined` request looks
				// like a network fault and wastes an operator's afternoon.
				throw new Error( `[nvoos-wp] ${collection.slug}: no restBase configured.` );
			}

			let fetched;
			try {
				fetched = await client.fetchAll(collection.restBase, {
					perPage: collection.perPage,
					maxPages: collection.maxPages,
					fields: collection.fields,
					embed: collection.embed,
				});
			} catch (error) {
				// An explicitly optional source may be absent (e.g. a store
				// without products) — empty collection, not a failed build.
				if (collection.optional && error instanceof WpClientError && error.code === 'wp_not_found') {
					context.store.clear();
					context.logger.warn(
						`[nvoos-wp] ${collection.slug}: source not found (marked optional) — empty collection.`
					);
					return;
				}
				throw error;
			}
			const { entries, truncated } = fetched;

			// Proposal 131 — the wp/v2 SEO sidecar for wires that carry no SEO
			// fields (wc/v3 products). One bounded request; never fatal.
			const seoIndex = await fetchSeoIndex( client, collection, context.logger );

			// Deletions in WP must disappear from the next build.
			context.store.clear();

			// wp/v2 without `_embed`: resolve featured images by ID —
			// bounded concurrency, and an image miss is never a build failure.
			const mediaUrls = new Map<number, string>();
			const mediaIds = new Set<number>();
			for (const entry of entries) {
				const fm = entry.featured_media;
				if (typeof fm === 'number' && fm > 0 && !extractFeaturedImage(entry)) {
					mediaIds.add(fm);
				}
			}
			await mapLimit([...mediaIds], 5, async (id) => {
				try {
					const { body } = await client.get<{ source_url?: string }>(`wp/v2/media/${id}`, {
						_fields: 'source_url',
					});
					if (typeof body.source_url === 'string') {
						mediaUrls.set(id, body.source_url);
					}
				} catch {
					// Bonus field — never fatal.
				}
			});

			for (const entry of entries) {
				if (collection.exclude?.includes(String(entry.slug ?? ''))) {
					continue;
				}
				const id = String(entry.id ?? entry.slug);
				const seoEntry = seoIndex.get(String(entry.id ?? ''));
				const raw = extractRaw(seoEntry ? { ...entry, ...seoEntry } : entry);
				const modified = raw.modified || raw.date;
				const featured = raw.featuredImage ?? mediaUrls.get(Number(entry.featured_media));

				context.store.set({
					id,
					data: {
						// Declared extra keys first, so a collection cannot silently
						// overwrite a projected field (`title`, `slug`, `id`).
						...passthroughFields( entry, collection ),
						id: Number(entry.id),
						title: raw.title,
						slug: raw.slug,
						date: raw.date,
						modified,
						excerpt: raw.excerpt ? sanitizeHtml(raw.excerpt, { internalOrigins }) : undefined,
						link: raw.link,
						author: raw.author,
						featuredImage: featured,
						seoTitle: raw.seoTitle,
						seoDescription: raw.seoDescription ?? plainText( raw.excerpt ),
						seo: raw.seo,
						price: raw.price,
						regularPrice: raw.regularPrice,
						salePrice: raw.salePrice,
						stockStatus: raw.stockStatus,
						sku: raw.sku,
						categories: raw.categories,
						images: raw.images,
						truncated: truncated ? true : undefined,
					},
					digest: context.generateDigest(modified),
					rendered: { html: sanitizeHtml(raw.html, { internalOrigins }) },
				});
			}

			if (truncated) {
				context.logger.warn(
					`[nvoos-wp] ${collection.slug}: fetch stopped at ${collection.maxPages ?? 50} pages — the build is incomplete. Raise maxPages in the site profile.`
				);
			}
		},
	};

	if ( ! local ) {
		if ( localOnly ) {
			// The profile says the CMS is gone, but nothing replaced it: name the
			// misconfiguration instead of letting the build die on a dead origin.
			return {
				name: 'nvoos-wp',
				schema: contentSchema,
				load: async () => {
					throw new Error(
						`[nvoos-wp] ${collection.slug}: content.source is "materialized" (the CMS is gone) but the collection has no local content source. ` +
							'Scaffold it with a local glob (`src/content/' + collection.slug + '/`) or restore the CMS.'
					);
				},
			};
		}
		return wpLoader;
	}

	// Hybrid local (106 P2): the local glob loads first, the WP loader second
	// (its clear() + re-set leaves the store WP-owned), then local entries are
	// re-set where WP produced nothing — WP wins on collision, local fills
	// the gaps. Drafts never ship (the 106 frontmatter contract).
	//
	// COLLISION is keyed on the ROUTE KEY (the collection's slug), not the store
	// id: a local entry's id is the filename slug while a WordPress entry's id
	// is numeric, so an id-keyed set can never match (virtuallysrilanka,
	// 2026-11-22 — every override silently duplicated). `override: true` in the
	// local frontmatter is the explicit escape hatch (see `adoptLocal`).
	//
	// `localOnly` (a materialized profile) short-circuits the WP half entirely:
	// there is no CMS to ask, so the snapshot is adopted as the whole store.
	const localLoader = (options.localLoaderFactory ?? defaultLocalLoader)(local, {
		// A materialized snapshot keys local entries by the source's numeric id:
		// two documents may share a slug, and Astro's slug-derived default id
		// would drop one of them at load time.
		preferWpId: localOnly,
	});
	return {
		name: 'nvoos-wp',
		schema: contentSchema,
		load: async (context: LoaderContext) => {
			await localLoader.load(context);
			const localEntries = context.store
				.entries()
				.filter(([, entry]) => !isDraftEntry(entry));

			// Local content may ship its body BESIDE the MDX. A materialized
			// WordPress page is arbitrary HTML (nested block tags inside a
			// paragraph), which MDX parses as JSX and rejects — so `<id>.html`
			// next to `<id>.mdx` is the body and the MDX carries only frontmatter.
			const rootUrl = context.config?.root;
			const rootDir = rootUrl ? fileURLToPath( rootUrl ) : '';
			const baseDir =
				local.base && rootUrl ? resolve( fileURLToPath( rootUrl ), local.base ) : '';
			/**
			 * @param wpIds     The store ids WordPress set (second guard).
			 * @param merge     `adoptWpId` for a materialized snapshot; `wpBySlug` is
			 *                  the WP slug index — present only when the CMS answered.
			 */
			const adoptLocal = (
				wpIds: ReadonlySet< string >,
				merge: {
					adoptWpId?: boolean;
					wpBySlug?: Map< string, { id: string; slug: string } >;
				} = {}
			): void => {
				const { adoptWpId = false, wpBySlug } = merge;
				const claimedSlugs = new Set< string >();
				for ( const [id, entry] of localEntries ) {
					const data = entry.data as Record< string, unknown >;
					const slug = ( typeof data.slug === 'string' && data.slug ) || id;
					// A de-duplicated snapshot publishes `<slug>-N` but originated at
					// `slug`. In a hybrid load the CMS still owns the original, so the
					// MERGE key must be the source slug — otherwise the fallback snapshot
					// would come back as a second page beside the live one. A materialized
					// load has no CMS to merge with (the renamed slug is the route).
					const renamedFrom =
						typeof data.slugRenamedFrom === 'string'
							? data.slugRenamedFrom.trim()
							: '';
					const mergeSlug = ! localOnly && renamedFrom !== '' ? renamedFrom : slug;
					const override = data.override === true;
					const slugKey = routeSlug( mergeSlug );
					const collision = wpBySlug?.get( slugKey );
					const publishedKey = routeSlug( slug );
					if ( claimedSlugs.has( publishedKey ) ) {
						// Two local entries for one URL. The glob loader only warns when
						// the FILENAMES collide; a frontmatter `slug` can collide while
						// the ids differ. `verify` fails the run on the duplicate route.
						context.logger.warn(
							`[nvoos-wp] ${ collection.slug }: a second local entry ("${ entryFilePath( entry, id ) }") publishes slug "${ slug }" — the route can only hold one.`
						);
					}
					claimedSlugs.add( publishedKey );

					if ( collision && override ) {
						// Phase 2 — the explicit escape hatch. An override must keep the
						// route the CMS publishes: the id for `/posts/<id>/<slug>/`
						// (posts, products, CPTs), the slug for pages. Anything else
						// would move a live URL, so it is an error, never a guess.
						if ( routesByStoreId( collection ) ) {
							const declared = data.id;
							const expectedRoute = `/${ ( collection.basePath ?? `/${ collection.slug }` ).replace( /^\/+|\/+$/g, '' ) }/${ collision.id }/${ collision.slug }/`;
							if ( declared === undefined || declared === null || String( declared ) === '' ) {
								throw new Error(
									`[nvoos-wp] ${ collection.slug }: "${ entryFilePath( entry, id ) }" sets override: true for WordPress slug "${ collision.slug }" but carries no id. Add "id: ${ collision.id }" so its route stays ${ expectedRoute }.`
								);
							}
							if ( String( declared ) !== collision.id ) {
								throw new Error(
									`[nvoos-wp] ${ collection.slug }: "${ entryFilePath( entry, id ) }" declares id ${ String( declared ) } but WordPress publishes slug "${ collision.slug }" as id ${ collision.id }. An override must keep the WordPress id — otherwise the live URL moves.`
								);
							}
						}
						context.store.delete( collision.id );
						context.logger.info(
							`[nvoos-wp] ${ collection.slug }: local "${ entryFilePath( entry, id ) }" overrides WordPress "${ collision.slug }" (id ${ collision.id }).`
						);
						context.store.set(
							localStoreEntry( entry, data, {
								storeId: routesByStoreId( collection ) ? collision.id : id,
								// The WordPress slug is the URL contract: a case-different
								// local slug must not relocate `/about/` to `/About/`.
								slug: collision.slug,
								baseDir,
								rootDir,
								internalOrigins,
							} )
						);
						continue;
					}

					if ( collision ) {
						// The documented contract: WordPress wins on collision. Silent is
						// what made this a defect, so always name both sources.
						context.logger.warn(
							`[nvoos-wp] ${ collection.slug }: local "${ entryFilePath( entry, id ) }" was ignored — WordPress already publishes slug "${ collision.slug }" (id ${ collision.id }). Add "override: true" to its frontmatter to replace it.`
						);
						continue;
					}

					if ( override && wpBySlug ) {
						context.logger.warn(
							`[nvoos-wp] ${ collection.slug }: local "${ entryFilePath( entry, id ) }" sets override: true but matches no WordPress document — rendering it as a normal local entry.`
						);
					}

					// An `override` that declares a live WordPress id but a different
					// slug is contradictory: the id says "replace this document", the
					// slug says "create another". Refuse rather than ship both.
					if ( override && data.id !== undefined && wpIds.has( String( data.id ) ) ) {
						throw new Error(
							`[nvoos-wp] ${ collection.slug }: "${ entryFilePath( entry, id ) }" declares the id of the WordPress document but a different slug — an override must keep the WordPress slug.`
						);
					}

					// A MATERIALIZED entry carries the source's numeric id in its
					// frontmatter (`lib/materialize.ts`), while the glob id is the
					// filename slug. Adopt it as the store id so every route, feed,
					// sitemap and redirect consumer keeps the WordPress shape
					// (`/posts/2228/blog-details/`) across the detach. Hand-written
					// local MDX has no `id` and keeps the slug id (the hybrid-local
					// contract).
					const storeId =
						adoptWpId && data.id !== undefined && data.id !== null && data.id !== ''
							? String( data.id )
							: id;
					if (wpIds.has(storeId)) {
						// The second guard: a local entry that claims a store id
						// WordPress already used (reachable only when `id` equals the
						// glob id, or in materialized mode with a duplicated snapshot).
						context.logger.warn(
							`[nvoos-wp] ${ collection.slug }: local "${ entryFilePath( entry, id ) }" claims store id "${ storeId }" WordPress already set — local entry ignored.`
						);
						continue;
					}
					context.store.set(
						localStoreEntry( entry, data, { storeId, slug, baseDir, rootDir, internalOrigins } )
					);
				}
			};

			if ( localOnly ) {
				// The CMS is declared gone — never contact it. The glob loader
				// already set the entries; clear and re-adopt so drafts disappear
				// and deleted files cannot linger (the same "clear up front"
				// contract the WP path honours).
				context.store.clear();
				adoptLocal( new Set(), { adoptWpId: true } );
				if ( localEntries.length === 0 ) {
					context.logger.warn(
						`[nvoos-wp] ${collection.slug}: content.source is "materialized" (the CMS is gone) and ${local.base} has no entries — empty collection.`
					);
				}
				return;
			}

			let wpIds = new Set<string>();
			let wpBySlug = new Map< string, { id: string; slug: string } >();
			let wpAnswered = false;
			try {
				await wpLoader.load(context);
				wpAnswered = true;
				// The slug index is the merge key: a local id is the filename slug
				// while a WP id is numeric, so an id-keyed collision set never
				// matches (virtuallysrilanka, 2026-11-22).
				const wpEntries = context.store.entries();
				wpIds = new Set( wpEntries.map( ([ entryId ]) => entryId ) );
				for ( const [ entryId, entry ] of wpEntries ) {
					const data = ( entry.data ?? {} ) as Record< string, unknown >;
					const slug = typeof data.slug === 'string' ? data.slug : '';
					if ( slug === '' ) {
						continue;
					}
					const key = routeSlug( slug );
					const existing = wpBySlug.get( key );
					if ( existing && existing.id !== entryId ) {
						// WordPress allows a PAGE slug to repeat across parents; the
						// rebuild's flat route cannot, and Astro's default conflict
						// behaviour ("warn", first path wins) hides it. Report it here and
						// let `verify`'s route-keys gate fail the run.
						context.logger.warn(
							`[nvoos-wp] ${ collection.slug }: WordPress publishes slug "${ slug }" twice (ids ${ existing.id }, ${ entryId }) — the flat route cannot hold both. Resolve it in the CMS.`
						);
						continue;
					}
					if ( ! existing ) {
						wpBySlug.set( key, { id: entryId, slug } );
					}
				}
			} catch (error) {
				// Network-level failures = no WP connection at all (refused, DNS):
				// degrade to local-only with a loud warning so a forged site stays
				// demo-runnable. HTTP-level errors (auth, 404, 5xx) still fail the
				// build — a reachable-but-misconfigured source is never silent.
				if (!isOfflineError(error)) {
					throw error;
				}
				context.logger.warn(
					`[nvoos-wp] ${collection.slug}: WordPress unreachable (${error instanceof Error ? error.message : String(error)}) — serving local content only.`
				);
			}
			// An offline CMS cannot judge an override: report "matched nothing" only
			// when WordPress actually answered (otherwise an offline demo warns on a
			// perfectly valid local file).
			adoptLocal( wpIds, wpAnswered ? { wpBySlug } : {} );
		},
	};
}

/**
 * A local glob entry's id.
 *
 * Astro's default is the frontmatter `slug` (else the filename, slugified), so
 * two documents sharing a slug collapse to one id and the glob loader drops
 * one. A MATERIALIZED snapshot can legitimately hold two (WordPress page slugs
 * are unique among siblings only), so a materialized load prefers the source's
 * numeric `id` — unique by construction — and lets the route gate report the
 * collision instead of losing a page.
 */
export function localEntryId( entry: { entry?: string; data?: Record< string, unknown > } ): string {
	const data = entry.data ?? {};
	const id = data.id;
	if ( id !== undefined && id !== null && String( id ) !== '' ) {
		return String( id );
	}
	if ( typeof data.slug === 'string' && data.slug !== '' ) {
		return data.slug;
	}
	// Astro's default slugifies each path segment with github-slugger; `slugify`
	// is the repo's slug rule and matches it for ASCII filenames.
	return String( entry.entry ?? '' )
		.replace( /\.[^./\\]+$/, '' )
		.split( /[/\\]/ )
		.map( ( segment ) => slugify( segment ) )
		.join( '/' );
}

/**
 * The production local loader — astro's glob() over the source dir.
 *
 * `preferWpId` (a materialized profile) swaps the default id for
 * `localEntryId`, so a same-slug snapshot pair survives the load.
 */
function defaultLocalLoader(
	source: HybridLocalSource,
	options: { preferWpId?: boolean } = {}
): Loader {
	return glob( {
		pattern: source.pattern,
		base: source.base,
		...( options.preferWpId ? { generateId: localEntryId } : {} ),
	} );
}

/**
 * The merge key for a hybrid-local collision: the route contract's slug.
 *
 * Normalized (trimmed, lower-cased) because the glob id is slugified by Astro
 * while frontmatter can carry any casing, and WordPress stores a lower-cased
 * `post_name`.
 */
function routeSlug(value: unknown): string {
	return String(value ?? '').trim().toLowerCase();
}

/**
 * Whether the collection's route carries the store id (`/<base>/<id>/<slug>/`).
 * Pages are the exception: `/[slug]/` routes on the document's own slug.
 */
function routesByStoreId(collection: WpCollection): boolean {
	return collection.kind !== 'page';
}

/** A local entry's source path for a log line (the glob loader sets it). */
function entryFilePath(entry: { filePath?: string }, fallback: string): string {
	const file = entry.filePath;
	return typeof file === 'string' && file !== '' ? file : `${ fallback }.mdx`;
}

/**
 * Build the store entry for an adopted local document.
 *
 * A local entry has TWO possible bodies and only one of them is HTML: the
 * sibling `<id>.html` is the rendered body (materialized WordPress content,
 * `lib/materialize.ts`), while the glob `body` is the Markdown/MDX SOURCE.
 * Promoting that source to `rendered.html` shadowed the compiled `<Content />`
 * and rendered a real MDX post as literal `import` lines and bullets
 * (2026-11-15).
 *
 * The slug is the caller's: the filename slug for a normal local entry, the
 * WordPress slug for an override (a case-different local slug must not move
 * `/about/` to `/About/`).
 */
function localStoreEntry< T extends { id?: unknown; digest?: unknown; rendered?: unknown; filePath?: unknown } > (
	entry: T,
	data: Record< string, unknown >,
	options: {
		storeId: string;
		slug: string;
		baseDir: string;
		rootDir: string;
		internalOrigins: string[];
	}
): T {
	const id = String(entry.id ?? options.storeId);
	const localBody = (() => {
		// Prefer the MDX file's own path: a snapshot may name the file `<slug>-2`
		// while the store id is the numeric WordPress id, so `<id>.html` would
		// miss. `<file>.html` is the shape `lib/materialize.ts` writes.
		const filePath = typeof entry.filePath === 'string' ? entry.filePath : '';
		if (filePath !== '' && options.rootDir !== '') {
			const source = resolve(options.rootDir, filePath);
			const sibling = source.replace(/\.(?:md|mdx|markdown)$/i, '.html');
			if (sibling !== source && existsSync(sibling)) {
				return readFileSync(sibling, 'utf8').trim();
			}
		}
		if (options.baseDir === '' || basename(id) !== id) {
			return '';
		}
		const fallback = resolve(options.baseDir, `${id}.html`);
		return existsSync(fallback) ? readFileSync(fallback, 'utf8').trim() : '';
	})();
	const existingRendered = entry.rendered as { html?: unknown } | undefined;
	const rendered: { html: string } | undefined =
		typeof existingRendered?.html === 'string'
			? { html: existingRendered.html }
			: localBody === ''
				? undefined
				: { html: sanitizeHtml(localBody, { internalOrigins: options.internalOrigins }) };
	// The digest gets a stable prefix so the store re-writes the entry once
	// (same-digest re-sets are skipped) and later incremental builds still
	// dedupe unchanged files.
	return {
		...entry,
		id: options.storeId,
		digest: entry.digest !== undefined ? `local:${ String( entry.digest ) }` : undefined,
		rendered,
		data: { ...data, slug: options.slug },
	} as T;
}

/** A local entry with draft: true never ships (the 106 frontmatter contract). */
export function isDraftEntry(entry: { data?: Record<string, unknown> }): boolean {
	return entry.data?.draft === true;
}

/** Network-level fetch failures = "no WP connection" (ECONNREFUSED, DNS…). */
export function isOfflineError(error: unknown): boolean {
	return error instanceof TypeError && !(error instanceof WpClientError);
}

interface RawEntry {
	title: string;
	slug: string;
	date: string;
	modified: string;
	html: string;
	excerpt?: string;
	link?: string;
	author?: string;
	featuredImage?: string;
	seoTitle?: string;
	seoDescription?: string;
	seo?: SeoMeta;
	price?: string;
	regularPrice?: string;
	salePrice?: string;
	stockStatus?: string;
	sku?: string;
	categories?: string[];
	images?: string[];
}

/**
 * Copy a collection's declared extra REST keys into the entry's `data`.
 *
 * The projected field set below is deliberate — an unbounded content store is
 * a memory and cache problem. A collection that genuinely needs more (the ECA
 * toolkit's `meta`, its taxonomy term ids, the raw body) names those keys on
 * `WpCollection.passthrough`; anything undeclared stays out.
 */
function passthroughFields( entry: Record< string, unknown >, collection: WpCollection ): Record< string, unknown > {
	const out: Record< string, unknown > = {};
	for ( const key of collection.passthrough ?? [] ) {
		if ( entry[ key ] !== undefined ) out[ key ] = entry[ key ];
	}
	return out;
}

/** Normalize one raw document into the stable entry shape. */
/** Map one wp/v2 or wc/v3 document into the stable entry shape. Exported
 * for the on-demand product route (104 Q4) — the SSR render path shares
 * the loader's extraction so static and dynamic PDPs stay identical. */
export function extractRaw(entry: Record<string, unknown>): RawEntry {
	// wc/v3 flat documents (product kind).
	const title = typeof entry.name === 'string' ? entry.name : extractHtml(entry.title);
	const html = typeof entry.description === 'string' ? entry.description : extractHtml(entry.content);
	const excerpt =
		typeof entry.short_description === 'string' ? entry.short_description : extractHtml(entry.excerpt);
	const date = String(
		entry.date_created_gmt ?? entry.date_created ?? entry.date_gmt ?? entry.date ?? ''
	);
	const modified = String(entry.date_modified_gmt ?? entry.date_modified ?? entry.modified_gmt ?? entry.modified ?? date);
	const embedded = entry._embedded as { author?: Array<{ name?: unknown }> } | undefined;

	const wcImages = Array.isArray(entry.images)
		? entry.images
				.map((image) => (image && typeof image === 'object' && 'src' in image ? String(image.src) : ''))
				.filter(Boolean)
		: [];
	const wcCategories = Array.isArray(entry.categories)
		? entry.categories
				.map((category) => (category && typeof category === 'object' && 'name' in category ? String(category.name) : ''))
				.filter(Boolean)
		: [];

	const seo = extractSeoMeta(entry);
	const hasSeo = Object.keys( seo ).length > 0;

	return {
		title: plainText( title ) || String( entry.id ?? '' ),
		slug: String(entry.slug ?? ''),
		date,
		modified,
		html,
		excerpt: excerpt || undefined,
		link: typeof entry.permalink === 'string' ? entry.permalink : typeof entry.link === 'string' ? entry.link : undefined,
		author: embedded?.author?.[0]?.name ? String(embedded.author[0].name) : undefined,
		featuredImage: extractFeaturedImage(entry) ?? wcImages[0],
		seoTitle: seo.title,
		seoDescription: seo.description,
		seo: hasSeo ? seo : undefined,
		price: extractCommerce(entry, ['price']),
		regularPrice: extractCommerce(entry, ['regular_price']),
		salePrice: extractCommerce(entry, ['sale_price']),
		stockStatus: extractCommerce(entry, ['stock_status']),
		sku: extractCommerce(entry, ['sku']),
		categories: wcCategories.length > 0 ? wcCategories : undefined,
		images: wcImages.length > 0 ? wcImages : undefined,
	};
}

/**
 * Normalized SEO head facts (Proposal 131). Sources, in precedence order:
 * Rank Math's flat REST fields → Yoast's `yoast_head_json` → nothing. Every
 * key is optional (a site without an SEO plugin yields `{}`).
 */
export interface SeoMeta {
	title?: string;
	description?: string;
	canonical?: string;
	robots?: string;
	ogTitle?: string;
	ogDescription?: string;
	ogImage?: string;
	ogType?: string;
	ogSiteName?: string;
	twitterCard?: string;
	twitterTitle?: string;
	twitterDescription?: string;
	twitterImage?: string;
}

/** The wp/v2 fields the SEO sidecar requests — nothing else. */
export const SEO_SIDECAR_FIELDS = [
	'id',
	'slug',
	'rank_math_title',
	'rank_math_description',
	'rank_math_canonical_url',
	'rank_math_robots',
	'rank_math_facebook_title',
	'rank_math_facebook_description',
	'rank_math_facebook_image',
	'rank_math_twitter_title',
	'rank_math_twitter_description',
	'rank_math_twitter_image',
	'yoast_head_json',
];

/** Yoast returns the head as an object; tolerate a JSON string too. */
function yoastHead( entry: Record< string, unknown > ): Record< string, unknown > {
	const raw = entry.yoast_head_json;
	if ( typeof raw === 'string' ) {
		try {
			const parsed = JSON.parse( raw );
			return parsed && typeof parsed === 'object' ? ( parsed as Record< string, unknown > ) : {};
		} catch {
			return {};
		}
	}
	return raw && typeof raw === 'object' ? ( raw as Record< string, unknown > ) : {};
}

function asHttpUrl( value: unknown ): string | undefined {
	return typeof value === 'string' && /^https?:\/\//i.test( value.trim() ) ? value.trim() : undefined;
}

/**
 * Normalize a robots value to a `content` string. Rank Math exposes an array
 * (['index','follow']); Yoast exposes an object ({ index: 'index', follow:
 * 'follow', 'max-snippet': 'max-snippet:-1' }).
 */
export function normalizeRobots( value: unknown ): string | undefined {
	if ( typeof value === 'string' && value.trim() ) {
		return value.trim().replace( /\s*,\s*/g, ', ' );
	}
	const parts = Array.isArray( value )
		? value.filter( ( entry ): entry is string => typeof entry === 'string' && entry.trim() !== '' )
		: value && typeof value === 'object'
			? Object.values( value as Record< string, unknown > ).filter(
					( entry ): entry is string => typeof entry === 'string' && entry.trim() !== ''
			  )
			: [];
	const normalized = [ ...new Set( parts.map( ( entry ) => entry.trim() ) ) ];
	return normalized.length > 0 ? normalized.join( ', ' ) : undefined;
}

/** The first Yoast og_image URL (the array-of-objects shape). */
function yoastOgImage( yoast: Record< string, unknown > ): string | undefined {
	const image = Array.isArray( yoast.og_image ) ? yoast.og_image[ 0 ] : undefined;
	if ( image && typeof image === 'object' && 'url' in image ) {
		return asHttpUrl( ( image as { url?: unknown } ).url );
	}
	return asHttpUrl( yoast.og_image );
}

/**
 * SEO meta from the REST document (Proposal 106 §1.2 discipline, extended by
 * 131): Rank Math flat fields first, then Yoast's `yoast_head_json`. Every
 * field is optional — plugins/fields absent from the response must never
 * crash the loader.
 */
export function extractSeoMeta( entry: Record< string, unknown > ): SeoMeta {
	const yoast = yoastHead( entry );
	const rankTitle = extractText( entry.rank_math_title );
	const rankDescription = extractText( entry.rank_math_description );
	const yoastDescription = extractText( yoast.description ) ?? extractText( yoast.og_description );
	const candidates: SeoMeta = {
		title: rankTitle ?? extractText( yoast.title ),
		description: rankDescription ?? yoastDescription,
		canonical: asHttpUrl( entry.rank_math_canonical_url ) ?? asHttpUrl( yoast.canonical ),
		robots: normalizeRobots( entry.rank_math_robots ) ?? normalizeRobots( yoast.robots ),
		ogTitle: extractText( entry.rank_math_facebook_title ) ?? extractText( yoast.og_title ),
		ogDescription: extractText( entry.rank_math_facebook_description ) ?? extractText( yoast.og_description ),
		ogImage: asHttpUrl( entry.rank_math_facebook_image ) ?? yoastOgImage( yoast ),
		ogType: extractText( yoast.og_type ),
		ogSiteName: extractText( yoast.og_site_name ),
		twitterCard: extractText( yoast.twitter_card ),
		twitterTitle: extractText( entry.rank_math_twitter_title ),
		twitterDescription: extractText( entry.rank_math_twitter_description ),
		twitterImage: asHttpUrl( entry.rank_math_twitter_image ),
	};
	for ( const key of Object.keys( candidates ) as Array< keyof SeoMeta > ) {
		if ( ! candidates[ key ] ) {
			delete candidates[ key ];
		}
	}
	return candidates;
}

/**
 * Proposal 131 — read the wp/v2 SEO sidecar for a commerce collection whose
 * primary wire (wc/v3) carries no SEO fields. ONE bounded paginated request;
 * a 404/403 (CPT not exposed) is a warning, never a failed build.
 */
async function fetchSeoIndex(
	client: WpClient,
	collection: WpCollection,
	logger: { warn: ( message: string ) => void }
): Promise< Map< string, Record< string, unknown > > > {
	const index = new Map< string, Record< string, unknown > >();
	if ( ! collection.seoFrom ) {
		return index;
	}
	try {
		const { entries } = await client.fetchAll< Record< string, unknown > >( collection.seoFrom, {
			perPage: 100,
			maxPages: collection.maxPages,
			fields: SEO_SIDECAR_FIELDS,
		} );
		for ( const entry of entries ) {
			const key = String( entry.id ?? '' );
			if ( key ) {
				index.set( key, entry );
			}
		}
	} catch {
		logger.warn(
			`[nvoos-wp] ${ collection.slug }: SEO sidecar ${ collection.seoFrom } unavailable — falling back to the primary wire's meta.`
		);
	}
	return index;
}

/** A non-empty string field, or undefined (absent fields are never fatal). */
function extractText(value: unknown): string | undefined {
	return typeof value === 'string' && value.length > 0 ? value : undefined;
}

/** The named references WordPress emits in rendered titles/excerpts. */
const NAMED_ENTITIES: Record<string, string> = {
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: "'",
	nbsp: ' ',
	hellip: '…',
	mdash: '—',
	ndash: '–',
	lsquo: '‘',
	rsquo: '’',
	ldquo: '“',
	rdquo: '”',
	laquo: '«',
	raquo: '»',
	copy: '©',
	reg: '®',
	trade: '™',
	times: '×',
	middot: '·',
	bull: '•',
	eacute: 'é',
	egrave: 'è',
	agrave: 'à',
	ccedil: 'ç',
	uuml: 'ü',
	ouml: 'ö',
	auml: 'ä',
	ntilde: 'ñ',
	Eacute: 'É',
};

function fromCodePoint(code: number): string {
	try {
		return String.fromCodePoint(code);
	} catch {
		return '';
	}
}

/**
 * Render a WordPress *rendered* field as PLAIN TEXT.
 *
 * `title.rendered` / `excerpt.rendered` arrive entity-encoded (`We Didn&#8217;t`)
 * because they are HTML fragments. Every consumer of `title` and
 * `seoDescription` is a TEXT sink — `<title>`, `<h1>`, meta/OG/Twitter
 * descriptions, JSON-LD — so the entity must be decoded here or it renders
 * literally (`We Didn&#8217;t`) and a meta description keeps its `<p>` wrapper.
 * `excerpt` itself keeps its HTML: cards and feeds render it as markup.
 */
export function plainText(value: string | undefined): string | undefined {
	if (!value) {
		return undefined;
	}
	const decoded = value
		.replace(/&#x([0-9a-f]+);/gi, (_, hex: string) => fromCodePoint(parseInt(hex, 16)))
		.replace(/&#(\d+);/g, (_, dec: string) => fromCodePoint(Number(dec)))
		.replace(/&([a-z][a-z0-9]*);/gi, (match, name: string) => NAMED_ENTITIES[name] ?? match);
	const text = decoded.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
	return text.length > 0 ? text : undefined;
}

/** Commerce fields are additive — read them when present, skip otherwise. */
function extractCommerce(entry: Record<string, unknown>, keys: string[]): string | undefined {
	for (const key of keys) {
		const value = entry[key];
		if (typeof value === 'string' && value.length > 0) {
			return value;
		}
		if (typeof value === 'number') {
			return String(value);
		}
	}
	return undefined;
}

/**
 * Payload-envelope branch — the nvoos-commerce/v1 wire format served by the
 * nvoos-astro-bridge: `{ docs, totalDocs, limit, totalPages, page,
 * hasNextPage, hasPrevPage }` pagination over flat documents (no `_fields`
 * pruning, no X-WP headers). Reads are public; auth is not sent.
 *
 * `source: 'local'` (Proposal 135 §D4) reads the SAME envelope from the
 * bridge's local-content collection — the CMS-authored documents a managed
 * site owns. It differs in exactly one way that matters here: local
 * documents carry an explicit `slug`, so the URL survives a title edit
 * (envelope documents otherwise derive their slug from the title).
 */
async function loadPayloadEntries(
	context: LoaderContext,
	client: WpClient,
	collection: WpCollection,
	internalOrigins: string[]
): Promise<void> {
	const restBase = payloadRestBase(collection);
	const perPage = collection.perPage ?? 100;
	const maxPages = collection.maxPages ?? 50;
	const entries: Array<Record<string, unknown>> = [];
	let truncated = false;
	let pages = 0;

	for (let page = 1; page <= maxPages; page++) {
		let body: PayloadPage;
		try {
			const response = await client.get<PayloadPage>(restBase, {
				page: String(page),
				limit: String(perPage),
			});
			body = response.body;
		} catch (error) {
			// An explicitly optional envelope source may be absent (a bridge
			// that has not shipped the collection yet, or a site with no local
			// content) — an empty collection, not a failed build. Mirrors the
			// wp/v2 branch's optional handling.
			if (collection.optional && error instanceof WpClientError && error.code === 'wp_not_found') {
				context.store.clear();
				context.logger.warn(
					`[nvoos-wp] ${collection.slug}: source not found (marked optional) — empty collection.`
				);
				return;
			}
			throw error;
		}
		entries.push(...(body.docs ?? []));
		pages = page;
		const totalPages = Math.max(1, Number(body.totalPages ?? 1));
		if (page >= totalPages) {
			break;
		}
		if (page === maxPages) {
			truncated = true;
		}
	}

	context.store.clear();
	for (const entry of entries) {
		if (collection.exclude?.includes(String(entry.slug ?? ''))) {
			continue;
		}
		const raw = extractPayloadRaw(entry);
		const modified = raw.modified || raw.date;
		context.store.set({
			id: String(entry.id ?? raw.slug),
			data: {
				id: Number(entry.id),
				title: raw.title,
				slug: raw.slug,
				date: raw.date,
				modified,
				excerpt: raw.excerpt ? sanitizeHtml(raw.excerpt, { internalOrigins }) : undefined,
				link: raw.link,
				author: raw.author,
				featuredImage: raw.featuredImage,
				seoTitle: raw.seoTitle,
				seoDescription: raw.seoDescription ?? plainText( raw.excerpt ),
				price: raw.price,
				regularPrice: raw.regularPrice,
				salePrice: raw.salePrice,
				stockStatus: raw.stockStatus,
				sku: raw.sku,
				categories: raw.categories,
				images: raw.images,
				truncated: truncated ? true : undefined,
			},
			digest: context.generateDigest(modified),
			rendered: { html: sanitizeHtml(raw.html, { internalOrigins }) },
		});
	}

	if (truncated) {
		context.logger.warn(
			`[nvoos-wp] ${collection.slug}: fetch stopped at ${maxPages} pages — the build is incomplete. Raise maxPages in the site profile.`
		);
	}
}

interface PayloadPage {
	docs?: Array<Record<string, unknown>>;
	totalDocs?: number;
	totalPages?: number;
	page?: number;
}

/**
 * The REST base for an envelope collection.
 *
 * `source: 'local'` has a canonical home, so a profile may omit `restBase`
 * entirely — one less thing to get wrong in 20 site profiles.
 */
export function payloadRestBase(collection: WpCollection): string {
	if (collection.restBase) {
		return collection.restBase;
	}
	return collection.source === 'local' ? 'nvoos-commerce/v1/local' : '';
}

/** Map one flat nvoos-commerce/v1 document into the stable entry shape. */
export function extractPayloadRaw(entry: Record<string, unknown>): RawEntry {
	const title = typeof entry.name === 'string' ? entry.name : String(entry.id ?? '');
	const html = typeof entry.description === 'string' ? entry.description : '';
	const date = String(entry.createdAt ?? entry.created_at ?? '');
	const modified = String(entry.updatedAt ?? entry.updated_at ?? date);

	// An explicit slug wins over the title-derived one: content with a URL
	// (local CMS documents) must not change its route when an editor renames
	// the title. Products carry no slug, so their behaviour is unchanged.
	const slug =
		typeof entry.slug === 'string' && entry.slug.trim() !== '' ? entry.slug.trim() : slugify(title);

	// Price semantics: `price` is the CURRENT price; `oldPrice` present means
	// a sale (strike-through = oldPrice). Mirrors the Woo mapping.
	const price = extractCommerce(entry, ['price']);
	const oldPrice = extractCommerce(entry, ['oldPrice', 'old_price', 'regular_price']);
	const regularPrice = oldPrice ?? price;
	const salePrice = oldPrice && price && oldPrice !== price ? price : undefined;

	const images = Array.isArray(entry.images)
		? entry.images.map((image) => String(image)).filter(Boolean)
		: [];
	const featured = typeof entry.image === 'string' && entry.image.length > 0 ? entry.image : images[0];

	const categories: string[] = [];
	if (Array.isArray(entry.categories)) {
		for (const category of entry.categories) {
			if (category && typeof category === 'object' && 'name' in category) {
				categories.push(String(category.name));
			}
		}
	} else if (entry.category && typeof entry.category === 'object' && 'name' in entry.category) {
		categories.push(String(entry.category.name));
	}

	// Stock statuses are named objects in the envelope (e.g. "In Stock").
	const stockStatus = Array.isArray(entry.statuses) && entry.statuses.length > 0
		? String((entry.statuses[0] as { name?: unknown })?.name ?? '')
		: undefined;

	// The envelope already carries the SEO meta (106 §1.2 contract).
	const seoTitle = extractText(entry.seoTitle);
	const seoDescription = extractText(entry.seoDescription);

	return {
		title,
		slug,
		date,
		modified,
		html,
		link: undefined,
		featuredImage: featured,
		seoTitle,
		seoDescription,
		price,
		regularPrice,
		salePrice,
		stockStatus: stockStatus || undefined,
		sku: undefined,
		categories: categories.length > 0 ? categories : undefined,
		images: images.length > 0 ? images : undefined,
	};
}

/**
 * Deterministic slug for envelope documents that carry none (D4 — limax).
 * Transliterates ("Café Crème" → "cafe-creme"), normalizes underscores
 * to dashes (the pre-limax rule), caps at 80 chars, and falls back to
 * "item" for empty input — the same rule the velocity side applies, so
 * both tracks slug identically.
 */
export function slugify(value: string): string {
	// separateApostrophes: false matches the velocity-side rule (apostrophes
	// dropped, not separated) — one canonical slug algorithm across both
	// tracks (Proposal 104 D4).
	const slug = limax(value, { tone: false, separateApostrophes: false })
		.replace(/_+/g, '-')
		.slice(0, 80);
	return slug || 'item';
}

/** Extract rendered HTML from a wp/v2 `content`/`excerpt` object. */
function extractHtml(field: unknown): string {
	if (typeof field === 'string') {
		return field;
	}
	if (field && typeof field === 'object' && typeof (field as { rendered?: unknown }).rendered === 'string') {
		return (field as { rendered: string }).rendered;
	}
	return '';
}

/** Featured image URL from `_embedded['wp:featuredmedia']` (or the plain field). */
function extractFeaturedImage(entry: Record<string, unknown>): string | undefined {
	const media = (entry._embedded as Record<string, unknown> | undefined)?.['wp:featuredmedia'];
	if (Array.isArray(media) && media.length > 0) {
		const source = media[0]?.['source_url'];
		if (typeof source === 'string' && source.length > 0) {
			return source;
		}
	}
	const plain = entry.featured_media_url ?? entry.featured_image;
	return typeof plain === 'string' && plain.length > 0 ? plain : undefined;
}

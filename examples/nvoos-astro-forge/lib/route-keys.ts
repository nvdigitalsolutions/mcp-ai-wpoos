// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// route-keys.ts — duplicate-URL detection from the content store.
//
// Every route consumer derives its pathname from two fields: `/[slug]/` for a
// page and `/<base>/<id>/<slug>/` for everything else (posts, products, and the
// generated CPT pair). Astro's default `prerenderConflictBehavior` is `warn`
// and keeps the FIRST path generated, so a collision publishes one document
// and drops the other with only a log line (virtuallysrilanka, 2026-11-22).
//
// A POST-BUILD scan of `dist/` cannot see a collision — the loser was
// overwritten. Two layers see it instead:
//
//  - the GLOB loader keys a local entry by its frontmatter `slug` (falling
//    back to the filename), so an EXACT duplicate slug in one collection is
//    warned about and dropped — or fails the build under
//    `build.strictRoutes` (which writes `prerenderConflictBehavior: "error"`);
//  - this gate reads the post-sync store, which still holds everything the
//    glob check does not compare: case/normalization variants (`About` vs
//    `about`), duplicate slugs across two SOURCES (numeric-id WordPress
//    entries bypass the glob check), and two collections mapping one path.
//
// `verify` therefore fails on what would otherwise publish two URLs for one
// document, and skips (never passes) when the store is absent.
// ---------------------------------------------------------------------------

import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

import { decodeContentStore } from './materialize.ts';

export interface RouteKeyConflict {
	collection: string;
	/**
	 * `duplicate-route` — two entries claim the same pathname (a page slug, or
	 * two entries sharing an id). `duplicate-slug` — two entries publish one
	 * slug under different ids (duplicate content on two URLs; the common
	 * post/product case).
	 */
	kind: 'duplicate-route' | 'duplicate-slug';
	slug: string;
	route: string;
	ids: string[];
}

export interface RouteKeyReport {
	conflicts: RouteKeyConflict[];
	/** Entries inspected (drafts excluded). */
	entries: number;
	/** Collections that carried at least one entry. */
	collections: string[];
}

interface CollectionLike {
	slug: string;
	kind?: string;
	basePath?: string;
}

/** The collection's URL prefix (`/posts`, `/case-studies`, …). */
function baseOf(collection: CollectionLike): string {
	const raw = (collection.basePath ?? `/${collection.slug}`).replace(/\/+$/, '');
	return raw.startsWith('/') ? raw : `/${raw}`;
}

/** The merge key: the route contract's slug, normalized for comparison. */
function routeSlug(value: unknown): string {
	return String(value ?? '')
		.trim()
		.toLowerCase();
}

/**
 * The pathname the template emits for one entry.
 *
 * Mirrors `src/pages/[slug].astro` (`page.data.slug`), `posts/[...parts].astro`
 * and `EntryView.astro` (`entry.id` + `data.slug`).
 */
export function routeKeyFor(collection: CollectionLike, id: string, slug: unknown): string {
	const normalized = routeSlug(slug) || routeSlug(id);
	if (collection.kind === 'page') {
		return `/${normalized}/`;
	}
	return `${baseOf(collection)}/${String(id).trim()}/${normalized}/`;
}

/**
 * Find every duplicate URL (and every duplicated document) in a decoded
 * content store. Drafts never ship, so they are excluded.
 *
 * @param root            The decoded store (`collection → id → entry`).
 * @param collections     The profile's collections (kind + basePath decide the
 *                        route shape); a collection the profile omits falls
 *                        back to the id-routed shape.
 */
export function findRouteKeyConflicts(
	root: Record<string, unknown>,
	collections: ReadonlyArray<CollectionLike>
): RouteKeyReport {
	const bySlug = new Map<string, CollectionLike>();
	for (const collection of collections) {
		bySlug.set(collection.slug, collection);
	}

	const conflicts: RouteKeyConflict[] = [];
	const routes = new Map<string, { collection: string; slug: string; ids: string[] }>();
	const slugs = new Map<string, { collection: string; slug: string; route: string; ids: string[] }>();
	const visited = new Set<string>();
	let entries = 0;

	for (const [name, value] of Object.entries(root)) {
		if (name === 'meta::meta' || !value || typeof value !== 'object' || Array.isArray(value)) {
			continue;
		}
		const collection = bySlug.get(name) ?? { slug: name };
		for (const [storeId, entry] of Object.entries(value as Record<string, unknown>)) {
			if (!entry || typeof entry !== 'object') {
				continue;
			}
			const data = (entry as { data?: Record<string, unknown> }).data ?? {};
			if (data.draft === true) {
				continue;
			}
			entries += 1;
			visited.add(name);
			const id = String(
				(entry as { id?: unknown }).id ?? storeId
			);
			const slug = String(data.slug ?? id);
			const route = routeKeyFor(collection, id, slug);

			const onRoute = routes.get(route);
			if (onRoute) {
				conflicts.push({
					collection: name,
					kind: 'duplicate-route',
					slug: routeSlug(slug),
					route,
					ids: [...onRoute.ids, id],
				});
			} else {
				routes.set(route, { collection: name, slug: routeSlug(slug), ids: [id] });
			}

			const slugKey = `${name}\u0000${routeSlug(slug)}`;
			const onSlug = slugs.get(slugKey);
			if (onSlug) {
				// Report the document duplicate once, and never twice for the same
				// pair (a page collision is already the louder `duplicate-route`).
				if (!onRoute) {
					conflicts.push({
						collection: name,
						kind: 'duplicate-slug',
						slug: routeSlug(slug),
						route,
						ids: [...onSlug.ids, id],
					});
				}
				onSlug.ids.push(id);
			} else {
				slugs.set(slugKey, { collection: name, slug: routeSlug(slug), route, ids: [id] });
			}
		}
	}

	return { conflicts, entries, collections: [...visited].sort() };
}

/**
 * Read and decode the built project's content store.
 *
 * Astro writes `data-store.json` under `node_modules/.astro/` (prod cache dir)
 * and `.astro/` (dev); the flat serialization needs `decodeContentStore`.
 */
export function readContentStore(
	targetDir: string
): { root: Record<string, unknown>; path: string } | { error: string } {
	const candidates = [
		join(targetDir, 'node_modules', '.astro', 'data-store.json'),
		join(targetDir, '.astro', 'data-store.json'),
	];
	const storePath = candidates.find((candidate) => existsSync(candidate));
	if (!storePath) {
		return {
			error: 'no content store in the target (run `npm run build` in the project first)',
		};
	}
	try {
		const parsed = JSON.parse(readFileSync(storePath, 'utf8')) as unknown;
		const flat = Array.isArray(parsed) ? parsed : Object.values(parsed as Record<string, unknown>);
		const root = decodeContentStore(flat);
		if (!root || typeof root !== 'object') {
			return { error: `the content store at ${storePath} did not decode to an object` };
		}
		return { root, path: storePath };
	} catch (error) {
		return {
			error: `the content store at ${storePath} could not be read: ${
				error instanceof Error ? error.message : String(error)
			}`,
		};
	}
}

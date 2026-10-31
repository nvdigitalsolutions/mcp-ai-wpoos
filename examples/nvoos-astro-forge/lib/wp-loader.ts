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
// ---------------------------------------------------------------------------

import type { Loader, LoaderContext } from 'astro/loaders';
import { z } from 'astro/zod';

import { WpClient } from './wp-client.ts';
import type { SiteProfile, WpAuth, WpCollection } from './types.ts';
import { sanitizeHtml } from './sanitize.ts';

export interface WpLoaderOptions {
	/** Site profile (allowlisted URL — the forge never takes arbitrary hosts). */
	site: SiteProfile;
	/** The collection this loader instance serves. */
	collection: WpCollection;
	/** Auth for private sites — never written into the scaffolded project. */
	auth?: WpAuth;
}

/**
 * Astro content-layer loader over one WordPress REST collection.
 *
 * The entry shape is deliberately stable and framework-agnostic:
 * `{ id, title, slug, date, modified, excerpt, content, link, author, featuredImage }`.
 */
export function createWpLoader(options: WpLoaderOptions): Loader {
	const { site, collection, auth } = options;

	const schema = z.object({
		id: z.number(),
		title: z.string(),
		slug: z.string(),
		date: z.string(),
		modified: z.string(),
		excerpt: z.string().optional(),
		link: z.string().optional(),
		author: z.string().optional(),
		featuredImage: z.string().optional(),
		/** True when the page cap stopped the fetch before the end (096: never silent truncation). */
		truncated: z.boolean().optional(),
	});

	return {
		name: 'nvoos-wp',
		schema,
		load: async (context: LoaderContext) => {
			const client = WpClient.fromProfile(site, { auth });
			const { entries, truncated } = await client.fetchAll(collection.restBase, {
				perPage: collection.perPage,
				maxPages: collection.maxPages,
				fields: collection.fields,
				embed: collection.embed,
			});

			// Deletions in WP must disappear from the next build.
			context.store.clear();

			for (const entry of entries) {
				const id = String(entry.id ?? entry.slug);
				const modified = String(entry.modified_gmt ?? entry.modified ?? entry.date_gmt ?? entry.date ?? '');
				const rawHtml = extractHtml(entry.content);
				const rawExcerpt = extractHtml(entry.excerpt);
				const featured = extractFeaturedImage(entry);
				const titleField = entry.title as { rendered?: unknown } | undefined;
				const embedded = entry._embedded as { author?: Array<{ name?: unknown }> } | undefined;
				const authorName = embedded?.author?.[0]?.name;

				context.store.set({
					id,
					data: {
						id: Number(entry.id),
						title: String(titleField?.rendered ?? ''),
						slug: String(entry.slug ?? ''),
						date: String(entry.date_gmt ?? entry.date ?? ''),
						modified,
						excerpt: rawExcerpt ? sanitizeHtml(rawExcerpt) : undefined,
						link: typeof entry.link === 'string' ? entry.link : undefined,
						author: authorName ? String(authorName) : undefined,
						featuredImage: featured,
						truncated: truncated ? true : undefined,
					},
					digest: context.generateDigest(modified),
					rendered: { html: sanitizeHtml(rawHtml) },
				});
			}

			if (truncated) {
				context.logger.warn(
					`[nvoos-wp] ${collection.slug}: fetch stopped at ${collection.maxPages ?? 50} pages — the build is incomplete. Raise maxPages in the site profile.`
				);
			}
		},
	};
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
		const source = media[0]?.source_url;
		if (typeof source === 'string' && source.length > 0) {
			return source;
		}
	}
	const plain = entry.featured_media_url ?? entry.featured_image;
	return typeof plain === 'string' && plain.length > 0 ? plain : undefined;
}

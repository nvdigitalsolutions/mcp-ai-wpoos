// ---------------------------------------------------------------------------
// Content collections — Proposal 106 §2 hybrid local.
//
// The envelope (bridge content/pages + content/posts) is the L1 source; the
// local markdown collections below fill gaps when no envelope doc matches
// (L2 merge: envelope wins on slug collision — src/lib/content/merge.ts).
// Demo-runnable with no backend at all.
//
// NOTE: the plan named `.mdx` files — velocity ships `.md` instead. The
// @astrojs/mdx renderer probes every React island by direct call, throwing
// an "Invalid hook call" log line per SSR render; the native markdown entry
// type renders the same hybrid-local layer with zero renderer conflicts and
// zero extra dependencies.
// ---------------------------------------------------------------------------

import { defineCollection } from 'astro:content';
import { z } from 'astro/zod';
import { glob } from 'astro/loaders';

const posts = defineCollection({
	loader: glob({ pattern: '**/*.md', base: './src/content/posts' }),
	schema: z.object({
		title: z.string(),
		description: z.string().optional(),
		date: z.coerce.date().optional(),
		draft: z.boolean().optional().default(false),
	}),
});

const pages = defineCollection({
	loader: glob({ pattern: '**/*.md', base: './src/content/pages' }),
	schema: z.object({
		title: z.string(),
		description: z.string().optional(),
		date: z.coerce.date().optional(),
		draft: z.boolean().optional().default(false),
	}),
});

export const collections = { posts, pages };

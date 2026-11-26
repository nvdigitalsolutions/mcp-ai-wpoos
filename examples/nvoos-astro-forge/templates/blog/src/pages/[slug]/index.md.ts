// SPDX-License-Identifier: MIT
// /<slug>/index.md — the markdown variant of a page (Proposal 136 §4.4).
//
// The llms.txt v2 proposal asks for a clean markdown version at the same URL
// with `.md` appended; for a directory-style URL that is `index.md`. The HTML
// stays canonical — `rel=canonical` still points at it, and Base.astro emits
// `rel="alternate" type="text/markdown"` to this route.
//
// No frontmatter fence: a `.ts` endpoint with a leading `---` is a syntax error.
import type { APIRoute, GetStaticPaths } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../../nvoos.json';
import { markdownFromHtml, markdownFromSource } from '../../forge/llms.ts';

export const getStaticPaths: GetStaticPaths = async () => {
	const pages = await getCollection('pages');
	// The static front page renders at `/` (index.astro), so its own slug must NOT
	// emit a route here: `/<frontPage>/` produced a directory holding only
	// `index.md`, which the static host answered with 403 (no index.html) — and a
	// second URL for the same content (krishandramikasavethe.world, 2026-11-19).
	// `[slug].astro` filters the same way for the HTML route.
	return pages
		.filter((page) => page.data.slug !== manifest.frontPage)
		.map((page) => ({
			params: { slug: page.data.slug },
			// A local MDX page carries a SOURCE body rather than `rendered.html`;
			// it is projected to markdown instead of shipped raw.
			props: { body: page.rendered?.html, source: page.body },
		}));
};

export const GET: APIRoute = ({ props }) => {
	const { body, source } = props as { body?: string; source?: string };
	const markdown =
		typeof body === 'string' && body.trim() !== '' ? markdownFromHtml(body) : markdownFromSource(source ?? '');
	return new Response(markdown, {
		headers: { 'Content-Type': 'text/markdown; charset=utf-8' },
	});
};

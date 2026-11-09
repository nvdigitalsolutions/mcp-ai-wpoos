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
import { markdownFromHtml } from '../../forge/llms.ts';

export const getStaticPaths: GetStaticPaths = async () => {
	const pages = await getCollection('pages');
	// The sanitized body is extracted HERE (server context) — props are
	// serialized and the store's `rendered` object does not survive the trip.
	return pages.map((page) => ({
		params: { slug: page.data.slug },
		props: { body: page.rendered?.html },
	}));
};

export const GET: APIRoute = ({ props }) =>
	new Response(markdownFromHtml((props.body as string | undefined) ?? ''), {
		headers: { 'Content-Type': 'text/markdown; charset=utf-8' },
	});

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
import { markdownFromHtml, markdownFromSource } from '../../forge/llms.ts';

export const getStaticPaths: GetStaticPaths = async () => {
	const pages = await getCollection('pages');
	// The sanitized body is extracted HERE (server context) — props are
	// serialized and the store's `rendered` object does not survive the trip.
	return pages.map((page) => ({
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

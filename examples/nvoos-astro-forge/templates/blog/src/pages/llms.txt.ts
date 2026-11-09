// /llms.txt — the agent-facing index (Proposal 136 §4.4).
//
// The shape is fixed by the llms.txt v2 proposal: an H1, a blockquote summary,
// then sections of links to markdown variants, with `## Optional` last.
//
// This is NOT an SEO feature. Google's changelog (2026-06-15) states llms.txt
// has no ranking effect in Search; its consumers are coding/chat agents and
// Chrome Lighthouse's agentic-browsing checks. Everything here is derived from
// the built collections — no model writes it, and it carries no timestamps, so
// two builds of the same content produce byte-identical output.
//
// NOTE: a `.ts` endpoint has NO frontmatter fence — a leading `---` is a
// syntax error (Proposal 114 gotcha).
import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';
import { composeLlmsTxt } from '../forge/llms.ts';

export const GET: APIRoute = async ({ site }) => {
	const origin = (site?.origin ?? manifest.url ?? '').replace(/\/+$/, '');

	const pages = (await getCollection('pages')).map((page) => ({
		path: `/${page.data.slug}/`,
		title: page.data.title ?? page.data.slug,
		note: page.data.seoDescription ?? page.data.excerpt,
	}));
	const posts = (await getCollection('posts')).map((post) => ({
		path: `/posts/${post.id}/${post.data.slug}/`,
		title: post.data.title ?? post.data.slug,
		note: post.data.seoDescription ?? post.data.excerpt,
	}));

	const body = composeLlmsTxt({
		name: manifest.name ?? 'Site',
		summary: manifest.seoDescription ?? manifest.name ?? '',
		origin,
		pages,
		posts,
		// Secondary: an agent can skip these under a short context budget.
		optional: manifest.surfaces?.search === false ? [] : [{ path: '/search/', title: 'Search' }],
	});

	return new Response(body, {
		headers: { 'Content-Type': 'text/plain; charset=utf-8' },
	});
};

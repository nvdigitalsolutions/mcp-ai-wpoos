// /posts/<id>/<slug>/index.md — the markdown variant of a post
// (Proposal 136 §4.4). The HTML page stays canonical.
//
// No frontmatter fence: a `.ts` endpoint with a leading `---` is a syntax error.
import type { APIRoute, GetStaticPaths } from 'astro';
import { getCollection } from 'astro:content';
import { markdownFromHtml } from '../../../forge/llms.ts';

export const getStaticPaths: GetStaticPaths = async () => {
	const posts = await getCollection('posts');
	// Astro 7 spread params are flat slash-joined strings, not arrays.
	return posts.map((post) => ({
		params: { parts: `${post.id}/${post.data.slug}` },
		props: { body: post.rendered?.html },
	}));
};

export const GET: APIRoute = ({ props }) =>
	new Response(markdownFromHtml((props.body as string | undefined) ?? ''), {
		headers: { 'Content-Type': 'text/markdown; charset=utf-8' },
	});

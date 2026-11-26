// SPDX-License-Identifier: MIT
// /posts/<id>/<slug>/index.md — the markdown variant of a post
// (Proposal 136 §4.4). The HTML page stays canonical.
//
// No frontmatter fence: a `.ts` endpoint with a leading `---` is a syntax error.
import type { APIRoute, GetStaticPaths } from 'astro';
import { getCollection } from 'astro:content';
import { markdownFromHtml, markdownFromSource } from '../../../forge/llms.ts';

export const getStaticPaths: GetStaticPaths = async () => {
	const posts = await getCollection('posts');
	// Astro 7 spread params are flat slash-joined strings, not arrays.
	// Both bodies are read HERE (server context) and passed as plain strings —
	// getStaticPaths props are serialized and the store's `rendered` object does
	// not survive the trip.
	return posts.map((post) => ({
		params: { parts: `${post.id}/${post.data.slug}` },
		// `rendered.html` is a sanitized body (WordPress, or a materialized
		// `<id>.html`). A local MDX post has none: its glob `body` is the SOURCE,
		// which is projected to markdown instead of shipped raw.
		props: { body: post.rendered?.html, source: post.body },
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

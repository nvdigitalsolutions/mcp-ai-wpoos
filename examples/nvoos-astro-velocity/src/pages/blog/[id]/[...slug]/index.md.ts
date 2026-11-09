// /blog/<id>/<slug>/index.md — the markdown variant of a post
// (Proposal 136 §4.4). Portable Text projects structurally; legacy
// `contentRendered` HTML degrades to paragraph-structured text.
//
// No frontmatter fence: a `.ts` endpoint with a leading `---` is a syntax error.
import type { APIRoute } from 'astro';
import { getPost } from '../../../../lib/commerce/client';
import { contentBody } from '../../../../lib/content/render';
import { markdownFromPortableText, textFromHtml } from '../../../../lib/llms';

export const GET: APIRoute = async ({ params, url }) => {
	const post = await getPost(Number(params.id), url);
	if (!post) {
		return new Response('Post not found', { status: 404 });
	}

	const body = contentBody(post);
	const content = body.blocks ? markdownFromPortableText(body.blocks) : textFromHtml(body.html);
	const parts = [`# ${post.title}`, content.trim()];

	return new Response(`${parts.filter(Boolean).join('\n\n').trim()}\n`, {
		headers: { 'Content-Type': 'text/markdown; charset=utf-8' },
	});
};

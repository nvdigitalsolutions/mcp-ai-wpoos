// /pages/<id>/<slug>/index.md — the markdown variant of a CMS page
// (Proposal 136 §4.4). Portable Text projects structurally; legacy
// `contentRendered` HTML degrades to paragraph-structured text.
//
// No frontmatter fence: a `.ts` endpoint with a leading `---` is a syntax error.
import type { APIRoute } from 'astro';
import { getPage } from '../../../../lib/commerce/client';
import { contentBody } from '../../../../lib/content/render';
import { markdownFromPortableText, textFromHtml } from '../../../../lib/llms';

export const GET: APIRoute = async ({ params, url }) => {
	const page = await getPage(Number(params.id), url);
	if (!page) {
		return new Response('Page not found', { status: 404 });
	}

	const body = contentBody(page);
	const content = body.blocks ? markdownFromPortableText(body.blocks) : textFromHtml(body.html);
	const parts = [`# ${page.title}`, content.trim()];

	return new Response(`${parts.filter(Boolean).join('\n\n').trim()}\n`, {
		headers: { 'Content-Type': 'text/markdown; charset=utf-8' },
	});
};

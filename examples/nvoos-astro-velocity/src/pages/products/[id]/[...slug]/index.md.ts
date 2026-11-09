// /products/<id>/<slug>/index.md — the markdown variant of a product
// (Proposal 136 §4.4). The HTML page stays canonical; BaseLayout emits
// `rel="alternate" type="text/markdown"` to this route.
//
// No frontmatter fence: a `.ts` endpoint with a leading `---` is a syntax error.
import type { APIRoute } from 'astro';
import { getProduct } from '../../../../lib/commerce/client';
import { isPublished } from '../../../../lib/commerce/publish';
import { textFromHtml } from '../../../../lib/llms';

export const GET: APIRoute = async ({ params, url }) => {
	const product = await getProduct(Number(params.id), url);
	if (!product || !isPublished(product)) {
		return new Response('Product not found', { status: 404 });
	}

	const parts = [`# ${product.name}`];
	if (product.description) {
		parts.push(textFromHtml(product.description).trim());
	}
	// The price string is the commerce contract's own — never reformatted, so
	// the markdown and the JSON-LD agree with the rendered page.
	if (product.price) {
		const was = product.oldPrice !== null && product.oldPrice !== undefined ? ` (was ${product.oldPrice})` : '';
		parts.push(`**Price:** ${product.price}${was}`);
	}

	return new Response(`${parts.filter(Boolean).join('\n\n').trim()}\n`, {
		headers: { 'Content-Type': 'text/markdown; charset=utf-8' },
	});
};

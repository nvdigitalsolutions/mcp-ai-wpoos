// ---------------------------------------------------------------------------
// Meta builders — Proposal 092 §5.3.
//
// Titles, meta descriptions, canonical and Open Graph/Twitter tags for
// PLP/PDP/home. Descriptions are plain-text excerpts (≤ ~160 chars).
// ---------------------------------------------------------------------------

import type { Product } from '../commerce/types';
import { absoluteUrl } from '../url';

const SITE_NAME = 'NV Store';

export function pageTitle(...parts: Array<string | undefined>): string {
	const tail = parts.filter(Boolean).join(' — ');
	return tail ? `${tail} | ${SITE_NAME}` : SITE_NAME;
}

/** Plain-text excerpt for meta descriptions (and schema text). */
export function plainExcerpt(html: string, max = 160): string {
	const text = html
		.replace(/<[^>]*>/g, ' ')
		.replace(/\s+/g, ' ')
		.trim();
	if (text.length <= max) {
		return text;
	}
	return `${text.slice(0, max - 1).trimEnd()}\u2026`;
}

export interface ProductMeta {
	title: string;
	description: string;
	canonical: string;
	og: {
		type: 'product';
		title: string;
		description: string;
		image: string;
		url: string;
	};
	twitter: {
		card: 'summary_large_image';
		title: string;
		description: string;
		image: string;
	};
}

export function metaForProduct(product: Product, site: string | URL): ProductMeta {
	const canonical = absoluteUrl(site, `/products/${product.id}/`);
	const description = plainExcerpt(product.description);
	const title = pageTitle(product.name);
	return {
		title,
		description,
		canonical,
		og: {
			type: 'product',
			title,
			description,
			image: absoluteUrl(site, product.image),
			url: canonical,
		},
		twitter: {
			card: 'summary_large_image',
			title,
			description,
			image: absoluteUrl(site, product.image),
		},
	};
}

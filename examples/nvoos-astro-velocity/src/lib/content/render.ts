// ---------------------------------------------------------------------------
// Content body resolution (Proposal 107, DECISION-2) — the ONE place a
// content document's body is decided, used by the page and post detail
// routes:
//
//   1. blocks  (Portable Text) — agent-authored structured content renders
//      through the allowlisted component set; parsed loudly (D3).
//   2. contentRendered — trusted upstream (WP the_content output, legacy
//      docs) renders exactly as today.
//   3. content — agent-authored raw HTML; ALWAYS sanitized here (D2) as the
//      render-side backstop to the write-side wp_kses_post gate.
// ---------------------------------------------------------------------------

import type { ContentDoc } from '../commerce/types';
import { parsePortableText, sanitizeContentHtml, type PortableTextBlock } from './portable-text';

export interface ContentBody {
	/** Parsed blocks when the doc renders as Portable Text; null otherwise. */
	blocks: PortableTextBlock[] | null;
	/** Raw HTML when the doc renders as HTML; '' when blocks render. */
	html: string;
}

/** Decide what a content doc renders (blocks-first). Loud failure on
 *  malformed blocks — a doc that declares blocks must validate. */
export function contentBody(doc: ContentDoc): ContentBody {
	if (Array.isArray(doc.blocks) && doc.blocks.length > 0) {
		return { blocks: parsePortableText(doc.blocks), html: '' };
	}
	if (doc.contentRendered) {
		return { blocks: null, html: doc.contentRendered };
	}
	if (doc.content) {
		return { blocks: null, html: sanitizeContentHtml(doc.content) };
	}
	return { blocks: null, html: '' };
}

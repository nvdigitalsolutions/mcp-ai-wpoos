/**
 * MarkdownContent — Renders Markdown with syntax highlighting and sanitization.
 *
 * Uses `marked` for parsing and `DOMPurify` for XSS protection.
 */

import { useMemo, type JSX } from 'react';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

export interface MarkdownContentProps {
	content: string;
	className?: string;
}

export function MarkdownContent( { content, className = '' }: MarkdownContentProps ): JSX.Element {
	const html = useMemo( () => {
		if ( ! content ) {
			return '';
		}
		try {
			const raw = marked.parse( content, { async: false } ) as string;
			return DOMPurify.sanitize( raw, {
				ALLOWED_TAGS: [
					'p', 'br', 'strong', 'em', 'u', 's', 'del', 'a', 'img',
					'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
					'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
					'table', 'thead', 'tbody', 'tr', 'th', 'td',
					'hr', 'span', 'div',
				],
				ALLOWED_ATTR: [ 'href', 'src', 'alt', 'title', 'class', 'target', 'rel' ],
			} );
		} catch {
			return DOMPurify.sanitize( content );
		}
	}, [ content ] );

	return (
		<div
			className={ `nvoos-pro-spa-markdown ${ className }` }
			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}

/**
 * ContentArea — renders Markdown content with custom components.
 *
 * Uses react-markdown with:
 *   - remark-gfm (tables, strikethrough, task lists)
 *   - remark-directive (:::note/tip/warning/danger callouts)
 *   - remark-frontmatter (strip frontmatter from display)
 *   - rehype-slug (id attributes on headings)
 *   - rehype-autolink-headings (# anchor links)
 *
 * Custom renderers:
 *   - `code` → CodeBlock (language label + copy button)
 *   - container directives → Callout
 *
 * @since 1.0.0
 */

import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import remarkDirective from 'remark-directive';
import remarkFrontmatter from 'remark-frontmatter';
import rehypeSlug from 'rehype-slug';
import rehypeAutolinkHeadings from 'rehype-autolink-headings';
import { visit } from 'unist-util-visit';
import CodeBlock from './CodeBlock';
import Callout from './Callout';
import type { Components } from 'react-markdown';
import type { Element } from 'hast';

interface ContentAreaProps {
	content: string;
	remoteUrl?: string;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Resolve a link href against a remote GitHub blob URL so that relative
 * links in remote-repo pages point to GitHub instead of the current site.
 *
 * Pure-anchor links (#section) are returned unchanged so in-page scroll
 * still works. Absolute URLs are also returned unchanged.
 */
function resolveRemoteHref( href: string, remoteUrl: string ): string {
	// Pure anchor — keep for in-page scroll.
	if ( href.startsWith( '#' ) ) {
		return href;
	}
	// Already absolute — keep as-is.
	if ( /^[a-z][a-z0-9+\-.]*:/i.test( href ) ) {
		return href;
	}
	try {
		return new URL( href, remoteUrl ).href;
	} catch {
		return href;
	}
}

// ---------------------------------------------------------------------------
// Custom component map
// ---------------------------------------------------------------------------

const components: Components = {
	// Fenced code blocks
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	code( props: any ) {
		const { node, inline, className, children, ...rest } = props;
		void node;
		void rest;

		if ( inline ) {
			return <code className={ className }>{ children }</code>;
		}

		const match = /language-(\w+)/.exec( className ?? '' );
		const lang = match ? match[ 1 ] : undefined;

		return (
			<CodeBlock language={ lang }>
				{ String( children ).replace( /\n$/, '' ) }
			</CodeBlock>
		);
	},

	// Container directives become callouts.
	// remark-directive transforms :::note into a <section data-directive="note"> node.
	// We intercept that with a custom div renderer.
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	div( props: any ) {
		const { node, className, children, ...rest } = props as {
			node: Element;
			className?: string;
			children: React.ReactNode;
			[ key: string ]: unknown;
		};
		void rest;

		// Check if this is a directive container.
		const directiveName = node?.data?.directiveName as string | undefined;

		if ( directiveName ) {
			const titleNode = node?.data?.hProperties as { title?: string } | undefined;
			return (
				<Callout variant={ directiveName as 'note' | 'tip' | 'warning' | 'danger' } title={ titleNode?.title }>
					{ children }
				</Callout>
			);
		}

		return <div className={ className }>{ children }</div>;
	},
};

// ---------------------------------------------------------------------------
// Remark plugin to expose directive node data through the MDAST tree
// ---------------------------------------------------------------------------

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function remarkDirectiveCallouts() {
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	return ( tree: any ) => {
		visit( tree, [ 'containerDirective' ], ( node: { type: string; name: string; data?: object } ) => {
			if ( ! node.data ) {
				node.data = {};
		  }
			Object.assign( node.data, {
				hName: 'div',
				directiveName: node.name,
				hProperties: { 'data-directive': node.name },
			} );
		} );
	};
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function ContentArea( { content, remoteUrl }: ContentAreaProps ) {
	const extraComponents: Components = {};

	if ( remoteUrl ) {
		const capturedUrl = remoteUrl;
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		extraComponents.a = function ( props: any ) {
			const { href, children, ...rest } = props;
			const resolvedHref = href ? resolveRemoteHref( String( href ), capturedUrl ) : undefined;
			return (
				<a href={ resolvedHref } { ...rest }>
					{ children }
				</a>
			);
		};
	}

	return (
		<article className="dh-content">
			<ReactMarkdown
				remarkPlugins={ [
					remarkGfm,
					remarkFrontmatter,
					remarkDirective,
					remarkDirectiveCallouts,
				] }
				rehypePlugins={ [
					rehypeSlug,
					[
						rehypeAutolinkHeadings,
						{
							behavior: 'prepend',
							properties: { className: 'dh-heading-anchor', ariaHidden: true, tabIndex: -1 },
							content: { type: 'text', value: '#' },
						},
					],
				] }
				components={ { ...components, ...extraComponents } }
			>
				{ content }
			</ReactMarkdown>
		</article>
	);
}

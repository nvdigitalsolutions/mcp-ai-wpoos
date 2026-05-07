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
 * Parse the GitHub owner + repo (lowercased) from any github.com URL.
 * Returns null for non-GitHub URLs.
 */
function parseGitHubOwnerRepo( url: string ): { owner: string; repo: string } | null {
	const m = /^https?:\/\/github\.com\/([^/?#]+)\/([^/?#]+)/i.exec( url );
	return m ? { owner: m[ 1 ].toLowerCase(), repo: m[ 2 ].toLowerCase() } : null;
}

/**
 * Derive a docs-hub slug from a GitHub repo-relative file path.
 * Mirrors NV_oOS_Docs_Hub_Indexer::derive_slug() in PHP so that the
 * generated hash route matches the slug stored in the manifest.
 *
 * Examples:
 *   docs/getting-started.md  → getting-started
 *   README.md                → readme
 *   docs/features/chat.md   → features/chat
 */
function deriveSlugFromPath( filePath: string ): string {
	let s = filePath.replace( /^docs\//i, '' );   // strip leading docs/
	s = s.replace( /\.[a-z]+$/i, '' );             // strip file extension
	s = s.toLowerCase();
	s = s.replace( /[^a-z0-9/\-]/g, '-' );        // non-alnum/slash/hyphen → -
	s = s.replace( /-{2,}/g, '-' );                // collapse runs of hyphens
	s = s.replace( /^[/\-]+|[/\-]+$/g, '' );      // trim leading/trailing /- 
	return s;
}

/**
 * Resolve a link href for a page sourced from a remote GitHub repository.
 *
 * Resolution rules (applied in order):
 *  1. Pure `#section` anchors  → unchanged (in-page scroll via HashRouter).
 *  2. Relative links           → resolved to an absolute URL using the
 *                               GitHub blob URL of the current page as base.
 *  3. Same-repo GitHub blob/tree `.md` links (whether originally absolute or
 *     resolved from relative) → converted to a SPA hash route `#/{slug}`
 *     so the user stays inside the docs hub.
 *  4. All other absolute URLs  → returned as-is; `RemoteAnchor` will add
 *                               `target="_blank"` so they open in a new tab.
 */
function resolveRemoteHref( href: string, remoteUrl: string ): string {
	// Rule 1 — pure in-page anchor.
	if ( href.startsWith( '#' ) ) {
		return href;
	}

	// Rule 2 — resolve relative links against the GitHub blob base URL.
	let absoluteHref: string;
	if ( /^[a-z][a-z0-9+\-.]*:/i.test( href ) ) {
		absoluteHref = href;
	} else {
		try {
			absoluteHref = new URL( href, remoteUrl ).href;
		} catch {
			return href;
		}
	}

	// Rule 3 — same-repo GitHub blob/tree links to .md files → SPA hash route.
	const currentRepo = parseGitHubOwnerRepo( remoteUrl );
	if ( currentRepo ) {
		const targetRepo = parseGitHubOwnerRepo( absoluteHref );
		if (
			targetRepo &&
			targetRepo.owner === currentRepo.owner &&
			targetRepo.repo === currentRepo.repo
		) {
			// Extract path after /blob/{ref}/ or /tree/{ref}/
			const pathMatch = /^https?:\/\/github\.com\/[^/]+\/[^/]+\/(?:blob|tree)\/[^/]+\/([^?#]*)([#?].*)?$/i.exec( absoluteHref );
			if ( pathMatch ) {
				const filePath = decodeURIComponent( pathMatch[ 1 ] );
				const fragment = pathMatch[ 2 ] ?? '';
				if ( /\.md$/i.test( filePath ) ) {
					const slug = deriveSlugFromPath( filePath );
					if ( slug ) {
						// Preserve heading anchor (e.g. #installation) for in-page scroll.
						return '#/' + slug + ( fragment.startsWith( '#' ) ? fragment : '' );
					}
				}
			}
		}
	}

	// Rule 4 — return the absolute URL unchanged; caller adds target="_blank".
	return absoluteHref;
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
		extraComponents.a = function RemoteAnchor( props: any ) {
			const { href, children, ...rest } = props;
			const resolvedHref = href ? resolveRemoteHref( String( href ), capturedUrl ) : undefined;

			// Links that didn't resolve to a SPA hash route are external —
			// open them in a new tab so the user stays in the docs hub.
			const isExternal = resolvedHref && ! resolvedHref.startsWith( '#' );

			return (
				<a
					href={ resolvedHref }
					{ ...rest }
					{ ...( isExternal ? { target: '_blank', rel: 'noopener noreferrer' } : {} ) }
				>
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

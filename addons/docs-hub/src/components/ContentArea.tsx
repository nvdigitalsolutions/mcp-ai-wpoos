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
import rehypeHighlight from 'rehype-highlight';
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
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Scroll to the element with the given id without changing window.location.hash.
 * Used for in-page section anchors so they don't corrupt the HashRouter state.
 */
function scrollToAnchor( e: React.MouseEvent<HTMLAnchorElement>, id: string ) {
	e.preventDefault();
	const target = document.getElementById( id );
	if ( target ) {
		target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}
}

/**
 * Return true for pure in-page section anchors (`#something`) that must NOT
 * navigate the HashRouter.  SPA hash routes (`#/slug`) are excluded.
 */
function isInPageAnchor( href: string ): boolean {
	return href.startsWith( '#' ) && ! href.startsWith( '#/' );
}

// ---------------------------------------------------------------------------
// Custom component map
// ---------------------------------------------------------------------------

const components: Components = {
	// Fenced code blocks.
	// rehype-highlight transforms code children to hljs span tokens before this
	// renderer is called, so `children` may be React nodes rather than a plain
	// string.  We extract the raw text from the hast node's original value (set
	// before highlighting) for the copy button.
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	code( props: any ) {
		const { node, inline, className, children, ...rest } = props;
		void rest;

		if ( inline ) {
			return <code className={ className }>{ children }</code>;
		}

		const match = /language-(\w+)/.exec( className ?? '' );
		const lang = match ? match[ 1 ] : undefined;

		// Extract raw text from hast node for the copy button.
		// rehype-highlight replaces text children with span elements but the
		// original text content is still accessible by walking hast children.
		function extractText( nodeArg: { type: string; value?: string; children?: unknown[] } ): string {
			if ( nodeArg.type === 'text' ) {
				return nodeArg.value ?? '';
			}
			if ( Array.isArray( nodeArg.children ) ) {
				return ( nodeArg.children as Array<{ type: string; value?: string; children?: unknown[] }> )
					.map( extractText )
					.join( '' );
			}
			return '';
		}
		const rawCode = node ? extractText( node as { type: string; value?: string; children?: unknown[] } ) : String( children );

		return (
			<CodeBlock language={ lang } rawCode={ rawCode.replace( /\n$/, '' ) }>
				{ children }
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
		const nodeData = node?.data as
			| { directiveName?: string; hProperties?: { title?: string } }
			| undefined;
		const directiveName = nodeData?.directiveName;

		if ( directiveName ) {
			const titleNode = nodeData?.hProperties;
			return (
				<Callout variant={ directiveName as 'note' | 'tip' | 'warning' | 'danger' } title={ titleNode?.title }>
					{ children }
				</Callout>
			);
		}

		return <div className={ className }>{ children }</div>;
	},

	// Anchors: intercept pure `#section` hrefs so they don't corrupt the
	// HashRouter by changing the hash to a path-less fragment.
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	a( props: any ) {
		const { href, children, ...rest } = props;
		const hrefStr = href ? String( href ) : '';

		if ( hrefStr && isInPageAnchor( hrefStr ) ) {
			return (
				<a
					href={ hrefStr }
					{ ...rest }
					onClick={ ( e: React.MouseEvent<HTMLAnchorElement> ) =>
						scrollToAnchor( e, hrefStr.slice( 1 ) )
					}
				>
					{ children }
				</a>
			);
		}

		return <a href={ hrefStr || undefined } { ...rest }>{ children }</a>;
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
			const resolvedHref = href ? resolveRemoteHref( String( href ), capturedUrl ) : '';

			// Pure in-page section anchor → smooth-scroll, don't change the hash.
			if ( resolvedHref && isInPageAnchor( resolvedHref ) ) {
				return (
					<a
						href={ resolvedHref }
						{ ...rest }
						onClick={ ( e: React.MouseEvent<HTMLAnchorElement> ) =>
							scrollToAnchor( e, resolvedHref.slice( 1 ) )
						}
					>
						{ children }
					</a>
				);
			}

			// SPA hash route (e.g. #/slug from a same-repo .md link) → navigate
			// normally; HashRouter will pick it up.
			if ( resolvedHref && resolvedHref.startsWith( '#' ) ) {
				return <a href={ resolvedHref } { ...rest }>{ children }</a>;
			}

			// External absolute URL → open in a new tab.
			return (
				<a href={ resolvedHref || undefined } { ...rest } target="_blank" rel="noopener noreferrer">
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
					[
						rehypeHighlight,
						{
							// Subset of languages — covers most documentation use-cases without
							// pulling in the full 190-language highlight.js build.  Additional
							// languages can be added here if needed.
							subset: false,
							detect: true,
							ignoreMissing: true,
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

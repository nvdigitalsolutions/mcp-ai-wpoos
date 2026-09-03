/**
 * App — root component.
 *
 * Fetches the manifest on mount, seeds the FlexSearch index, and renders
 * the three-column layout with HashRouter routes.
 *
 * Route structure:
 *   /#/           → first page in manifest (or empty state)
 *   /#/:slug*     → DocPage
 *
 * @since 1.0.0
 */

import { useEffect, useMemo, useState } from 'react';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { fetchManifest } from './api/manifest-client';
import { indexManifest } from './search/flexsearch-adapter';
import type { Manifest } from './api/manifest-client';
import Sidebar from './components/Sidebar';
import SearchBox from './components/SearchBox';
import DocPage from './routes/DocPage';

// ---------------------------------------------------------------------------
// Theming helper
// ---------------------------------------------------------------------------

function getInitialTheme(): string {
	const config = window.NVOOS_DOCS_HUB?.config ?? {};
	if ( config.theme ) {
		return config.theme;
	}
	if ( typeof window !== 'undefined' && window.matchMedia( '(prefers-color-scheme: dark)' ).matches ) {
		return 'dark';
	}
	return 'light';
}

// ---------------------------------------------------------------------------
// Home redirect
// ---------------------------------------------------------------------------

function HomeRedirect( { manifest }: { manifest: Manifest } ) {
	const config = window.NVOOS_DOCS_HUB?.config ?? {};
	const home = config.home;

	// Use configured home page or first page in manifest.
	const firstSlug = home ?? manifest.tree[ 0 ]?.pages[ 0 ]?.slug ?? '';

	if ( firstSlug ) {
		return <Navigate to={ `/${ firstSlug }` } replace />;
	}

	return (
		<div className="dh-loading" style={ { flexDirection: 'column', gap: '1rem' } }>
			<p>No documentation pages found.</p>
			<p style={ { fontSize: 'var(--dh-font-size-sm)', color: 'var(--dh-text-muted)' } }>
				Add Markdown files to your <code>docs/</code> directory and trigger a rebuild.
			</p>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Main App
// ---------------------------------------------------------------------------

export default function App() {
	const [ manifest, setManifest ] = useState<Manifest | null>( null );
	const [ manifestError, setManifestError ] = useState<string | null>( null );
	const [ theme, setTheme ] = useState<string>( getInitialTheme );
	const [ mobileSidebarOpen, setMobileSidebarOpen ] = useState( false );

	// Set of every indexed slug — used by ContentArea to rewrite internal
	// `.md` links into `#/slug` hash routes.
	const slugSet = useMemo(
		() => new Set( ( manifest?.tree ?? [] ).flatMap( ( g ) => g.pages.map( ( p ) => p.slug ) ) ),
		[ manifest ]
	);

	useEffect( () => {
		fetchManifest()
			.then( async ( m ) => {
				setManifest( m );
				// Flatten all entries and seed the search index.
				const allEntries = m.tree.flatMap( ( g ) => g.pages );
				await indexManifest( allEntries );
			} )
			.catch( ( err: unknown ) => {
				setManifestError( err instanceof Error ? err.message : String( err ) );
			} );
	}, [] );

	function toggleTheme() {
		setTheme( ( t ) => ( t === 'dark' ? 'light' : 'dark' ) );
	}

	const closeMobileSidebar = () => setMobileSidebarOpen( false );

	const rootAttrs = {
		className: 'nvoos-docs-hub-root',
		role: 'application',
		'aria-label': 'Documentation browser',
		'data-theme': theme,
	} as React.HTMLAttributes<HTMLDivElement>;

	if ( manifestError ) {
		return (
			<div { ...rootAttrs }>
				<div className="dh-error">
					<h2>Could not load documentation</h2>
					<p style={ { marginTop: '0.5rem', fontSize: 'var(--dh-font-size-sm)' } }>{ manifestError }</p>
				</div>
			</div>
		);
	}

	if ( ! manifest ) {
		return (
			<div { ...rootAttrs }>
				<div className="dh-loading">
					<span className="dh-spinner" role="status" aria-label="Loading documentation" />
					Loading documentation…
				</div>
			</div>
		);
	}

	return (
		<div { ...rootAttrs }>
			<HashRouter>
				<a href="#nvoos-dh-main" className="dh-skip-link">
					Skip to main content
				</a>
				<div className="dh-layout">
					{ /* Header */ }
					<header className="dh-header-area">
						<button
							type="button"
							className="dh-mobile-menu-btn"
							aria-label="Open navigation"
							onClick={ () => setMobileSidebarOpen( true ) }
						>
							☰
						</button>
						<span className="dh-header-brand">Docs</span>
						<div className="dh-header-search-wrap">
							<SearchBox />
						</div>
						<button
							type="button"
							className="dh-header-theme-toggle"
							aria-label={ `Switch to ${ theme === 'dark' ? 'light' : 'dark' } theme` }
							onClick={ toggleTheme }
						>
							{ theme === 'dark' ? '☀️' : '🌙' }
						</button>
					</header>

					{ /* Mobile sidebar overlay backdrop */ }
					{ mobileSidebarOpen && (
						<div
							className="dh-sidebar-overlay"
							role="button"
							tabIndex={ 0 }
							aria-label="Close navigation"
							onClick={ closeMobileSidebar }
							onKeyDown={ ( e ) => { if ( e.key === 'Enter' || e.key === ' ' ) closeMobileSidebar(); } }
						/>
					) }

					{ /* Sidebar */ }
					<aside className={ `dh-sidebar-area${ mobileSidebarOpen ? ' dh-sidebar-open' : '' }` }>
						<Sidebar manifest={ manifest } onNavClose={ closeMobileSidebar } />
					</aside>

					{ /* Main content (routes) — DocPage / NotFound own their own
					     `.dh-main-area` grid cell. The skip-link above targets
					     `#nvoos-dh-main`, which is set on the rendered route's
					     wrapper so screen-reader users land directly on the
					     content region rather than the sidebar. */ }
					<Routes>
						<Route path="/" element={ <HomeRedirect manifest={ manifest } /> } />
						{ /* DocPage renders NotFound itself for unknown slugs; a
							 separate `*` fallback would be unreachable because `/*`
							 already matches everything. */ }
						<Route path="/*" element={ <DocPage slugSet={ slugSet } /> } />
					</Routes>
				</div>
			</HashRouter>
		</div>
	);
}

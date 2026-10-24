/**
 * DocsPage — native NV oOS Docs Hub island inside the Pro SPA.
 *
 * Mirrors KnowledgeGraphPage: the React shell owns the page states, config
 * delivery and auth, while the vendored Docs Hub app mounts as an imperative
 * island. The vendored bundle (upstream/docs-hub.js — byte-identical to
 * addons/docs-hub/assets/dist/docs-hub.js) is a self-contained IIFE with its
 * own React/Router/markdown pipeline, so there is no dependency collision
 * with the Pro SPA tree. Unlike the previous iframe framing, the island runs
 * same-origin: it reads a local REST API directly.
 *
 * Two content sources (Proposal 087, Phase 2), selected in the page header
 * when both are available:
 *   - "Docs Hub" — the standalone Docs Hub plugin's `nvoos-docs/v1` API
 *     (the docsHub endpoint, empty when the plugin is inactive);
 *   - "OKF Knowledge" — curated OKF bundles served in the same Docs Hub
 *     manifest/pages/search shape by `mcp-ai-pro/v1/okf/docs`
 *     (the okf endpoint + `/docs`).
 *
 * Data flow:
 *   1. The runtime (`window.NVOOS_PRO_SPA`) supplies both REST bases.
 *   2. DocsPage injects `window.NVOOS_DOCS_HUB` (`apiUrl`, `nonce`, and
 *      `config: { router: 'memory', defer_mount: true }`) so the island
 *      renders under MemoryRouter — the Pro SPA owns `window.location.hash`.
 *   3. `window.NVoosDocsHub.mountDocsHub( container )` renders the app into
 *      the shell-owned container; the returned `unmount()` tears the React
 *      root down on page exit (and on source switches).
 */

import { type JSX, useEffect, useMemo, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { readProSpaConfig } from '../../api/config';
import './upstream/docs-hub';
import './upstream/docs-hub.css';
import './docs-hub.css';

type DocSource = 'docs' | 'okf';

interface DocsHubIsland {
	mountDocsHub( container: HTMLElement ): { unmount(): void };
}

export function DocsPage(): JSX.Element {
	const runtime = useMemo( () => readProSpaConfig(), [] );
	const docsBase = ( runtime?.endpoints?.docsHub ?? '' )
		.trim()
		.replace( /\/+$/, '' );
	const okfRoot = ( runtime?.endpoints?.okf ?? '' )
		.trim()
		.replace( /\/+$/, '' );
	const okfDocsBase = okfRoot ? `${ okfRoot }/docs` : '';
	const nonce = runtime?.nonce ?? '';
	const theme = runtime?.config?.theme;

	// Auto-select the only available source; prefer the Docs Hub plugin when
	// both are present (the toggle lets the user switch).
	const [ source, setSource ] = useState< DocSource >( () =>
		! docsBase && okfDocsBase ? 'okf' : 'docs'
	);
	const apiBase = source === 'okf' ? okfDocsBase : docsBase;

	const [ failed, setFailed ] = useState( '' );
	const containerRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		if ( ! apiBase || ! containerRef.current ) {
			return;
		}
		let unmount: ( () => void ) | undefined;

		setFailed( '' );

		// Island config — MemoryRouter + explicit mount (no auto-scan).
		( window as unknown as { NVOOS_DOCS_HUB?: Record< string, unknown > } )
			.NVOOS_DOCS_HUB = {
			apiUrl: apiBase,
			nonce,
			config: {
				router: 'memory',
				defer_mount: true,
				...( theme === 'light' || theme === 'dark' ? { theme } : {} ),
			},
		};

		const island = (
			window as unknown as { NVoosDocsHub?: DocsHubIsland }
		 ).NVoosDocsHub;

		if ( ! island?.mountDocsHub ) {
			setFailed(
				__(
					'The documentation browser could not be initialised.',
					'nvoos-pro-spa'
				)
			);
			return;
		}

		unmount = island.mountDocsHub( containerRef.current ).unmount;

		return () => {
			unmount?.();
			delete (
				window as unknown as { NVOOS_DOCS_HUB?: unknown }
			 ).NVOOS_DOCS_HUB;
		};
	}, [ apiBase, nonce, theme ] );

	// Neither source is available — same contract as the knowledge-graph page.
	if ( ! docsBase && ! okfDocsBase ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-docs-hub">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Docs', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div
					className="nvoos-pro-spa-docs-hub__unavailable"
					role="status"
				>
					<p>
						{ __(
							'The connected WordPress site does not expose a documentation API.',
							'nvoos-pro-spa'
						) }
					</p>
					<p>
						{ __(
							'Install and activate the NV oOS Docs Hub plugin, or enable the OKF knowledge surface, to browse documentation here.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		);
	}

	if ( failed ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-docs-hub">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Docs', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div
					className="nvoos-pro-spa-docs-hub__unavailable"
					role="alert"
				>
					<p>{ failed }</p>
				</div>
			</div>
		);
	}

	const showToggle = Boolean( docsBase && okfDocsBase );

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-docs-hub">
			<header className="nvoos-pro-spa-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Docs', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ source === 'okf'
						? __(
							'Content is managed through the OKF knowledge store.',
							'nvoos-pro-spa'
						  )
						: __(
							'Content management lives in the WordPress admin.',
							'nvoos-pro-spa'
						  ) }
				</p>

				{ showToggle && (
					<div
						className="nvoos-pro-spa-docs-hub__source-toggle"
						role="group"
						aria-label={ __(
							'Documentation source',
							'nvoos-pro-spa'
						) }
					>
						<button
							type="button"
							className="nvoos-pro-spa-docs-hub__source-option"
							aria-pressed={ source === 'docs' }
							onClick={ () => setSource( 'docs' ) }
						>
							{ __( 'Docs Hub', 'nvoos-pro-spa' ) }
						</button>
						<button
							type="button"
							className="nvoos-pro-spa-docs-hub__source-option"
							aria-pressed={ source === 'okf' }
							onClick={ () => setSource( 'okf' ) }
						>
							{ __( 'OKF Knowledge', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) }
			</header>

			<div
				ref={ containerRef }
				className="nvoos-pro-spa-docs-hub__island"
			/>
		</div>
	);
}

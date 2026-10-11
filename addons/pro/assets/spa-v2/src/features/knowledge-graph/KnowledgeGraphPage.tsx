/**
 * KnowledgeGraphPage — NV oOS Content Graph explorer inside the Pro SPA.
 *
 * Mounts the vendored Cytoscape explorer (upstream/content-graph-admin.js,
 * ported verbatim from the standalone nvoos-content-graph plugin) as an
 * imperative island: the React layer owns the page shell, auth, and config
 * delivery, while the vendored module owns the graph itself.
 *
 * Data flow:
 *   1. The runtime (`window.NVOOS_PRO_SPA`) supplies the REST base for the
 *      `nvoos-content-graph/v1` namespace.
 *   2. `GET /graph/visual-config` delivers the same visual tokens, presets,
 *      height, and node budget the plugin's admin page localizes.
 *   3. The page writes `window.nvoosContentGraphAdmin` and hands the mounted
 *      DOM to the vendored factory; unmount calls the returned `destroy()`.
 *
 * Auth: the standalone app's global fetch wrapper attaches the bearer /
 * guest / basic credentials to every request, so this page works in all
 * four auth modes without any credential code of its own (the plugin's REST
 * read routes accept logged-in users, assistant credentials, and guest
 * tokens).
 */

import { type JSX, useEffect, useMemo, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import $ from 'jquery';
import cytoscape from 'cytoscape';
import fcose from 'cytoscape-fcose';
import { readProSpaConfig } from '../../api/config';
import {
	initNvoosGraphExplorer,
	type NvoosGraphExplorerHandle,
} from './upstream/content-graph-admin';
import './upstream/content-graph-theme';
import './upstream/content-graph-icons';
import './upstream/content-graph-admin.css';
import './knowledge-graph.css';

// Register the fcose force layout onto the shared cytoscape core before the
// vendored explorer reads `window.cytoscape`.
cytoscape.use( fcose );
( window as unknown as { cytoscape?: unknown } ).cytoscape = cytoscape;

/** Explorer strings mirroring the plugin's admin localization (English). */
const GRAPH_I18N: Record< string, string > = {
	all_types: 'All types',
	loading: 'Loading graph…',
	load_error: 'Failed to load graph data. Ensure the graph has been built.',
	cy_missing: 'Cytoscape.js did not load. Check your network connection.',
	legend: 'Legend',
	zoom_in: 'Zoom in',
	zoom_out: 'Zoom out',
	fit: 'Fit',
	fullscreen: 'Fullscreen',
	export_png: 'Export PNG',
	bg_theme: 'Background: theme',
	bg_transparent: 'Background: transparent',
	bg_white: 'Background: white',
	scale: 'Scale',
	color_by: 'Color by',
	layout: 'Layout',
	node: 'Node',
	connections: 'connections',
	community: 'Community',
	view_post: 'View post ↗',
	neighbors: 'Neighbors',
	a11y_hint:
		'Graph explorer. Use arrow keys to move between nodes, Enter to open details, Escape to clear, + and - to zoom.',
};

interface VisualConfigResponse {
	visual?: Record< string, unknown >;
	presets?: Record< string, unknown >;
	height?: string;
	max_nodes?: number;
}

export function KnowledgeGraphPage(): JSX.Element {
	const runtime = useMemo( () => readProSpaConfig(), [] );
	const endpoint = runtime?.endpoints?.contentGraph ?? '';
	const nonce = runtime?.nonce ?? '';

	const [ config, setConfig ] = useState< VisualConfigResponse | null >( null );
	const [ error, setError ] = useState< string >( '' );
	const explorerRef = useRef< HTMLDivElement | null >( null );

	// Fetch the explorer config (visual tokens + presets) from the plugin.
	useEffect( () => {
		if ( ! endpoint ) {
			return;
		}
		let cancelled = false;
		setError( '' );
		fetch( `${ endpoint.replace( /\/+$/, '' ) }/graph/visual-config` )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( `visual-config returned ${ response.status }` );
				}
				return response.json() as Promise< VisualConfigResponse >;
			} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				setConfig( data );
			} )
			.catch( ( err: unknown ) => {
				if ( cancelled ) {
					return;
				}
				console.error( '[Pro SPA] Knowledge graph config fetch failed:', err );
				setError(
					__(
						'Could not load the knowledge graph configuration.',
						'nvoos-pro-spa'
					)
				);
			} );
		return () => {
			cancelled = true;
		};
	}, [ endpoint ] );

	// Mount the vendored explorer once the config is present and the DOM is
	// rendered; destroy it on unmount (React ⇄ jQuery imperative-island
	// contract: every listener/timer the module registers is torn down).
	useEffect( () => {
		if ( ! config || ! explorerRef.current ) {
			return;
		}
		( window as unknown as { nvoosContentGraphAdmin?: unknown } )
			.nvoosContentGraphAdmin = {
			rest_url: endpoint.replace( /\/+$/, '' ),
			nonce,
			ajax_url: '',
			ajax_nonce: '',
			height: typeof config.height === 'string' ? config.height : '600px',
			max_nodes:
				typeof config.max_nodes === 'number' ? config.max_nodes : 2000,
			visual: config.visual ?? {},
			presets: config.presets ?? {},
			i18n: GRAPH_I18N,
		};

		const handle: NvoosGraphExplorerHandle = initNvoosGraphExplorer( $ );
		return () => {
			handle.destroy();
			delete ( window as unknown as {
				nvoosContentGraphAdmin?: unknown;
			} ).nvoosContentGraphAdmin;
		};
	}, [ config, endpoint, nonce ] );

	// The connected site does not expose the Content Graph API at all.
	if ( ! endpoint ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-knowledge-graph">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Knowledge Graph', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div
					className="nvoos-pro-spa-knowledge-graph__unavailable"
					role="status"
				>
					<p>
						{ __(
							'The connected WordPress site does not expose the NV oOS Content Graph API.',
							'nvoos-pro-spa'
						) }
					</p>
					<p>
						{ __(
							'Install and activate the NV oOS Content Graph plugin on the site to browse its knowledge graph here.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-knowledge-graph">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Knowledge Graph', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div
					className="nvoos-pro-spa-knowledge-graph__unavailable"
					role="alert"
				>
					<p>{ error }</p>
					<p>
						{ __(
							'This surface needs NV oOS Content Graph 1.1.0 or later (it reads the /graph/visual-config REST route).',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			</div>
		);
	}

	if ( ! config ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-knowledge-graph">
				<header className="nvoos-pro-spa-page__header">
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Knowledge Graph', 'nvoos-pro-spa' ) }
					</h2>
				</header>
				<div
					className="nvoos-pro-spa-page-skeleton"
					role="status"
					aria-label={ __( 'Loading knowledge graph', 'nvoos-pro-spa' ) }
				>
					<div
						className="nvoos-pro-spa-page-skeleton__spinner"
						aria-hidden="true"
					/>
				</div>
			</div>
		);
	}

	const height = typeof config.height === 'string' ? config.height : '600px';

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-knowledge-graph">
			<header className="nvoos-pro-spa-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Knowledge Graph', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Explore how the site’s content connects. Read-only view — rebuild and appearance settings live in the WordPress admin.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			{ /* Toolbar + explorer markup mirrors the plugin's settings page
			     (plugins/nvoos-content-graph/src/Admin/SettingsPage.php),
			     minus the admin-only rebuild button. */ }
			<div className="nvoos-content-graph-explorer-wrap">
				<div className="nvoos-content-graph-explorer-toolbar">
					<input
						type="text"
						id="nvoos-content-graph-search"
						placeholder={ __( 'Search nodes…', 'nvoos-pro-spa' ) }
					/>
					<span
						id="nvoos-content-graph-search-count"
						className="nvoos-cg-search-count"
						aria-live="polite"
						hidden
					></span>
					<select id="nvoos-content-graph-type-filter">
						<option value="">
							{ __( 'All types', 'nvoos-pro-spa' ) }
						</option>
					</select>
					<select
						id="nvoos-content-graph-color-by"
						title={ __(
							'Color nodes by — live preview',
							'nvoos-pro-spa'
						) }
					>
						<option value="type">
							{ __( 'Color: Type', 'nvoos-pro-spa' ) }
						</option>
						<option value="community">
							{ __( 'Color: Community', 'nvoos-pro-spa' ) }
						</option>
						<option value="degree">
							{ __( 'Color: Degree', 'nvoos-pro-spa' ) }
						</option>
						<option value="monochrome">
							{ __( 'Color: Monochrome', 'nvoos-pro-spa' ) }
						</option>
					</select>
					<select
						id="nvoos-content-graph-layout-select"
						title={ __( 'Layout algorithm', 'nvoos-pro-spa' ) }
					></select>
					<select
						id="nvoos-content-graph-edge-style"
						title={ __( 'Edge style — live preview', 'nvoos-pro-spa' ) }
					>
						<option value="plain">
							{ __( 'Edges: Plain', 'nvoos-pro-spa' ) }
						</option>
						<option value="arrows">
							{ __( 'Edges: Arrows', 'nvoos-pro-spa' ) }
						</option>
						<option value="tapered">
							{ __( 'Edges: Tapered', 'nvoos-pro-spa' ) }
						</option>
						<option value="density">
							{ __( 'Edges: Density', 'nvoos-pro-spa' ) }
						</option>
						<option value="auto">
							{ __( 'Edges: Auto', 'nvoos-pro-spa' ) }
						</option>
					</select>
					<input
						type="text"
						id="nvoos-content-graph-agent-filter"
						placeholder={ __( 'Agent ID…', 'nvoos-pro-spa' ) }
						style={ { width: '140px' } }
					/>
					<input
						type="text"
						id="nvoos-content-graph-wing-filter"
						placeholder={ __( 'Wing…', 'nvoos-pro-spa' ) }
						style={ { width: '120px' } }
					/>
					<button
						id="nvoos-content-graph-memory-preset-btn"
						className="button"
						title={ __(
							'Show only the agent / wing combination above',
							'nvoos-pro-spa'
						) }
					>
						{ __( 'Apply', 'nvoos-pro-spa' ) }
					</button>
					<button
						id="nvoos-content-graph-memory-clear-btn"
						className="button"
					>
						{ __( 'Clear', 'nvoos-pro-spa' ) }
					</button>
					<span className="nvoos-cg-zoom-cluster">
						<button
							id="nvoos-content-graph-zoom-out-btn"
							className="button"
							title={ __( 'Zoom out', 'nvoos-pro-spa' ) }
						>
							−
						</button>
						<span
							id="nvoos-content-graph-zoom-badge"
							className="nvoos-cg-zoom-badge"
							aria-live="polite"
						>
							100%
						</span>
						<button
							id="nvoos-content-graph-zoom-in-btn"
							className="button"
							title={ __( 'Zoom in', 'nvoos-pro-spa' ) }
						>
							+
						</button>
						<button
							id="nvoos-content-graph-fit-btn"
							className="button"
						>
							{ __( 'Fit', 'nvoos-pro-spa' ) }
						</button>
					</span>
					<select
						id="nvoos-content-graph-export-bg"
						title={ __( 'Export background', 'nvoos-pro-spa' ) }
					>
						<option value="theme">
							{ __( 'BG: Theme', 'nvoos-pro-spa' ) }
						</option>
						<option value="transparent">
							{ __( 'BG: Transparent', 'nvoos-pro-spa' ) }
						</option>
						<option value="white">
							{ __( 'BG: White', 'nvoos-pro-spa' ) }
						</option>
					</select>
					<select
						id="nvoos-content-graph-export-scale"
						title={ __( 'Export scale', 'nvoos-pro-spa' ) }
					>
						<option value="1">1×</option>
						<option value="2">2×</option>
						<option value="3">3×</option>
					</select>
					<button
						id="nvoos-content-graph-export-png-btn"
						className="button"
					>
						{ __( 'Export PNG', 'nvoos-pro-spa' ) }
					</button>
					<button
						id="nvoos-content-graph-fullscreen-btn"
						className="button"
						title={ __( 'Toggle fullscreen', 'nvoos-pro-spa' ) }
					>
						⛶
					</button>
				</div>
				<div className="nvoos-cg-explorer-body">
					{ /* The explorer canvas is keyboard-interactive by design: the
					     vendored module binds arrow-key navigation to this element,
					     which requires it to be focusable (mirrors the plugin's own
					     settings-page markup). */ }
					<div
						ref={ explorerRef }
						id="nvoos-content-graph-explorer"
						style={ { height } }
						tabIndex={ /* eslint-disable-line jsx-a11y/no-noninteractive-tabindex */ 0 }
						role="application"
						aria-label={ __(
							'Knowledge graph explorer',
							'nvoos-pro-spa'
						) }
					>
						<div
							id="nvoos-content-graph-legend"
							className="nvoos-cg-legend"
							hidden
						></div>
						<div
							id="nvoos-content-graph-minimap"
							className="nvoos-cg-minimap"
							hidden
						>
							<canvas
								id="nvoos-content-graph-minimap-canvas"
								width="160"
								height="100"
							></canvas>
						</div>
					</div>
					<div
						id="nvoos-content-graph-sidebar"
						className="nvoos-content-graph-sidebar"
						style={ { display: 'none' } }
					></div>
				</div>
			</div>
		</div>
	);
}

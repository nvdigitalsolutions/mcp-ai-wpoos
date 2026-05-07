/**
 * NV oOS Toolkit Shell — root component.
 *
 * Loads the manifest for the requested toolkit and renders the appropriate
 * view. Phase-0 implementation: minimal table view + status header. Refine
 * integration and Kanban / Calendar views are layered in by future PRs.
 *
 * @since 0.1.0
 */

import { useEffect, useState } from 'react';
import { fetchManifest } from './api/manifest-client';
import { fetchResource } from './api/resource-client';
import type { Manifest, Resource, View } from './api/types';
import { TableView } from './components/TableView';

interface AppProps {
	config: {
		toolkit?: string;
		theme?: 'auto' | 'light' | 'dark';
		view?: string;
		height?: string;
	};
}

type LoadState =
	| { kind: 'idle' }
	| { kind: 'loading' }
	| { kind: 'error'; message: string }
	| { kind: 'ready'; manifest: Manifest };

export function App( { config }: AppProps ) {
	const [ state, setState ] = useState<LoadState>( { kind: 'idle' } );
	const [ rows, setRows ] = useState<Array<Record<string, unknown>> | null>( null );

	useEffect( () => {
		if ( ! config.toolkit ) {
			setState( {
				kind: 'error',
				message: 'No toolkit specified. Add toolkit="<slug>" to the shortcode.',
			} );
			return;
		}
		setState( { kind: 'loading' } );
		fetchManifest( config.toolkit )
			.then( ( manifest ) => {
				setState( { kind: 'ready', manifest } );
				const view = pickView( manifest, config.view );
				if ( ! view ) {
					return;
				}
				const resource = manifest.resources.find( ( r ) => r.name === view.resource );
				if ( ! resource ) {
					return;
				}
				fetchResource( manifest.rest_namespace, resource )
					.then( setRows )
					.catch( () => setRows( [] ) );
			} )
			.catch( ( err: Error ) => {
				setState( { kind: 'error', message: err.message } );
			} );
	}, [ config.toolkit, config.view ] );

	const heightStyle = config.height ? { height: config.height } : undefined;

	return (
		<div
			className="nvoos-toolkit-shell-app"
			data-theme={ config.theme ?? 'auto' }
			style={ heightStyle }
		>
			<header className="nvoos-toolkit-shell-header">
				<h2>{ headerLabel( state ) }</h2>
			</header>
			<main className="nvoos-toolkit-shell-main">
				{ state.kind === 'loading' && <p>Loading manifest…</p> }
				{ state.kind === 'error' && (
					<p className="nvoos-toolkit-shell-error">Error: { state.message }</p>
				) }
				{ state.kind === 'ready' && (
					<ToolkitView
						manifest={ state.manifest }
						viewName={ config.view }
						rows={ rows }
					/>
				) }
			</main>
		</div>
	);
}

function pickView( manifest: Manifest, requested?: string ): View | undefined {
	if ( requested ) {
		const match = manifest.views.find( ( v ) => v.name === requested );
		if ( match ) {
			return match;
		}
	}
	return manifest.views.find( ( v ) => v.default ) ?? manifest.views[ 0 ];
}

function headerLabel( state: LoadState ): string {
	if ( state.kind === 'ready' ) {
		return state.manifest.label || state.manifest.toolkit;
	}
	return 'NV oOS Toolkit';
}

interface ToolkitViewProps {
	manifest: Manifest;
	viewName?: string;
	rows: Array<Record<string, unknown>> | null;
}

function ToolkitView( { manifest, viewName, rows }: ToolkitViewProps ) {
	const view = pickView( manifest, viewName );
	if ( ! view ) {
		return <p>This toolkit has no views.</p>;
	}
	const resource = manifest.resources.find(
		( r: Resource ) => r.name === view.resource
	);
	if ( ! resource ) {
		return <p>View references unknown resource: { view.resource }</p>;
	}
	if ( view.type === 'table' ) {
		return <TableView resource={ resource } rows={ rows ?? [] } />;
	}
	// Kanban, calendar, chart views are stubbed out for now.
	return (
		<div>
			<p>
				The <code>{ view.type }</code> view type is not yet implemented in this
				phase. Falling back to a table.
			</p>
			<TableView resource={ resource } rows={ rows ?? [] } />
		</div>
	);
}

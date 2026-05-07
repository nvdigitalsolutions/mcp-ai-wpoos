/**
 * NV oOS Toolkit Shell — root component.
 *
 * Phase-1: manifest-driven router with table / kanban / detail / form views,
 * a tab strip to switch between manifest views, and full CRUD flow against
 * whichever REST namespace the manifest declares.
 *
 * @since 0.1.0
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { fetchManifest } from './api/manifest-client';
import {
	createResource,
	deleteResource,
	getResource,
	listResource,
	updateResource,
	type ListResult,
} from './api/resource-client';
import type { Manifest, Resource, View } from './api/types';
import { TableView } from './components/TableView';
import { KanbanView } from './components/KanbanView';
import { DetailView } from './components/DetailView';
import { FormView } from './components/FormView';

interface AppProps {
	config: {
		toolkit?: string;
		theme?: 'auto' | 'light' | 'dark';
		view?: string;
		height?: string;
	};
}

type ManifestState =
	| { kind: 'idle' }
	| { kind: 'loading' }
	| { kind: 'error'; message: string }
	| { kind: 'ready'; manifest: Manifest };

type Mode =
	| { kind: 'list' }
	| { kind: 'detail'; id: string | number }
	| { kind: 'create' }
	| { kind: 'edit'; id: string | number };

const PER_PAGE = 25;

export function App( { config }: AppProps ) {
	const [ manifestState, setManifestState ] = useState<ManifestState>( { kind: 'idle' } );
	const [ activeViewName, setActiveViewName ] = useState<string | undefined>( config.view || undefined );
	const [ mode, setMode ] = useState<Mode>( { kind: 'list' } );

	useEffect( () => {
		if ( ! config.toolkit ) {
			setManifestState( {
				kind: 'error',
				message: __( 'No toolkit specified. Add toolkit="<slug>" to the shortcode.', 'nvoos-toolkit-shell' ),
			} );
			return;
		}
		setManifestState( { kind: 'loading' } );
		fetchManifest( config.toolkit )
			.then( ( manifest ) => {
				setManifestState( { kind: 'ready', manifest } );
				if ( ! activeViewName ) {
					setActiveViewName( pickDefaultViewName( manifest ) );
				}
			} )
			.catch( ( err: Error ) =>
				setManifestState( { kind: 'error', message: err.message } )
			);
	}, [ config.toolkit ] );

	const heightStyle = config.height ? { height: config.height } : undefined;

	return (
		<div
			className="nvoos-toolkit-shell-app"
			data-theme={ config.theme ?? 'auto' }
			style={ heightStyle }
		>
			<header className="nvoos-toolkit-shell-header">
				<h2>{ headerLabel( manifestState ) }</h2>
				{ manifestState.kind === 'ready' && (
					<ViewTabs
						manifest={ manifestState.manifest }
						active={ activeViewName }
						onChange={ ( name ) => {
							setActiveViewName( name );
							setMode( { kind: 'list' } );
						} }
					/>
				) }
			</header>
			<main className="nvoos-toolkit-shell-main">
				{ manifestState.kind === 'loading' && <p>{ __( 'Loading manifest…', 'nvoos-toolkit-shell' ) }</p> }
				{ manifestState.kind === 'error' && (
					<p className="nvoos-toolkit-shell-error">
						{ __( 'Error:', 'nvoos-toolkit-shell' ) } { manifestState.message }
					</p>
				) }
				{ manifestState.kind === 'ready' && activeViewName && (
					<ViewSurface
						manifest={ manifestState.manifest }
						viewName={ activeViewName }
						mode={ mode }
						setMode={ setMode }
					/>
				) }
			</main>
		</div>
	);
}

function ViewTabs( {
	manifest,
	active,
	onChange,
}: {
	manifest: Manifest;
	active: string | undefined;
	onChange: ( name: string ) => void;
} ) {
	if ( manifest.views.length <= 1 ) {
		return null;
	}
	return (
		<div className="nvoos-toolkit-shell-tabs" role="tablist">
			{ manifest.views.map( ( v ) => (
				<button
					key={ v.name }
					type="button"
					role="tab"
					aria-selected={ v.name === active }
					className={
						v.name === active
							? 'nvoos-toolkit-shell-tab is-active'
							: 'nvoos-toolkit-shell-tab'
					}
					onClick={ () => onChange( v.name ) }
				>
					{ v.label || v.name }
				</button>
			) ) }
		</div>
	);
}

interface ViewSurfaceProps {
	manifest: Manifest;
	viewName: string;
	mode: Mode;
	setMode: ( mode: Mode ) => void;
}

function ViewSurface( { manifest, viewName, mode, setMode }: ViewSurfaceProps ) {
	const view = manifest.views.find( ( v ) => v.name === viewName );
	const resource = useMemo(
		() =>
			view
				? manifest.resources.find( ( r ) => r.name === view.resource )
				: undefined,
		[ manifest, view ]
	);

	const [ list, setList ] = useState<ListResult>( { items: [] } );
	const [ listLoading, setListLoading ] = useState( false );
	const [ listError, setListError ] = useState<string | null>( null );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );

	const [ detail, setDetail ] = useState<Record<string, unknown> | null>( null );
	const [ detailLoading, setDetailLoading ] = useState( false );
	const [ detailError, setDetailError ] = useState<string | null>( null );

	const [ formSaving, setFormSaving ] = useState( false );
	const [ formError, setFormError ] = useState<string | null>( null );

	const reloadList = useCallback( () => {
		if ( ! resource ) {
			return;
		}
		setListLoading( true );
		setListError( null );
		listResource( manifest.rest_namespace, resource, {
			page,
			per_page: PER_PAGE,
			search: search || undefined,
		} )
			.then( ( result ) => setList( result ) )
			.catch( ( err: Error ) => {
				setList( { items: [] } );
				setListError( err.message );
			} )
			.finally( () => setListLoading( false ) );
	}, [ manifest.rest_namespace, resource, page, search ] );

	useEffect( () => {
		if ( mode.kind === 'list' && resource ) {
			reloadList();
		}
	}, [ mode.kind, reloadList, resource ] );

	useEffect( () => {
		if ( ( mode.kind === 'detail' || mode.kind === 'edit' ) && resource ) {
			setDetailLoading( true );
			setDetailError( null );
			getResource( manifest.rest_namespace, resource, mode.id )
				.then( ( row ) => setDetail( row ) )
				.catch( ( err: Error ) => {
					setDetail( null );
					setDetailError( err.message );
				} )
				.finally( () => setDetailLoading( false ) );
		}
	}, [ mode, manifest.rest_namespace, resource ] );

	if ( ! view ) {
		return <p>This toolkit has no view named &ldquo;{ viewName }&rdquo;.</p>;
	}
	if ( ! resource ) {
		return (
			<p>View references unknown resource: <code>{ view.resource }</code>.</p>
		);
	}

	if ( mode.kind === 'create' ) {
		return (
			<FormView
				resource={ resource }
				row={ null }
				mode="create"
				saving={ formSaving }
				error={ formError }
				onCancel={ () => setMode( { kind: 'list' } ) }
				onSubmit={ ( values ) => {
					setFormSaving( true );
					setFormError( null );
					createResource( manifest.rest_namespace, resource, values )
						.then( () => setMode( { kind: 'list' } ) )
						.catch( ( err: Error ) => setFormError( err.message ) )
						.finally( () => setFormSaving( false ) );
				} }
			/>
		);
	}

	if ( mode.kind === 'edit' ) {
		return (
			<FormView
				resource={ resource }
				row={ detail }
				mode="edit"
				saving={ formSaving }
				error={ formError ?? detailError }
				onCancel={ () => setMode( { kind: 'detail', id: mode.id } ) }
				onSubmit={ ( values ) => {
					setFormSaving( true );
					setFormError( null );
					updateResource( manifest.rest_namespace, resource, mode.id, values )
						.then( () => setMode( { kind: 'detail', id: mode.id } ) )
						.catch( ( err: Error ) => setFormError( err.message ) )
						.finally( () => setFormSaving( false ) );
				} }
			/>
		);
	}

	if ( mode.kind === 'detail' ) {
		return (
			<DetailView
				resource={ resource }
				row={ detail }
				loading={ detailLoading }
				error={ detailError }
				onClose={ () => setMode( { kind: 'list' } ) }
				onEdit={ () => setMode( { kind: 'edit', id: mode.id } ) }
			/>
		);
	}

	// Default: list mode.
	return (
		<>
			<div className="nvoos-toolkit-shell-toolbar">
				<input
					type="search"
					placeholder={ __( 'Search…', 'nvoos-toolkit-shell' ) }
					value={ search }
					onChange={ ( e ) => {
						setPage( 1 );
						setSearch( e.target.value );
					} }
					aria-label={ __( 'Search', 'nvoos-toolkit-shell' ) }
				/>
				<button type="button" onClick={ () => setMode( { kind: 'create' } ) }>
					{ __( '+ New', 'nvoos-toolkit-shell' ) }
				</button>
			</div>
			{ listError && (
				<p className="nvoos-toolkit-shell-error">{ __( 'Error:', 'nvoos-toolkit-shell' ) } { listError }</p>
			) }
			{ listLoading && <p>{ __( 'Loading…', 'nvoos-toolkit-shell' ) }</p> }
			{ ! listLoading && view.type === 'kanban' && (
				<KanbanView
					resource={ resource }
					view={ view }
					rows={ list.items }
					onRowClick={ ( id ) => setMode( { kind: 'detail', id } ) }
				/>
			) }
			{ ! listLoading && view.type !== 'kanban' && (
				<TableView
					resource={ resource }
					rows={ list.items }
					onRowClick={ ( id ) => setMode( { kind: 'detail', id } ) }
					onDelete={ ( id ) => {
						if ( ! confirm( __( 'Delete this record?', 'nvoos-toolkit-shell' ) ) ) {
							return;
						}
						deleteResource( manifest.rest_namespace, resource, id )
							.then( () => reloadList() )
							.catch( ( err: Error ) => setListError( err.message ) );
					} }
				/>
			) }
			{ ( list.totalPages ?? 0 ) > 1 && (
				<div className="nvoos-toolkit-shell-pagination">
					<button
						type="button"
						disabled={ page <= 1 }
						onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
					>
						{ __( 'Previous', 'nvoos-toolkit-shell' ) }
					</button>
					<span>
						{ list.totalPages
							? sprintf(
								__( 'Page %1$d of %2$d', 'nvoos-toolkit-shell' ),
								page,
								list.totalPages
							)
							: sprintf( __( 'Page %d', 'nvoos-toolkit-shell' ), page ) }
					</span>
					<button
						type="button"
						disabled={
							list.totalPages !== undefined && page >= list.totalPages
						}
						onClick={ () => setPage( ( p ) => p + 1 ) }
					>
						{ __( 'Next', 'nvoos-toolkit-shell' ) }
					</button>
				</div>
			) }
		</>
	);
}

function pickDefaultViewName( manifest: Manifest ): string | undefined {
	const def = manifest.views.find( ( v: View ) => v.default );
	return ( def ?? manifest.views[ 0 ] )?.name;
}

function headerLabel( state: ManifestState ): string {
	if ( state.kind === 'ready' ) {
		return state.manifest.label || state.manifest.toolkit;
	}
	return __( 'NV oOS Toolkit', 'nvoos-toolkit-shell' );
}

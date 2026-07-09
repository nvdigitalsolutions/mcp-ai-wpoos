/**
 * Pro SPA v2 — Tasks / Jobs drawer.
 *
 * Right-side overlay panel listing all active/completed/failed jobs.
 * Mirrors chat-spa's TasksDrawer with pro namespace.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX, useCallback, useEffect, useMemo, useState } from 'react';
import type { JobRecord, JobStatus } from './JobCard';

export interface TasksDrawerProps {
	jobs: Record< string, JobRecord >;
	runningCount: number;
	onCancelJob: ( id: string ) => Promise< void >;
	onRetryJob: ( id: string ) => Promise< void >;
	onDismissJob: ( id: string ) => void;
	onDismissAll: () => void;
}

type FilterTab = 'all' | 'active' | 'completed' | 'failed';
const TABS: { key: FilterTab; label: string }[] = [ { key: 'all', label: 'All' }, { key: 'active', label: 'Active' }, { key: 'completed', label: 'Completed' }, { key: 'failed', label: 'Failed' } ];

function matches( j: JobRecord, f: FilterTab ): boolean {
	switch ( f ) { case 'active': return j.status === 'queued' || j.status === 'running' || j.status === 'polling'; case 'completed': return j.status === 'completed'; case 'failed': return j.status === 'failed'; default: return true; }
}

function elapsed( startedAt: number ): string {
	const s = Math.floor( ( Date.now() - startedAt ) / 1000 );
	if ( s < 60 ) return `${ s }s`; const m = Math.floor( s / 60 ); if ( m < 60 ) return `${ m }m`; return `${ Math.floor( m / 60 ) }h ${ m % 60 }m`;
}

export function TasksDrawer( { jobs, runningCount, onCancelJob, onRetryJob, onDismissJob, onDismissAll }: TasksDrawerProps ): JSX.Element | null {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ filter, setFilter ] = useState< FilterTab >( 'all' );
	const [ cancelling, setCancelling ] = useState< Set< string > >( new Set() );
	const [ retrying, setRetrying ] = useState< Set< string > >( new Set() );

	const list = useMemo( () => Object.values( jobs ).filter( ( j ) => matches( j, filter ) ), [ jobs, filter ] );
	const failedCount = Object.values( jobs ).filter( ( j ) => j.status === 'failed' ).length;
	const totalJobs = Object.keys( jobs ).length;

	const doCancel = useCallback( async ( id: string ) => { setCancelling( ( s ) => new Set( s ).add( id ) ); try { await onCancelJob( id ); } catch {} setCancelling( ( s ) => { const n = new Set( s ); n.delete( id ); return n; } ); }, [ onCancelJob ] );
	const doRetry = useCallback( async ( id: string ) => { setRetrying( ( s ) => new Set( s ).add( id ) ); try { await onRetryJob( id ); } catch {} setRetrying( ( s ) => { const n = new Set( s ); n.delete( id ); return n; } ); }, [ onRetryJob ] );

	useEffect( () => { const h = ( e: KeyboardEvent ) => { if ( e.key === 'Escape' && isOpen ) setIsOpen( false ); }; document.addEventListener( 'keydown', h ); return () => document.removeEventListener( 'keydown', h ); }, [ isOpen ] );

	if ( totalJobs === 0 && ! isOpen ) return null;

	const iconMap: Record< JobStatus, string > = { queued: '⏳', running: '⟳', polling: '⟳', completed: '✓', failed: '✗', cancelled: '⊘' };

	return (
		<>
			<button type="button" className="nvoos-pro-spa-tasks-toggle nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
				aria-label={ sprintf( __( '%d active tasks', 'nvoos-pro-spa' ), runningCount ) }
				onClick={ () => setIsOpen( ( o ) => ! o ) }>
				⚙{ runningCount > 0 && <span className="nvoos-pro-spa-tasks-badge">{ runningCount }</span> }
				{ failedCount > 0 && runningCount === 0 && <span className="nvoos-pro-spa-tasks-badge nvoos-pro-spa-tasks-badge--error">!</span> }
			</button>
			{ isOpen && ( // eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<div className="nvoos-pro-spa-tasks-overlay" onClick={ () => setIsOpen( false ) } /> ) }
			<aside className={ `nvoos-pro-spa-tasks-drawer${ isOpen ? ' nvoos-pro-spa-tasks-drawer--open' : '' }` } aria-hidden={ ! isOpen }>
				<div className="nvoos-pro-spa-tasks-drawer-header">
					<h2 className="nvoos-pro-spa-tasks-drawer-title">{ __( 'Tasks', 'nvoos-pro-spa' ) }{ totalJobs > 0 && <span className="nvoos-pro-spa-tasks-drawer-count">{ totalJobs }</span> }</h2>
					<button type="button" className="nvoos-pro-spa-tasks-drawer-close" aria-label={ __( 'Close', 'nvoos-pro-spa' ) } onClick={ () => setIsOpen( false ) }>×</button>
				</div>
				<div className="nvoos-pro-spa-tasks-drawer-tabs" role="tablist">
					{ TABS.map( ( t ) => { const c = Object.values( jobs ).filter( ( j ) => matches( j, t.key ) ).length; return (
						<button key={ t.key } type="button" role="tab" aria-selected={ filter === t.key }
							className={ `nvoos-pro-spa-tasks-drawer-tab${ filter === t.key ? ' nvoos-pro-spa-tasks-drawer-tab--active' : '' }` }
							onClick={ () => setFilter( t.key ) }>{ __( t.label, 'nvoos-pro-spa' ) }{ c > 0 && <span className="nvoos-pro-spa-tasks-drawer-tab-count">{ c }</span> }</button>
					); } ) }
				</div>
				{ list.length > 0 && <div className="nvoos-pro-spa-tasks-drawer-batch"><button type="button" className="nvoos-pro-spa-tasks-drawer-batch-btn" onClick={ onDismissAll }>{ __( 'Dismiss all', 'nvoos-pro-spa' ) }</button></div> }
				<div className="nvoos-pro-spa-tasks-drawer-list">
					{ list.length === 0 && <p className="nvoos-pro-spa-tasks-drawer-empty">{ __( 'No tasks.', 'nvoos-pro-spa' ) }</p> }
					{ list.map( ( j ) => (
						<div key={ j.jobId } className={ `nvoos-pro-spa-tasks-drawer-item nvoos-pro-spa-tasks-drawer-item--${ j.status }` }>
							<span className="nvoos-pro-spa-tasks-drawer-item-icon" aria-hidden="true">{ iconMap[ j.status ] ?? '○' }</span>
							<div className="nvoos-pro-spa-tasks-drawer-item-body">
								<span className="nvoos-pro-spa-tasks-drawer-item-name">{ j.toolName || j.jobId }</span>
								<span className="nvoos-pro-spa-tasks-drawer-item-meta">{ j.startedAt ? elapsed( j.startedAt ) : '' }{ j.message ? ` · ${ j.message }` : '' }</span>
								{ typeof j.progress === 'number' && j.status !== 'completed' && j.status !== 'failed' && j.status !== 'cancelled' && <div className="nvoos-pro-spa-tasks-drawer-item-progress"><div className="nvoos-pro-spa-tasks-drawer-item-progress-fill" style={ { width: `${ Math.round( j.progress ) }%` } } /></div> }
							</div>
							<div className="nvoos-pro-spa-tasks-drawer-item-actions">
								{ j.status !== 'completed' && j.status !== 'failed' && j.status !== 'cancelled' && <button type="button" className="nvoos-pro-spa-tasks-drawer-item-btn" disabled={ cancelling.has( j.jobId ) } onClick={ () => doCancel( j.jobId ) }>{ __( 'Cancel', 'nvoos-pro-spa' ) }</button> }
								{ j.status === 'failed' && <button type="button" className="nvoos-pro-spa-tasks-drawer-item-btn nvoos-pro-spa-tasks-drawer-item-btn--retry" disabled={ retrying.has( j.jobId ) } onClick={ () => doRetry( j.jobId ) }>{ __( 'Retry', 'nvoos-pro-spa' ) }</button> }
								{ ( j.status === 'completed' || j.status === 'failed' || j.status === 'cancelled' ) && <button type="button" className="nvoos-pro-spa-tasks-drawer-item-btn-dismiss" onClick={ () => onDismissJob( j.jobId ) }>×</button> }
							</div>
						</div>
					) ) }
				</div>
			</aside>
		</>
	);
}

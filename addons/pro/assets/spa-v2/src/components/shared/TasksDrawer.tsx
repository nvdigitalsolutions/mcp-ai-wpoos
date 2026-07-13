/**
 * Pro SPA v2 — Tasks / Jobs drawer.
 *
 * Right-side overlay panel listing all active/completed/failed jobs.
 * Mirrors chat-spa's TasksDrawer with pro namespace.
 *
 * The toggle button lives in the ChatPage toolbar; this component only
 * renders the drawer panel itself, driven by `isOpen` / `onClose` /
 * `toggleRef` props — matching the Memory, ToolShortcuts, and
 * SlashCommands drawer pattern.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, type RefObject, useCallback, useEffect, useMemo, useState } from 'react';
import type { JobRecord, JobStatus } from './JobCard';

export interface TasksDrawerProps {
	jobs: Record< string, JobRecord >;
	runningCount: number;
	/** Whether the drawer panel is visible (driven by parent). */
	isOpen: boolean;
	/** Called when the drawer should close (Escape key, overlay click, × button). */
	onClose: () => void;
	/** Ref to the toolbar toggle button for focus-return on close. */
	toggleRef?: RefObject< HTMLButtonElement | null >;
	onCancelJob: ( id: string ) => Promise< void >;
	onRetryJob: ( id: string ) => Promise< void >;
	onDismissJob: ( id: string ) => void;
	onDismissAll: () => void;
}

type FilterTab = 'all' | 'active' | 'completed' | 'failed';
const TABS: { key: FilterTab; label: string }[] = [
	{ key: 'all', label: 'All' },
	{ key: 'active', label: 'Active' },
	{ key: 'completed', label: 'Completed' },
	{ key: 'failed', label: 'Failed' },
];

function matches( j: JobRecord, f: FilterTab ): boolean {
	switch ( f ) {
		case 'active':
			return j.status === 'queued' || j.status === 'running' || j.status === 'polling';
		case 'completed':
			return j.status === 'completed';
		case 'failed':
			return j.status === 'failed';
		default:
			return true;
	}
}

function elapsed( startedAt: number ): string {
	const s = Math.floor( ( Date.now() - startedAt ) / 1000 );
	if ( s < 60 ) return `${ s }s`;
	const m = Math.floor( s / 60 );
	if ( m < 60 ) return `${ m }m`;
	return `${ Math.floor( m / 60 ) }h ${ m % 60 }m`;
}

export function TasksDrawer( {
	jobs,
	runningCount: _runningCount,
	isOpen,
	onClose,
	toggleRef,
	onCancelJob,
	onRetryJob,
	onDismissJob,
	onDismissAll,
}: TasksDrawerProps ): JSX.Element | null {
	const [ filter, setFilter ] = useState< FilterTab >( 'all' );
	const [ cancelling, setCancelling ] = useState< Set< string > >( new Set() );
	const [ retrying, setRetrying ] = useState< Set< string > >( new Set() );

	const list = useMemo(
		() => Object.values( jobs ).filter( ( j ) => matches( j, filter ) ),
		[ jobs, filter ]
	);
	const totalJobs = Object.keys( jobs ).length;

	const doCancel = useCallback(
		async ( id: string ) => {
			setCancelling( ( s ) => new Set( s ).add( id ) );
			try { await onCancelJob( id ); } catch { /* ignore */ }
			setCancelling( ( s ) => {
				const n = new Set( s );
				n.delete( id );
				return n;
			} );
		},
		[ onCancelJob ]
	);
	const doRetry = useCallback(
		async ( id: string ) => {
			setRetrying( ( s ) => new Set( s ).add( id ) );
			try { await onRetryJob( id ); } catch { /* ignore */ }
			setRetrying( ( s ) => {
				const n = new Set( s );
				n.delete( id );
				return n;
			} );
		},
		[ onRetryJob ]
	);

	// Focus return: when the drawer closes, focus the toolbar toggle button.
	useEffect( () => {
		if ( ! isOpen && toggleRef?.current ) {
			toggleRef.current.focus();
		}
	}, [ isOpen, toggleRef ] );

	// Esc to close.
	useEffect( () => {
		if ( ! isOpen ) return;
		const h = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) onClose();
		};
		document.addEventListener( 'keydown', h );
		return () => document.removeEventListener( 'keydown', h );
	}, [ isOpen, onClose ] );

	const iconMap: Record< JobStatus, string > = {
		queued: '⏳',
		running: '⟳',
		polling: '⟳',
		completed: '✓',
		failed: '✗',
		cancelled: '⊘',
	};

	return (
		<>
			{ /* Overlay */ }
			{ isOpen && (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<div
					className="nvoos-pro-spa-tasks-overlay"
					onClick={ onClose }
				/>
			) }

			{ /* Drawer panel */ }
			<aside
				className={ `nvoos-pro-spa-tasks-drawer${
					isOpen ? ' nvoos-pro-spa-tasks-drawer--open' : ''
				}` }
				aria-label={ __( 'Tasks', 'nvoos-pro-spa' ) }
				aria-hidden={ ! isOpen }
			>
				<div className="nvoos-pro-spa-tasks-drawer-header">
					<h2 className="nvoos-pro-spa-tasks-drawer-title">
						{ __( 'Tasks', 'nvoos-pro-spa' ) }
						{ totalJobs > 0 && (
							<span className="nvoos-pro-spa-tasks-drawer-count">
								{ totalJobs }
							</span>
						) }
					</h2>
					<button
						type="button"
						className="nvoos-pro-spa-tasks-drawer-close"
						aria-label={ __( 'Close', 'nvoos-pro-spa' ) }
						onClick={ onClose }
					>
						×
					</button>
				</div>

				{ /* Filter tabs */ }
				<div className="nvoos-pro-spa-tasks-drawer-tabs" role="tablist">
					{ TABS.map( ( t ) => {
						const c = Object.values( jobs ).filter( ( j ) =>
							matches( j, t.key )
						).length;
						return (
							<button
								key={ t.key }
								type="button"
								role="tab"
								aria-selected={ filter === t.key }
								className={ `nvoos-pro-spa-tasks-drawer-tab${
									filter === t.key
										? ' nvoos-pro-spa-tasks-drawer-tab--active'
										: ''
								}` }
								onClick={ () => setFilter( t.key ) }
							>
								{ __( t.label, 'nvoos-pro-spa' ) }
								{ c > 0 && (
									<span className="nvoos-pro-spa-tasks-drawer-tab-count">
										{ c }
									</span>
								) }
							</button>
						);
					} ) }
				</div>

				{ /* Batch bar */ }
				{ list.length > 0 && (
					<div className="nvoos-pro-spa-tasks-drawer-batch">
						<button
							type="button"
							className="nvoos-pro-spa-tasks-drawer-batch-btn"
							onClick={ onDismissAll }
						>
							{ __( 'Dismiss all', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) }

				{ /* Job list */ }
				<div className="nvoos-pro-spa-tasks-drawer-list">
					{ list.length === 0 && (
						<p className="nvoos-pro-spa-tasks-drawer-empty">
							{ __( 'No tasks.', 'nvoos-pro-spa' ) }
						</p>
					) }
					{ list.map( ( j ) => {
						const isTerminal =
							j.status === 'completed' ||
							j.status === 'failed' ||
							j.status === 'cancelled';
						return (
							<div
								key={ j.jobId }
								className={ `nvoos-pro-spa-tasks-drawer-item nvoos-pro-spa-tasks-drawer-item--${ j.status }` }
							>
								<span
									className="nvoos-pro-spa-tasks-drawer-item-icon"
									aria-hidden="true"
								>
									{ iconMap[ j.status ] ?? '○' }
								</span>
								<div className="nvoos-pro-spa-tasks-drawer-item-body">
									<span className="nvoos-pro-spa-tasks-drawer-item-name">
										{ j.toolName || j.jobId }
									</span>
									<span className="nvoos-pro-spa-tasks-drawer-item-meta">
										{ j.startedAt ? elapsed( j.startedAt ) : '' }
										{ j.message ? ` · ${ j.message }` : '' }
									</span>
									{ typeof j.progress === 'number' &&
										! isTerminal && (
											<div className="nvoos-pro-spa-tasks-drawer-item-progress">
												<div
													className="nvoos-pro-spa-tasks-drawer-item-progress-fill"
													style={ {
														width: `${ Math.round( j.progress ) }%`,
													} }
												/>
											</div>
										) }
								</div>
								<div className="nvoos-pro-spa-tasks-drawer-item-actions">
									{ ! isTerminal && (
										<button
											type="button"
											className="nvoos-pro-spa-tasks-drawer-item-btn"
											disabled={ cancelling.has( j.jobId ) }
											onClick={ () => doCancel( j.jobId ) }
										>
											{ __( 'Cancel', 'nvoos-pro-spa' ) }
										</button>
									) }
									{ j.status === 'failed' && (
										<button
											type="button"
											className="nvoos-pro-spa-tasks-drawer-item-btn nvoos-pro-spa-tasks-drawer-item-btn--retry"
											disabled={ retrying.has( j.jobId ) }
											onClick={ () => doRetry( j.jobId ) }
										>
											{ __( 'Retry', 'nvoos-pro-spa' ) }
										</button>
									) }
									{ isTerminal && (
										<button
											type="button"
											className="nvoos-pro-spa-tasks-drawer-item-btn-dismiss"
											onClick={ () => onDismissJob( j.jobId ) }
										>
											×
										</button>
									) }
								</div>
							</div>
						);
					} ) }
				</div>
			</aside>
		</>
	);
}

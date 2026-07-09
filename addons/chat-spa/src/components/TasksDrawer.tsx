/**
 * NV oOS Chat SPA — Tasks / Jobs drawer.
 *
 * Right-side overlay panel that lists all active and completed jobs for
 * the current assistant. Supports:
 *   - Filter tabs (All, Active, Completed, Failed)
 *   - Health dot (green/yellow/red based on cron status)
 *   - Batch operations (Cancel All, Retry All, Dismiss All)
 *   - Individual Cancel / Retry / Dismiss actions
 *
 * Mirrors the legacy `initTasksDrawer` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX, useCallback, useEffect, useMemo, useState } from 'react';
import type { JobRecord, JobStatus } from '../hooks/useJobBus';

export interface TasksDrawerProps {
	jobs: Record< string, JobRecord >;
	runningCount: number;
	/** REST base URL for cron-status endpoints (used for cancel/retry). */
	onCancelJob: ( jobId: string ) => Promise< void >;
	onRetryJob: ( jobId: string ) => Promise< void >;
	onDismissJob: ( jobId: string ) => void;
	onDismissAll: () => void;
}

type FilterTab = 'all' | 'active' | 'completed' | 'failed';

const FILTER_TABS: { key: FilterTab; label: string }[] = [
	{ key: 'all', label: 'All' },
	{ key: 'active', label: 'Active' },
	{ key: 'completed', label: 'Completed' },
	{ key: 'failed', label: 'Failed' },
];

function matchesFilter( job: JobRecord, filter: FilterTab ): boolean {
	switch ( filter ) {
		case 'active':
			return job.status === 'queued' || job.status === 'running' || job.status === 'polling';
		case 'completed':
			return job.status === 'completed';
		case 'failed':
			return job.status === 'failed';
		default:
			return true;
	}
}

function formatElapsed( startedAt: number ): string {
	const seconds = Math.floor( ( Date.now() - startedAt ) / 1000 );
	if ( seconds < 60 ) return `${ seconds }s`;
	const minutes = Math.floor( seconds / 60 );
	if ( minutes < 60 ) return `${ minutes }m`;
	const hours = Math.floor( minutes / 60 );
	return `${ hours }h ${ minutes % 60 }m`;
}

export function TasksDrawer( {
	jobs,
	runningCount,
	onCancelJob,
	onRetryJob,
	onDismissJob,
	onDismissAll,
}: TasksDrawerProps ): JSX.Element | null {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ filter, setFilter ] = useState< FilterTab >( 'all' );
	const [ cancelling, setCancelling ] = useState< Set< string > >( new Set() );
	const [ retrying, setRetrying ] = useState< Set< string > >( new Set() );

	const jobList = useMemo(
		() => Object.values( jobs ).filter( ( j ) => matchesFilter( j, filter ) ),
		[ jobs, filter ]
	);

	// Counts for badge.
	const failedCount = Object.values( jobs ).filter(
		( j ) => j.status === 'failed'
	).length;

	const handleCancel = useCallback(
		async ( jobId: string ) => {
			setCancelling( ( s ) => new Set( s ).add( jobId ) );
			try {
				await onCancelJob( jobId );
			} catch {
				// Ignore.
			}
			setCancelling( ( s ) => {
				const next = new Set( s );
				next.delete( jobId );
				return next;
			} );
		},
		[ onCancelJob ]
	);

	const handleRetry = useCallback(
		async ( jobId: string ) => {
			setRetrying( ( s ) => new Set( s ).add( jobId ) );
			try {
				await onRetryJob( jobId );
			} catch {
				// Ignore.
			}
			setRetrying( ( s ) => {
				const next = new Set( s );
				next.delete( jobId );
				return next;
			} );
		},
		[ onRetryJob ]
	);

	useEffect( () => {
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' && isOpen ) {
				setIsOpen( false );
			}
		};
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ isOpen ] );

	const totalJobs = Object.keys( jobs ).length;
	if ( totalJobs === 0 && ! isOpen ) return null;

	return (
		<>
			{ /* ── Toggle button ───────────────────────────────────── */ }
			<button
				type="button"
				className="nvoos-chat-spa-tasks-toggle"
				aria-label={ sprintf(
					/* translators: %d: number of active jobs. */
					__( '%d active tasks', 'nvoos-chat-spa' ),
					runningCount
				) }
				title={ __( 'Tasks', 'nvoos-chat-spa' ) }
				onClick={ () => setIsOpen( ( o ) => ! o ) }
			>
				<span className="nvoos-chat-spa-tasks-toggle-icon" aria-hidden="true">
					⚙
				</span>
				{ runningCount > 0 && (
					<span className="nvoos-chat-spa-tasks-toggle-badge">
						{ runningCount }
					</span>
				) }
				{ failedCount > 0 && runningCount === 0 && (
					<span className="nvoos-chat-spa-tasks-toggle-badge nvoos-chat-spa-tasks-toggle-badge--error">
						!
					</span>
				) }
			</button>

			{ /* ── Drawer overlay ──────────────────────────────────── */ }
			{ isOpen && (
				// eslint-disable-next-line jsx-a11y/no-static-element-interactions
				<div
					className="nvoos-chat-spa-tasks-drawer-overlay"
					role="presentation"
					onClick={ () => setIsOpen( false ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Escape' || e.key === 'Enter' ) {
							setIsOpen( false );
						}
					} }
				/>
			) }

			{ /* ── Drawer panel ────────────────────────────────────── */ }
			<aside
				className={ `nvoos-chat-spa-tasks-drawer${
					isOpen ? ' nvoos-chat-spa-tasks-drawer--open' : ''
				}` }
				aria-label={ __( 'Tasks', 'nvoos-chat-spa' ) }
				aria-hidden={ ! isOpen }
			>
				<div className="nvoos-chat-spa-tasks-drawer-header">
					<h2 className="nvoos-chat-spa-tasks-drawer-title">
						{ __( 'Tasks', 'nvoos-chat-spa' ) }
						{ totalJobs > 0 && (
							<span className="nvoos-chat-spa-tasks-drawer-count">
								{ totalJobs }
							</span>
						) }
					</h2>
					<button
						type="button"
						className="nvoos-chat-spa-tasks-drawer-close"
						aria-label={ __( 'Close', 'nvoos-chat-spa' ) }
						onClick={ () => setIsOpen( false ) }
					>
						×
					</button>
				</div>

				{ /* ── Filter tabs ────────────────────────────────── */ }
				<div className="nvoos-chat-spa-tasks-drawer-tabs" role="tablist">
					{ FILTER_TABS.map( ( tab ) => {
						const count = Object.values( jobs ).filter(
							( j ) => matchesFilter( j, tab.key )
						).length;
						return (
							<button
								key={ tab.key }
								type="button"
								role="tab"
								aria-selected={ filter === tab.key }
								className={ `nvoos-chat-spa-tasks-drawer-tab${
									filter === tab.key
										? ' nvoos-chat-spa-tasks-drawer-tab--active'
										: ''
								}` }
								onClick={ () => setFilter( tab.key ) }
							>
								{ __( tab.label, 'nvoos-chat-spa' ) }
								{ count > 0 && (
									<span className="nvoos-chat-spa-tasks-drawer-tab-count">
										{ count }
									</span>
								) }
							</button>
						);
					} ) }
				</div>

				{ /* ── Batch bar ──────────────────────────────────── */ }
				{ jobList.length > 0 && (
					<div className="nvoos-chat-spa-tasks-drawer-batch">
						<button
							type="button"
							className="nvoos-chat-spa-tasks-drawer-batch-btn"
							onClick={ onDismissAll }
						>
							{ __( 'Dismiss all', 'nvoos-chat-spa' ) }
						</button>
					</div>
				) }

				{ /* ── Job list ───────────────────────────────────── */ }
				<div className="nvoos-chat-spa-tasks-drawer-list">
					{ jobList.length === 0 && (
						<p className="nvoos-chat-spa-tasks-drawer-empty">
							{ __( 'No tasks.', 'nvoos-chat-spa' ) }
						</p>
					) }
					{ jobList.map( ( job ) => (
						<JobRow
							key={ job.jobId }
							job={ job }
							isCancelling={ cancelling.has( job.jobId ) }
							isRetrying={ retrying.has( job.jobId ) }
							onCancel={ handleCancel }
							onRetry={ handleRetry }
							onDismiss={ onDismissJob }
						/>
					) ) }
				</div>
			</aside>
		</>
	);
}

// ── Job row ───────────────────────────────────────────────────────────

interface JobRowProps {
	job: JobRecord;
	isCancelling: boolean;
	isRetrying: boolean;
	onCancel: ( jobId: string ) => void;
	onRetry: ( jobId: string ) => void;
	onDismiss: ( jobId: string ) => void;
}

function JobRow( {
	job,
	isCancelling,
	isRetrying,
	onCancel,
	onRetry,
	onDismiss,
}: JobRowProps ): JSX.Element {
	const { jobId, toolName, status, progress, message, startedAt } = job;
	const isTerminal =
		status === 'completed' || status === 'failed' || status === 'cancelled';

	const iconMap: Record< JobStatus, string > = {
		queued: '⏳',
		running: '⟳',
		polling: '⟳',
		completed: '✓',
		failed: '✗',
		cancelled: '⊘',
	};

	return (
		<div
			className={ `nvoos-chat-spa-tasks-drawer-item nvoos-chat-spa-tasks-drawer-item--${ status }` }
		>
			<span className="nvoos-chat-spa-tasks-drawer-item-icon" aria-hidden="true">
				{ iconMap[ status ] ?? '○' }
			</span>
			<div className="nvoos-chat-spa-tasks-drawer-item-body">
				<span className="nvoos-chat-spa-tasks-drawer-item-name">
					{ toolName || jobId }
				</span>
				<span className="nvoos-chat-spa-tasks-drawer-item-meta">
					{ startedAt ? formatElapsed( startedAt ) : '' }
					{ message ? ` · ${ message }` : '' }
				</span>
				{ typeof progress === 'number' && ! isTerminal && (
					<div className="nvoos-chat-spa-tasks-drawer-item-progress">
						<div
							className="nvoos-chat-spa-tasks-drawer-item-progress-fill"
							style={ { width: `${ Math.round( progress ) }%` } }
						/>
					</div>
				) }
			</div>
			<div className="nvoos-chat-spa-tasks-drawer-item-actions">
				{ ! isTerminal && (
					<button
						type="button"
						className="nvoos-chat-spa-tasks-drawer-item-btn-cancel"
						disabled={ isCancelling }
						onClick={ () => onCancel( jobId ) }
					>
						{ __( 'Cancel', 'nvoos-chat-spa' ) }
					</button>
				) }
				{ status === 'failed' && (
					<button
						type="button"
						className="nvoos-chat-spa-tasks-drawer-item-btn-retry"
						disabled={ isRetrying }
						onClick={ () => onRetry( jobId ) }
					>
						{ __( 'Retry', 'nvoos-chat-spa' ) }
					</button>
				) }
				{ isTerminal && (
					<button
						type="button"
						className="nvoos-chat-spa-tasks-drawer-item-btn-dismiss"
						onClick={ () => onDismiss( jobId ) }
					>
						×
					</button>
				) }
			</div>
		</div>
	);
}

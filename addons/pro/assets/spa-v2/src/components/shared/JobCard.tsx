/**
 * Pro SPA v2 — Job card component.
 *
 * Inline progress card for async tool invocations.
 *
 * @package NV_oOS_Pro_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useCallback, useState } from 'react';

export interface JobStep {
	id?: string; label: string;
	status: 'pending' | 'running' | 'completed' | 'failed';
}

export type JobStatus = 'queued' | 'running' | 'polling' | 'completed' | 'failed' | 'cancelled';

export interface JobRecord {
	jobId: string; toolName: string; status: JobStatus;
	progress?: number; eta?: string; message?: string;
	steps: JobStep[]; result?: unknown; error?: string; startedAt: number;
}

export interface JobCardProps {
	job: JobRecord;
	onCancel?: ( jobId: string ) => void;
	onRetry?: ( jobId: string ) => void;
}

const MAX_VISIBLE_STEPS = 5;

export function JobCard( { job, onCancel, onRetry }: JobCardProps ): JSX.Element {
	const [ showAll, setShowAll ] = useState( false );
	const [ cancelling, setCancelling ] = useState( false );
	const [ retrying, setRetrying ] = useState( false );

	const { jobId, toolName, status, progress, eta, message, steps, error } = job;
	const isTerminal = status === 'completed' || status === 'failed' || status === 'cancelled';
	const pct = typeof progress === 'number' && ! isTerminal ? Math.round( progress ) : 0;
	const visibleSteps = showAll ? steps : steps.slice( 0, MAX_VISIBLE_STEPS );

	let statusLabel = __( 'Processing…', 'nvoos-pro-spa' );
	if ( status === 'completed' ) statusLabel = __( 'Completed', 'nvoos-pro-spa' );
	else if ( status === 'failed' ) statusLabel = __( 'Failed', 'nvoos-pro-spa' );
	else if ( status === 'cancelled' ) statusLabel = __( 'Cancelled', 'nvoos-pro-spa' );

	const handleCancel = useCallback( () => { setCancelling( true ); onCancel?.( jobId ); }, [ jobId, onCancel ] );
	const handleRetry = useCallback( () => { setRetrying( true ); onRetry?.( jobId ); }, [ jobId, onRetry ] );

	return (
		<div className={ `nvoos-pro-spa-job-card nvoos-pro-spa-job-card--${ status }` } data-job-id={ jobId }>
			<div className="nvoos-pro-spa-job-card__header">
				<span className="nvoos-pro-spa-job-card__title">{ toolName || jobId }</span>
				<span className="nvoos-pro-spa-job-card__status">{ statusLabel }</span>
				{ ! isTerminal && (
					<button type="button" className="nvoos-pro-spa-job-card__cancel" disabled={ cancelling } onClick={ handleCancel }>
						{ cancelling ? __( 'Cancelling…', 'nvoos-pro-spa' ) : __( 'Cancel', 'nvoos-pro-spa' ) }
					</button>
				) }
			</div>
			{ typeof progress === 'number' && ! isTerminal && (
				<div className="nvoos-pro-spa-job-card__progress-row">
					<div className="nvoos-pro-spa-job-card__progress-bar" role="progressbar" aria-valuenow={ pct } aria-valuemin={ 0 } aria-valuemax={ 100 }>
						<div className="nvoos-pro-spa-job-card__progress-fill" style={ { width: `${ pct }%` } } />
					</div>
					<span className="nvoos-pro-spa-job-card__progress-pct">{ pct }%</span>
					{ eta && <span className="nvoos-pro-spa-job-card__eta">~{ eta }</span> }
				</div>
			) }
			{ ( message || error ) && <div className="nvoos-pro-spa-job-card__message">{ error || message }</div> }
			{ steps.length > 0 && (
				<ul className="nvoos-pro-spa-job-card__steps">
					{ visibleSteps.map( ( s, i ) => (
						<li key={ s.id ?? i } className={ `nvoos-pro-spa-job-card__step nvoos-pro-spa-job-card__step--${ s.status }` }>
							<span className="nvoos-pro-spa-job-card__step-icon" aria-hidden="true">
								{ s.status === 'completed' ? '✓' : s.status === 'running' ? '⟳' : s.status === 'failed' ? '✗' : '○' }
							</span>
							{ s.label }
						</li>
					) ) }
					{ ! showAll && steps.length > MAX_VISIBLE_STEPS && (
						<li><button type="button" className="nvoos-pro-spa-job-card__steps-more" onClick={ () => setShowAll( true ) }>+ { steps.length - MAX_VISIBLE_STEPS } more</button></li>
					) }
				</ul>
			) }
			{ status === 'failed' && (
				<button type="button" className="nvoos-pro-spa-job-card__retry" disabled={ retrying } onClick={ handleRetry }>
					{ retrying ? __( 'Retrying…', 'nvoos-pro-spa' ) : __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			) }
		</div>
	);
}

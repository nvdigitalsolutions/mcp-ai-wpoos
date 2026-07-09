/**
 * NV oOS Chat SPA — Inline job progress card.
 *
 * Renders inside a message bubble when an async tool invocation has a
 * `job_id`. Shows a progress bar, ETA, expandable step list, and
 * Cancel / Retry action buttons.
 *
 * Mirrors the legacy `createJobProgressCard` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useCallback, useState } from 'react';
import type { JobRecord, JobStep } from '../hooks/useJobBus';

export interface JobCardProps {
	job: JobRecord;
	onCancel?: ( jobId: string ) => void;
	onRetry?: ( jobId: string ) => void;
}

const MAX_VISIBLE_STEPS = 5;

function JobCardImpl( { job, onCancel, onRetry }: JobCardProps ): JSX.Element {
	const [ showAllSteps, setShowAllSteps ] = useState( false );
	const [ cancelling, setCancelling ] = useState( false );
	const [ retrying, setRetrying ] = useState( false );

	const { jobId, toolName, status, progress, eta, message, steps, error } = job;
	const isTerminal = status === 'completed' || status === 'failed' || status === 'cancelled';
	const hasProgress = typeof progress === 'number' && ! isTerminal;
	const pct = hasProgress ? Math.round( progress! ) : 0;

	const visibleSteps = showAllSteps ? steps : steps.slice( 0, MAX_VISIBLE_STEPS );
	const hiddenCount = steps.length - MAX_VISIBLE_STEPS;

	const handleCancel = useCallback( () => {
		setCancelling( true );
		onCancel?.( jobId );
	}, [ jobId, onCancel ] );

	const handleRetry = useCallback( () => {
		setRetrying( true );
		onRetry?.( jobId );
	}, [ jobId, onRetry ] );

	let statusLabel = __( 'Processing…', 'nvoos-chat-spa' );
	if ( status === 'completed' ) statusLabel = __( 'Completed', 'nvoos-chat-spa' );
	else if ( status === 'failed' ) statusLabel = __( 'Failed', 'nvoos-chat-spa' );
	else if ( status === 'cancelled' ) statusLabel = __( 'Cancelled', 'nvoos-chat-spa' );
	else if ( status === 'queued' ) statusLabel = __( 'Queued', 'nvoos-chat-spa' );

	return (
		<div
			className={ `nvoos-chat-spa-job-card nvoos-chat-spa-job-card--${ status }` }
			data-job-id={ jobId }
		>
			<div className="nvoos-chat-spa-job-card__header">
				<span className="nvoos-chat-spa-job-card__title">
					{ toolName || jobId }
				</span>
				<span className="nvoos-chat-spa-job-card__status">
					{ statusLabel }
				</span>
				{ ! isTerminal && (
					<button
						type="button"
						className="nvoos-chat-spa-job-card__cancel"
						disabled={ cancelling }
						onClick={ handleCancel }
					>
						{ cancelling
							? __( 'Cancelling…', 'nvoos-chat-spa' )
							: __( 'Cancel', 'nvoos-chat-spa' ) }
					</button>
				) }
			</div>

			{ hasProgress && (
				<div className="nvoos-chat-spa-job-card__progress-row">
					<div
						className="nvoos-chat-spa-job-card__progress-bar"
						role="progressbar"
						aria-valuenow={ pct }
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
					>
						<div
							className="nvoos-chat-spa-job-card__progress-fill"
							style={ { width: `${ pct }%` } }
						/>
					</div>
					<span className="nvoos-chat-spa-job-card__progress-pct">
						{ pct }%
					</span>
					{ eta && (
						<span className="nvoos-chat-spa-job-card__eta">
							~{ eta }
						</span>
					) }
				</div>
			) }

			{ ( message || error ) && (
				<div className="nvoos-chat-spa-job-card__message">
					{ error || message }
				</div>
			) }

			{ steps.length > 0 && (
				<ul className="nvoos-chat-spa-job-card__steps">
					{ visibleSteps.map( ( step, i ) => (
						<StepItem key={ step.id ?? i } step={ step } />
					) ) }
					{ ! showAllSteps && hiddenCount > 0 && (
						<li>
							<button
								type="button"
								className="nvoos-chat-spa-job-card__steps-more"
								onClick={ () => setShowAllSteps( true ) }
							>
								+ { hiddenCount } more
							</button>
						</li>
					) }
				</ul>
			) }

			{ status === 'failed' && (
				<button
					type="button"
					className="nvoos-chat-spa-job-card__retry"
					disabled={ retrying }
					onClick={ handleRetry }
				>
					{ retrying
						? __( 'Retrying…', 'nvoos-chat-spa' )
						: __( 'Retry', 'nvoos-chat-spa' ) }
				</button>
			) }
		</div>
	);
}

function StepItem( { step }: { step: JobStep } ): JSX.Element {
	return (
		<li
			className={ `nvoos-chat-spa-job-card__step nvoos-chat-spa-job-card__step--${ step.status }` }
		>
			<span className="nvoos-chat-spa-job-card__step-icon" aria-hidden="true">
				{ step.status === 'completed'
					? '✓'
					: step.status === 'running'
					? '⟳'
					: step.status === 'failed'
					? '✗'
					: '○' }
			</span>
			<span className="nvoos-chat-spa-job-card__step-label">
				{ step.label }
			</span>
		</li>
	);
}

export const JobCard = JobCardImpl;

/**
 * NV oOS Chat SPA — Workflow progress tracker.
 *
 * Inline component rendered in the message stream when an
 * execute_workflow / update_task_plan / get_task_plan tool result
 * contains steps. Shows a progress bar and numbered step list.
 *
 * Mirrors the legacy `updateWorkflowTracker` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX } from 'react';
import type { WorkflowState, WorkflowStep } from '../hooks/useAgentTeam';

export interface WorkflowTrackerProps {
	workflow: WorkflowState;
}

function isCompleted( status?: string ): boolean {
	const s = ( status ?? '' ).toLowerCase();
	return s === 'completed' || s === 'done';
}

function isActive( status?: string ): boolean {
	const s = ( status ?? '' ).toLowerCase();
	return s === 'active' || s === 'executing' || s === 'working';
}

function isFailed( status?: string ): boolean {
	const s = ( status ?? '' ).toLowerCase();
	return s === 'error' || s === 'failed';
}

export function WorkflowTracker( {
	workflow,
}: WorkflowTrackerProps ): JSX.Element | null {
	const { steps, progress: providedProgress } = workflow;
	if ( steps.length === 0 ) return null;

	const completedCount = steps.filter( ( s ) => isCompleted( s.status ) ).length;
	const progress =
		typeof providedProgress === 'number'
			? Math.round( providedProgress )
			: Math.round( ( completedCount / steps.length ) * 100 );

	return (
		<div className="nvoos-chat-spa-workflow-tracker">
			<div className="nvoos-chat-spa-workflow-tracker-header">
				<span className="nvoos-chat-spa-workflow-tracker-title">
					{ __( 'Workflow Progress', 'nvoos-chat-spa' ) }
				</span>
				<span className="nvoos-chat-spa-workflow-tracker-pct">
					{ progress }%
				</span>
			</div>
			<div className="nvoos-chat-spa-workflow-tracker-bar">
				<div
					className="nvoos-chat-spa-workflow-tracker-fill"
					style={ { width: `${ progress }%` } }
				/>
			</div>
			<ol className="nvoos-chat-spa-workflow-tracker-steps">
				{ steps.map( ( step, i ) => (
					<WorkflowStepItem key={ i } step={ step } index={ i } />
				) ) }
			</ol>
		</div>
	);
}

function WorkflowStepItem( {
	step,
	index,
}: {
	step: WorkflowStep;
	index: number;
} ): JSX.Element {
	const status = ( step.status ?? 'pending' ).toLowerCase();
	let icon = String( index + 1 );
	let cls = 'nvoos-chat-spa-workflow-step';

	if ( isCompleted( status ) ) {
		icon = '✓';
		cls += ' nvoos-chat-spa-workflow-step--completed';
	} else if ( isActive( status ) ) {
		icon = '⟳';
		cls += ' nvoos-chat-spa-workflow-step--active';
	} else if ( isFailed( status ) ) {
		icon = '✗';
		cls += ' nvoos-chat-spa-workflow-step--failed';
	}

	return (
		<li className={ cls }>
			<span className="nvoos-chat-spa-workflow-step-icon" aria-hidden="true">
				{ icon }
			</span>
			<span className="nvoos-chat-spa-workflow-step-label">
				{ step.label || step.name || `${ __( 'Step', 'nvoos-chat-spa' ) } ${ index + 1 }` }
			</span>
		</li>
	);
}

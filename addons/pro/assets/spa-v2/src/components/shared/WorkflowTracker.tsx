/**
 * Pro SPA v2 — Workflow progress tracker.
 * Mirrors chat-spa's WorkflowTracker with pro namespace.
 * @package NV_oOS_Pro_Spa @since 0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX } from 'react';

export interface WorkflowStep { label?: string; name?: string; status?: string; }
export interface WorkflowState { steps: WorkflowStep[]; progress?: number; }

function isC( s?: string ) { const x = ( s ?? '' ).toLowerCase(); return x === 'completed' || x === 'done'; }
function isA( s?: string ) { const x = ( s ?? '' ).toLowerCase(); return x === 'active' || x === 'executing' || x === 'working'; }
function isF( s?: string ) { const x = ( s ?? '' ).toLowerCase(); return x === 'error' || x === 'failed'; }

export function WorkflowTracker( { workflow }: { workflow: WorkflowState } ): JSX.Element | null {
	const { steps, progress: pp } = workflow;
	if ( steps.length === 0 ) return null;
	const cc = steps.filter( ( s ) => isC( s.status ) ).length;
	const pct = typeof pp === 'number' ? Math.round( pp ) : Math.round( ( cc / steps.length ) * 100 );
	return (
		<div className="nvoos-pro-spa-workflow-tracker">
			<div className="nvoos-pro-spa-workflow-tracker-header"><span className="nvoos-pro-spa-workflow-tracker-title">{ __( 'Workflow Progress', 'nvoos-pro-spa' ) }</span><span className="nvoos-pro-spa-workflow-tracker-pct">{ pct }%</span></div>
			<div className="nvoos-pro-spa-workflow-tracker-bar"><div className="nvoos-pro-spa-workflow-tracker-fill" style={ { width: `${ pct }%` } } /></div>
			<ol className="nvoos-pro-spa-workflow-tracker-steps">
				{ steps.map( ( s, i ) => { const st = ( s.status ?? 'pending' ).toLowerCase(); let ico = String( i + 1 ); let cl = 'nvoos-pro-spa-workflow-step'; if ( isC( st ) ) { ico = '✓'; cl += ' nvoos-pro-spa-workflow-step--completed'; } else if ( isA( st ) ) { ico = '⟳'; cl += ' nvoos-pro-spa-workflow-step--active'; } else if ( isF( st ) ) { ico = '✗'; cl += ' nvoos-pro-spa-workflow-step--failed'; }
					return <li key={ i } className={ cl }><span className="nvoos-pro-spa-workflow-step-icon" aria-hidden="true">{ ico }</span><span className="nvoos-pro-spa-workflow-step-label">{ s.label || s.name || `${ __( 'Step', 'nvoos-pro-spa' ) } ${ i + 1 }` }</span></li>;
				} ) }
			</ol>
		</div>
	);
}

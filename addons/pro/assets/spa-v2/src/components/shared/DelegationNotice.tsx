/**
 * Pro SPA v2 — Delegation notice banner.
 * Mirrors chat-spa's DelegationNotice with pro namespace.
 * @package NV_oOS_Pro_Spa @since 0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX } from 'react';

export interface DelegationData { agentName?: string; task?: string; status?: string; summary?: string; }

export function DelegationNotice( { delegation }: { delegation: DelegationData } ): JSX.Element {
	const { agentName, task, status } = delegation;
	const isC = status === 'complete' || status === 'completed';
	const isE = status === 'error' || status === 'failed';
	let ico = '🔀', title = __( 'Delegating to sub-agent', 'nvoos-pro-spa' ), cls = 'nvoos-pro-spa-delegation';
	if ( isC ) { ico = '✅'; title = __( 'Sub-agent completed', 'nvoos-pro-spa' ); cls += ' nvoos-pro-spa-delegation--complete'; }
	else if ( isE ) { ico = '❌'; title = __( 'Sub-agent failed', 'nvoos-pro-spa' ); cls += ' nvoos-pro-spa-delegation--error'; }
	return (
		<div className={ cls }>
			<span className="nvoos-pro-spa-delegation-icon" aria-hidden="true">{ ico }</span>
			<div className="nvoos-pro-spa-delegation-body">
				<span className="nvoos-pro-spa-delegation-title">{ title }</span>
				{ ( agentName || task ) && <span className="nvoos-pro-spa-delegation-details">{ agentName ? `${ __( 'Agent:', 'nvoos-pro-spa' ) } ${ agentName }${ task ? ' — ' : '' }` : '' }{ task }</span> }
			</div>
		</div>
	);
}

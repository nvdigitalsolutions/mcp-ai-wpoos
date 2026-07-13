/**
 * Pro SPA v2 — Delegation notice banner.
 * Mirrors chat-spa's DelegationNotice with pro namespace.
 * @package NV_oOS_Pro_Spa @since 0.9.0
 */

import { __, sprintf } from '@wordpress/i18n';
import { type JSX } from 'react';

export interface DelegationData { agentName?: string; task?: string; status?: string; summary?: string; }

export function DelegationNotice( { delegation }: { delegation: DelegationData } ): JSX.Element {
	const { agentName, task, status } = delegation;
	const isComplete = status === 'complete' || status === 'completed';
	const isError = status === 'error' || status === 'failed';

	let icon = '\u{1F500}'; // 🔀
	let title = __( 'Delegating to sub-agent', 'nvoos-pro-spa' );
	let cls = 'nvoos-pro-spa-delegation';

	if ( isComplete ) {
		icon = '\u2705'; // ✅
		title = __( 'Sub-agent completed', 'nvoos-pro-spa' );
		cls += ' nvoos-pro-spa-delegation--complete';
	} else if ( isError ) {
		icon = '\u274C'; // ❌
		title = __( 'Sub-agent failed', 'nvoos-pro-spa' );
		cls += ' nvoos-pro-spa-delegation--error';
	}

	const statusLabel = isComplete
		? __( 'Completed', 'nvoos-pro-spa' )
		: isError
		? __( 'Failed', 'nvoos-pro-spa' )
		: __( 'In progress', 'nvoos-pro-spa' );

	return (
		<div
			className={ cls }
			role="status"
			aria-label={ sprintf(
				/* translators: 1: status label, 2: agent name (optional) */
				__( 'Sub-agent %1$s%2$s', 'nvoos-pro-spa' ),
				statusLabel,
				agentName ? ` — ${ agentName }` : ''
			) }
		>
			<span className="nvoos-pro-spa-delegation-icon" aria-hidden="true">
				{ icon }
			</span>
			<div className="nvoos-pro-spa-delegation-body">
				<span className="nvoos-pro-spa-delegation-title">
					{ title }
				</span>
				{ ( agentName || task ) && (
					<span className="nvoos-pro-spa-delegation-details">
						{ agentName
							? `${ __( 'Agent:', 'nvoos-pro-spa' ) } ${ agentName }${ task ? ' — ' : '' }`
							: '' }
						{ task }
					</span>
				) }
			</div>
		</div>
	);
}

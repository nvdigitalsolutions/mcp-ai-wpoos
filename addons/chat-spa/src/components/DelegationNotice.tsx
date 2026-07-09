/**
 * NV oOS Chat SPA — Delegation notice banner.
 *
 * Inline banner displayed in the message stream when a tool delegates
 * work to a sub-agent. Shows the agent name, task, and status.
 *
 * Mirrors the legacy `createDelegationNotice` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX } from 'react';
import type { DelegationData } from '../hooks/useAgentTeam';

export interface DelegationNoticeProps {
	delegation: DelegationData;
}

export function DelegationNotice( {
	delegation,
}: DelegationNoticeProps ): JSX.Element {
	const { agentName, task, status } = delegation;
	const isComplete = status === 'complete' || status === 'completed';
	const isError = status === 'error' || status === 'failed';

	let icon = '🔀';
	let title = __( 'Delegating to sub-agent', 'nvoos-chat-spa' );
	let cls = 'nvoos-chat-spa-delegation';

	if ( isComplete ) {
		icon = '✅';
		title = __( 'Sub-agent completed', 'nvoos-chat-spa' );
		cls += ' nvoos-chat-spa-delegation--complete';
	} else if ( isError ) {
		icon = '❌';
		title = __( 'Sub-agent failed', 'nvoos-chat-spa' );
		cls += ' nvoos-chat-spa-delegation--error';
	}

	return (
		<div className={ cls }>
			<span className="nvoos-chat-spa-delegation-icon" aria-hidden="true">
				{ icon }
			</span>
			<div className="nvoos-chat-spa-delegation-body">
				<span className="nvoos-chat-spa-delegation-title">{ title }</span>
				{ ( agentName || task ) && (
					<span className="nvoos-chat-spa-delegation-details">
						{ agentName
							? `${ __( 'Agent:', 'nvoos-chat-spa' ) } ${ agentName }${ task ? ' — ' : '' }`
							: '' }
						{ task }
					</span>
				) }
			</div>
		</div>
	);
}

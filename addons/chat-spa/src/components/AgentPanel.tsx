/**
 * NV oOS Chat SPA — Agent team panel.
 *
 * Collapsible panel showing the current multi-agent team with status
 * cards for each agent. Mirrors the legacy `initAgentPanel` /
 * `updateAgentPanel` from `assets/js/chat.js`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { __ } from '@wordpress/i18n';
import { type JSX, useCallback, useState } from 'react';
import type { AgentTeamState, AgentInfo } from '../hooks/useAgentTeam';

export interface AgentPanelProps {
	team: AgentTeamState | null;
}

function isActive( status?: string ): boolean {
	const s = ( status ?? '' ).toLowerCase();
	return s === 'active' || s === 'executing' || s === 'working';
}

function isCompleted( status?: string ): boolean {
	const s = ( status ?? '' ).toLowerCase();
	return s === 'completed' || s === 'done';
}

function isFailed( status?: string ): boolean {
	const s = ( status ?? '' ).toLowerCase();
	return s === 'error' || s === 'failed';
}

export function AgentPanel( { team }: AgentPanelProps ): JSX.Element | null {
	const [ expanded, setExpanded ] = useState( false );

	const toggle = useCallback( () => {
		setExpanded( ( e ) => ! e );
	}, [] );

	if ( ! team || team.agents.length === 0 ) return null;

	const activeCount = team.agents.filter( ( a ) => isActive( a.status ) ).length;

	return (
		<div className="nvoos-chat-spa-agent-panel">
			<button
				type="button"
				className="nvoos-chat-spa-agent-panel-toggle"
				aria-expanded={ expanded }
				aria-controls="nvoos-chat-spa-agent-panel-body"
				onClick={ toggle }
			>
				<span className="nvoos-chat-spa-agent-panel-toggle-label">
					{ team.teamName
						? team.teamName
						: __( 'Agent Team', 'nvoos-chat-spa' ) }
				</span>
				{ activeCount > 0 && (
					<span className="nvoos-chat-spa-agent-panel-count">
						{ activeCount }
					</span>
				) }
				<span className="nvoos-chat-spa-agent-panel-chevron" aria-hidden="true">
					{ expanded ? '▾' : '▸' }
				</span>
			</button>

			{ expanded && (
				<div
					id="nvoos-chat-spa-agent-panel-body"
					className="nvoos-chat-spa-agent-panel-body"
					role="list"
				>
					{ team.agents.map( ( agent, i ) => (
						<AgentCard key={ agent.id ?? agent.name ?? i } agent={ agent } />
					) ) }
				</div>
			) }
		</div>
	);
}

function AgentCard( { agent }: { agent: AgentInfo } ): JSX.Element {
	let statusClass = 'nvoos-chat-spa-agent-card';
	if ( isActive( agent.status ) ) {
		statusClass += ' nvoos-chat-spa-agent-card--active';
	} else if ( isCompleted( agent.status ) ) {
		statusClass += ' nvoos-chat-spa-agent-card--completed';
	} else if ( isFailed( agent.status ) ) {
		statusClass += ' nvoos-chat-spa-agent-card--error';
	} else {
		statusClass += ' nvoos-chat-spa-agent-card--idle';
	}

	return (
		<div className={ statusClass } role="listitem">
			<span
				className="nvoos-chat-spa-agent-card-status"
				aria-hidden="true"
			/>
			<div className="nvoos-chat-spa-agent-card-body">
				<span className="nvoos-chat-spa-agent-card-name">
					{ agent.name || agent.id || __( 'Agent', 'nvoos-chat-spa' ) }
				</span>
				{ agent.role && (
					<span className="nvoos-chat-spa-agent-card-role">
						{ agent.role }
					</span>
				) }
				{ agent.task && (
					<span
						className="nvoos-chat-spa-agent-card-task"
						title={ agent.task }
					>
						{ agent.task }
					</span>
				) }
			</div>
		</div>
	);
}

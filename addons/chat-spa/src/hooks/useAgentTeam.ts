/**
 * NV oOS Chat SPA — Agent team state hook.
 *
 * Scans all message tool invocations for agent-related tool results
 * (create_agent_team, manage_autonomous_session) and extracts the
 * current agent team state for the AgentPanel.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.9.0
 */

import { useMemo } from 'react';
import type { Message } from '@ai-sdk/react';

export interface AgentInfo {
	id?: string;
	name?: string;
	role?: string;
	status?: string;
	task?: string;
}

export interface AgentTeamState {
	teamName?: string;
	agents: AgentInfo[];
}

export interface WorkflowStep {
	label?: string;
	name?: string;
	status?: string;
}

export interface WorkflowState {
	steps: WorkflowStep[];
	progress?: number;
}

export interface DelegationData {
	agentName?: string;
	task?: string;
	status?: string;
	summary?: string;
}

/**
 * Extract agent team data from tool invocations across all messages.
 * Returns the latest agent team found, or null if none.
 */
export function useAgentTeam( messages: Message[] ): AgentTeamState | null {
	return useMemo( () => {
		let latest: AgentTeamState | null = null;

		for ( const msg of messages ) {
			const invocations = ( msg as unknown as Record<string, unknown> ).toolInvocations;
			if ( ! Array.isArray( invocations ) ) continue;

			for ( const inv of invocations as Array< Record< string, unknown > > ) {
				if ( inv.state !== 'result' ) continue;
				const result = inv.result as Record< string, unknown > | undefined;
				const toolName = String( inv.toolName ?? '' );
				if ( ! result || ! toolName ) continue;

				// create_agent_team
				if (
					toolName === 'create_agent_team' &&
					result.success &&
					result.team
				) {
					const team = result.team as Record< string, unknown >;
					const agents = ( team.agents ?? team.members ?? [] ) as AgentInfo[];
					if ( agents.length > 0 ) {
						latest = {
							teamName: String( team.team_name ?? '' ),
							agents,
						};
					}
				}

				// manage_autonomous_session
				if ( toolName === 'manage_autonomous_session' && result.session ) {
					const session = result.session as Record< string, unknown >;
					const agents = ( session.agents ?? [] ) as AgentInfo[];
					if ( agents.length > 0 ) {
						latest = { agents };
					}
				}
			}
		}

		return latest;
	}, [ messages ] );
}

/**
 * Extract workflow data from tool invocations.
 * Returns the latest workflow state, or null.
 */
export function useWorkflowState( messages: Message[] ): WorkflowState | null {
	return useMemo( () => {
		let latest: WorkflowState | null = null;

		for ( const msg of messages ) {
			const invocations = ( msg as unknown as Record<string, unknown> ).toolInvocations;
			if ( ! Array.isArray( invocations ) ) continue;

			for ( const inv of invocations as Array< Record< string, unknown > > ) {
				if ( inv.state !== 'result' ) continue;
				const result = inv.result as Record< string, unknown > | undefined;
				const toolName = String( inv.toolName ?? '' );
				if ( ! result?.steps ) continue;

				if (
					toolName === 'execute_workflow' ||
					toolName === 'update_task_plan' ||
					toolName === 'get_task_plan'
				) {
					latest = {
						steps: ( result.steps as WorkflowStep[] ) ?? [],
						progress: typeof result.progress === 'number' ? result.progress : undefined,
					};
				}
			}
		}

		return latest;
	}, [ messages ] );
}

/**
 * Extract delegation notices from tool invocations.
 * Returns all delegation notices found.
 */
export function useDelegationNotices(
	messages: Message[]
): DelegationData[] {
	return useMemo( () => {
		const notices: DelegationData[] = [];

		for ( const msg of messages ) {
			const invocations = ( msg as unknown as Record<string, unknown> ).toolInvocations;
			if ( ! Array.isArray( invocations ) ) continue;

			for ( const inv of invocations as Array< Record< string, unknown > > ) {
				if ( inv.state !== 'result' ) continue;
				const result = inv.result as Record< string, unknown > | undefined;
				const toolName = String( inv.toolName ?? '' );
				if ( ! result || ! toolName ) continue;

				// delegate_to_agent / delegate_to_a2a_agent
				if (
					( toolName === 'delegate_to_agent' ||
						toolName === 'delegate_to_a2a_agent' ) &&
					result.delegated_to
				) {
					notices.push( {
						agentName: String( result.delegated_to ),
						task: String( result.task ?? result.message ?? '' ),
						status: String( result.status ?? 'delegated' ),
					} );
				}

				// aggregate_agent_results
				if ( toolName === 'aggregate_agent_results' && result.success ) {
					notices.push( {
						task: String( result.summary ?? 'Agent results aggregated' ),
						status: 'complete',
					} );
				}
			}
		}

		return notices;
	}, [ messages ] );
}

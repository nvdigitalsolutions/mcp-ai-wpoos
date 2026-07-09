/**
 * Pro SPA v2 — Agent team + workflow + delegation extractor.
 * Scans message tool invocations for agent-related tool results.
 * @package NV_oOS_Pro_Spa @since 0.9.0
 */

import { useMemo } from 'react';
import type { Message } from '@ai-sdk/react';
import type { WorkflowState } from '../components/shared/WorkflowTracker';
import type { DelegationData } from '../components/shared/DelegationNotice';

export function useWorkflowState( messages: Message[] ): WorkflowState | null {
	return useMemo( () => {
		for ( const msg of messages ) {
			const invs = ( msg as unknown as Record< string, unknown > ).toolInvocations;
			if ( ! Array.isArray( invs ) ) continue;
			for ( const inv of invs as Array< Record< string, unknown > > ) {
				if ( inv.state !== 'result' ) continue;
				const r = inv.result as Record< string, unknown > | undefined;
				const tn = String( inv.toolName ?? '' );
				if ( r?.steps && ( tn === 'execute_workflow' || tn === 'update_task_plan' || tn === 'get_task_plan' ) ) {
					return { steps: ( r.steps as WorkflowState[ 'steps' ] ) ?? [], progress: typeof r.progress === 'number' ? r.progress : undefined };
				}
			}
		}
		return null;
	}, [ messages ] );
}

export function useDelegationNotices( messages: Message[] ): DelegationData[] {
	return useMemo( () => {
		const notices: DelegationData[] = [];
		for ( const msg of messages ) {
			const invs = ( msg as unknown as Record< string, unknown > ).toolInvocations;
			if ( ! Array.isArray( invs ) ) continue;
			for ( const inv of invs as Array< Record< string, unknown > > ) {
				if ( inv.state !== 'result' ) continue;
				const r = inv.result as Record< string, unknown > | undefined;
				const tn = String( inv.toolName ?? '' );
				if ( ( tn === 'delegate_to_agent' || tn === 'delegate_to_a2a_agent' ) && r?.delegated_to ) {
					notices.push( { agentName: String( r.delegated_to ), task: String( r.task ?? r.message ?? '' ), status: String( r.status ?? 'delegated' ) } );
				}
				if ( tn === 'aggregate_agent_results' && r?.success ) {
					notices.push( { task: String( r.summary ?? 'Agent results aggregated' ), status: 'complete' } );
				}
			}
		}
		return notices;
	}, [ messages ] );
}

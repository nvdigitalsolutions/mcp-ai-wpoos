/**
 * useWorkflowBuilder — Hook for the workflow list + selected detail.
 */

	import { useState, useCallback, useEffect, useMemo } from 'react';
	import { __ } from '@wordpress/i18n';
	import {
		WorkflowBuilderClient,
		type WorkflowDetail,
		type WorkflowGraph,
		type WorkflowNodeExecutePayload,
		type WorkflowRunSummary,
		type WorkflowSummary,
	} from '../api/workflow-builder';

export interface UseWorkflowBuilderOptions {
	endpoint: string;
	nonce: string;
	nodesEndpoint?: string;
	runsEndpoint?: string;
}

	export interface UseWorkflowBuilderReturn {
		workflows: WorkflowSummary[];
		selected: WorkflowDetail | null;
		runs: WorkflowRunSummary[];
		lastNodeResult: string | null;
		loading: boolean;
		saving: boolean;
		error: string | null;
		actionError: string | null;
		refresh: () => Promise< void >;
		selectWorkflow: ( id: number ) => Promise< void >;
		createWorkflow: ( name: string, tags?: string[] ) => Promise< boolean >;
		removeWorkflow: ( id: number ) => Promise< boolean >;
		executeWorkflow: ( id: number ) => Promise< boolean >;
		executeNode: ( payload: WorkflowNodeExecutePayload ) => Promise< boolean >;
		saveGraph: ( id: number, graph: WorkflowGraph ) => Promise< boolean >;
	}

export function useWorkflowBuilder(
	options: UseWorkflowBuilderOptions
): UseWorkflowBuilderReturn {
	const client = useMemo(
		() =>
			new WorkflowBuilderClient( {
				endpoint: options.endpoint,
				nonce: options.nonce,
				nodesEndpoint: options.nodesEndpoint,
				runsEndpoint: options.runsEndpoint,
			} ),
		[ options.endpoint, options.nonce, options.nodesEndpoint, options.runsEndpoint ]
	);

	const [ workflows, setWorkflows ] = useState< WorkflowSummary[] >( [] );
	const [ selected, setSelected ] = useState< WorkflowDetail | null >( null );
	const [ runs, setRuns ] = useState< WorkflowRunSummary[] >( [] );
	const [ lastNodeResult, setLastNodeResult ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ saving, setSaving ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ actionError, setActionError ] = useState< string | null >( null );

	const refresh = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setWorkflows( await client.list() );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void refresh();
	}, [ refresh ] );

	const selectWorkflow = useCallback(
		async ( id: number ) => {
			setActionError( null );
			setSelected( await client.get( id ) );
			setRuns( await client.runs( id ) );
		},
		[ client ]
	);

	const runAction = useCallback(
		async ( action: () => Promise< void > ): Promise< boolean > => {
			setActionError( null );
			try {
				await action();
				await refresh();
				return true;
			} catch ( err ) {
				setActionError( err instanceof Error ? err.message : String( err ) );
				return false;
			}
		},
		[ refresh ]
	);

	const createWorkflow = useCallback(
		( name: string, tags: string[] = [] ) =>
			runAction( () => client.create( { name, tags } ) ),
		[ client, runAction ]
	);

	const removeWorkflow = useCallback(
		( id: number ) => {
			if ( selected && selected.id === id ) {
				setSelected( null );
			}
			return runAction( () => client.remove( id ) );
		},
		[ client, runAction, selected ]
	);

	const executeWorkflow = useCallback(
		( id: number ) => runAction( () => client.execute( id ) ),
		[ client, runAction ]
	);

	const executeNode = useCallback(
		async ( payload: WorkflowNodeExecutePayload ): Promise< boolean > => {
			setActionError( null );
			setLastNodeResult( null );
			try {
				await client.executeNode( payload );
				setLastNodeResult( __( 'Node executed.', 'nvoos-pro-spa' ) );
				if ( selected ) {
					setRuns( await client.runs( selected.id ) );
				}
				return true;
			} catch ( err ) {
				setActionError( err instanceof Error ? err.message : String( err ) );
				return false;
			}
		},
		[ client, selected ]
	);

	const saveGraph = useCallback(
		async ( id: number, graph: WorkflowGraph ): Promise< boolean > => {
			setActionError( null );
			setSaving( true );
			try {
				await client.update( id, { graph } );
				setSelected( await client.get( id ) );
				return true;
			} catch ( err ) {
				setActionError( err instanceof Error ? err.message : String( err ) );
				return false;
			} finally {
				setSaving( false );
			}
		},
		[ client ]
	);

	return {
		workflows,
		selected,
		runs,
		lastNodeResult,
		loading,
		saving,
		error,
		actionError,
		refresh,
		selectWorkflow,
		createWorkflow,
		removeWorkflow,
		executeWorkflow,
		executeNode,
		saveGraph,
	};
}

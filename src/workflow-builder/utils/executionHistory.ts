/**
 * Execution History Tracker — TypeScript edition.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

interface ExecutionState { status: string; [key: string]: unknown; }
interface ExecutionRecord {
	id: string; workflowId: string; timestamp: number; duration: number;
	status: string; nodeCount: number; completedNodes: number; failedNodes: number;
	results: Record< string, unknown >; errors: Array< { message: string } >;
	nodeStates: Record< string, ExecutionState >;
}

declare const window: Window & { mcpAiWorkflowBuilder?: { ajaxUrl: string; nonce: string } };

export const saveExecutionHistory = ( workflowId: string, execution: { duration?: number; status: string; nodeStates?: Record< string, ExecutionState >; results?: Record< string, unknown >; errors?: Array< { message: string } > } ): ExecutionRecord | null => {
	try {
		const key = `workflow_executions_${ workflowId }`;
		const history = getExecutionHistory( workflowId );
		const states = execution.nodeStates || {};
		const record: ExecutionRecord = {
			id: `exec-${ Date.now() }`, workflowId, timestamp: Date.now(),
			duration: execution.duration || 0, status: execution.status,
			nodeCount: Object.keys( states ).length,
			completedNodes: Object.values( states ).filter( ( s ) => s.status === 'completed' ).length,
			failedNodes: Object.values( states ).filter( ( s ) => s.status === 'failed' ).length,
			results: execution.results || {}, errors: execution.errors || [], nodeStates: states,
		};
		history.unshift( record );
		if ( history.length > 50 ) { history.splice( 50 ); }
		localStorage.setItem( key, JSON.stringify( history ) );
		saveExecutionToBackend( record );
		return record;
	} catch { return null; }
};

export const getExecutionHistory = ( workflowId: string ): ExecutionRecord[] => {
	try { const d = localStorage.getItem( `workflow_executions_${ workflowId }` ); return d ? JSON.parse( d ) : []; }
	catch { return []; }
};

export const getExecution = ( workflowId: string, executionId: string ): ExecutionRecord | undefined => {
	return getExecutionHistory( workflowId ).find( ( e ) => e.id === executionId );
};

const saveExecutionToBackend = async ( execution: ExecutionRecord ): Promise< void > => {
	try {
		await fetch( window.mcpAiWorkflowBuilder?.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( { action: 'wp_mcp_ai_save_workflow_execution', nonce: window.mcpAiWorkflowBuilder?.nonce || '', execution: JSON.stringify( execution ) } ),
		} );
	} catch { /* ignore */ }
};

export const clearExecutionHistory = ( workflowId: string ): boolean => {
	try { localStorage.removeItem( `workflow_executions_${ workflowId }` ); return true; }
	catch { return false; }
};

export const formatExecution = ( execution: ExecutionRecord ) => {
	const d = new Date( execution.timestamp );
	return { ...execution, formattedDate: d.toLocaleString(), formattedDuration: execution.duration ? `${ ( execution.duration / 1000 ).toFixed( 2 ) }s` : 'N/A', successRate: `${ execution.nodeCount > 0 ? Math.round( ( execution.completedNodes / execution.nodeCount ) * 100 ) : 0 }%` };
};

export const getExecutionStats = ( workflowId: string ) => {
	const history = getExecutionHistory( workflowId );
	if ( ! history.length ) { return { totalExecutions: 0, successfulExecutions: 0, failedExecutions: 0, averageDuration: 0, successRate: 0, lastExecution: null as string | null }; }
	const successful = history.filter( ( e ) => e.status === 'completed' ).length;
	return { totalExecutions: history.length, successfulExecutions: successful, failedExecutions: history.filter( ( e ) => e.status === 'failed' ).length, averageDuration: Math.round( history.reduce( ( s, e ) => s + ( e.duration || 0 ), 0 ) / history.length ), successRate: Math.round( ( successful / history.length ) * 100 ), lastExecution: new Date( history[ 0 ].timestamp ).toLocaleString() };
};

export const getNodeMetrics = ( workflowId: string ): Record< string, { executions: number; successes: number; failures: number; totalDuration: number; averageDuration: number; successRate: number } > => {
	const metrics: Record< string, { executions: number; successes: number; failures: number; totalDuration: number; averageDuration: number; successRate: number } > = {};
	for ( const ex of getExecutionHistory( workflowId ) ) {
		if ( ! ex.nodeStates ) { continue; }
		for ( const [ nid, s ] of Object.entries( ex.nodeStates ) ) {
			if ( ! metrics[ nid ] ) { metrics[ nid ] = { executions: 0, successes: 0, failures: 0, totalDuration: 0, averageDuration: 0, successRate: 0 }; }
			metrics[ nid ].executions++;
			if ( s.status === 'completed' ) { metrics[ nid ].successes++; }
			else if ( s.status === 'failed' ) { metrics[ nid ].failures++; }
			if ( s.duration ) { metrics[ nid ].totalDuration += Number( s.duration ); }
		}
	}
	for ( const m of Object.values( metrics ) ) { m.averageDuration = m.executions ? Math.round( m.totalDuration / m.executions ) : 0; m.successRate = m.executions ? Math.round( ( m.successes / m.executions ) * 100 ) : 0; }
	return metrics;
};

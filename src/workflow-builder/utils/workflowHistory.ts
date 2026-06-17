/**
 * Workflow History Manager — TypeScript edition.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import type { WorkflowNode, WorkflowEdge } from '../../shared/types';

interface HistoryEntry {
	nodes: WorkflowNode[];
	edges: WorkflowEdge[];
	timestamp: number;
}

export class WorkflowHistory {
	private history: HistoryEntry[] = [];
	private currentIndex = -1;
	private maxHistorySize: number;

	constructor( maxHistorySize = 50 ) {
		this.maxHistorySize = maxHistorySize;
	}

	push( state: { nodes: WorkflowNode[]; edges: WorkflowEdge[] } ): void {
		this.history = this.history.slice( 0, this.currentIndex + 1 );
		this.history.push( { nodes: JSON.parse( JSON.stringify( state.nodes ) ), edges: JSON.parse( JSON.stringify( state.edges ) ), timestamp: Date.now() } );
		if ( this.history.length > this.maxHistorySize ) { this.history.shift(); }
		else { this.currentIndex++; }
	}

	undo(): { nodes: WorkflowNode[]; edges: WorkflowEdge[] } | null {
		if ( ! this.canUndo() ) { return null; }
		this.currentIndex--;
		return this.getCurrentState();
	}

	redo(): { nodes: WorkflowNode[]; edges: WorkflowEdge[] } | null {
		if ( ! this.canRedo() ) { return null; }
		this.currentIndex++;
		return this.getCurrentState();
	}

	canUndo(): boolean { return this.currentIndex > 0; }
	canRedo(): boolean { return this.currentIndex < this.history.length - 1; }

	getCurrentState(): { nodes: WorkflowNode[]; edges: WorkflowEdge[] } | null {
		if ( this.currentIndex < 0 || this.currentIndex >= this.history.length ) { return null; }
		const s = this.history[ this.currentIndex ];
		return { nodes: JSON.parse( JSON.stringify( s.nodes ) ), edges: JSON.parse( JSON.stringify( s.edges ) ) };
	}

	clear(): void { this.history = []; this.currentIndex = -1; }

	getStats(): { size: number; currentIndex: number; canUndo: boolean; canRedo: boolean } {
		return { size: this.history.length, currentIndex: this.currentIndex, canUndo: this.canUndo(), canRedo: this.canRedo() };
	}
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export const debounce = ( func: ( ...args: any[] ) => void, wait: number ): ( ...args: any[] ) => void => {
	let timeout: ReturnType< typeof setTimeout >;
	return function ( ...args ) { clearTimeout( timeout ); timeout = setTimeout( () => func( ...args ), wait ); };
};

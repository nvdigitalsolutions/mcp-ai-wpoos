/**
 * Workflow History Manager
 *
 * Manages undo/redo history for workflow changes.
 *
 * @package WP_MCP_AI
 * @since 2.1.0
 */

/**
 * Create a history manager for undo/redo functionality
 */
export class WorkflowHistory {
	constructor( maxHistorySize = 50 ) {
		this.history = [];
		this.currentIndex = -1;
		this.maxHistorySize = maxHistorySize;
	}

	/**
	 * Add a state to history
	 */
	push( state ) {
		// Remove any states after current index (when user undoes then makes new changes)
		this.history = this.history.slice( 0, this.currentIndex + 1 );

		// Add new state
		this.history.push( {
			nodes: JSON.parse( JSON.stringify( state.nodes ) ),
			edges: JSON.parse( JSON.stringify( state.edges ) ),
			timestamp: Date.now(),
		} );

		// Maintain max history size
		if ( this.history.length > this.maxHistorySize ) {
			this.history.shift();
		} else {
			this.currentIndex++;
		}
	}

	/**
	 * Undo to previous state
	 */
	undo() {
		if ( this.canUndo() ) {
			this.currentIndex--;
			return this.getCurrentState();
		}
		return null;
	}

	/**
	 * Redo to next state
	 */
	redo() {
		if ( this.canRedo() ) {
			this.currentIndex++;
			return this.getCurrentState();
		}
		return null;
	}

	/**
	 * Check if undo is possible
	 */
	canUndo() {
		return this.currentIndex > 0;
	}

	/**
	 * Check if redo is possible
	 */
	canRedo() {
		return this.currentIndex < this.history.length - 1;
	}

	/**
	 * Get current state
	 */
	getCurrentState() {
		if ( this.currentIndex >= 0 && this.currentIndex < this.history.length ) {
			const state = this.history[ this.currentIndex ];
			return {
				nodes: JSON.parse( JSON.stringify( state.nodes ) ),
				edges: JSON.parse( JSON.stringify( state.edges ) ),
			};
		}
		return null;
	}

	/**
	 * Clear history
	 */
	clear() {
		this.history = [];
		this.currentIndex = -1;
	}

	/**
	 * Get history stats
	 */
	getStats() {
		return {
			size: this.history.length,
			currentIndex: this.currentIndex,
			canUndo: this.canUndo(),
			canRedo: this.canRedo(),
		};
	}
}

/**
 * Debounce function to limit history pushes
 */
export const debounce = ( func, wait ) => {
	let timeout;
	return function executedFunction( ...args ) {
		const later = () => {
			clearTimeout( timeout );
			func( ...args );
		};
		clearTimeout( timeout );
		timeout = setTimeout( later, wait );
	};
};

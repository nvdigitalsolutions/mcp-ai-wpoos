/**
 * Generic undo/redo history hook.
 *
 * Maintains a stack of state snapshots. Call `push(state)` before applying
 * a change; navigate with `undo()` / `redo()`.
 *
 * @since 0.2.0
 */

import { useCallback, useRef, useState } from 'react';

export interface HistoryAPI<T> {
	/** Push the current state onto the undo stack (call BEFORE mutating). */
	push: ( state: T ) => void;
	/** Undo the last pushed state. Returns the previous state. */
	undo: () => T | null;
	/** Redo a previously undone state. */
	redo: () => T | null;
	/** Whether there are states to undo. */
	canUndo: boolean;
	/** Whether there are states to redo. */
	canRedo: boolean;
	/** Clear all history. */
	clear: () => void;
}

const MAX_HISTORY = 50;

/**
 * useHistory — snapshot-based undo/redo.
 *
 * @template T Type of the state snapshots.
 * @returns History API.
 */
export function useHistory<T>(): HistoryAPI<T> {
	const pastRef = useRef<T[]>( [] );
	const futureRef = useRef<T[]>( [] );
	const [ canUndo, setCanUndo ] = useState( false );
	const [ canRedo, setCanRedo ] = useState( false );

	const updateFlags = useCallback( () => {
		setCanUndo( pastRef.current.length > 0 );
		setCanRedo( futureRef.current.length > 0 );
	}, [] );

	const push = useCallback( ( state: T ) => {
		pastRef.current.push( state );
		if ( pastRef.current.length > MAX_HISTORY ) {
			pastRef.current.shift();
		}
		futureRef.current = [];
		updateFlags();
	}, [ updateFlags ] );

	const undo = useCallback( (): T | null => {
		const past = pastRef.current;
		if ( past.length === 0 ) {
			return null;
		}
		const prev = past.pop()!;
		futureRef.current.push( prev ); // future holds the "undone" state — we return the new top
		const restored = past.length > 0 ? past[ past.length - 1 ] : null;
		updateFlags();
		return restored;
	}, [ updateFlags ] );

	const redo = useCallback( (): T | null => {
		const future = futureRef.current;
		if ( future.length === 0 ) {
			return null;
		}
		const next = future.pop()!;
		pastRef.current.push( next );
		updateFlags();
		return next;
	}, [ updateFlags ] );

	const clear = useCallback( () => {
		pastRef.current = [];
		futureRef.current = [];
		updateFlags();
	}, [ updateFlags ] );

	return { push, undo, redo, canUndo, canRedo, clear };
}

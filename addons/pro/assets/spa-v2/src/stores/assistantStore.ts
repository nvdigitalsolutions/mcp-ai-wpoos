/**
 * Assistant Store — Zustand store for the currently selected assistant (agent).
 */

import { create } from 'zustand';
import type { AssistantRecord } from '../api/assistants';
import { readProSpaConfig, type RuntimeAssistantSummary } from '../api/config';

export interface AssistantState {
	assistantId: number;
	assistants: AssistantRecord[];

	setActiveAssistant: ( id: number ) => void;
	setAssistants: ( list: AssistantRecord[] ) => void;
}

const STORAGE_KEY_PREFIX = 'nvoos-pro-spa.active-assistant';

/**
 * Read the initial assistant ID from localStorage (per-user key),
 * falling back to the server-provided runtime config.
 */
function getInitialAssistantId(): number {
	const config = readProSpaConfig();
	const userId = config?.user?.id ?? 0;

	// 1. Try localStorage with the user-specific key.
	if ( userId > 0 ) {
		try {
			const stored = localStorage.getItem( `${ STORAGE_KEY_PREFIX }-${ userId }` );
			if ( stored !== null ) {
				const parsed = parseInt( stored, 10 );
				if ( ! isNaN( parsed ) && parsed > 0 ) {
					return parsed;
				}
			}
		} catch ( _ ) {
			// localStorage unavailable (SSR, privacy mode, etc.)
		}
	}

	// 2. Fallback to runtime config (same source as useBootstrap().runtime).
	return config?.config?.assistantId ?? config?.user?.assistant_id ?? 0;
}

/**
 * Resolve the current user ID from the global runtime config.
 */
function getUserId(): number {
	const config = readProSpaConfig();
	return config?.user?.id ?? 0;
}

/**
 * Read pre-loaded assistants from the server runtime config and
 * map them to the shape the ChatSidebar select expects.
 */
function getInitialAssistants(): AssistantRecord[] {
	const config = readProSpaConfig();
	const list = config?.assistants;
	if ( ! Array.isArray( list ) || list.length === 0 ) {
		return [];
	}
	return list.map( ( a: RuntimeAssistantSummary ) => ( {
		id: a.id,
		title: a.title,
		provider: a.provider,
		model: a.model,
	} ) );
}

export const useAssistantStore = create< AssistantState >( ( set ) => ( {
	assistantId: getInitialAssistantId(),
	assistants: getInitialAssistants(),

	setActiveAssistant: ( id ) => {
		const userId = getUserId();
		if ( userId > 0 ) {
			try {
				localStorage.setItem(
					`${ STORAGE_KEY_PREFIX }-${ userId }`,
					String( id )
				);
			} catch ( _ ) {
				// Silently ignore storage failures.
			}
		}
		set( { assistantId: id } );
	},

	setAssistants: ( list ) => set( { assistants: list } ),
} ) );

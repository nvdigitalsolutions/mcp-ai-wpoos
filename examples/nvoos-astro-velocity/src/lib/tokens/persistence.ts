// ---------------------------------------------------------------------------
// persistence.ts — Phase 4 durable design state (Proposal 095 §4.4).
//
// Best-effort JSON persistence for the runtime TokenService: overrides,
// the active theme, runtime themes and rollback snapshots survive process
// restarts when a persist path is configured (constructor option or the
// NVOOS_MCP_STATE_FILE env var). Loads are fail-safe — any unreadable or
// malformed state yields null and the service starts from defaults. Saves
// are atomic (tmp file + rename on the same volume) and never throw into an
// MCP tool call or /manage write.
// ---------------------------------------------------------------------------

import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

export interface PersistedDesignState {
	overrides: Record<string, string>;
	activeTheme: string;
	runtimeThemes: Record<string, unknown>;
	snapshots: unknown[];
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Read + parse the persisted design state. Returns null on ANY failure
 * (missing file, invalid JSON, wrong shape) — callers fall back to defaults.
 */
export function loadDesignState(path: string): PersistedDesignState | null {
	try {
		const parsed: unknown = JSON.parse(readFileSync(path, 'utf8'));
		if (!isPlainObject(parsed)) return null;
		const overrides = parsed.overrides;
		const activeTheme = parsed.activeTheme;
		const runtimeThemes = parsed.runtimeThemes;
		const snapshots = parsed.snapshots;
		if (
			!isPlainObject(overrides) ||
			typeof activeTheme !== 'string' ||
			!isPlainObject(runtimeThemes) ||
			!Array.isArray(snapshots)
		) {
			return null;
		}
		for (const value of Object.values(overrides)) {
			if (typeof value !== 'string') return null;
		}
		for (const value of Object.values(runtimeThemes)) {
			if (!isPlainObject(value)) return null;
		}
		for (const entry of snapshots) {
			if (!isPlainObject(entry)) return null;
		}
		return {
			overrides: overrides as Record<string, string>,
			activeTheme,
			runtimeThemes,
			snapshots,
		};
	} catch {
		return null;
	}
}

/**
 * Persist the design state atomically: write `${path}.tmp` then rename over
 * the target (same directory, so the rename stays on one volume). Failures
 * are warned and swallowed — persistence is best-effort and must never
 * surface as a failed tool call.
 */
export function saveDesignState(path: string, state: PersistedDesignState): void {
	try {
		mkdirSync(dirname(path), { recursive: true });
		writeFileSync(`${path}.tmp`, JSON.stringify(state, null, '\t'));
		renameSync(`${path}.tmp`, path);
	} catch (error) {
		console.warn(`[tokens] failed to persist design state to ${path}: ${(error as Error).message}`);
	}
}

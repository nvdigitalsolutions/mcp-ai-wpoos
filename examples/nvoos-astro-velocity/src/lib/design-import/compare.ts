// ---------------------------------------------------------------------------
// compare.ts — re-extraction diff (Proposal 098 Phase 2.2).
//
// Pure function: two DesignLanguage snapshots in, a structured diff out.
// The Element Armory discipline ("re-run extraction quarterly") rides on
// this — design_compare re-analyzes a source and reports what drifted.
// ---------------------------------------------------------------------------

import type { DesignLanguage } from './types';

export interface DesignCompareResult {
	/** Per-role value changes (role → before → after). */
	rolesChanged: Array<{ role: string; before: string; after: string }>;
	rolesAdded: string[];
	rolesRemoved: string[];
	conceptChanged: { before: string; after: string } | null;
	familiesAdded: string[];
	familiesRemoved: string[];
	scaleAdded: number[];
	scaleRemoved: number[];
	spacingAdded: number[];
	spacingRemoved: number[];
	/** One-line summary of the drift. */
	summary: string;
}

export function compareDesignLanguages(before: DesignLanguage, after: DesignLanguage): DesignCompareResult {
	const rolesChanged: Array<{ role: string; before: string; after: string }> = [];
	const rolesAdded: string[] = [];
	const rolesRemoved: string[] = [];
	const allRoles = new Set([...Object.keys(before.palette.roles), ...Object.keys(after.palette.roles)]);
	for (const role of allRoles) {
		const prev = before.palette.roles[role]?.value;
		const next = after.palette.roles[role]?.value;
		if (prev && next && prev !== next) {
			rolesChanged.push({ role, before: prev, after: next });
		} else if (!prev && next) {
			rolesAdded.push(role);
		} else if (prev && !next) {
			rolesRemoved.push(role);
		}
	}

	const diffList = (prev: string[], next: string[]) => {
		const added = next.filter((item) => !prev.includes(item));
		const removed = prev.filter((item) => !next.includes(item));
		return { added, removed };
	};
	const families = diffList(
		before.typography.families.map((entry) => entry.family),
		after.typography.families.map((entry) => entry.family)
	);
	const scale = diffList(before.typography.scalePx.map(String), after.typography.scalePx.map(String));
	const spacing = diffList(before.spacing.scalePx.map(String), after.spacing.scalePx.map(String));

	const conceptChanged =
		before.concept !== after.concept ? { before: before.concept, after: after.concept } : null;

	const moves = rolesChanged.length + rolesAdded.length + rolesRemoved.length;
	const summaryParts: string[] = [];
	if (moves > 0) {
		summaryParts.push(`${moves} role change${moves === 1 ? '' : 's'}`);
	}
	if (conceptChanged) {
		summaryParts.push('concept changed');
	}
	if (families.added.length + families.removed.length > 0) {
		summaryParts.push('font stack changed');
	}
	if (scale.added.length + scale.removed.length + spacing.added.length + spacing.removed.length > 0) {
		summaryParts.push('scale/rhythm changed');
	}
	const summary = summaryParts.length > 0 ? summaryParts.join(', ') : 'no changes';

	return {
		rolesChanged,
		rolesAdded,
		rolesRemoved,
		conceptChanged,
		familiesAdded: families.added,
		familiesRemoved: families.removed,
		scaleAdded: scale.added.map(Number),
		scaleRemoved: scale.removed.map(Number),
		spacingAdded: spacing.added.map(Number),
		spacingRemoved: spacing.removed.map(Number),
		summary,
	};
}

/**
 * Security Center — pure presentation helpers for the posture report and
 * action results (unit-tested; the page keeps no parsing logic inline).
 */

/** One posture signal as returned by GET /mcp-ai/v1/security/posture. */
export interface PostureSignal {
	id: string;
	label: string;
	weight: number;
	passed: boolean;
	detail: string;
	subtab: string;
	anchor: string;
}

/** The parsed posture report (defensive — unknown payloads yield null). */
export interface PostureReport {
	score: number;
	grade: string;
	signals: PostureSignal[];
	quickWins: PostureSignal[];
	computedAt: string;
}

export interface SignalGroup {
	label: string;
	signals: PostureSignal[];
}

/** Human summary of the last security action (snapshot / restore / self-test). */
export interface ActionSummary {
	title: string;
	detail: string;
	tone: 'ok' | 'error';
	/** Per-check rows for the self-test result, when present. */
	checks?: { id: string; label: string; passed: boolean; message: string }[];
}

/** Subtab slugs → human labels (the wp-admin Security Center subtab names). */
const SUBTAB_LABELS: Record< string, string > = {
	network: 'Network',
	access: 'Access & Identity',
	audit: 'Audit & Compliance',
	ai_safety: 'AI Safety',
};

/** Parse the posture payload defensively; null when the shape is unknown. */
export function parsePosture( raw: Record< string, unknown > | null ): PostureReport | null {
	if ( ! raw || typeof raw !== 'object' ) {
		return null;
	}
	const score = typeof raw.score === 'number' && isFinite( raw.score ) ? raw.score : NaN;
	if ( Number.isNaN( score ) ) {
		return null;
	}
	const signals = ( Array.isArray( raw.signals ) ? raw.signals : [] )
		.map( ( rawSignal ): PostureSignal | null => {
			if ( ! rawSignal || typeof rawSignal !== 'object' ) {
				return null;
			}
			const s = rawSignal as Record< string, unknown >;
			if ( typeof s.id !== 'string' || typeof s.label !== 'string' ) {
				return null;
			}
			return {
				id: s.id,
				label: s.label,
				weight: typeof s.weight === 'number' ? s.weight : 0,
				passed: s.passed === true,
				detail: typeof s.detail === 'string' ? s.detail : '',
				subtab: typeof s.subtab === 'string' ? s.subtab : '',
				anchor: typeof s.anchor === 'string' ? s.anchor : '',
			};
		} )
		.filter( ( s ): s is PostureSignal => s !== null );

	const quickWins = ( Array.isArray( raw.quick_wins ) ? raw.quick_wins : [] )
		.map( ( rawSignal ): PostureSignal | null => {
			if ( ! rawSignal || typeof rawSignal !== 'object' ) {
				return null;
			}
			const s = rawSignal as Record< string, unknown >;
			if ( typeof s.id !== 'string' || typeof s.label !== 'string' ) {
				return null;
			}
			return {
				id: s.id,
				label: s.label,
				weight: typeof s.weight === 'number' ? s.weight : 0,
				passed: s.passed === true,
				detail: typeof s.detail === 'string' ? s.detail : '',
				subtab: typeof s.subtab === 'string' ? s.subtab : '',
				anchor: typeof s.anchor === 'string' ? s.anchor : '',
			};
		} )
		.filter( ( s ): s is PostureSignal => s !== null );

	return {
		score,
		grade: typeof raw.grade === 'string' ? raw.grade : '',
		signals,
		quickWins,
		computedAt: typeof raw.computed_at === 'string' ? raw.computed_at : '',
	};
}

/** Tone for a letter grade — drives the badge colour. */
export function gradeTone( grade: string ): 'ok' | 'warning' | 'error' {
	const g = grade.trim().toUpperCase();
	if ( g === 'A' || g === 'B' ) {
		return 'ok';
	}
	if ( g === 'C' ) {
		return 'warning';
	}
	return 'error';
}

/** Human label for a subtab slug; unknown slugs group under “Other”. */
export function subtabLabel( subtab: string ): string {
	return SUBTAB_LABELS[ subtab ] ?? 'Other';
}

/**
 * Group signals by subtab, preserving signal order within each group and
 * the first-seen order across groups (the definition order is meaningful —
 * authentication checks lead).
 */
export function groupSignals( signals: PostureSignal[] ): SignalGroup[] {
	const groups: SignalGroup[] = [];
	const byLabel = new Map< string, SignalGroup >();
	for ( const signal of signals ) {
		const label = subtabLabel( signal.subtab );
		let group = byLabel.get( label );
		if ( ! group ) {
			group = { label, signals: [] };
			byLabel.set( label, group );
			groups.push( group );
		}
		group.signals.push( signal );
	}
	return groups;
}

/** Localized timestamp for the report header (fallback: empty string). */
export function formatTimestamp( iso: string ): string {
	if ( ! iso ) {
		return '';
	}
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	return date.toLocaleString();
}

const SELF_TEST_LABELS: Record< string, string > = {
	auth_fail: 'Auth-failure event',
	ip_block: 'IP-block event',
	injection: 'Injection event',
	audit_log_check: 'Audit-log delivery',
};

/**
 * Turn the raw last-action payload into a human summary.
 *
 * The three actions (save snapshot, restore snapshot, self-test) have
 * different shapes — this keeps the page free of shape-specific parsing.
 */
export function summarizeAction( action: Record< string, unknown > | null ): ActionSummary | null {
	if ( ! action || typeof action !== 'object' ) {
		return null;
	}

	// Self-test: { success, results: { key: { passed, message } } }.
	if ( action.results && typeof action.results === 'object' && ! Array.isArray( action.results ) ) {
		const checks = Object.entries( action.results as Record< string, unknown > )
			.map( ( [ id, raw ] ) => {
				const check = ( raw ?? {} ) as Record< string, unknown >;
				return {
					id,
					label: SELF_TEST_LABELS[ id ] ?? id,
					passed: check.passed === true,
					message: typeof check.message === 'string' ? check.message : '',
				};
			} )
			.filter( ( check ) => check.message !== '' || check.label !== '' );
		const passedCount = checks.filter( ( check ) => check.passed ).length;
		const allPassed = passedCount === checks.length;
		return {
			title: 'Self-test',
			tone: allPassed ? 'ok' : 'error',
			detail: allPassed
				? `All ${ checks.length } self-test checks passed.`
				: `${ passedCount } of ${ checks.length } self-test checks passed.`,
			checks,
		};
	}

	// Restore: { success, snapshot_id, label, keys_restored }.
	if ( typeof action.label === 'string' && typeof action.keys_restored === 'number' ) {
		return {
			title: 'Settings restored',
			tone: action.success === true ? 'ok' : 'error',
			detail: `Restored “${ action.label }” (${ action.keys_restored } security settings).`,
		};
	}

	// Save snapshot: { success, snapshot_id, label, created_at }.
	if ( typeof action.snapshot_id === 'string' && typeof action.label === 'string' ) {
		return {
			title: 'Snapshot saved',
			tone: action.success === true ? 'ok' : 'error',
			detail: `Snapshot “${ action.label }” saved${ formatTimestamp( typeof action.created_at === 'string' ? action.created_at : '' ) ? ` at ${ formatTimestamp( action.created_at as string ) }` : '' }.`,
		};
	}

	return {
		title: 'Security action',
		tone: action.success === false ? 'error' : 'ok',
		detail: action.success === false ? 'The action did not complete successfully.' : 'The action completed.',
	};
}

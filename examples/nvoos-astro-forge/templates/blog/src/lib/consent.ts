// ---------------------------------------------------------------------------
// consent.ts — the pure consent model (Proposal 130 §2.5).
//
// The inline bootstrap in Tracking.astro and the banner script are plain JS
// and mirror this module; CONSENT_KEY / CONSENT_VERSION are pinned by a unit
// test so the two can never silently drift (the theme-policy precedent).
// ---------------------------------------------------------------------------

/** localStorage key holding the visitor's decision (never a cookie). */
export const CONSENT_KEY = 'nvoos-consent';

/** The stored-decision schema version. */
export const CONSENT_VERSION = 1;

export type ConsentCategory = 'necessary' | 'analytics' | 'advertising' | 'functional' | 'personalization';

export const CONSENT_CATEGORIES: ConsentCategory[] = [
	'necessary',
	'analytics',
	'advertising',
	'functional',
	'personalization',
];

export interface ConsentDecision {
	v: number;
	ts: number;
	categories: Record< ConsentCategory, boolean >;
	/** Global Privacy Control was active when the decision was taken. */
	gpc?: boolean;
}

/** Every non-essential category denied; `necessary` is always on. */
export function defaultDecision( gpc = false ): ConsentDecision {
	return {
		v: CONSENT_VERSION,
		ts: 0,
		categories: {
			necessary: true,
			analytics: false,
			advertising: false,
			functional: false,
			personalization: false,
		},
		gpc,
	};
}

/** Build a decision from a category map (banner save). */
export function decisionFromCategories(
	categories: Partial< Record< ConsentCategory, boolean > >,
	gpc = false
): ConsentDecision {
	return {
		v: CONSENT_VERSION,
		ts: Date.now(),
		categories: {
			necessary: true,
			analytics: categories.analytics === true,
			advertising: categories.advertising === true,
			functional: categories.functional === true,
			personalization: categories.personalization === true,
		},
		gpc,
	};
}

/**
 * Parse a stored decision. Corrupt JSON, an old schema version, or a missing
 * category map all degrade to `null` (treated as "no decision" → denied).
 */
export function parseDecision( raw: string | null | undefined ): ConsentDecision | null {
	if ( ! raw ) {
		return null;
	}
	try {
		const parsed = JSON.parse( raw ) as Partial< ConsentDecision > | null;
		if ( ! parsed || parsed.v !== CONSENT_VERSION || ! parsed.categories || typeof parsed.categories !== 'object' ) {
			return null;
		}
		const decision = defaultDecision( parsed.gpc === true );
		for ( const category of CONSENT_CATEGORIES ) {
			decision.categories[ category ] = category === 'necessary' ? true : parsed.categories[ category ] === true;
		}
		decision.ts = typeof parsed.ts === 'number' ? parsed.ts : 0;
		return decision;
	} catch {
		return null;
	}
}

export function serializeDecision( decision: ConsentDecision ): string {
	return JSON.stringify( decision );
}

/** Is one category allowed by this decision? `necessary` is always true. */
export function isGranted( decision: ConsentDecision | null, category: ConsentCategory ): boolean {
	if ( category === 'necessary' ) {
		return true;
	}
	return decision?.categories[ category ] === true;
}

/**
 * The Consent Mode v2 signal map. GPC (or a decision recorded under GPC)
 * forces the three advertising signals back to denied regardless of storage —
 * the Global Privacy Control contract is an opt-out that outranks a stored
 * grant.
 */
export function gtagConsentMap( decision: ConsentDecision | null, gpcActive = false ): Record< string, 'granted' | 'denied' > {
	const gpc = gpcActive || decision?.gpc === true;
	const advertising = isGranted( decision, 'advertising' ) && ! gpc;
	return {
		ad_storage: advertising ? 'granted' : 'denied',
		ad_user_data: advertising ? 'granted' : 'denied',
		ad_personalization: advertising ? 'granted' : 'denied',
		analytics_storage: isGranted( decision, 'analytics' ) ? 'granted' : 'denied',
		functionality_storage: isGranted( decision, 'functional' ) ? 'granted' : 'denied',
		personalization_storage: isGranted( decision, 'personalization' ) ? 'granted' : 'denied',
		security_storage: 'granted',
	};
}

/** Map a detector vendor category onto a banner category. */
export function vendorConsentCategory( category: string ): Exclude< ConsentCategory, 'necessary' > {
	if ( category === 'advertising' ) {
		return 'advertising';
	}
	if ( category === 'functional' ) {
		return 'functional';
	}
	return 'analytics';
}

/** Does the visitor still need to be asked? (No stored decision at all.) */
export function needsDecision( decision: ConsentDecision | null ): boolean {
	return decision === null;
}

// ---------------------------------------------------------------------------
// tracking-audit.ts — the `forge verify --tracking` gate (Proposal 130 §3.3).
//
// Pure over the built HTML + the profile block, so it unit-tests without a
// build. The contract it enforces is the one that actually breaks in the
// wild: consent defaults must precede every tag, a deferred site must not
// carry a vendor <script src> in its static HTML, purchase events have
// exactly one owner, and the CSP allowlist tracks the enabled vendors.
// ---------------------------------------------------------------------------

import { TRACKING_VENDORS, cspHostsFor } from './tracking.ts';
import type { ForgeTracking } from './types.ts';

export interface TrackingFinding {
	code: string;
	severity: 'fail' | 'warn';
	message: string;
}

export interface AuditTrackingOptions {
	/** dist/ existence probe for verification proof files. */
	hasDistFile?: ( path: string ) => boolean;
}

/** Markers the generated layout emits (keep in sync with Tracking.astro). */
export const CONSENT_DEFAULT_ID = 'nvoos-consent-default';
export const TRACKING_EVENTS_ID = 'nvoos-tracking-events';

/** The `<script src>` for one vendor, if it exists in the static HTML. */
function vendorScriptIndex( html: string, hosts: RegExp[] ): number {
	for ( const host of hosts ) {
		const match = new RegExp( `<script[^>]+src=["'][^"']*(?:${ host.source })`, 'i' ).exec( html );
		if ( match ) {
			return match.index;
		}
	}
	return -1;
}

/** Parse the emitted events JSON (absent → null). */
function readEvents( html: string ): Record< string, unknown > | null {
	const match = new RegExp( `<script[^>]*id=["']${ TRACKING_EVENTS_ID }["'][^>]*>([\\s\\S]*?)</script>`, 'i' ).exec( html );
	if ( ! match ) {
		return null;
	}
	try {
		const parsed = JSON.parse( match[ 1 ].trim() );
		return parsed && typeof parsed === 'object' ? ( parsed as Record< string, unknown > ) : null;
	} catch {
		return null;
	}
}

/**
 * Audit the built document against the profile's tracking block.
 * `fail` is always a gate failure; `warn` only fails under --strict-tracking.
 */
export function auditTracking(
	tracking: ForgeTracking | undefined,
	html: string,
	options: AuditTrackingOptions = {}
): TrackingFinding[] {
	const findings: TrackingFinding[] = [];
	if ( ! tracking ) {
		return findings;
	}

	const push = ( code: string, severity: 'fail' | 'warn', message: string ) => findings.push( { code, severity, message } );
	const enabled = tracking.trackers.filter( ( tracker ) => tracker.enabled );
	const loadable = enabled.filter( ( tracker ) => tracker.category !== 'verification' && tracker.category !== 'server' );

	// --- verification -------------------------------------------------------
	const verification: Array< [ string, string, string ] > = [
		[ 'google', 'google-site-verification', tracking.verification?.google ?? '' ],
		[ 'bing', 'msvalidate.01', tracking.verification?.bing ?? '' ],
		[ 'yandex', 'yandex-verification', tracking.verification?.yandex ?? '' ],
		[ 'pinterest', 'p:domain_verify', tracking.verification?.pinterest ?? '' ],
		[ 'facebook', 'facebook-domain-verification', tracking.verification?.facebook ?? '' ],
	];
	for ( const [ key, metaName, value ] of verification ) {
		if ( ! value ) {
			continue;
		}
		if ( ! new RegExp( `<meta[^>]+name=["']${ metaName }["'][^>]+content=["']${ escapeRegExp( value ) }["']`, 'i' ).test( html ) ) {
			push( 'verification-meta-missing', 'fail', `Verification token "${ key }" is configured but the built HTML has no <meta name="${ metaName }">.` );
		}
	}
	for ( const file of tracking.verification?.files ?? [] ) {
		if ( options.hasDistFile && ! options.hasDistFile( file.path ) ) {
			push( 'verification-file-missing', 'fail', `Verification file ${ file.path } is configured but missing from dist/.` );
		}
	}

	if ( loadable.length === 0 ) {
		return findings;
	}

	// --- consent ordering ---------------------------------------------------
	const consentMode = tracking.consent?.mode ?? 'none';
	const strategy = tracking.consent?.strategy ?? 'basic';
	const consentIndex = html.indexOf( CONSENT_DEFAULT_ID );
	const firstTagIndex = loadable.reduce( ( earliest, tracker ) => {
		const spec = TRACKING_VENDORS.find( ( entry ) => entry.vendor === tracker.vendor );
		if ( ! spec?.hosts ) {
			return earliest;
		}
		const index = vendorScriptIndex( html, spec.hosts );
		return index === -1 ? earliest : earliest === -1 ? index : Math.min( earliest, index );
	}, -1 );

	if ( consentIndex === -1 ) {
		push( 'consent-default-missing', 'fail', `Trackers are enabled but the built HTML has no #${ CONSENT_DEFAULT_ID } consent bootstrap.` );
	} else if ( firstTagIndex !== -1 && firstTagIndex < consentIndex ) {
		push( 'tag-before-consent', 'fail', 'A vendor <script src> appears BEFORE the consent-default block — consent must be declared first.' );
	}

	if ( consentMode === 'none' ) {
		push( 'trackers-never-fire', 'warn', 'consent.mode is "none" while trackers are enabled — nothing will ever load. Set mode to "forge", "external-cmp" or "consent-mode-only".' );
	}
	if ( consentMode === 'external-cmp' && ! tracking.consent?.cmp?.script ) {
		push( 'cmp-script-missing', 'warn', 'consent.mode is "external-cmp" but no cmp.script is configured — the loader will stay denied.' );
	}

	// --- static-HTML purity (deferred + basic) ------------------------------
	const delivery = tracking.delivery?.mode ?? 'deferred';
	if ( delivery === 'deferred' && strategy === 'basic' && firstTagIndex !== -1 ) {
		push( 'tracker-in-static-html', 'fail', 'delivery is "deferred" with the "basic" strategy, but a vendor <script src> is in the static HTML — it must be injected only after consent.' );
	}
	if ( delivery === 'inline' && strategy === 'basic' ) {
		push( 'inline-basic-conflict', 'warn', '"inline" delivery with the "basic" strategy loads tags before consent; the loader is used instead at emission.' );
	}
	if ( delivery === 'partytown' || tracking.delivery?.partytown ) {
		push( 'partytown-unavailable', 'warn', 'Partytown is not bundled by the forge — deferred first-party loading is used instead.' );
	}

	// --- ownership / deprecation / duplication ------------------------------
	const events = readEvents( html );
	if ( events ) {
		if ( events.purchase === true && ( tracking.ecommerce?.purchaseSource ?? 'wordpress' ) === 'wordpress' ) {
			push( 'purchase-owned-by-wordpress', 'fail', 'purchaseSource is "wordpress" but the built bundle emits a purchase event — double counting.' );
		}
	}
	for ( const tracker of enabled.filter( ( entry ) => entry.deprecated ) ) {
		push( 'deprecated-tracker', 'fail', `${ tracker.vendor } is deprecated and must not be enabled.` );
	}
	const hasGtm = enabled.some( ( tracker ) => tracker.vendor === 'google-tag-manager' );
	const hasGa4 = enabled.some( ( tracker ) => tracker.vendor === 'google-analytics-4' );
	if ( hasGtm && hasGa4 ) {
		push( 'gtm-gtag-double', 'warn', 'GTM and a hardcoded GA4 tag are both enabled — page views are double-counted (GTM wins at emission).' );
	}

	// --- CSP ----------------------------------------------------------------
	const required = cspHostsFor( tracking.trackers );
	const declared = tracking.csp?.hosts ?? [];
	if ( required.length > 0 ) {
		const missing = required.filter( ( host ) => ! declared.includes( host ) );
		if ( missing.length > 0 ) {
			push( 'csp-host-missing', 'fail', `tracking.csp.hosts is missing ${ missing.join( ', ' ) } — the deploy header would block an enabled vendor.` );
		}
		const stale = declared.filter( ( host ) => ! required.includes( host ) );
		if ( stale.length > 0 ) {
			push( 'csp-host-stale', 'warn', `tracking.csp.hosts declares ${ stale.join( ', ' ) } for no enabled vendor — a stale allowlist is a real risk.` );
		}
	} else if ( declared.length > 0 ) {
		push( 'csp-host-stale', 'warn', 'tracking.csp.hosts is declared but no vendor is enabled.' );
	}

	return findings;
}

function escapeRegExp( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

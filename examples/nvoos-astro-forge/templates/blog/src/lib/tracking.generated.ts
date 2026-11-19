// ---------------------------------------------------------------------------
// tracking.generated.ts — placeholder shipped by the template. The scaffold
// overwrites this file when the site profile carries a `tracking` block
// (Proposal 130). With no block, `enabled: false` and Tracking.astro renders
// no tracking markup at all — the built HTML is identical to a forge without
// this feature.
//
// KEEP THIS IN SYNC with renderTrackingGenerated() in lib/scaffold.ts: a
// profile without a tracking block keeps this stub, so any field the template
// reads must be declared here or `astro check` fails on every untracked site.
// ---------------------------------------------------------------------------

export type TrackingConsentCategory = 'analytics' | 'advertising' | 'functional' | 'personalization';

export interface TrackingVendor {
	vendor: string;
	category: string;
	consentCategory: TrackingConsentCategory;
	/** Cookieless audience measurement — stores nothing on the device. */
	cookieless?: boolean;
	/** Operator-declared basis to load without a consent decision. */
	consentExempt?: boolean;
	ids: Record<string, string>;
}

export interface TrackingConfig {
	enabled: boolean;
	vendors: TrackingVendor[];
	consent: {
		mode: 'forge' | 'external-cmp' | 'consent-mode-only' | 'none';
		default: 'denied' | 'granted';
		strategy: 'basic' | 'advanced';
		waitForUpdate: number;
		gpc: boolean;
		cmp?: { vendor?: string; script?: string; cookieName?: string };
		policy?: { privacy?: string; cookies?: string };
		banner?: { position?: string; theme?: string };
		enforceCsp?: boolean;
	};
	verification?: Record<string, string>;
	/** SPA route measurement for the beacon; false for a static MPA build. */
	spa: boolean;
	delivery: { mode: 'deferred' | 'inline' | 'partytown' };
	ecommerce?: { events?: 'ga4' | 'none'; purchaseSource?: 'wordpress' | 'forge' };
}

export const tracking: TrackingConfig = {
	enabled: false,
	spa: false,
	vendors: [],
	consent: {
		mode: 'none',
		default: 'denied',
		strategy: 'basic',
		waitForUpdate: 500,
		gpc: true,
	},
	delivery: { mode: 'deferred' },
};

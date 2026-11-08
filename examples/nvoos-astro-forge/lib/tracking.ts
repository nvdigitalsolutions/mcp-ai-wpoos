// ---------------------------------------------------------------------------
// tracking.ts — the tracker & measurement capture pass (Proposal 130).
//
// Trackers never reach wp/v2: they are injected by the theme, by wp_head, or
// by WordPress plugins into the rendered document. This module reads that
// document (plus, optionally, a real browser's network log) and reports the
// measurement surface as FACTS — vendor, public id, category, confidence —
// so the scaffold can rebuild it with first-party, consent-gated delivery.
//
// It never executes vendor code, never clones a snippet, and never reads a
// cookie VALUE (probe mode records names only).
// ---------------------------------------------------------------------------

import { Parser } from 'htmlparser2';

import type {
	ForgeConsent,
	ForgeTracker,
	ForgeTracking,
	ForgeVerification,
	TrackerCategory,
} from './types.ts';

// -- vendor vocabulary -------------------------------------------------------

type CspDirective = 'script-src' | 'img-src' | 'connect-src';

interface VendorSpec {
	vendor: string;
	label: string;
	category: TrackerCategory;
	/** Script hosts (matched against <script src>). */
	hosts?: RegExp[];
	/** Inline call shapes (medium confidence when no script src matches). */
	inline?: RegExp[];
	/** Public id extractors — first capture group wins. */
	idPatterns?: Array<{ key: string; re: RegExp }>;
	/** Probe signals. */
	probeHosts?: string[];
	probeGlobals?: string[];
	cookieNames?: RegExp[];
	/** CSP hosts when this vendor is enabled. */
	csp?: Partial<Record<CspDirective, string[]>>;
	/** A tag with no runtime (verification) or a dead one (UA). */
	deprecated?: boolean;
	/** Cookieless analytics — still consent-gated in the EEA by default. */
	cookieless?: boolean;
}

const GA_CSP: Partial<Record<CspDirective, string[]>> = {
	'script-src': ['https://www.googletagmanager.com'],
	'img-src': ['https://*.google-analytics.com', 'https://www.googletagmanager.com', 'https://*.g.doubleclick.net'],
	'connect-src': [
		'https://*.google-analytics.com',
		'https://*.analytics.google.com',
		'https://www.googletagmanager.com',
		'https://stats.g.doubleclick.net',
	],
};

const META_CSP: Partial<Record<CspDirective, string[]>> = {
	'script-src': ['https://connect.facebook.net'],
	'img-src': ['https://www.facebook.com'],
	'connect-src': ['https://connect.facebook.net', 'https://www.facebook.com'],
};

/**
 * The fingerprint table. Data, not branches: adding a vendor is a row.
 * Order matters only for reporting (analytics first, then advertising).
 */
export const TRACKING_VENDORS: VendorSpec[] = [
	{
		vendor: 'google-tag-manager',
		label: 'Google Tag Manager',
		category: 'analytics',
		hosts: [/googletagmanager\.com\/gtm\.js/i],
		inline: [/['"]gtm\.start['"]\s*:/, /\bdataLayer\s*=\s*\[\]/],
		idPatterns: [{ key: 'containerId', re: /\b(GTM-[A-Z0-9]{4,10})\b/ }],
		probeHosts: ['googletagmanager.com/gtm.js'],
		probeGlobals: ['dataLayer'],
		csp: GA_CSP,
	},
	{
		vendor: 'google-analytics-4',
		label: 'Google Analytics 4',
		category: 'analytics',
		hosts: [/googletagmanager\.com\/gtag\/js/i],
		inline: [/\bgtag\(\s*['"]config['"]/, /['"]G-[A-Z0-9]{6,12}['"]/],
		idPatterns: [{ key: 'measurementId', re: /\b(G-[A-Z0-9]{6,12})\b/ }],
		probeHosts: ['google-analytics.com/g/collect', 'analytics.google.com/g/collect'],
		probeGlobals: ['gtag'],
		cookieNames: [/^_ga$/, /^_ga_/],
		csp: GA_CSP,
	},
	{
		vendor: 'google-tag',
		label: 'Google Tag',
		category: 'analytics',
		hosts: [/googletagmanager\.com\/gtag\/js\?id=GT-/i],
		idPatterns: [{ key: 'tagId', re: /\b(GT-[A-Z0-9]{6,12})\b/ }],
		csp: GA_CSP,
	},
	{
		vendor: 'google-ads',
		label: 'Google Ads',
		category: 'advertising',
		hosts: [/googletagmanager\.com\/gtag\/js\?id=AW-/i],
		inline: [/\bgtag\(\s*['"]config['"]\s*,\s*['"]AW-/, /conversion_linker/i],
		idPatterns: [{ key: 'conversionId', re: /\b(AW-\d{6,12})\b/ }],
		probeHosts: ['googleadservices.com', 'google.com/pagead'],
		csp: {
			...GA_CSP,
			'img-src': [...(GA_CSP['img-src'] ?? []), 'https://www.google.com', 'https://www.googleadservices.com'],
			'connect-src': [...(GA_CSP['connect-src'] ?? []), 'https://www.google.com', 'https://googleads.g.doubleclick.net'],
		},
	},
	{
		vendor: 'universal-analytics',
		label: 'Universal Analytics (deprecated)',
		category: 'analytics',
		hosts: [/google-analytics\.com\/analytics\.js/i],
		inline: [/\bUA-\d{4,12}-\d{1,4}\b/],
		idPatterns: [{ key: 'trackingId', re: /\b(UA-\d{4,12}-\d{1,4})\b/ }],
		deprecated: true,
		csp: GA_CSP,
	},
	{
		vendor: 'meta-pixel',
		label: 'Meta Pixel',
		category: 'advertising',
		hosts: [/connect\.facebook\.net\/[^"']*fbevents\.js/i],
		inline: [/\bfbq\(\s*['"]init['"]/, /['"]facebook-domain-verification['"]/],
		idPatterns: [{ key: 'pixelId', re: /\bfbq\(\s*['"]init['"]\s*,\s*['"](\d{10,20})['"]/ }],
		probeHosts: ['facebook.com/tr', 'connect.facebook.net'],
		probeGlobals: ['fbq'],
		cookieNames: [/^_fbp$/, /^_fbc$/],
		csp: META_CSP,
	},
	{
		vendor: 'tiktok-pixel',
		label: 'TikTok Pixel',
		category: 'advertising',
		hosts: [/analytics\.tiktok\.com/i],
		inline: [/\bttq\.(?:load|page)\s*\(/],
		idPatterns: [{ key: 'pixelId', re: /\b(C[A-Z0-9]{15,24})\b/ }],
		probeHosts: ['analytics.tiktok.com'],
		probeGlobals: ['ttq'],
		cookieNames: [/^_ttp$/],
		csp: {
			'script-src': ['https://analytics.tiktok.com'],
			'img-src': ['https://analytics.tiktok.com'],
			'connect-src': ['https://analytics.tiktok.com'],
		},
	},
	{
		vendor: 'linkedin-insight',
		label: 'LinkedIn Insight Tag',
		category: 'advertising',
		hosts: [/snap\.licdn\.com/i],
		inline: [/_linkedin_partner_id/],
		idPatterns: [{ key: 'partnerId', re: /_linkedin_partner_id\s*=\s*['"]?(\d{5,10})/ }],
		probeHosts: ['px.ads.linkedin.com', 'snap.licdn.com'],
		csp: {
			'script-src': ['https://snap.licdn.com'],
			'img-src': ['https://px.ads.linkedin.com'],
			'connect-src': ['https://px.ads.linkedin.com', 'https://www.linkedin.com'],
		},
	},
	{
		vendor: 'pinterest-tag',
		label: 'Pinterest Tag',
		category: 'advertising',
		hosts: [/s\.pinimg\.com\/ct\/core\.js/i],
		inline: [/\bpintrk\s*\(/],
		idPatterns: [{ key: 'tagId', re: /\bpintrk\(\s*['"]load['"]\s*,\s*['"](\d{10,16})['"]/ }],
		probeHosts: ['ct.pinterest.com'],
		probeGlobals: ['pintrk'],
		csp: {
			'script-src': ['https://s.pinimg.com'],
			'img-src': ['https://ct.pinterest.com'],
			'connect-src': ['https://ct.pinterest.com'],
		},
	},
	{
		vendor: 'x-pixel',
		label: 'X (Twitter) Pixel',
		category: 'advertising',
		hosts: [/static\.ads-twitter\.com/i],
		inline: [/\btwq\s*\(/],
		idPatterns: [{ key: 'pixelId', re: /\btwq\(\s*['"]config['"]\s*,\s*['"]([a-z0-9]{5,})['"]/ }],
		probeHosts: ['static.ads-twitter.com', 'analytics.twitter.com'],
		probeGlobals: ['twq'],
		csp: {
			'script-src': ['https://static.ads-twitter.com'],
			'img-src': ['https://t.co'],
			'connect-src': ['https://analytics.twitter.com'],
		},
	},
	{
		vendor: 'snap-pixel',
		label: 'Snap Pixel',
		category: 'advertising',
		hosts: [/sc-static\.net/i],
		inline: [/\bsnaptr\s*\(/],
		idPatterns: [{ key: 'pixelId', re: /\bsnaptr\(\s*['"]init['"]\s*,\s*['"]([0-9a-f-]{16,})['"]/ }],
		probeHosts: ['tr.snapchat.com', 'sc-static.net'],
		probeGlobals: ['snaptr'],
		csp: {
			'script-src': ['https://sc-static.net'],
			'img-src': ['https://tr.snapchat.com'],
			'connect-src': ['https://tr.snapchat.com'],
		},
	},
	{
		vendor: 'reddit-pixel',
		label: 'Reddit Pixel',
		category: 'advertising',
		hosts: [/redditstatic\.com\/ads\/pixel\.js/i],
		inline: [/\brdt\s*\(/],
		idPatterns: [{ key: 'pixelId', re: /\brdt\(\s*['"]init['"]\s*,\s*['"](t2_[A-Za-z0-9_]{4,})['"]/ }],
		probeHosts: ['alb.reddit.com'],
		probeGlobals: ['rdt'],
		csp: {
			'script-src': ['https://www.redditstatic.com'],
			'img-src': ['https://alb.reddit.com'],
			'connect-src': ['https://alb.reddit.com'],
		},
	},
	{
		vendor: 'bing-uet',
		label: 'Microsoft Advertising UET',
		category: 'advertising',
		hosts: [/bat\.bing\.com/i],
		inline: [/\buetq\b/, /\btiq\b/],
		idPatterns: [{ key: 'tagId', re: /\bti\s*=\s*['"]?(\d{6,10})/ }, { key: 'tagId', re: /\buetq\s*=\s*\[[^\]]*['"]?(\d{6,10})/ }],
		probeHosts: ['bat.bing.com'],
		probeGlobals: ['uetq'],
		cookieNames: [/^_uetsid$/, /^_uetvid$/],
		csp: {
			'script-src': ['https://bat.bing.com'],
			'img-src': ['https://bat.bing.com', 'https://bat.bing.net'],
			'connect-src': ['https://bat.bing.com'],
		},
	},
	{
		vendor: 'hotjar',
		label: 'Hotjar',
		category: 'session',
		hosts: [/static\.hotjar\.com/i],
		inline: [/\bhj\s*\(/],
		idPatterns: [{ key: 'siteId', re: /hotjar-(\d{4,10})\.js/ }, { key: 'siteId', re: /\bhjid\s*=\s*(\d{4,10})/ }],
		probeHosts: ['static.hotjar.com', 'in.hotjar.com'],
		probeGlobals: ['hj'],
		cookieNames: [/^_hjSession/, /^_hjAbsoluteSession/],
		csp: {
			'script-src': ['https://static.hotjar.com'],
			'img-src': ['https://in.hotjar.com'],
			'connect-src': ['https://in.hotjar.com', 'https://vc.hotjar.io'],
		},
	},
	{
		vendor: 'microsoft-clarity',
		label: 'Microsoft Clarity',
		category: 'session',
		hosts: [/clarity\.ms\/tag/i],
		inline: [/\bclarity\s*\(/],
		idPatterns: [{ key: 'projectId', re: /clarity\.ms\/tag\/([a-z0-9]{8,14})/ }],
		probeHosts: ['clarity.ms'],
		probeGlobals: ['clarity'],
		cookieNames: [/^_clck$/, /^_clsk$/],
		csp: {
			'script-src': ['https://www.clarity.ms'],
			'img-src': ['https://c.clarity.ms'],
			'connect-src': ['https://c.clarity.ms', 'https://www.clarity.ms'],
		},
	},
	{
		vendor: 'hubspot',
		label: 'HubSpot',
		category: 'analytics',
		hosts: [/js\.hs-scripts\.com/i, /js\.hs-analytics\.net/i],
		idPatterns: [{ key: 'portalId', re: /js\.hs-scripts\.com\/(\d{5,10})\.js/ }],
		probeHosts: ['js.hs-scripts.com', 'api.hsforms.com'],
		cookieNames: [/^__hstc$/, /^__hssc$/],
		csp: {
			'script-src': ['https://js.hs-scripts.com', 'https://js.hs-analytics.net'],
			'img-src': ['https://www.hubspot.com'],
			'connect-src': ['https://api.hsforms.com', 'https://forms.hsforms.com'],
		},
	},
	{
		vendor: 'klaviyo',
		label: 'Klaviyo',
		category: 'analytics',
		hosts: [/static\.klaviyo\.com/i],
		idPatterns: [{ key: 'companyId', re: /company_id=([A-Za-z0-9]{5,10})/ }],
		probeHosts: ['static.klaviyo.com', 'a.klaviyo.com'],
		probeGlobals: ['klaviyo'],
		cookieNames: [/^__kla_id$/],
		csp: {
			'script-src': ['https://static.klaviyo.com'],
			'img-src': ['https://a.klaviyo.com'],
			'connect-src': ['https://a.klaviyo.com', 'https://static.klaviyo.com'],
		},
	},
	{
		vendor: 'matomo',
		label: 'Matomo',
		category: 'analytics',
		hosts: [/matomo\.js/i, /piwik\.js/i],
		inline: [/\b_paq\b/],
		idPatterns: [{ key: 'siteId', re: /setSiteId\(\s*['"]?(\d{1,6})/ }],
		probeGlobals: ['_paq'],
		cookieNames: [/^_pk_id/, /^_pk_ses/],
		// Self-hosted: the host is the site's own origin — no CSP entry.
	},
	{
		vendor: 'plausible',
		label: 'Plausible',
		category: 'analytics',
		hosts: [/plausible\.io\/js\//i],
		probeHosts: ['plausible.io/api/event'],
		cookieless: true,
		csp: { 'script-src': ['https://plausible.io'], 'connect-src': ['https://plausible.io'] },
	},
	{
		vendor: 'fathom',
		label: 'Fathom',
		category: 'analytics',
		hosts: [/cdn\.usefathom\.com/i],
		probeHosts: ['cdn.usefathom.com'],
		cookieless: true,
		csp: { 'script-src': ['https://cdn.usefathom.com'], 'connect-src': ['https://cdn.usefathom.com'] },
	},
	{
		vendor: 'cloudflare-web-analytics',
		label: 'Cloudflare Web Analytics',
		category: 'analytics',
		hosts: [/cloudflareinsights\.com\/beacon/i],
		probeHosts: ['cloudflareinsights.com'],
		cookieless: true,
		csp: { 'script-src': ['https://static.cloudflareinsights.com'], 'connect-src': ['https://cloudflareinsights.com'] },
	},
	{
		vendor: 'cloudflare-zaraz',
		label: 'Cloudflare Zaraz',
		category: 'server',
		inline: [ /"zaraz"/, /\bzaraz\b/ ],
		probeHosts: [ '/cdn-cgi/zaraz/' ],
	},
	// Consent management platforms — recorded so the rebuild can hand off.
	{
		vendor: 'cookiebot',
		label: 'Cookiebot',
		category: 'consent',
		hosts: [/consent\.cookiebot\.com/i],
		probeHosts: ['consent.cookiebot.com'],
		cookieNames: [/^CookieConsent$/],
	},
	{
		vendor: 'onetrust',
		label: 'OneTrust',
		category: 'consent',
		hosts: [/cdn\.cookielaw\.org/i, /optanon/i],
		probeGlobals: ['OneTrust'],
		cookieNames: [/^OptanonConsent$/, /^OptanonAlertBoxClosed$/],
	},
	{
		vendor: 'cookieyes',
		label: 'CookieYes',
		category: 'consent',
		hosts: [/cdn-cookieyes\.com/i, /cookieyes/i],
		probeGlobals: ['cookieyes'],
		cookieNames: [/^cookieyes-consent$/],
	},
	{
		vendor: 'complianz',
		label: 'Complianz',
		category: 'consent',
		inline: [/cmplz/i],
		cookieNames: [/^cmplz_/],
	},
	{
		vendor: 'osano',
		label: 'Osano',
		category: 'consent',
		hosts: [/cmp\.osano\.com/i],
		probeGlobals: ['Osano'],
		cookieNames: [/^osano_consentmanager/],
	},
	{
		vendor: 'iubenda',
		label: 'iubenda',
		category: 'consent',
		hosts: [/cdn\.iubenda\.com/i],
		probeGlobals: ['_iub'],
		cookieNames: [/^_iub_cs-/],
	},
	{
		vendor: 'termly',
		label: 'Termly',
		category: 'consent',
		hosts: [/app\.termly\.io/i],
		cookieNames: [/^termly-/],
	},
	{
		vendor: 'usercentrics',
		label: 'Usercentrics',
		category: 'consent',
		hosts: [/usercentrics\.eu/i],
		probeGlobals: ['UC_UI'],
		cookieNames: [/^uc_settings$/, /^uc_user_interaction$/],
	},
	{
		vendor: 'didomi',
		label: 'Didomi',
		category: 'consent',
		hosts: [/sdk\.privacy-center\.org/i, /didomi/i],
		probeGlobals: ['Didomi'],
		cookieNames: [/^didomi_token$/],
	},
];

/** The verification meta names the detector reads (files have no meta tag). */
const VERIFICATION_META: Record< Exclude< keyof ForgeVerification, 'files' >, string > = {
	google: 'google-site-verification',
	bing: 'msvalidate.01',
	yandex: 'yandex-verification',
	pinterest: 'p:domain_verify',
	facebook: 'facebook-domain-verification',
};

// -- HTML signals ------------------------------------------------------------

export interface TrackingHtmlSignals {
	/** Absolute or relative <script src> values, document order. */
	scriptSrcs: string[];
	/** Concatenated inline <script> bodies (bounded by the caller's HTML). */
	inlineJs: string;
	/** <meta name="…" content="…"> pairs (lower-cased name). */
	metas: Record<string, string>;
	/** <noscript><img src> — the JS-disabled pixel convention. */
	noscriptImgSrcs: string[];
	/** <link rel="icon">/manifest hrefs are ignored; kept for future use. */
	links: string[];
}

/** Parse one rendered document into the raw signals the detector consumes. */
export function scanTrackingHtml( html: string ): TrackingHtmlSignals {
	const signals: TrackingHtmlSignals = {
		scriptSrcs: [],
		inlineJs: '',
		metas: {},
		noscriptImgSrcs: [],
		links: [],
	};
	let inScript = false;
	let inNoscript = false;
	const inline: string[] = [];

	const parser = new Parser(
		{
			onopentag( name, attribs ) {
				if ( name === 'script' ) {
					if ( attribs.src ) {
						signals.scriptSrcs.push( attribs.src );
					} else {
						inScript = true;
					}
					return;
				}
				if ( name === 'meta' && attribs.name && attribs.content ) {
					signals.metas[ attribs.name.toLowerCase() ] = attribs.content;
					return;
				}
				if ( name === 'noscript' ) {
					inNoscript = true;
					return;
				}
				if ( name === 'img' && inNoscript && attribs.src ) {
					signals.noscriptImgSrcs.push( attribs.src );
					return;
				}
				if ( name === 'link' && attribs.href ) {
					signals.links.push( attribs.href );
				}
			},
			ontext( text ) {
				if ( inScript ) {
					inline.push( text );
				}
			},
			onclosetag( name ) {
				if ( name === 'script' ) {
					inScript = false;
				}
				if ( name === 'noscript' ) {
					inNoscript = false;
				}
			},
		},
		{ decodeEntities: true, lowerCaseTags: true, lowerCaseAttributeNames: true }
	);
	parser.write( html );
	parser.end();

	// The inline bundle is bounded — a hostile document must not blow memory.
	signals.inlineJs = inline.join( '\n' ).slice( 0, 512 * 1024 );
	return signals;
}

// -- probe (optional, browser-backed) ---------------------------------------

export interface TrackingProbeFacts {
	/** Every request URL seen while the page settled. */
	requests: string[];
	/** Global stubs that exist after load (window.gtag, window.fbq, …). */
	globals: Record<string, boolean>;
	/** Cookie NAMES only — never values. */
	cookies: string[];
}

type PlaywrightChromium = typeof import('playwright').chromium;

async function loadChromium(): Promise< PlaywrightChromium > {
	try {
		const playwright = await import('playwright');
		return playwright.chromium;
	} catch {
		throw new Error( 'The tracking probe needs Playwright. Install it in the forge (npm i -D playwright).' );
	}
}

const PROBE_UA =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/**
 * Load the page in a real browser and record the network + global surface.
 * This is the only way to see tags injected by a tag manager, an edge worker,
 * or a CMP. Fail-soft by design: the caller falls back to HTML-only.
 */
export async function probeTracking(
	url: string,
	options: { timeoutMs?: number; settleMs?: number } = {}
): Promise< TrackingProbeFacts > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { headless: true } );
	try {
		const context = await browser.newContext( { userAgent: PROBE_UA } );
		const page = await context.newPage();
		const requests = new Set< string >();
		page.on( 'request', ( request ) => {
			requests.add( request.url() );
		} );
		await page.goto( url, { waitUntil: 'domcontentloaded', timeout: options.timeoutMs ?? 25_000 } );
		await page.waitForTimeout( options.settleMs ?? 1_800 );
		const facts = await page.evaluate( () => {
			const win = window as unknown as Record< string, unknown >;
			const names = [
				'dataLayer', 'gtag', 'fbq', 'ttq', 'pintrk', 'twq', 'snaptr', 'rdt',
				'uetq', 'hj', 'clarity', '_paq', 'klaviyo', 'OneTrust', 'Didomi', 'Osano',
			];
			const globals: Record< string, boolean > = {};
			for ( const name of names ) {
				globals[ name ] = typeof win[ name ] !== 'undefined';
			}
			const cookies = document.cookie
				.split( ';' )
				.map( ( entry ) => entry.split( '=' )[ 0 ].trim() )
				.filter( Boolean );
			return { globals, cookies };
		} );
		return {
			requests: [ ...requests ],
			globals: facts.globals,
			cookies: facts.cookies,
		};
	} finally {
		await browser.close();
	}
}

// -- detection ---------------------------------------------------------------

export interface TrackingReport {
	url: string;
	pagesAnalyzed: string[];
	trackers: ForgeTracker[];
	consent: {
		detected: boolean;
		defaultGated: boolean;
		cmp?: { vendor?: string; script?: string; cookieName?: string };
	};
	verification: ForgeVerification;
	warnings: string[];
}

function absolute( value: string, base: string ): string | undefined {
	try {
		const url = new URL( value, base );
		return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : undefined;
	} catch {
		return undefined;
	}
}

function matchesAny( values: string[], patterns: RegExp[] ): string | undefined {
	for ( const value of values ) {
		for ( const pattern of patterns ) {
			if ( pattern.test( value ) ) {
				return value;
			}
		}
	}
	return undefined;
}

interface Accumulator {
	spec: VendorSpec;
	detectedBy: string[];
	confidence: 'high' | 'medium' | 'low';
	via: 'html' | 'probe';
	ids: Record< string, string >;
	script?: string;
}

const CONFIDENCE_RANK: Record< 'high' | 'medium' | 'low', number > = { low: 0, medium: 1, high: 2 };

/** Scan the accumulated HTML signals for every vendor in the table. */
export function detectTrackers(
	signals: TrackingHtmlSignals,
	baseUrl: string,
	probe?: TrackingProbeFacts
): { trackers: ForgeTracker[]; consent: TrackingReport['consent']; verification: ForgeVerification; warnings: string[] } {
	const found = new Map< string, Accumulator >();
	const warnings: string[] = [];
	const scriptSrcs = signals.scriptSrcs.map( ( src ) => absolute( src, baseUrl ) ?? src );
	const probeRequests = probe?.requests ?? [];

	const record = ( spec: VendorSpec, detectedBy: string, confidence: 'high' | 'medium' | 'low', via: 'html' | 'probe', script?: string ) => {
		const existing = found.get( spec.vendor );
		if ( ! existing ) {
			found.set( spec.vendor, { spec, detectedBy: [ detectedBy ], confidence, via, ids: {}, script } );
			return;
		}
		existing.detectedBy.push( detectedBy );
		if ( CONFIDENCE_RANK[ confidence ] > CONFIDENCE_RANK[ existing.confidence ] ) {
			existing.confidence = confidence;
		}
		existing.via = via;
		existing.script = existing.script ?? script;
	};

	for ( const spec of TRACKING_VENDORS ) {
		const scriptHit = spec.hosts ? matchesAny( scriptSrcs, spec.hosts ) : undefined;
		if ( scriptHit ) {
			record( spec, `script:${ hostOf( scriptHit ) ?? scriptHit }`, 'high', 'html', scriptHit );
		} else if ( spec.inline && matchesAny( [ signals.inlineJs ], spec.inline ) ) {
			record( spec, 'inline:call-shape', 'medium', 'html' );
		}

		const probeHit = spec.probeHosts
			? probeRequests.find( ( request ) => spec.probeHosts!.some( ( host ) => request.includes( host ) ) )
			: undefined;
		if ( probeHit ) {
			record( spec, `probe:request:${ hostOf( probeHit ) ?? probeHit }`, 'high', 'probe' );
		} else if ( spec.probeGlobals?.some( ( name ) => probe?.globals[ name ] ) ) {
			record( spec, 'probe:global', 'medium', 'probe' );
		}

		const cookieHit = spec.cookieNames?.some( ( pattern ) => probe?.cookies.some( ( name ) => pattern.test( name ) ) );
		if ( cookieHit ) {
			record( spec, 'probe:cookie-name', 'medium', 'probe' );
		}
	}

	// Id extraction over the whole evidence corpus.
	const corpus = [ scriptSrcs.join( '\n' ), signals.inlineJs ].join( '\n' );
	const trackers: ForgeTracker[] = [];
	for ( const entry of found.values() ) {
		const ids: Record< string, string > = {};
		for ( const pattern of entry.spec.idPatterns ?? [] ) {
			// Path-based ids (hotjar-123.js, clarity.ms/tag/abc) live in the URL.
			const match = pattern.re.exec( `${ scriptSrcs.join( '\n' ) }\n${ corpus }` );
			if ( match && match[ 1 ] ) {
				ids[ pattern.key ] = match[ 1 ];
			}
		}
		// Script-only vendors (Plausible/Fathom/CF analytics) need the URL to load.
		// Vendors WITH id extractors keep an empty id set so the report can flag
		// the missing id (a script URL would not help their loader).
		if ( entry.script && ( entry.spec.idPatterns ?? [] ).length === 0 && Object.keys( ids ).length === 0 ) {
			ids.script = entry.script;
		}
		const notes: string[] = [];
		let confidence = entry.confidence;
		if ( entry.spec.category !== 'consent' && entry.spec.vendor !== 'google-tag-manager' && Object.keys( ids ).length === 0 ) {
			notes.push( 'Vendor detected but no public id could be extracted — confirm it against the source before enabling.' );
			confidence = 'low';
		}
		if ( entry.spec.deprecated ) {
			notes.push( 'Deprecated vendor (Universal Analytics stopped processing in 2023) — reported, never rebuilt.' );
		}
		if ( entry.spec.cookieless ) {
			notes.push( 'Cookieless analytics is still consent-gated in the EEA; the UK DUAA 2025 exception is narrow. Promote to exempt only with a documented legal basis.' );
		}
		trackers.push( {
			vendor: entry.spec.vendor,
			category: entry.spec.category,
			enabled: false,
			ids,
			detectedBy: [ ...new Set( entry.detectedBy ) ],
			confidence,
			detectedVia: entry.via,
			...( entry.spec.deprecated ? { deprecated: true } : {} ),
			...( notes.length > 0 ? { notes } : {} ),
		} );
	}
	trackers.sort( ( a, b ) => a.vendor.localeCompare( b.vendor ) );

	// Verification tokens from the meta tags.
	const verification: ForgeVerification = {};
	for ( const [ key, metaName ] of Object.entries( VERIFICATION_META ) as Array< [ Exclude< keyof ForgeVerification, 'files' >, string ] > ) {
		const value = signals.metas[ metaName ];
		if ( value ) {
			verification[ key ] = value;
		}
	}

	// Consent layer.
	const cmpTracker = trackers.find( ( tracker ) => tracker.category === 'consent' );
	const cmpScript = cmpTracker
		? scriptSrcs.find( ( src ) => TRACKING_VENDORS.find( ( spec ) => spec.vendor === cmpTracker.vendor )?.hosts?.some( ( host: RegExp ) => host.test( src ) ) )
		: undefined;
	const consent = {
		detected: Boolean( cmpTracker ),
		defaultGated: Boolean( cmpTracker ),
		...( cmpTracker
			? {
					cmp: {
						vendor: cmpTracker.vendor,
						...( cmpScript ? { script: cmpScript } : {} ),
						...( probe?.cookies.find( ( name ) => /consent/i.test( name ) ) ? { cookieName: probe.cookies.find( ( name ) => /consent/i.test( name ) ) } : {} ),
					},
			  }
			: {} ),
	};

	if ( trackers.some( ( tracker ) => tracker.deprecated ) ) {
		warnings.push( 'Universal Analytics detected — it stopped processing in July 2023 and is not rebuilt.' );
	}
	if ( cmpTracker && trackers.some( ( tracker ) => tracker.category !== 'consent' ) ) {
		warnings.push( `The source runs a consent layer (${ cmpTracker.vendor }) — the rebuild must ship one too (mode: external-cmp or forge).` );
	}
	if ( ! cmpTracker && trackers.some( ( tracker ) => tracker.category === 'analytics' || tracker.category === 'advertising' ) ) {
		warnings.push( 'Trackers found with no consent layer on the source — the forged site defaults to denied and will not fire them until a visitor opts in.' );
	}
	const gtm = trackers.find( ( tracker ) => tracker.vendor === 'google-tag-manager' );
	if ( gtm && trackers.some( ( tracker ) => tracker.vendor === 'google-analytics-4' ) ) {
		warnings.push( 'Both a tag manager and a hardcoded GA4 tag were found — enabling both double-counts page views (GTM wins at emission).' );
	}

	return { trackers, consent, verification, warnings };
}

function hostOf( value: string ): string | undefined {
	try {
		return new URL( value ).hostname.toLowerCase();
	} catch {
		return undefined;
	}
}

// -- analysis driver ---------------------------------------------------------

export interface AnalyzeTrackingOptions {
	url: string;
	fetchImpl?: typeof fetch;
	/** Reuse a document already fetched by --design (avoids a second fetch). */
	html?: string;
	/** Additional representative pages (product/post) to scan. */
	pageUrls?: string[];
	/** Run the browser probe (network + globals + cookie names). */
	probe?: boolean;
}

const MAX_TRACKING_PAGES = 4;

async function fetchDocument( url: string, fetchImpl: typeof fetch ): Promise< string | undefined > {
	try {
		const res = await fetchImpl( url, { redirect: 'follow', signal: AbortSignal.timeout( 20_000 ) } );
		if ( ! res.ok ) {
			return undefined;
		}
		return ( await res.text() ).slice( 0, 3 * 1024 * 1024 );
	} catch {
		return undefined;
	}
}

/** Analyse the source's measurement surface and return the report. */
export async function analyzeTracking( options: AnalyzeTrackingOptions ): Promise< TrackingReport > {
	const base = options.url.replace( /\/+$/, '' );
	const fetchImpl = options.fetchImpl ?? fetch;
	const pages: Array< { url: string; html: string } > = [];

	if ( options.html ) {
		pages.push( { url: base, html: options.html } );
	} else {
		const home = await fetchDocument( base, fetchImpl );
		if ( ! home ) {
			throw new Error( `Tracking analysis failed: ${ base } did not return HTML.` );
		}
		pages.push( { url: base, html: home } );
	}

	for ( const pageUrl of ( options.pageUrls ?? [] ).slice( 0, MAX_TRACKING_PAGES - 1 ) ) {
		if ( pages.some( ( page ) => page.url === pageUrl ) ) {
			continue;
		}
		const html = await fetchDocument( pageUrl, fetchImpl );
		if ( html ) {
			pages.push( { url: pageUrl, html } );
		}
	}

	const warnings: string[] = [];
	let probe: TrackingProbeFacts | undefined;
	if ( options.probe ) {
		try {
			probe = await probeTracking( base );
		} catch ( error ) {
			warnings.push( `Browser probe skipped: ${ error instanceof Error ? error.message : String( error ) }` );
		}
	}

	// Merge signals across pages: a tracker that only mounts on a product page
	// still belongs to the site.
	const merged: TrackingHtmlSignals = { scriptSrcs: [], inlineJs: '', metas: {}, noscriptImgSrcs: [], links: [] };
	for ( const page of pages ) {
		const signals = scanTrackingHtml( page.html );
		merged.scriptSrcs.push( ...signals.scriptSrcs );
		merged.inlineJs += `\n${ signals.inlineJs }`;
		merged.metas = { ...merged.metas, ...signals.metas };
		merged.noscriptImgSrcs.push( ...signals.noscriptImgSrcs );
		merged.links.push( ...signals.links );
	}
	merged.scriptSrcs = [ ...new Set( merged.scriptSrcs ) ];

	const detected = detectTrackers( merged, base, probe );
	if ( merged.noscriptImgSrcs.length > 0 ) {
		warnings.push(
			'<noscript> tracking pixels were found — they fire for JS-disabled visitors with no way to withhold consent, so they are not rebuilt.'
		);
	}
	return {
		url: base,
		pagesAnalyzed: pages.map( ( page ) => page.url ),
		trackers: detected.trackers,
		consent: detected.consent,
		verification: detected.verification,
		warnings: [ ...detected.warnings, ...warnings ],
	};
}

// -- report → profile --------------------------------------------------------

export function consentCategoryFor( category: TrackerCategory ): 'analytics' | 'advertising' | 'functional' {
	if ( category === 'advertising' ) {
		return 'advertising';
	}
	return 'analytics';
}

/** Flatten the enabled vendors' CSP hosts into one de-duplicated list. */
export interface CspDirectives {
	'script-src': string[];
	'img-src': string[];
	'connect-src': string[];
}

/**
 * Per-directive CSP hosts for the ENABLED vendors only (Proposal 130 §2.11).
 * GA4 regional collection hits need the `*.google-analytics.com` wildcard, and
 * `www.googletagmanager.com` is required in script-src, img-src AND
 * connect-src — so the table carries directives, never a flat host list.
 * `extraOrigins` (the WordPress origin) covers the forms bridge POST and the
 * CDN image passthrough.
 */
export function cspDirectivesFor( trackers: ForgeTracker[], extraOrigins: string[] = [] ): CspDirectives {
	const out: CspDirectives = { 'script-src': [], 'img-src': [], 'connect-src': [] };
	for ( const tracker of trackers ) {
		if ( ! tracker.enabled ) {
			continue;
		}
		const spec = TRACKING_VENDORS.find( ( entry ) => entry.vendor === tracker.vendor );
		for ( const directive of Object.keys( out ) as Array< keyof CspDirectives > ) {
			for ( const host of spec?.csp?.[ directive ] ?? [] ) {
				if ( ! out[ directive ].includes( host ) ) {
					out[ directive ].push( host );
				}
			}
		}
	}
	for ( const origin of extraOrigins ) {
		if ( ! out[ 'img-src' ].includes( origin ) ) {
			out[ 'img-src' ].push( origin );
		}
		if ( ! out[ 'connect-src' ].includes( origin ) ) {
			out[ 'connect-src' ].push( origin );
		}
	}
	for ( const directive of Object.keys( out ) as Array< keyof CspDirectives > ) {
		out[ directive ].sort();
	}
	return out;
}

/** The flat union (the profile's `csp.hosts`; the audit diffs against it). */
export function cspHostsFor( trackers: ForgeTracker[] ): string[] {
	const directives = cspDirectivesFor( trackers );
	return [ ...new Set( [ ...directives[ 'script-src' ], ...directives[ 'img-src' ], ...directives[ 'connect-src' ] ] ) ].sort();
}

export interface TrackingDraftOptions {
	/** The source sells — seed the commerce event map. */
	hasCommerce?: boolean;
}

/**
 * Convert a report into a profile block. Detection NEVER enables a tracker:
 * every entry arrives disabled and the operator promotes it explicitly.
 */
export function trackingFromReport( report: TrackingReport, options: TrackingDraftOptions = {} ): ForgeTracking {
	const trackers: ForgeTracker[] = report.trackers
		.filter( ( tracker ) => tracker.category !== 'consent' )
		.map( ( tracker ) => ( { ...tracker, enabled: false } ) );
	const consent: ForgeConsent = {
		mode: report.consent.detected ? 'external-cmp' : 'none',
		default: 'denied',
		strategy: 'basic',
		waitForUpdate: 500,
		gpc: true,
		...( report.consent.cmp ? { cmp: report.consent.cmp } : {} ),
	};
	const verification = Object.keys( report.verification ).length > 0 ? report.verification : undefined;
	return {
		trackers,
		consent,
		...( verification ? { verification } : {} ),
		delivery: { mode: 'deferred' },
		...( options.hasCommerce ? { ecommerce: { events: 'ga4' as const, purchaseSource: 'wordpress' as const } } : {} ),
		csp: { hosts: cspHostsFor( trackers ) },
		analyzedAt: new Date().toISOString(),
		pagesAnalyzed: report.pagesAnalyzed,
	};
}

/**
 * Merge a fresh draft into an existing block (118 §3 discipline): the
 * operator's decisions survive — enabled flags, the whole consent block,
 * delivery/ecommerce choices, and any operator-declared tracker the source
 * no longer shows.
 */
export function mergeTrackingDraft( existing: ForgeTracking | undefined, fresh: ForgeTracking ): ForgeTracking {
	if ( ! existing ) {
		return fresh;
	}
	const merged = new Map< string, ForgeTracker >();
	for ( const tracker of fresh.trackers ) {
		const prior = existing.trackers.find( ( entry ) => entry.vendor === tracker.vendor );
		merged.set( tracker.vendor, prior ? { ...tracker, enabled: prior.enabled, ...( prior.notes ? { notes: [ ...new Set( [ ...( tracker.notes ?? [] ), ...prior.notes ] ) ] } : {} ) } : tracker );
	}
	for ( const tracker of existing.trackers ) {
		if ( ! merged.has( tracker.vendor ) ) {
			merged.set( tracker.vendor, tracker );
		}
	}
	const trackers = [ ...merged.values() ];
	const verification: ForgeVerification = { ...fresh.verification, ...existing.verification };
	return {
		trackers,
		consent: existing.consent,
		...( Object.keys( verification ).length > 0 ? { verification } : {} ),
		delivery: existing.delivery ?? fresh.delivery,
		ecommerce: existing.ecommerce ?? fresh.ecommerce,
		csp: { hosts: cspHostsFor( trackers ) },
		analyzedAt: fresh.analyzedAt,
		pagesAnalyzed: fresh.pagesAnalyzed,
	};
}

// -- console report ----------------------------------------------------------

/** Human-readable summary for `forge analyze --tracking`. */
export function renderTrackingReport( report: TrackingReport ): string {
	const lines: string[] = [];
	lines.push( `Tracking — ${ report.url } (${ report.pagesAnalyzed.length } page(s) analyzed)` );
	if ( report.trackers.length === 0 ) {
		lines.push( '  no trackers, pixels or verification tokens detected.' );
	}
	for ( const tracker of report.trackers ) {
		const ids = Object.entries( tracker.ids )
			.map( ( [ key, value ] ) => `${ key }=${ maskId( value ) }` )
			.join( ' ' );
		lines.push(
			`  ${ tracker.vendor.padEnd( 24 ) } ${ tracker.category.padEnd( 11 ) } ${ tracker.confidence.padEnd( 6 ) } ${ ids || '—' }`
		);
	}
	if ( report.consent.detected ) {
		lines.push( `  consent layer: ${ report.consent.cmp?.vendor ?? 'detected' } (the rebuild must ship one)` );
	}
	const verification = Object.entries( report.verification ).filter( ( [ key ] ) => key !== 'files' );
	if ( verification.length > 0 ) {
		lines.push( `  verification: ${ verification.map( ( [ key ] ) => key ).join( ', ' ) }` );
	}
	for ( const warning of report.warnings ) {
		lines.push( `  ! ${ warning }` );
	}
	return lines.join( '\n' );
}

/** Ids are public but customer-identifying — keep the middle out of logs. */
export function maskId( value: string ): string {
	if ( value.length <= 8 ) {
		return value;
	}
	return `${ value.slice( 0, 4 ) }…${ value.slice( -4 ) }`;
}

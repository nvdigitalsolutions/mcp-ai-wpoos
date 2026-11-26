// SPDX-License-Identifier: MIT
/**
 * @fileoverview Decides what a BUILT forged site publishes while the CMS is gated.
 *
 * Invariant: a bridge failure or an unreadable verdict publishes the real site,
 * never a gate nobody asked for. Never opens a manager session — publication
 * state is a public fact, so this module needs no credentials.
 */
// ---------------------------------------------------------------------------
// gate-publish.ts — the BUILT site's publication state (Proposal 165).
//
// Distinct from `gate.ts`: that module answers "how does the FORGE get in to a
// gated source?". This one answers "how must the GENERATED site behave while
// the CMS is gated?" — the Elementor parity feature. The verdict is resolved
// once at scaffold time (or supplied by `--gate`) and written to
// `gate.generated.json`, which the managed front controller reads at boot and
// then polls.
//
// Three rules the whole module obeys:
//
//   1. **Fail OPEN to the real site.** A bridge or network failure publishes
//      the site; it never builds a gate nobody asked for. The runtime's own
//      fallback is the same (see gate-runtime.ts).
//   2. **The manifest carries no secret.** Cookie NAMES and TTLs are recorded;
//      the bypass token and the session HMAC are derived at runtime from
//      NVOOS_FORGE_GATE_SECRET / NVOOS_MANAGE_SECRET.
//   3. **`honored: false` means "record the verdict, publish the site".** The
//      `honor` policy can decline a mode (`coming_soon-only` refuses
//      maintenance); the manifest then keeps the evidence but names no page,
//      and the runtime treats it as off.
//
// REBUILD NOTE (2026-11-26): this module was lost with the rest of the
// uncommitted tree and rebuilt from its call sites (`scaffold.ts`,
// `scripts/forge.mjs`), the `GatePublication` / `ForgeGatePublish` contracts
// in `types.ts`, and `gate.ts`'s `GateProbe`. Behaviour is pinned by
// `tests/gate-publish.test.ts`.
// ---------------------------------------------------------------------------

import { probeGate, type GateProbe } from './gate.ts';
import type { ForgeGatePublish, GateMode, GatePublication, SiteProfile } from './types.ts';

/** The resolved, non-secret runtime configuration the artifact carries. */
export interface ResolvedGateConfig {
	/** The signed-in preview (the Elementor behaviour). */
	session: { bypass: boolean; path: string; cookieName: string };
	/** The operator preview link (`/?nvoos-gate=<token>`). */
	bypass: { ttlSeconds: number; cookieName: string };
	/** The managed runtime's poll loop. */
	managed: { enabled: boolean; pollSeconds: number; maxStalenessSeconds: number };
	/** `Retry-After` on a 503 (seconds, 1..86400). */
	retryAfterSeconds: number;
	/** What a STATIC publish does with the artifact. */
	policy: 'replace' | 'keep';
	/** Which CMS modes the artifact honours. */
	honor: 'auto' | 'coming_soon-only' | 'off';
	/** Where a verdict may come from. */
	trust: 'bridge' | 'probe';
	/** The gate page's own copy (already sanitised by the caller). */
	page: NonNullable< ForgeGatePublish[ 'page' ] >;
	/** Whether the coming-soon page may be indexed. */
	comingSoonIndexable: boolean;
}

/** The artifact's `gate.generated.json` — the verdict PLUS the runtime config. */
export interface GateManifest extends GatePublication {
	session: ResolvedGateConfig[ 'session' ];
	bypass: ResolvedGateConfig[ 'bypass' ];
	managed: ResolvedGateConfig[ 'managed' ];
	retryAfterSeconds: number;
	honor: ResolvedGateConfig[ 'honor' ];
	trust: ResolvedGateConfig[ 'trust' ];
}

const DEFAULT_SESSION_PATH = '/wp-json/nvoos-commerce/v1/auth/me';
const DEFAULT_COOKIE = 'nvoos_forge_session';
const DEFAULT_BYPASS_COOKIE = 'nvoos_gate';
const DEFAULT_BYPASS_TTL = 43_200; // 12 hours
const DEFAULT_RETRY_AFTER = 600;
const MAX_RETRY_AFTER = 86_400;

/** Coerce a number into a sane band, falling back when it is not one. */
function bounded( value: unknown, fallback: number, min: number, max: number ): number {
	const number = Number( value );
	if ( ! Number.isFinite( number ) ) {
		return fallback;
	}
	return Math.min( max, Math.max( min, Math.floor( number ) ) );
}

/** Normalise a CMS mode string (`coming-soon`, `Coming Soon`, …). */
export function normalizeGateMode( value: unknown ): GateMode {
	const mode = String( value ?? '' )
		.trim()
		.toLowerCase()
		.replace( /[-\s]+/g, '_' );
	if ( mode === 'maintenance' || mode === 'coming_soon' ) {
		return mode;
	}
	return 'off';
}

/**
 * The runtime configuration a profile resolves to.
 *
 * Precedence is profile → default for every field, so an absent `gatePublish`
 * block is indistinguishable from one that names only what it changes.
 */
export function resolveGateConfig(
	profile: Pick< SiteProfile, 'gatePublish' >,
	env: NodeJS.ProcessEnv = process.env
): ResolvedGateConfig {
	const block = profile.gatePublish ?? {};
	const session = block.session ?? {};
	const bypass = block.bypass ?? {};
	const managed = block.managed ?? {};
	const page = block.page ?? {};
	const honor = block.honor ?? 'auto';
	const trust = block.trust ?? 'bridge';

	return {
		session: {
			bypass: session.bypass !== false,
			path:
				typeof session.path === 'string' && session.path.startsWith( '/' )
					? session.path
					: DEFAULT_SESSION_PATH,
			cookieName:
				typeof session.cookieName === 'string' && session.cookieName.trim() !== ''
					? session.cookieName.trim()
					: DEFAULT_COOKIE,
		},
		bypass: {
			ttlSeconds: bounded( bypass.ttlSeconds, DEFAULT_BYPASS_TTL, 60, MAX_RETRY_AFTER ),
			cookieName:
				typeof bypass.cookieName === 'string' && bypass.cookieName.trim() !== ''
					? bypass.cookieName.trim()
					: DEFAULT_BYPASS_COOKIE,
		},
		managed: {
			enabled: managed.enabled !== false,
			pollSeconds: bounded( managed.pollSeconds, 60, 15, 3_600 ),
			maxStalenessSeconds: bounded( managed.maxStalenessSeconds, 900, 60, 86_400 ),
		},
		retryAfterSeconds: bounded( block.retryAfterSeconds, DEFAULT_RETRY_AFTER, 1, MAX_RETRY_AFTER ),
		policy: block.static?.policy === 'keep' ? 'keep' : 'replace',
		honor: honor === 'coming_soon-only' || honor === 'off' ? honor : 'auto',
		trust: trust === 'probe' ? 'probe' : 'bridge',
		page: {
			mode:
				page.mode === 'copy' || page.mode === 'source' ? page.mode : 'generated',
			...page,
		},
		comingSoonIndexable: block.comingSoon?.indexable === true,
	};
}

/** The gate is off and the site publishes normally. */
export function offPublication(): GatePublication {
	return {
		mode: 'off',
		source: 'build',
		plugin: null,
		checkedAt: new Date().toISOString(),
		honored: true,
		policy: 'replace',
		page: null,
		comingSoonIndexable: false,
	};
}

/**
 * Turn a probe into the artifact's verdict.
 *
 * `trust` decides how much HTML inference is allowed:
 *   • `bridge` (default) — only the bridge's `/gate` route is authoritative.
 *     An HTML-only "looks gated" reading is NOT enough to gate a customer
 *     site (a cached anonymous page can look gated to a logged-out probe).
 *   • `probe` — allow the HTML classification when the bridge is absent.
 */
export function publicationFromProbe(
	probe: GateProbe,
	config: ResolvedGateConfig,
	now: Date = new Date()
): GatePublication {
	const bridge = probe.bridge;
	const fromBridge = Boolean( bridge && typeof bridge.mode === 'string' );
	const mode = fromBridge
		? normalizeGateMode( bridge?.mode )
		: config.trust === 'probe'
		? inferProbeMode( probe )
		: 'off';

	const declared = probe.retryAfter ?? bridge?.retryAfter ?? 0;
	const retryAfter =
		mode === 'maintenance'
			? bounded( declared || config.retryAfterSeconds, config.retryAfterSeconds, 1, MAX_RETRY_AFTER )
			: undefined;

	// A policy can decline a mode. `coming_soon-only` exists because a
	// pre-launch site may want the soft holding page but must never be taken
	// down for maintenance it has not announced.
	const honored =
		config.honor === 'off' ? false : ! ( config.honor === 'coming_soon-only' && mode === 'maintenance' );
	const effective: GateMode = honored ? mode : 'off';

	return {
		mode: effective,
		source: fromBridge ? 'bridge' : 'probe',
		plugin: fromBridge ? ( bridge?.plugin ?? null ) : null,
		...( retryAfter !== undefined ? { retryAfter } : {} ),
		checkedAt: ( fromBridge && bridge?.checkedAt ) || now.toISOString(),
		honored,
		policy: config.policy,
		page: effective === 'maintenance' ? 'maintenance' : effective === 'coming_soon' ? 'coming-soon' : null,
		comingSoonIndexable: config.comingSoonIndexable,
	};
}

/**
 * HTML-inference fallback (only reachable with `trust: 'probe'`).
 *
 * A 503 (or a 429/`Retry-After`) is maintenance; a 200 that reads like a
 * holding page is coming-soon. Anything else is "open".
 */
function inferProbeMode( probe: GateProbe ): GateMode {
	if ( probe.state === 'gated' ) {
		return probe.status === 503 || probe.fingerprint === 'core-maintenance'
			? 'maintenance'
			: 'coming_soon';
	}
	if ( probe.state === 'rate-limited' ) {
		return 'maintenance';
	}
	return 'off';
}

/** An explicit `--gate` mode becomes a verdict without touching the network. */
export function overridePublication(
	mode: GateMode,
	config: ResolvedGateConfig,
	now: Date = new Date()
): GatePublication {
	const honored =
		config.honor === 'off' ? false : ! ( config.honor === 'coming_soon-only' && mode === 'maintenance' );
	const effective: GateMode = honored ? mode : 'off';
	return {
		mode: effective,
		source: 'override',
		plugin: null,
		...( effective === 'maintenance' ? { retryAfter: config.retryAfterSeconds } : {} ),
		checkedAt: now.toISOString(),
		honored,
		policy: config.policy,
		page: effective === 'maintenance' ? 'maintenance' : effective === 'coming_soon' ? 'coming-soon' : null,
		comingSoonIndexable: config.comingSoonIndexable,
	};
}

export interface ResolveLiveOptions {
	profile: SiteProfile;
	/** `auto` asks the CMS; a mode forces the answer. */
	override?: 'auto' | GateMode;
	/** Test seam — a probe already in hand (no network). */
	probe?: GateProbe;
	/** Test seam — the clock. */
	now?: Date;
	env?: NodeJS.ProcessEnv;
}

/**
 * Resolve the CMS's live publication state into the artifact's verdict.
 *
 * Fails OPEN: a probe that throws (unreachable, SSRF guard, timeout) publishes
 * the real site with `source: 'probe'` and the failure in `checkedAt`'s
 * neighbourhood only — a build must never be blocked by a CMS outage.
 */
export async function resolveLivePublication( options: ResolveLiveOptions ): Promise< GatePublication > {
	const env = options.env ?? process.env;
	const config = resolveGateConfig( options.profile, env );

	if ( options.override && options.override !== 'auto' ) {
		return overridePublication( options.override, config, options.now );
	}
	if ( config.honor === 'off' ) {
		return { ...offPublication(), honored: false, checkedAt: ( options.now ?? new Date() ).toISOString() };
	}

	let probe = options.probe;
	if ( ! probe ) {
		try {
			probe = await probeGate( options.profile, {
				mode: 'auto',
				allowLoopback: env.NVOOS_FORGE_ALLOW_LOOPBACK === '1',
			} );
		} catch {
			// Unreachable / guarded / timed out — publish the real site.
			return {
				...offPublication(),
				source: 'probe',
				checkedAt: ( options.now ?? new Date() ).toISOString(),
			};
		}
	}

	return publicationFromProbe( probe, config, options.now );
}

/** Serialise the manifest exactly as the artifact ships it (tabs + newline). */
export function gateManifest( publication: GatePublication, config: ResolvedGateConfig ): GateManifest {
	return {
		mode: publication.mode,
		source: publication.source,
		plugin: publication.plugin ?? null,
		...( publication.retryAfter !== undefined ? { retryAfter: publication.retryAfter } : {} ),
		checkedAt: publication.checkedAt,
		honored: publication.honored,
		policy: publication.policy,
		page: publication.page,
		comingSoonIndexable: publication.comingSoonIndexable,
		session: config.session,
		bypass: config.bypass,
		managed: config.managed,
		retryAfterSeconds: config.retryAfterSeconds,
		honor: config.honor,
		trust: config.trust,
	};
}

/**
 * Write `gate.generated.json` into the project.
 *
 * Always written — even with the gate off — because it is the runtime's BOOT
 * STATE: a cold start during a gate must know to gate before its first poll.
 */
export async function writeGateManifest(
	targetDir: string,
	publication: GatePublication,
	profile: Pick< SiteProfile, 'gatePublish' >
): Promise< GateManifest > {
	const { writeFile } = await import( 'node:fs/promises' );
	const path = await import( 'node:path' );
	const config = resolveGateConfig( profile );
	const manifest = gateManifest( publication, config );
	await writeFile(
		path.join( targetDir, 'gate.generated.json' ),
		`${ JSON.stringify( manifest, null, '\t' ) }\n`,
		'utf8'
	);
	return manifest;
}

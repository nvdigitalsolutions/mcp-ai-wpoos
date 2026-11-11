// ---------------------------------------------------------------------------
// gate-policy.ts — per-site gate blocking policy (Proposal 114 §3.6 addendum).
//
// The scorecard has two jobs and conflating them is what made the forge
// single-site: MEASURING a gate (identical on every site — that is what makes
// it a gate) and BLOCKING on it (not identical at all). This module owns the
// second job only. It is pure — no I/O, no clock, no environment — so the
// policy a CI run actually applied is reproducible in a unit test.
//
// Which gates are addressable is a deliberate, small list. Only the gates whose
// verdict is RENDER- or THRESHOLD-sensitive are here: a pixel diff rides on the
// source's font rasterisation, image renditions and third-party widgets, none
// of which a rebuild can reproduce byte-for-byte, and a box delta can absorb
// sub-pixel font-metric drift on a band of long prose. Content gates are NOT
// addressable — `band flow`, `token block`, `text parity` and `design language`
// prove the rebuild is complete and carries the source's words and colours.
// Downgrading those is not a policy decision, it is a broken site.
//
// Industry anchors (2026 visual-regression practice):
//   - Playwright runs CI with `updateSnapshots: 'none'` so a MISSING baseline
//     fails loudly instead of writing itself — the gate must never be
//     satisfiable by the build comparing to itself.
//   - Committing a new baseline is self-approval, so a build-to-build diff is a
//     REGRESSION signal and must never be reported as parity. Hence the two
//     separate gate names below.
//   - Advisory visual findings are reported distinctly from blocking ones and
//     never silently dropped; `maxDiffPixelRatio: 0.01` is the conventional
//     starting tolerance and `threshold: 0.2` the per-pixel colour sensitivity.
// ---------------------------------------------------------------------------

/**
 * The gates a site profile may address — the whole addressable vocabulary.
 *
 * Adding a name here is a promise that `verify` honours `advisory` for it, so
 * the list stays short on purpose. `pixel regression` exists as its own gate
 * precisely so a self-comparison can never masquerade as parity.
 */
export const FORGE_ADDRESSABLE_GATES = [ 'pixel parity', 'pixel regression', 'box geometry' ] as const;

/** One addressable gate name. */
export type ForgeGateName = ( typeof FORGE_ADDRESSABLE_GATES )[ number ];

/**
 * `gate` fails the run when the measurement is below the bar — the default.
 * `advisory` still runs and still reports; it just does not fail the run.
 * `auto` is resolved from the site's own evidence (see `resolveAuto`).
 *
 * There is deliberately no `off`. Hiding a gate is what would make the
 * scorecard's percentage dishonest, and the legitimate need ("do not pay for a
 * browser in this job") is already served by `--minimal` and `--no-bands` —
 * flags, so the omission is visible in the pipeline definition instead of
 * buried in a data file.
 */
export type ForgeGatePolicy = 'gate' | 'advisory' | 'auto';

/** A resolution — never `auto`. */
export type ForgeGateMode = 'gate' | 'advisory';

/**
 * Pixel-specific defaults that outlive a single `verify` invocation, so they
 * travel with the site instead of living in a CI flag nobody re-reads.
 */
export interface ForgePixelPolicy {
	/**
	 * Which comparison `--pixel` makes by default.
	 *
	 * `source` (the default) is PARITY: the rebuild against the live site, the
	 * only comparison that can prove fidelity. `build` is REGRESSION: the build
	 * against its own recorded baseline, which proves nothing has changed since
	 * the baseline but cannot prove the target was ever reached. A `build`
	 * comparison is therefore reported under `pixel regression`, never as
	 * `pixel parity` — a build must not be able to pass the parity gate by
	 * comparing to itself.
	 */
	baseline?: 'source' | 'build';
	/**
	 * Selectors of genuinely dynamic regions (carousels, clocks, live chat) to
	 * mask on both sides. The profile-level default; `--pixel-mask` overrides it
	 * so a one-off investigation can widen the mask without editing the site.
	 */
	masks?: string[];
}

/** The profile's gate-policy block. Absent = every default applies. */
export interface ForgeVerify {
	/** Per-gate policy, keyed by an addressable gate name. */
	gates?: Partial< Record< ForgeGateName, ForgeGatePolicy > >;
	/** Pixel defaults (parity vs regression, dynamic-region masks). */
	pixel?: ForgePixelPolicy;
}

/** What `auto` needs to know — supplied by the caller so this module stays pure. */
export interface GateEvidence {
	/** A SOURCE seam (the parity reference) exists for at least one viewport. */
	hasSourceSeam?: boolean;
}

/** A resolved policy plus its provenance, which the scorecard prints. */
export interface GateResolution {
	mode: ForgeGateMode;
	/** Where the mode came from. A downgrade must never be anonymous. */
	from: 'flag' | 'profile' | 'default';
	/** The declared value when the profile supplied one (may be `auto`). */
	declared?: ForgeGatePolicy;
	/** Human phrase explaining an `auto` resolution. */
	reason?: string;
}

/**
 * Gates whose default is not `gate`. Only parity is listed: with no source seam
 * there is nothing to be equal to, so a brand-new forge would fail its own
 * first run for having no reference — noise, not signal.
 */
const AUTO_DEFAULTS: Partial< Record< ForgeGateName, ForgeGatePolicy > > = {
	'pixel parity': 'auto',
};

/** Resolve `auto` from the site's own evidence. */
function resolveAuto( name: ForgeGateName, evidence: GateEvidence ): GateResolution {
	if ( name === 'pixel parity' ) {
		return evidence.hasSourceSeam === true
			? { mode: 'gate', from: 'default', declared: 'auto', reason: 'source seam recorded' }
			: {
					mode: 'advisory',
					from: 'default',
					declared: 'auto',
					reason: 'no source seam — record one with verify --pixel-record-source',
			  };
	}
	// `auto` is meaningless for a gate that needs no reference; treat it as the
	// default rather than silently softening the gate.
	return { mode: 'gate', from: 'default', declared: 'auto', reason: 'auto has no effect on this gate' };
}

/**
 * Resolve one gate's mode. Precedence: CLI flag → profile → default.
 *
 * A flag may only STRENGTHEN to `gate` or soften to `advisory`; it is the
 * escape hatch CI needs, and it is the one override a reviewer can see in the
 * pipeline file.
 */
export function resolveGateMode(
	name: ForgeGateName,
	verify?: ForgeVerify,
	evidence: GateEvidence = {},
	flag?: ForgeGatePolicy
): GateResolution {
	if ( flag === 'gate' || flag === 'advisory' ) {
		return { mode: flag, from: 'flag' };
	}
	const declared = verify?.gates?.[ name ];
	if ( declared === undefined ) {
		const policy = AUTO_DEFAULTS[ name ];
		if ( policy === undefined ) {
			return { mode: 'gate', from: 'default' };
		}
		if ( policy === 'auto' ) {
			return resolveAuto( name, evidence );
		}
		return { mode: policy, from: 'default', declared: policy };
	}
	if ( declared === 'auto' ) {
		const resolved = resolveAuto( name, evidence );
		// The PROFILE asked for it even though the resolution came from evidence.
		return { ...resolved, from: 'profile' };
	}
	return { mode: declared, from: 'profile', declared };
}

/**
 * The scorecard suffix for a downgraded gate. Empty for a blocking gate —
 * printing the provenance of the default on every run is noise; only a
 * DOWNGRADE needs to be visible.
 */
export function gateNote( resolution: GateResolution ): string {
	if ( resolution.mode !== 'advisory' ) {
		return '';
	}
	const detail: string[] = [ resolution.from ];
	if ( resolution.declared ) {
		detail.push( resolution.declared );
	}
	if ( resolution.reason ) {
		detail.push( resolution.reason );
	}
	return `advisory (${ detail.join( ' \u00b7 ' ) })`;
}

/**
 * Problems with a profile's `verify` block, for the CLI to print verbatim.
 *
 * Unknown keys and unknown gate names are errors, not warnings: a typo in a
 * JSON file is silent otherwise, and the site would keep the DEFAULT policy
 * while the operator believes they changed it.
 */
export function validateVerifyBlock( verify: unknown ): string[] {
	if ( verify === undefined || verify === null ) {
		return [];
	}
	if ( typeof verify !== 'object' || Array.isArray( verify ) ) {
		return [ 'verify must be an object ({ gates, pixel })' ];
	}
	const problems: string[] = [];
	const block = verify as Record< string, unknown >;
	for ( const key of Object.keys( block ) ) {
		if ( key !== 'gates' && key !== 'pixel' ) {
			problems.push( `unknown verify key "${ key }" — use gates, pixel` );
		}
	}

	const gates = block.gates;
	if ( gates !== undefined && gates !== null ) {
		if ( typeof gates !== 'object' || Array.isArray( gates ) ) {
			problems.push( 'verify.gates must be an object keyed by gate name' );
		} else {
			for ( const [ name, value ] of Object.entries( gates as Record< string, unknown > ) ) {
				if ( ! ( FORGE_ADDRESSABLE_GATES as readonly string[] ).includes( name ) ) {
					problems.push(
						`verify.gates["${ name }"] is not addressable — only ${ FORGE_ADDRESSABLE_GATES.join(
							', '
						) } accept a policy (the content gates are fixed)`
					);
					continue;
				}
				if ( value !== 'gate' && value !== 'advisory' && value !== 'auto' ) {
					problems.push(
						`verify.gates["${ name }"] must be gate, advisory or auto (got ${ JSON.stringify(
							value
						) }) — there is no "off"; use advisory to keep the measurement without blocking`
					);
				}
			}
		}
	}

	const pixel = block.pixel;
	if ( pixel !== undefined && pixel !== null ) {
		if ( typeof pixel !== 'object' || Array.isArray( pixel ) ) {
			problems.push( 'verify.pixel must be an object ({ baseline, masks })' );
		} else {
			const record = pixel as Record< string, unknown >;
			for ( const key of Object.keys( record ) ) {
				if ( key !== 'baseline' && key !== 'masks' ) {
					problems.push( `unknown verify.pixel key "${ key }" — use baseline, masks` );
				}
			}
			if ( record.baseline !== undefined && record.baseline !== 'source' && record.baseline !== 'build' ) {
				problems.push( 'verify.pixel.baseline must be source (parity) or build (regression)' );
			}
			if (
				record.masks !== undefined &&
				( ! Array.isArray( record.masks ) || record.masks.some( ( mask ) => typeof mask !== 'string' ) )
			) {
				problems.push( 'verify.pixel.masks must be an array of CSS selectors' );
			}
		}
	}

	return problems;
}

/**
 * The masks the pixel gate applies: `--pixel-mask` when given, otherwise the
 * site's own `verify.pixel.masks`. Shared by the record-source and the compare
 * paths so a recorded seam is always diffed with the mask it was captured with.
 */
export function resolvePixelMasks( verify?: ForgeVerify, cliMasks?: unknown ): string[] {
	const cli = Array.isArray( cliMasks ) ? cliMasks.filter( ( mask ): mask is string => typeof mask === 'string' && mask.length > 0 ) : [];
	if ( cli.length > 0 ) {
		return cli;
	}
	const site = verify?.pixel?.masks;
	return Array.isArray( site ) ? site.filter( ( mask ) => typeof mask === 'string' && mask.length > 0 ) : [];
}

// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// structure-pass.ts — the opt-in VISION structure pass (Proposal 163 §P6).
//
// `lib/vision.ts` can label a band's STRUCTURE from a screenshot, and
// `analyzeSections` can measure a few structures deterministically (the
// comparison matrix). Everything else — a timeline's connector, a pricing
// table published as three cards — is invisible to both the sanitiser and the
// classifier: the builder renders those semantics as `<svg>` and pseudo-
// elements, so the only reader left is a vision model looking at a screenshot.
//
// This module is the wiring: serve the built site, screenshot each band at the
// two ends the template renders, ask the model, and merge — with three
// contracts that keep it from becoming a source of noise:
//
//  1. OPT-IN AND FAIL-SOFT. No reachable vision endpoint → `skipped`, never an
//     error. A band the model cannot name stays unnamed.
//  2. A DETERMINISTIC DETECTOR ALWAYS WINS (`mergeStructureVerdicts`). Vision
//     may only fill a gap, and a stored label carries its provenance
//     (`source: 'vision'`, `model`, `confidence`) so a wrong label is
//     attributable and removable.
//  3. NOTHING IS STORED WITHOUT `--write`. The pass returns the merged profile;
//     the CLI decides whether to persist it.
//
// Playwright is optional (same contract as geometry.ts/screenshot.ts): the
// capture step is injectable, and a missing browser degrades to `skipped`.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';
import { join } from 'node:path';

import type { SiteProfile } from './types.ts';
import { mergeStructureVerdicts, resolveVisionConfig, STRUCTURE_PROMPT, visionStructure, type StructureVerdict, type VisionConfig, type VisionImage } from './vision.ts';

/** One device end the template renders (the two the structure pass asks). */
export interface StructureDevice {
	name: string;
	width: number;
	height: number;
}

export const STRUCTURE_DEVICES: StructureDevice[] = [
	{ name: 'desktop', width: 1440, height: 900 },
	{ name: 'mobile', width: 390, height: 844 },
];

/** One screenshot: the band id, the device, and the image. */
export interface BandShot {
	id: string;
	device: string;
	png: Uint8Array;
	mime: string;
	/** The band's own heading, read from the render — the prompt's evidence. */
	title?: string;
	/** The band renders a form — a MEASURED structure, so vision must not guess. */
	hasForm?: boolean;
}

export interface CachedStructureVerdict {
	type: string;
	confidence: number;
	note?: string;
}

export interface StructurePassBand {
	id: string;
	device: string;
	type: string;
	confidence: number;
	/** The verdict came from the content-addressed cache (no model call). */
	cached: boolean;
	/** The band already carried a measured (non-vision) structure. */
	skipped: boolean;
	note?: string;
}

export interface StructurePassOptions {
	/** The scaffolded project; its built `dist/` is served. */
	target: string;
	/** The page to read (default `/`). */
	path?: string;
	viewports?: StructureDevice[];
	/** Below this, a label is dropped (default 0.7 — `mergeStructureVerdicts`). */
	minConfidence?: number;
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
	/** Previously-cached verdicts, keyed by `id:sha256`. */
	cache?: Map< string, CachedStructureVerdict >;
	/** Injected by tests; the default serves `dist/` and drives Playwright. */
	capture?: ( options: StructurePassOptions ) => Promise< BandShot[] >;
	onProgress?: ( message: string ) => void;
}

export interface StructurePassOutcome {
	/** Why the pass did nothing, when it did nothing. Never an error. */
	skipped?: string;
	model?: string;
	shots: number;
	asked: number;
	cached: number;
	labelled: number;
	unknown: number;
	failed: number;
	/** Bands whose device reads named DIFFERENT structures (nothing stored). */
	conflicts: number;
	bands: StructurePassBand[];
	/** The profile with vision labels merged — a COPY; the input is untouched. */
	profile: SiteProfile;
	/** Verdicts keyed by `id:sha256` — persist when the caller writes. */
	cache: Map< string, CachedStructureVerdict >;
}

/** The cache key: a band id plus the CONTENT hash of its screenshot. */
export function structureCacheKey( id: string, png: Uint8Array ): string {
	return `${ id }:${ createHash( 'sha256' ).update( png ).digest( 'hex' ).slice( 0, 16 ) }`;
}

/** `true` when the band already carries a deterministic (non-vision) label. */
function hasMeasuredStructure( section: {
	structure?: Array< { source?: string } >;
	comparison?: unknown;
} ): boolean {
	// The typed `comparison` fast path is a measurement too. A profile recorded
	// before the `structure` channel carries the matrix there, and the renderer
	// falls back to it ONLY while `structure` is empty — so a vision label added
	// to such a band would suppress the table it was meant to complement.
	if ( section.comparison ) {
		return true;
	}
	return ( section.structure ?? [] ).some( ( entry ) => entry.source !== 'vision' );
}

/**
 * The default capture: serve `dist/` over HTTP and screenshot every band at
 * every device. Playwright is loaded lazily so the package stays usable
 * without it — a missing browser is reported, never thrown.
 */
async function captureBands( options: StructurePassOptions ): Promise< BandShot[] > {
	const viewports = options.viewports ?? STRUCTURE_DEVICES;
	let chromium: typeof import('playwright').chromium;
	let serveDir: typeof import('./geometry.ts').serveDir;
	let builtStaticRoot: typeof import('./geometry.ts').builtStaticRoot;
	try {
		chromium = ( await import('playwright') ).chromium;
		( { serveDir, builtStaticRoot } = await import('./geometry.ts') );
	} catch {
		throw new Error( 'structure pass needs Playwright (npm i -D playwright)' );
	}
	// A SERVER-output build keeps its prerendered HTML under dist/client/.
	// `builtStaticRoot` takes the DIST path (every other caller passes
	// `join(target, 'dist')`) — passing the project root serves the project
	// directory, answers 404, and captures nothing.
	const served = await serveDir( await builtStaticRoot( join( options.target, 'dist' ) ) );
	const shots: BandShot[] = [];
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		for ( const device of viewports ) {
			const context = await browser.newContext( {
				viewport: { width: device.width, height: device.height },
				deviceScaleFactor: 1,
				reducedMotion: 'reduce',
				colorScheme: 'light',
				locale: 'en-US',
				timezoneId: 'UTC',
			} );
			const page = await context.newPage();
			try {
				await page.goto( new URL( options.path ?? '/', served.url ).toString(), {
					waitUntil: 'load',
					timeout: 90_000,
				} );
				await page.addStyleTag( {
					content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}',
				} );
				await page.evaluate( async () => {
					const doc = document as Document & { fonts?: { ready: Promise< unknown > } };
					if ( doc.fonts ) {
						await doc.fonts.ready;
					}
				} );
				const ids = await page.$$eval( '[data-section-id]', ( nodes ) =>
					Array.from(
						new Set( nodes.map( ( node ) => node.getAttribute( 'data-section-id' ) ?? '' ) )
					).filter( Boolean )
				);
				for ( const id of ids ) {
					const locator = page.locator( `[data-section-id="${ id }"]` ).first();
					try {
						const buffer = await locator.screenshot( {
							animations: 'disabled',
							caret: 'hide',
							scale: 'css',
							timeout: 20_000,
						} );
						// The band's heading is the cheapest disambiguator a screenshot
						// lacks — read it from the MEASURED render, never inferred.
						const title = await locator
							.locator( 'h1,h2,h3,h4' )
							.first()
							.innerText( { timeout: 2_000 } )
							.catch( () => '' );
						// A band that renders a form is measured, not guessed: a local VLM
						// reads "Questions? Get a quick reply…" as `faq` on eca.directory's
						// enquiry band, at 0.9, on BOTH device ends.
						const hasForm = ( await locator.locator( 'form, [data-nvoos-form]' ).count() ) > 0;
						shots.push( {
							id,
							device: device.name,
							png: new Uint8Array( buffer ),
							mime: 'image/png',
							...( title.trim() ? { title: title.trim().replace( /\s+/g, ' ' ).slice( 0, 140 ) } : {} ),
							...( hasForm ? { hasForm: true } : {} ),
						} );
					} catch {
						// A band the template hides at this end is not a fact.
					}
				}
			} finally {
				await context.close();
			}
		}
	} finally {
		await browser.close();
		await new Promise< void >( ( resolve ) => served.server.close( () => resolve() ) );
	}
	return shots;
}

/** Group shots per band, in the order the devices were captured. */
function shotsByBand( shots: BandShot[] ): Map< string, BandShot[] > {
	const grouped = new Map< string, BandShot[] >();
	for ( const shot of shots ) {
		grouped.set( shot.id, [ ...( grouped.get( shot.id ) ?? [] ), shot ] );
	}
	return grouped;
}

/**
 * Run the pass. Never throws for a missing endpoint, a missing browser, or a
 * model error — those are reported and the profile comes back unchanged.
 */
export async function runStructurePass(
	profile: SiteProfile,
	options: StructurePassOptions
): Promise< StructurePassOutcome > {
	const env = options.env ?? process.env;
	const cache = options.cache ?? new Map< string, CachedStructureVerdict >();
	const base: StructurePassOutcome = {
		shots: 0,
		asked: 0,
		cached: 0,
		labelled: 0,
		unknown: 0,
		failed: 0,
		conflicts: 0,
		bands: [],
		profile,
		cache,
	};

	const config: VisionConfig | undefined = await resolveVisionConfig( env );
	if ( ! config ) {
		return { ...base, skipped: 'no vision config' };
	}

	let shots: BandShot[];
	try {
		shots = await ( options.capture ?? captureBands )( options );
	} catch ( error ) {
		return {
			...base,
			model: config.model,
			skipped: error instanceof Error ? error.message : String( error ),
		};
	}

	// An empty capture is a FAILURE to read the page, not a clean result: the
	// first version of this pass served the project root instead of `dist/`,
	// captured nothing, and reported a tidy row of zeros.
	if ( shots.length === 0 ) {
		return {
			...base,
			model: config.model,
			skipped: 'no bands found (does dist/ contain [data-section-id]?)',
		};
	}

	const sections = profile.design?.sections ?? [];
	const byId = new Map( sections.map( ( section ) => [ section.id, section ] ) );
	const grouped = shotsByBand( shots );
	/**
	 * The per-band prompt: the measured HEADING rides along as evidence.
	 *
	 * A screenshot alone says "lots of text" for a hero and for a services row,
	 * which is exactly the answer a local VLM gave (every band `text` or a
	 * sibling label, all at 0.9). The heading is a MEASURED fact from the
	 * profile, not a guess, and it is the cheapest disambiguator there is.
	 */
	const promptFor = ( heading?: string ): string => {
		const trimmed = ( heading ?? '' ).trim();
		if ( ! trimmed ) {
			return STRUCTURE_PROMPT;
		}
		return `${ STRUCTURE_PROMPT }\n\nThe band's heading, measured from the page: "${ trimmed }". Treat it as evidence, but the screenshot decides.`;
	};
	// A shallow copy: the caller's profile is only mutated through `--write`.
	const updated: SiteProfile = {
		...profile,
		design: profile.design
			? {
					...profile.design,
					sections: sections.map( ( section ) => ( { ...section } ) ),
			  }
			: profile.design,
	};
	const updatedById = new Map( ( updated.design?.sections ?? [] ).map( ( section ) => [ section.id, section ] ) );

	let asked = 0;
	let cachedHits = 0;
	let labelled = 0;
	let unknown = 0;
	let failed = 0;
	let conflicts = 0;
	const bands: StructurePassBand[] = [];

	for ( const [ id, bandShots ] of grouped ) {
		const section = byId.get( id );
		if ( ! section ) {
			continue;
		}
		if ( hasMeasuredStructure( section ) ) {
			bands.push( { id, device: bandShots[ 0 ]?.device ?? '', type: '', confidence: 0, cached: false, skipped: true } );
			continue;
		}
		// A rendered form is measured, so vision is not asked about it. The local
		// VLM called eca.directory's enquiry band `faq` at 0.9 on both ends, and
		// its heading (`Questions? Get a quick reply…`) genuinely reads that way.
		if ( bandShots.some( ( shot ) => shot.hasForm ) ) {
			bands.push( { id, device: bandShots[ 0 ]?.device ?? '', type: 'form', confidence: 0, cached: false, skipped: true } );
			continue;
		}
		// Every non-abstaining read for this band, whichever device produced it.
		const reads: Array< { verdict: StructureVerdict; device: string; cached: boolean } > = [];
		for ( const shot of bandShots ) {
			const key = structureCacheKey( id, shot.png );
			const hit = cache.get( key );
			if ( hit ) {
				cachedHits += 1;
				bands.push( { ...hit, id, device: shot.device, type: hit.type, cached: true, skipped: false } );
				reads.push( {
					verdict: {
						type: hit.type as StructureVerdict[ 'type' ],
						confidence: hit.confidence,
						...( hit.note ? { note: hit.note } : {} ),
					},
					device: shot.device,
					cached: true,
				} );
				continue;
			}
			asked += 1;
			const image: VisionImage = { data: Buffer.from( shot.png ).toString( 'base64' ), mime: shot.mime };
			const outcome = await visionStructure( config, image, options.fetchImpl, undefined, promptFor( shot.title ) );
			if ( ! outcome.ok ) {
				failed += 1;
				options.onProgress?.( `  ! ${ id } (${ shot.device }): ${ outcome.error }` );
				continue;
			}
			const verdict = outcome.verdict;
			if ( verdict.type === 'unknown' ) {
				unknown += 1;
				bands.push( { id, device: shot.device, type: 'unknown', confidence: verdict.confidence, cached: false, skipped: false } );
				continue;
			}
			cache.set( key, {
				type: verdict.type,
				confidence: verdict.confidence,
				...( verdict.note ? { note: verdict.note } : {} ),
			} );
			bands.push( {
				id,
				device: shot.device,
				type: verdict.type,
				confidence: verdict.confidence,
				cached: false,
				skipped: false,
				...( verdict.note ? { note: verdict.note } : {} ),
			} );
			reads.push( { verdict, device: shot.device, cached: false } );
		}
		const minimum = options.minConfidence ?? 0.7;
		const confident = reads.filter( ( read ) => read.verdict.confidence >= minimum );
		// TWO reads, one per device end, and they must AGREE. An abstention is not
		// a contradiction, and a single confident read stands when the other end
		// abstained — a matrix legitimately collapses to cards on a phone. Two
		// different labels mean the band is genuinely ambiguous at this model's
		// resolution, and a wrong structure is worse than none.
		const types = new Set( confident.map( ( read ) => read.verdict.type ) );
		if ( types.size > 1 ) {
			conflicts += 1;
			options.onProgress?.(
				`  ! ${ id }: the device reads disagree (${ confident
					.map( ( read ) => `${ read.verdict.type }@${ read.device }` )
					.join( ', ' ) }) — nothing stored`
			);
			continue;
		}
		const accepted = confident[ 0 ];
		if ( ! accepted ) {
			continue;
		}
		const target = updatedById.get( id );
		if ( ! target ) {
			continue;
		}
		const merged = mergeStructureVerdicts(
			( target.structure ?? [] ) as Array< { type: string; source?: string } >,
			accepted.verdict,
			{ model: config.model, minConfidence: options.minConfidence }
		);
		if ( merged.length > ( target.structure ?? [] ).length ) {
			labelled += 1;
			target.structure = merged as unknown as typeof target.structure;
		}
	}

	return {
		model: config.model,
		shots: shots.length,
		asked,
		cached: cachedHits,
		labelled,
		unknown,
		failed,
		conflicts,
		bands,
		profile: updated,
		cache,
	};
}

/** The cache file's on-disk shape (written only by an explicit `--write`). */
export interface StructureCacheFile {
	version: 1;
	entries: Record< string, CachedStructureVerdict >;
}

export function serializeStructureCache( cache: Map< string, CachedStructureVerdict > ): StructureCacheFile {
	return { version: 1, entries: Object.fromEntries( cache ) };
}

export function parseStructureCache( raw: string ): Map< string, CachedStructureVerdict > {
	const out = new Map< string, CachedStructureVerdict >();
	try {
		const parsed = JSON.parse( raw ) as Partial< StructureCacheFile >;
		if ( parsed.version !== 1 || ! parsed.entries || typeof parsed.entries !== 'object' ) {
			return out;
		}
		for ( const [ key, value ] of Object.entries( parsed.entries ) ) {
			if ( value && typeof value.type === 'string' && typeof value.confidence === 'number' ) {
				out.set( key, {
					type: value.type,
					confidence: value.confidence,
					...( typeof value.note === 'string' ? { note: value.note } : {} ),
				} );
			}
		}
	} catch {
		// A corrupt cache is a cache miss, never a failure.
	}
	return out;
}

#!/usr/bin/env node
// ---------------------------------------------------------------------------
// forge.mjs — the Forge CLI (Proposal 096; commander + @clack/prompts since
// Proposal 104 §4.3).
//
//   node scripts/forge.mjs analyze <url> [--write <site.json>]
//   node scripts/forge.mjs [forge] <site.json|name> --target <dir> [--check]
//   node scripts/forge.mjs site add|remove|list|use …
//   node scripts/forge.mjs vault set|delete|list …
//   node scripts/forge.mjs deploy <site.json|name> --target <dir> --to <mode>
//     [--mirror-repo owner/repo --site-branch site/<name>]  (velocity shared mirror — branch per site)
//   node scripts/forge.mjs wizard <url> --target <dir> [--preset …] [--build]
//
// `analyze` performs the polite discovery pass (site_analyze). The scaffold
// command (bare or the explicit `forge` word) materializes the Astro project
// from an operator-written SiteProfile (by file path, or by name when
// registered via `site add`). `deploy` scaffolds if needed, emits the deploy
// pipeline (Phase 3.3), creates a PRIVATE repo by default, and pushes the
// source with per-invocation auth (GITHUB_TOKEN env or the vault entry
// `github` — never persisted, always masked in output).
//
// `wizard` gains `--preset blog|storefront|minimal` (104 §4.3); without it
// the preset is suggested from the site_analyze report — interactively via
// @clack/prompts on a TTY, deterministically (suggestPreset) otherwise, so
// CI/e2e runs stay non-interactive.
//
// This file is PLAIN JavaScript — `.mjs` gets NO type stripping: `import
// type` and inline `type` specifiers are syntax errors here.
//
// Requires Node >= 22.6 (native TypeScript type stripping).
// ---------------------------------------------------------------------------

import { readFile, readdir, mkdir, stat, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { execFileSync, spawn } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { confirm, isCancel, select, spinner, text } from '@clack/prompts';
import { Command } from 'commander';

import { analyzeBandContinuity, analyzeBuiltCss, analyzeDesignInput, applyMeasuredHeader, diffDesignFacts, fetchDesignInput, renderableBands, scoreParity, verifyBandFlow } from '../lib/design.ts';
import { auditBands, formatBandReport } from '../lib/band-audit.ts';
import { countAnimations, diffAnimations, measureAnimations, planAnimationRebuild } from '../lib/animations.ts';
import { auditClassCoverage } from '../lib/class-audit.ts';
import { computeLock, describeLock, isLocked, lockDrift, readLock, removeLock, writeLock } from '../lib/lock.ts';
import { DEPLOY_RUNTIMES, emitDeployConfig, resolveSiteUrl } from '../lib/deploy-config.ts';
import { analyzeSite, mergeProfileDraft, profileFromDiscovery, renderReport } from '../lib/discovery.ts';
import { applyDesignPins, findDroppedPins } from '../lib/pins.ts';
import { resolveSiteShape, siteNavAnchors } from '../lib/inventory.ts';
import { DEFAULT_GEOMETRY_VIEWPORTS, builtStaticRoot, diffGeometry, measureBandFacts, measureGeometry, measureNavToggle, measureStickyHeader, serveDir } from '../lib/geometry.ts';
import { measurePageBands } from '../lib/page-bands.ts';
import { countInteractionStates, describeInteractionDeviation, diffInteractionStates, measureInteractions } from '../lib/interactions.ts';
import { measureBandDiff } from '../lib/band-diff.ts';
import { gateNote, resolveGateMode, resolvePixelMasks, validateVerifyBlock } from '../lib/gate-policy.ts';
import { auditFacts } from '../lib/fact-audit.ts';
import { captureEndpoint, captureRemote, listArtifacts, readArtifact } from '../lib/capture.ts';
import { comparePngBuffers, encodeDiff } from '../lib/pixel.ts';
import { applyBandSurfaces, compareRegionColorModes, DEFAULT_COLOR_REGIONS, measureRegionColors } from '../lib/region-colors.ts';
import { captureDist, capturePage } from '../lib/screenshot.ts';
import { compareText, extractSectionTexts, textBaselineFromHtml, textFromHtml } from '../lib/text-parity.ts';
import { createOctokitClient, GitHubDeploy } from '../lib/github.ts';
import { discoverInventory, fetchText } from '../lib/inventory.ts';
import { PRESETS, PRESET_NAMES, RENDER_MODES, isValidThemePolicy } from '../lib/presets.ts';
import { collectProjectFiles, slugify } from '../lib/project-files.ts';
import { auditTokenBlock, mergeTokens, rewriteGlobalCss, scaffold } from '../lib/scaffold.ts';
import { auditSeo, scanSeoHtml } from '../lib/seo-audit.ts';
import { analyzeTracking, mergeTrackingDraft, renderTrackingReport, trackingFromReport } from '../lib/tracking.ts';
import { auditTracking } from '../lib/tracking-audit.ts';
import { SitesStore } from '../lib/sites.ts';
import { maskSecret, Vault } from '../lib/vault.ts';
import { COMPARE_PROMPT, resolveVisionConfig, visionCompare } from '../lib/vision.ts';
import { extractWireframes } from '../lib/wireframes.ts';
import { learnVocabulary } from '../lib/vocabulary.ts';
import { setupWizard } from '../lib/wizard.ts';
import { WpClient } from '../lib/wp-client.ts';

const DEPLOY_MODES = [ 'github-pages', 'cloudflare-pages', 'velocity' ];

function fail( message ) {
	console.error( message );
	process.exit( 2 );
}

function failDeployMode( mode ) {
	fail( `Unknown deploy mode "${ mode }" — use one of: ${ DEPLOY_MODES.join( ', ' ) }` );
}

function failRuntime( runtime ) {
	fail( `Unknown deploy runtime "${ runtime }" — use one of: ${ DEPLOY_RUNTIMES.join( ', ' ) }` );
}

/** The deploy-runtime guard — the CLI fails before anything is emitted. */
function assertRuntime( options ) {
	if ( options.runtime && ! DEPLOY_RUNTIMES.includes( options.runtime ) ) {
		failRuntime( options.runtime );
	}
}

/** Shared-mirror deploy validation — the two flags travel together and the
 * repo must be owner/repo shaped (it becomes the clone URL). */
function assertMirrorOptions( options ) {
	if ( options.siteBranch && ! options.mirrorRepo ) {
		fail( '--site-branch requires --mirror-repo.' );
	}
	if ( options.mirrorRepo ) {
		if ( ! options.siteBranch ) {
			fail( '--mirror-repo requires --site-branch (the per-site branch, e.g. site/jayainc).' );
		}
		if ( ! /^[a-z0-9][a-z0-9-]*\/[a-z0-9._-]+$/i.test( options.mirrorRepo ) ) {
			fail( `Invalid --mirror-repo "${ options.mirrorRepo }" — use owner/repo.` );
		}
	}
}

function failPreset( preset ) {
	fail( `Unknown preset "${ preset }" — use one of: ${ PRESET_NAMES.join( ', ' ) }` );
}

function failRender( render ) {
	fail( `Unknown render mode "${ render }" — use one of: ${ RENDER_MODES.join( ', ' ) }` );
}

function failThemePolicy( policy ) {
	fail( `Unknown theme policy "${ policy }" — use one of: auto, light, dark, brand:<slug> (slug: a-z0-9-, e.g. brand:lenas-chair)` );
}

/** The clack-side policy validator mirrors presets.ts (the slug regex pair). */
const THEME_POLICY_SLUG_REGEX = /^[a-z0-9-]+$/;

function assertThemePolicy( policy ) {
	if ( policy !== undefined && ! isValidThemePolicy( policy ) ) {
		failThemePolicy( policy );
	}
	if ( policy === undefined ) {
		return undefined;
	}
	// brand:<slug> — the slug half is re-checked here so a malformed value
	// fails with the clack message even if the enum half passed.
	if ( policy.startsWith( 'brand:' ) ) {
		const slug = policy.slice( 'brand:'.length );
		if ( ! slug || ! THEME_POLICY_SLUG_REGEX.test( slug ) ) {
			failThemePolicy( policy );
		}
	}
	return policy;
}

/** clack prompts only make sense on a real terminal — never in CI/e2e. */
function isInteractive() {
	return Boolean( process.stdin.isTTY && process.stdout.isTTY );
}

// -- profile resolution ------------------------------------------------------

async function loadProfile( ref ) {
	// A path on disk wins; otherwise treat the ref as a registered site name.
	const fileProfile = await readFile( ref, 'utf8' ).then(
		( raw ) => JSON.parse( raw ),
		( error ) => {
			if ( error.code !== 'ENOENT' ) {
				console.error( `Cannot read profile ${ ref }: ${ error.message }` );
				process.exit( 1 );
			}
			return undefined;
		}
	);
	if ( fileProfile !== undefined ) {
		return fileProfile;
	}
	const sites = new SitesStore();
	try {
		const resolved = await sites.resolve( ref );
		console.log( `\u2713 Site "${ ref }" -> ${ resolved.ref.url } (${ resolved.ref.profile })` );
		return resolved.profile;
	} catch {
		console.error( `No profile file or registered site named "${ ref }".` );
		process.exit( 1 );
	}
}

function assertProfile( profile ) {
	if ( ! profile.url || ! Array.isArray( profile.collections ) || profile.collections.length === 0 ) {
		console.error( 'site.json must contain { url, collections: [...] }' );
		process.exit( 1 );
	}
	// A typo in the policy block is otherwise SILENT: the site would keep the
	// default policy while the operator believes they changed it.
	const verifyProblems = validateVerifyBlock( profile.verify );
	if ( verifyProblems.length > 0 ) {
		console.error( `site.json verify block: ${ verifyProblems.join( '; ' ) }` );
		process.exit( 1 );
	}
}

// -- modes -------------------------------------------------------------------

/** Representative pages for --tracking: the inventory's nav pages, bounded
 *  and junk-free. The homepage is always page 1. */
function trackingPageUrls( profile, options ) {
	const total = Number( options.trackingPages ?? 3 );
	if ( ! Number.isFinite( total ) || total <= 1 ) {
		return [];
	}
	const base = String( profile.url ).replace( /\/+$/, '' );
	const junk = new Set( [ 'cart', 'checkout', 'my-account', 'login', 'search' ] );
	const paths = ( profile.inventory?.navPages ?? [] ).filter( ( path ) => {
		const slug = String( path ).split( '/' ).filter( Boolean ).pop() ?? '';
		return slug && ! junk.has( slug );
	} );
	return [ ...new Set( paths ) ]
		.slice( 0, total - 1 )
		.map( ( path ) => new URL( path, base ).href );
}

/**
 * The pages a `--pages` run measures: the pages collection's live entries,
 * minus the front page (it renders at `/`) and the operator's exclusions.
 * Wireframe placeholders are not here — they 404 on the source.
 */
async function listSourcePages( profile ) {
	const collection = ( profile.collections ?? [] ).find( ( entry ) => entry.slug === 'pages' );
	const restBase = collection?.restBase ?? 'wp/v2/pages';
	const excluded = new Set( collection?.exclude ?? [] );
	const base = String( profile.url ).replace( /\/+$/, '' );
	const response = await fetch( `${ base }/wp-json/${ restBase }?per_page=100&_fields=slug,link` );
	if ( ! response.ok ) {
		throw new Error( `page listing returned HTTP ${ response.status }` );
	}
	const items = await response.json();
	if ( ! Array.isArray( items ) ) {
		return [];
	}
	return items
		.filter( ( item ) => item && item.slug && item.link && item.slug !== profile.frontPage && ! excluded.has( item.slug ) )
		.map( ( item ) => ( { slug: String( item.slug ), url: String( item.link ) } ) );
}

/**
 * Apply the operator's top-level `pins` to a freshly analyzed design block.
 * Used by `analyze` BEFORE the measurement passes so a pinned band anchor is
 * addressable (`--geometry`, `--text`, `--colors`) in the same run that
 * re-extracts the design. Absent/unreadable profile = no pins.
 */
async function applyWritePins( design, writePath ) {
	if ( ! writePath ) {
		return design;
	}
	try {
		const existing = JSON.parse( await readFile( writePath, 'utf8' ) );
		const pins = Array.isArray( existing?.pins ) ? existing.pins : [];
		return pins.length > 0 ? applyDesignPins( design, pins ) : design;
	} catch {
		return design;
	}
}

async function runAnalyze( url, options ) {
	if ( ! url || ! /^https?:\/\//.test( url ) ) {
		fail( 'analyze needs an https?:// URL.' );
	}

	// --no-cms: a bot-protected source answers 403 on /wp-json, so CMS discovery
	// cannot run. The design/colour/text baselines only need the RENDERED
	// homepage, which normally is not blocked — so analyze that alone rather
	// than dying before any baseline is recorded (virtuallyjamaica.com).
	let profile;
	if ( options.cms === false ) {
		const host = new URL( url ).hostname.replace( /^www\./, '' );
		console.log(
			`\u26a0 --no-cms: skipping WordPress discovery for ${ host } \u2014 analyzing the rendered page only (no content collections).`
		);
		profile = { name: slugify( host ), url: url.replace( /\/+$/, '' ) };
	} else {
		const report = await analyzeSite( new WpClient( { baseUrl: url.replace( /\/+$/, '' ) } ) );
		console.log( renderReport( report ) );
		profile = profileFromDiscovery( report );
	}

	// --design (114 P1): run the design-language extraction and attach the v2
	// design block to the drafted profile. Extraction failures never block the
	// draft (the operator can still scaffold content-only).
	let designCss; // The --design stylesheets, fed to breakpoint extraction (115).
	let designInput; // Reused by --tracking so the homepage is fetched once.
	if ( options.design ) {
		try {
			const input = await fetchDesignInput( profile.url, fetch, {
				// The CLI-only loopback opt-in (local dev sites) — mirrors the
				// wizard's allowLoopback plumbing.
				allowLoopback: process.env.NVOOS_FORGE_ALLOW_LOOPBACK === '1',
			} );
			designInput = input;
			// The source's own head description (Proposal 131 §2.5). wp/v2 does not
			// expose rank_math_description on every install (jayainc returns only
			// id/slug/title/excerpt), so the rendered homepage head is the only
			// exact source. It becomes the front page's description fallback.
			const headFacts = scanSeoHtml( input.html ?? '' );
			if ( headFacts.metas.description ) {
				profile.seo = { ...( profile.seo ?? {} ), description: headFacts.metas.description };
				console.log( `\u2713 Head description captured: ${ headFacts.metas.description.slice( 0, 60 ) }${ headFacts.metas.description.length > 60 ? '\u2026' : '' }` );
			}
			const design = analyzeDesignInput( input );
			// Operator pins apply to the FRESH design before the measurement passes
			// run: `--geometry`/`--text` address a band by its anchor, and an
			// outline-only builder (Flatsome, WPBakery…) carries none until the
			// operator pins one. Applying them at merge time was too late — the
			// baseline had already measured nothing (2026-11-11).
			profile.design = await applyWritePins( design, options.write );
			designCss = ( input.css ?? [] ).join( '\n' );
			console.log( `\u2713 Design language extracted: ${ design.concept }` );
			const roles = Object.keys( design.palette.roles );
			console.log(
				`  roles: ${ roles.length > 0 ? roles.join( ', ' ) : '(none confident)' }; unassigned pool: ${ design.palette.unassigned.length } color(s); type: ${ design.typography.display ?? design.typography.body ?? 'system' }`
			);
			// The band-continuity report (124 §7) — the source's own transition
			// signature: continuous flows meet at their gradient edges; a hard
			// cut here is the source's design (the verify gate later treats any
			// built/source MISMATCH of this signature as the defect).
			const bandSections = design.sections ?? [];
			if ( bandSections.length > 0 ) {
				console.log( `  bands: ${ bandSections.length } section(s) with band facts` );
				const seams = analyzeBandContinuity( bandSections );
				if ( seams.length === 0 ) {
					console.log( '  transitions: continuous — every adjacent band meets its neighbor.' );
				} else {
					for ( const seam of seams ) {
						console.log(
							`  hard cut: ${ seam.fromId ?? '?' } ${ seam.fromEdge ?? '' } → ${ seam.toId ?? '?' } ${ seam.toEdge ?? '' } (ΔE ${ seam.distance })`
						);
					}
				}
			}
		} catch ( error ) {
			console.error( `\u26a0 Design extraction failed (continuing without): ${ error instanceof Error ? error.message : error }` );
		}
	}

	// --inventory / --wireframes (115): the site-shape analysis (one-pager vs
	// multi-page), the missing-page diff, and per-page wireframes for every
	// path the source exposes but the REST collections do not cover.
	if ( options.inventory || options.wireframes ) {
		try {
			const inventory = await discoverInventory( profile, { fetch, wayback: options.wayback === true } );
			profile.inventory = inventory;
			console.log( `\u2713 Site shape: ${ inventory.shape } (${ inventory.navAnchors.length } nav anchor(s), ${ inventory.navPages.length } nav page(s))` );
			// The verdict is not a decision. `profile.shape` drives the build; the
			// analysis only proposes it, so say so instead of letting an inference
			// quietly become the setting on a new site.
			if ( ! profile.shape ) {
				console.warn(
					`\u26a0 Site shape is not declared — add "shape": "${ inventory.shape }" to the profile to make this a decision (the verdict is used until then).`
				);
			} else if ( profile.shape !== inventory.shape ) {
				console.warn(
					`\u26a0 Site shape: the profile declares ${ profile.shape } but the source reads ${ inventory.shape }. The setting wins — confirm it is deliberate.`
				);
			}
			console.log( `  covered ${ inventory.covered.length }, excluded ${ inventory.excluded.length }, missing ${ inventory.missing.length }: ${ inventory.missing.slice( 0, 12 ).join( ' ' ) || '(none)' }` );
			for ( const anchor of inventory.danglingAnchors ) {
				console.warn( `\u26a0 Nav anchor #${ anchor } has no matching section id on the homepage.` );
			}
		} catch ( error ) {
			console.error( `\u26a0 Inventory failed (continuing without): ${ error instanceof Error ? error.message : error }` );
		}
	}
	if ( options.wireframes ) {
		const missing = profile.inventory?.missing ?? [];
		if ( missing.length === 0 ) {
			console.log( '\u2713 No missing pages — nothing to wireframe.' );
		} else {
			try {
				profile.wireframes = await extractWireframes( profile, missing, { fetch, css: designCss } );
				for ( const wireframe of Object.values( profile.wireframes ) ) {
					console.log( `\u2713 Wireframe ${ wireframe.slug } (${ wireframe.status }, ${ wireframe.sections.length } section(s))` );
				}
			} catch ( error ) {
				console.error( `\u26a0 Wireframe extraction failed (continuing without): ${ error instanceof Error ? error.message : error }` );
			}
		}
	}

	// --vocabulary (116 §3): learn the widget/block vocabulary — the block
	// registry from the WP REST API + every elementor-widget-* name on the
	// rendered homepage. Unknown names are REPORTED (they still classify as
	// `content`, never dropped) so the operator can pin them.
	if ( options.vocabulary ) {
		try {
			const home = await fetchText( fetch, profile.url, {} );
			profile.vocabulary = await learnVocabulary( profile, {
				fetch,
				htmls: home.status === 200 ? [ home.text ] : [],
			} );
			console.log(
				`\u2713 Vocabulary learned: ${ Object.keys( profile.vocabulary.blockRoles ).length } block role(s), ${ profile.vocabulary.unknownWidgets.length } unknown widget(s), ${ profile.vocabulary.unknownBlocks.length } unknown block(s).`
			);
			for ( const name of profile.vocabulary.unknownWidgets.slice( 0, 12 ) ) {
				console.warn( `  \u26a0 Unknown widget "${ name }" — pin it via profile.vocabulary.widgetRoles (no forge release needed).` );
			}
		} catch ( error ) {
			console.error( `\u26a0 Vocabulary learning failed (continuing without): ${ error instanceof Error ? error.message : error }` );
		}
	}

	// --animations (2026-11-12): inventory the source's animated effects. The
	// static pass reads @keyframes + library signatures from the HTML/CSS the
	// design pass already fetched; the browser pass uses document.getAnimations()
	// at rest and after scrolling. The browser is the only standards-based way to
	// see CSS *and* script-driven Web Animations. Fail-soft: an unavailable
	// browser or a WAF block never stops the draft.
	if ( options.animations ) {
		try {
			let animationInput = designInput;
			if ( ! animationInput ) {
				try {
					animationInput = await fetchDesignInput( profile.url, fetch, {
						allowLoopback: process.env.NVOOS_FORGE_ALLOW_LOOPBACK === '1',
					} );
				} catch ( error ) {
					console.warn(
						`\u26a0 Animated effects: the homepage stylesheets could not be fetched (${ error instanceof Error ? error.message : error }); the browser probe still runs.`
					);
				}
			}
			const inventory = await measureAnimations( {
				url: profile.url,
				html: animationInput?.html ?? '',
				css: animationInput?.css ?? [],
				timeout: 90000,
			} );
			if ( inventory.error ) {
				console.warn( `\u26a0 Animated effects: ${ inventory.error }` );
			} else {
				// A fresh `--animations` run may not carry `--design`; the site's
				// committed profile still owns the reveal/parallax entries, so use them
				// for the rebuild decision (the merge at --write keeps the design).
				let animationBehavior = profile.design?.behavior;
				if ( ! animationBehavior && options.write ) {
					try {
						const existing = JSON.parse( await readFile( options.write, 'utf8' ) );
						animationBehavior = existing.design?.behavior;
					} catch {
						// Fresh draft — no existing design to map against.
					}
				}
				const planned = planAnimationRebuild( inventory.facts, animationBehavior, { allowInfinite: true } );
				profile.animations = { ...inventory, facts: planned, counts: countAnimations( planned ) };
				const counts = countAnimations( planned );
				console.log(
					`\u2713 Animated effects: ${ planned.length } measured \u2014 ${ counts[ 'rebuild:css' ] ?? 0 } CSS rebuild, ${ counts[ 'rebuild:mapped' ] ?? 0 } mapped, ${ counts[ 'rebuild:deferred' ] ?? 0 } deferred; runtimes: ${ ( inventory.libraries ?? [] ).join( ', ' ) || '(none)' }.`
				);
				for ( const fact of planned.filter( ( entry ) => entry.rebuild === 'deferred' ).slice( 0, 8 ) ) {
					console.log( `  \u2013 ${ fact.bandId ?? '?' } ${ fact.name } (${ fact.target ?? '?' }): ${ fact.note ?? 'deferred' }` );
				}
				// Per-page inventory (opt-in): the homepage drives the generated CSS;
				// a sub-page's facts are reported in MOTION.md so the operator can see
				// what moves there without pretending the homepage band ids apply.
				const pageLimit = Number( options.animationsPages ?? 0 );
				if ( Number.isFinite( pageLimit ) && pageLimit > 0 ) {
					try {
						const pages = await listSourcePages( profile );
						profile.pageAnimations = {};
						for ( const page of pages.slice( 0, pageLimit ) ) {
							const pageInventory = await measureAnimations( { url: page.url, timeout: 90000 } );
							if ( pageInventory.error ) {
								console.warn( `  \u26a0 /${ page.slug }/: ${ pageInventory.error }` );
								continue;
							}
							const pagePlanned = planAnimationRebuild( pageInventory.facts, animationBehavior, {
								allowInfinite: true,
							} );
							profile.pageAnimations[ page.slug ] = {
								...pageInventory,
								facts: pagePlanned,
								counts: countAnimations( pagePlanned ),
							};
							console.log( `  \u2013 /${ page.slug }/: ${ pagePlanned.length } animated fact(s)` );
						}
					} catch ( error ) {
						console.warn(
							`\u26a0 Per-page animation inventory skipped: ${ error instanceof Error ? error.message : error }`
						);
					}
				}
			}
		} catch ( error ) {
			console.error( `\u26a0 Animated effects failed (continuing without): ${ error instanceof Error ? error.message : error }` );
		}
	}

	// --geometry (114 §3.6): measure the SOURCE bands so `verify --geometry` can
	// diff the build against real boxes instead of inferred CSS. Browser-backed,
	// so it stays opt-in (the parity gate above never needs a browser).
	if ( options.geometry || options.pages ) {
		let bands = profile.design?.sections ?? [];
		// `--geometry` must not force a design re-analysis: re-extracting the
		// design block overwrites the hand-tuned chrome/tokens an operator has
		// already pinned. When the fresh draft has no design block (the common
		// case — the site is already profiled), read the bands from the profile
		// being written, exactly as `--text` does below.
		if ( bands.length === 0 && options.write ) {
			try {
				const existing = JSON.parse( await readFile( options.write, 'utf8' ) );
				bands = existing.design?.sections ?? [];
			} catch {
				// No existing profile — nothing to measure.
			}
		}
		const ids = bands.map( ( section ) => section.id ).filter( Boolean );
		// Outline-band builders (WPBakery/Brando here) emit no Elementor class, so
		// the id selector alone measures nothing. The band's own anchor is the one
		// stable handle the source does expose — the nav links to it.
		const selectors = Object.fromEntries(
			bands.filter( ( band ) => band.id && band.anchor ).map( ( band ) => [ band.id, `#${ band.anchor }` ] )
		);
		if ( ids.length === 0 ) {
			console.warn(
				'\u26a0 --geometry needs a design block to know which bands to measure — pass --design, or --write an existing profile that already has one.'
			);
		} else {
			try {
				profile.geometry = await measureGeometry( {
					url: profile.url,
					ids,
					kind: 'source',
					selectors,
				} );
				const measured = Object.keys( profile.geometry ).length;
				console.log(
					`\u2713 Geometry baseline: ${ measured }/${ ids.length } band(s) at ${ DEFAULT_GEOMETRY_VIEWPORTS.length } viewports.`
				);
				// The band presentation facts ride the same pass (container width +
				// the band's own heading/body typography). Without them a raw-HTML
				// band renders at the browser's default heading scale.
				try {
					profile.bandFacts = await measureBandFacts( {
						url: profile.url,
						ids,
						kind: 'source',
						selectors,
					} );
					console.log(
						`\u2713 Band facts: ${ Object.keys( profile.bandFacts ).length } band(s) — container width + typography.`
					);
				} catch ( error ) {
					console.warn(
						`\u26a0 Band facts skipped: ${ error instanceof Error ? error.message : error }`
					);
				}
				// Interaction states (hover / focus / focus-visible / active) ride the
				// same pass. The vendor sheet never ships, so a rebuilt control loses
				// every state effect the source expressed in CSS — the flatness a
				// reader notices first. Forced pseudo-classes are the only faithful
				// source (the rules live behind builder classes and theme variables).
				try {
					const { facts: interactions, skippedChildStates } = await measureInteractions( {
						url: profile.url,
						ids,
						selectors,
					} );
					const bandsWithStates = Object.keys( interactions ).length;
					if ( profile.bandFacts ) {
						for ( const [ id, facts ] of Object.entries( interactions ) ) {
							profile.bandFacts[ id ] = { ...( profile.bandFacts[ id ] ?? {} ), interactions: facts };
						}
					}
					const states = countInteractionStates( interactions );
					if ( states > 0 ) {
						console.log(
							`\u2713 Interaction states: ${ states } state(s) on ${ bandsWithStates } band(s) — hover, focus, focus-visible, active.`
						);
					} else {
						console.log( '  Interaction states: none (the source declares no hover/focus/active effect).' );
					}
					if ( skippedChildStates > 0 ) {
						console.log(
							`  \u2139 ${ skippedChildStates } child effect(s) NOT recorded \u2014 the child is a bare slot whose fill lives on a pseudo-element (an overlay ::after), which a rebuild does not ship.`
						);
					}
				} catch ( error ) {
					console.warn(
						`\u26a0 Interaction states skipped: ${ error instanceof Error ? error.message : error }`
					);
				}
				// The sticky header rides the same pass: pinning, the scrolled
				// background and the shrink are all browser facts.
				try {
					profile.stickyHeader = await measureStickyHeader( { url: profile.url } );
					const sticky = profile.stickyHeader;
					if ( sticky.sticky ) {
						const state = sticky.bgChanged
							? `bg ${ sticky.restBg ?? 'transparent' } \u2192 ${ sticky.pinnedBg }`
							: 'bg unchanged';
						console.log(
							`\u2713 Sticky header: pinned, ${ state }${ sticky.heightChanged ? `, ${ sticky.restHeight }px \u2192 ${ sticky.pinnedHeight }px` : '' }`
						);
					} else {
						console.log( '  Sticky header: none (the source header does not pin).' );
					}
				} catch ( error ) {
					console.warn(
						`\u26a0 Sticky header skipped: ${ error instanceof Error ? error.message : error }`
					);
				}
				// The mobile nav disclosure: presence comes from the markup (design.ts),
				// the widths it appears at from a browser. Without it the rebuild wraps
				// the nav into a stack where the source shows a hamburger.
				try {
					const toggle = await measureNavToggle( { url: profile.url } );
					if ( toggle ) {
						profile.design = profile.design ?? {};
						profile.design.chrome = profile.design.chrome ?? {};
						profile.design.chrome.navToggle = {
							...profile.design.chrome.navToggle,
							visible: toggle.visible,
						};
						const at = Object.entries( toggle.visible )
							.filter( ( [ , shown ] ) => shown )
							.map( ( [ device ] ) => device )
							.join( ', ' );
						console.log( `\u2713 Nav toggle: visible at ${ at }.` );
					} else {
						console.log( '  Nav toggle: none (the source nav never collapses).' );
					}
				} catch ( error ) {
					console.warn(
						`\u26a0 Nav toggle skipped: ${ error instanceof Error ? error.message : error }`
					);
				}
				// A baseline that measured nothing used to report success, which left
				// `verify --geometry` with no expectations and silently passing.
				if ( measured === 0 ) {
					console.warn(
						'\u26a0 No band boxes matched — the source uses a builder whose elements carry neither an Elementor class nor the profile anchor. Geometry is NOT recorded.'
					);
				} else if ( measured < ids.length ) {
					const missed = ids.filter( ( id ) => ! profile.geometry[ id ] );
					console.warn( `\u26a0 ${ missed.length } band(s) measured nothing: ${ missed.join( ', ' ) }` );
				}
			} catch ( error ) {
				console.warn( `\u26a0 Geometry baseline skipped: ${ error instanceof Error ? error.message : error }` );
			}
		}

		// --pages: the same measurement for EVERY page the rebuild renders. A
		// positional band list is per-PAGE data — the front page's heights and
		// surfaces applied to a sub-page pinned a 7,497px article band.
		if ( options.pages ) {
			try {
				const targets = await listSourcePages( profile );
				const measured = await measurePageBands( {
					pages: targets,
					limit: Number( options.pagesLimit ?? 8 ),
					onPage: ( slug, result, error ) => {
						if ( error ) {
							console.warn( `  \u26a0 /${ slug }/: skipped (${ error instanceof Error ? error.message : error }).` );
						} else if ( ! result ) {
							console.log( `  \u2013 /${ slug }/: no bands found (nothing to measure).` );
						} else {
							console.log( `  \u2713 /${ slug }/: ${ Object.keys( result.geometry ).length }/${ result.sections.length } band(s).` );
						}
					},
				} );
				profile.pageBands = measured;
				console.log( `\u2713 Per-page bands: ${ Object.keys( measured ).length } page(s) measured.` );
			} catch ( error ) {
				console.warn( `\u26a0 Per-page bands skipped: ${ error instanceof Error ? error.message : error }` );
			}
		}
	}

	// --text: record the source's normalised visible text so `verify --text`
	// can diff the build's content without any network at verify time (the
	// same baseline discipline `--geometry` uses). Pure extraction — no model,
	// no browser.
	if ( options.text ) {
		let bands = profile.design?.sections ?? [];
		// `--text` should not force a design re-analysis: when the fresh draft
		// has no design block, take the bands from the profile being written.
		if ( bands.length === 0 && options.write ) {
			try {
				const existing = JSON.parse( await readFile( options.write, 'utf8' ) );
				bands = existing.design?.sections ?? [];
			} catch {
				// No existing profile — page-level text only.
			}
		}
		const ids = bands.map( ( section ) => section.id ).filter( Boolean );
		// An outline band's source node carries the vendor DOM id, never the
		// stable hash — pass the anchor as a locator alias so the baseline keeps
		// per-band text (Proposal 115's band check) instead of going page-level.
		const selectors = Object.fromEntries(
			bands
				.filter( ( section ) => section.id && section.anchor )
				.map( ( section ) => [ section.id, section.anchor ] )
		);
		try {
			const home = designInput?.html
				? { status: 200, text: designInput.html }
				: await fetchText( fetch, profile.url, {} );
			if ( home.status !== 200 || ! home.text ) {
				console.warn( `\u26a0 Text baseline skipped: the homepage returned ${ home.status }.` );
			} else {
				profile.text = textBaselineFromHtml( home.text, {
					url: profile.url,
					ids,
					selectors,
					includeAlt: options.textAlt === true,
				} );
				console.log(
					`\u2713 Text baseline: ${ profile.text.page.length } chars, ${ profile.text.sections.length } band(s), ${ profile.text.skipped.length } band(s) skipped.`
				);
				if ( profile.text.sections.length === 0 && ( profile.design?.outline ?? [] ).length > 0 ) {
					console.log(
						'  per-band text needs addressable band ids (design.sections + an anchor/domId); this site is outline-only and unanchored, so the baseline is page-level.'
					);
				}
			}
		} catch ( error ) {
			console.warn( `\u26a0 Text baseline skipped: ${ error instanceof Error ? error.message : error }` );
		}
	}

	// --colors: record the RENDERED background/text colour of the page regions
	// (body, header, footer). Computed styles are machine-stable, so the
	// baseline is plain data — the same discipline as --geometry.
	if ( options.colors ) {
		try {
			const modes = parseColorModes( options.colorsModes );
			// The band surfaces matter as much as the chrome: measure one region per
			// renderable band anchor, with the composited background.
			const regions = [ ...DEFAULT_COLOR_REGIONS, ...bandColorRegions( renderableBands( profile.design ) ) ];
			profile.colors = await measureRegionColors( { url: profile.url, modes, regions, effective: true } );
			for ( const mode of Object.keys( profile.colors ) ) {
				const regions = Object.keys( profile.colors[ mode ] );
				console.log( `\u2713 Colour baseline (${ mode }): ${ regions.length } region(s) \u2014 ${ regions.join( ', ' ) || 'none' }.` );
				for ( const region of [ 'body', 'header', 'footer' ] ) {
					if ( ! profile.colors[ mode ][ region ] ) {
						console.warn( `  \u26a0 ${ region } not found in the source markup (${ mode } selector ladder missed it).` );
					}
				}
			}
			// Bake the measured band SURFACES into the homepage's band facts: a
			// gradient painted on a child layer is invisible to the CSS harvest (the
			// sanitizer drops the `<style>` that declared it), and an outline band
			// carries no facts at all. Pinned facts win — `applyBandSurfaces` never
			// overwrites a surface that is already set.
			const light = profile.colors.light ?? {};
			if ( profile.design ) {
				applyBandSurfaces(
					profile.design.sections ?? [],
					( section ) => ( section.anchor ? light[ `band:${ section.anchor }` ] : undefined ),
					light.body?.background
				);
			}
		} catch ( error ) {
			console.warn( `\u26a0 Colour baseline skipped: ${ error instanceof Error ? error.message : error }` );
		}
	}

	// --tracking (130): detect the source's measurement surface — analytics,
	// ad pixels, a CMP and the ownership-verification tokens — and draft the
	// tracking block with every tracker DISABLED. Detection is never consent
	// to ship a tag; the operator promotes what the client keeps.
	if ( options.tracking ) {
		try {
			const report = await analyzeTracking( {
				url: profile.url,
				fetchImpl: fetch,
				html: designInput?.html,
				pageUrls: trackingPageUrls( profile, options ),
				probe: options.probe === true,
			} );
			profile.tracking = trackingFromReport( report, {
				hasCommerce: profile.collections.some( ( collection ) => collection.slug === 'products' ),
			} );
			console.log( renderTrackingReport( report ) );
			if ( profile.tracking.trackers.length > 0 ) {
				console.log(
					`\u2713 Tracking drafted: ${ profile.tracking.trackers.length } vendor(s), all DISABLED — enable what the client keeps, then re-scaffold.`
				);
			}
		} catch ( error ) {
			console.error( `\u26a0 Tracking analysis failed (continuing without): ${ error instanceof Error ? error.message : error }` );
		}
	}

	// The MEASURED sticky header outranks the stylesheet harvest, and it has to be
	// reconciled on the profile that is actually written: a `--geometry` run
	// without `--design` carries no fresh design block, so the chrome that ships
	// comes from the merged EXISTING profile (Brando declares a red `.navbar`
	// background that a transparent variation overrides — only the browser knows).
	const reconcileHeader = ( target ) => {
		applyMeasuredHeader( target?.design?.chrome, target?.stickyHeader ?? profile.stickyHeader );
		return target;
	};
	reconcileHeader( profile );
	const profileJson = `${ JSON.stringify( profile, null, '\t' ) }\n`;
	if ( options.write ) {
		// Merge into an existing profile by default (Proposal 118 §3): the
		// operator's hand-tuned collections/tokens/frontPage/forms survive;
		// the analyzed blocks refresh. `--fresh` forces a clean draft.
		let text = profileJson;
		let mode = 'Draft profile written to';
		try {
			const existing = JSON.parse( await readFile( options.write, 'utf8' ) );
			if ( existing && typeof existing === 'object' ) {
				const pins = Array.isArray( existing.pins ) ? existing.pins : [];
				if ( options.fresh ) {
					if ( pins.length > 0 ) {
						console.warn( `  \u26a0 --fresh drops ${ pins.length } operator pin(s) in ${ options.write } — re-add them, or drop the flag to merge.` );
					}
				} else {
					const merged = mergeProfileDraft( existing, profile );
					// `reconcileHeader` runs LAST, so the diff must too: it can re-add
					// header facts, and reporting those as dropped would be a false alarm.
					const reconciled = reconcileHeader( merged );
					text = `${ JSON.stringify( reconciled, null, '\t' ) }\n`;
					mode = 'Draft profile MERGED into';
					// Pins are re-applied by the merge, so anything still dropped
					// means the source no longer has that section — say so instead
					// of letting the operator assume the pin held.
					for ( const pin of findDroppedPins( reconciled.design, pins ) ) {
						console.warn( `  \u26a0 Pin "${ pin.path }" could not be re-applied — its section is gone from the source.` );
					}
					// Unpinned facts the refresh failed to re-extract. Mostly that is a
					// REAL source change, but it is not always: virtuallyjamaica's #wifi
					// anchor was silently lost this way (2026-11-11). Name what went so
					// the operator can tell the two apart and pin what still exists.
					for ( const dropped of diffDesignFacts( existing.design, reconciled.design ) ) {
						console.warn( `  \u26a0 Design refresh dropped ${ dropped } — pin it (pins) if the source still has it.` );
					}
				}
			}
		} catch {
			// No existing file — a fresh write.
		}
		await writeFile( options.write, text, 'utf8' );
		console.log( `\u2713 ${ mode } ${ options.write } — review excludes/frontPage, then scaffold.` );
	} else {
		console.log( '--- draft site.json ---' );
		console.log( profileJson.trimEnd() );
	}
	// Graceful exit — process.exit() races undici's teardown on Windows and
	// dies with the libuv UV_HANDLE_CLOSING assertion (0xC0000409).
}

async function runSiteAdd( name, profilePath, options ) {
	if ( ! name || ! profilePath ) {
		fail( 'site add needs <name> <profile.json>.' );
	}
	let url = options.url;
	const isDefault = options.default === true;
	const profile = JSON.parse( await readFile( profilePath, 'utf8' ) );
	if ( ! url && profile.url ) url = profile.url;
	if ( ! url ) {
		console.error( 'No URL — pass --url <url> or include "url" in the profile.' );
		process.exit( 1 );
	}
	const sites = new SitesStore();
	await sites.add( name, { url, profile: profilePath }, { isDefault } );
	console.log( `\u2713 Registered site "${ name }" -> ${ url } (${ profilePath })` );
}

async function runSiteRemove( name ) {
	if ( ! name ) {
		fail( 'site remove needs <name>.' );
	}
	await ( new SitesStore() ).remove( name );
	console.log( `\u2713 Removed site "${ name }".` );
}

async function runSiteList() {
	const sites = new SitesStore();
	const listed = await sites.list();
	const defaultName = await sites.defaultName();
	if ( Object.keys( listed ).length === 0 ) {
		console.log( 'No registered sites.' );
		return;
	}
	for ( const [ name, ref ] of Object.entries( listed ) ) {
		console.log( `${ name === defaultName ? '*' : ' ' } ${ name } -> ${ ref.url } (${ ref.profile })` );
	}
}

async function runSiteUse( name ) {
	if ( ! name ) {
		fail( 'site use needs <name>.' );
	}
	await ( new SitesStore() ).setDefault( name );
	console.log( `\u2713 Default site is now "${ name }".` );
}

async function runVaultSet( name ) {
	if ( ! name ) {
		fail( 'vault set needs <name>.' );
	}
	const vault = new Vault();
	// The secret arrives on stdin (never argv, never the shell history).
	const value = await new Promise( ( resolvePromise ) => {
		let input = '';
		process.stdin.setEncoding( 'utf8' );
		process.stdin.on( 'data', ( chunk ) => ( input += chunk ) );
		process.stdin.on( 'end', () => resolvePromise( input.replace( /[\r\n]+$/, '' ) ) );
		process.stdin.resume();
	} );
	if ( ! value ) {
		console.error( 'No value on stdin — nothing stored.' );
		process.exit( 1 );
	}
	await vault.set( name, value );
	console.log( `\u2713 Stored vault entry "${ name }" (${ maskSecret( value ) }).` );
}

async function runVaultDelete( name ) {
	if ( ! name ) {
		fail( 'vault delete needs <name>.' );
	}
	await ( new Vault() ).delete( name );
	console.log( `\u2713 Deleted vault entry "${ name }".` );
}

async function runVaultList() {
	console.log( ( await new Vault().list() ).join( '\n' ) || '(empty)' );
}

async function runDeploy( profileRef, options ) {
	const mode = options.to ?? 'github-pages';
	if ( ! DEPLOY_MODES.includes( mode ) ) {
		failDeployMode( mode );
	}
	assertMirrorOptions( options );
	assertRuntime( options );
	if ( options.preset && ! PRESET_NAMES.includes( options.preset ) ) {
		failPreset( options.preset );
	}
	if ( options.render && ! RENDER_MODES.includes( options.render ) ) {
		failRender( options.render );
	}
	assertThemePolicy( options.themePolicy );
	const profile = await loadProfile( profileRef );
	assertProfile( profile );

	// 1. Scaffold (idempotent — an existing forge project is reused).
	//    The preset (when passed) applies its collection profile + surfaces
	//    inside scaffold() — no pre-application needed here.
	try {
		await stat( join( options.target, 'nvoos.json' ) );
		console.log( `\u2713 Target ${ options.target } is already a forge project — reusing.` );
	} catch {
		const result = await scaffold( {
			profile,
			targetDir: options.target,
			preset: options.preset,
			render: options.render,
			surfaces: options.ogImages ? { ogImages: true } : undefined,
			agents: options.agents,
			themePolicy: options.themePolicy,
			strictContrast: options.strictContrast === true,
			demoContent: options.demoContent === true,
			unlock: options.unlock === true,
		} );
		console.log( `\u2713 Scaffolded ${ result.collections.length } collection(s) into ${ result.targetDir }` );
		for ( const warning of result.warnings ) {
			console.warn( `\u26a0 ${ warning }` );
		}
	}

	// 2. Emit the deploy pipeline (adapter-generic — rebuild wrapper, deploy
	//    workflow, and the fail-closed dispatch verifier).
	const emitted = await emitDeployConfig( options.target, {
		mode,
		siteUrl: resolveSiteUrl( profile, options.siteUrl ),
		cloudflareProject: options.cloudflareProject,
		mirrorRepo: options.mirrorRepo,
		siteBranch: options.siteBranch,
		runtime: options.runtime,
	} );
	for ( const file of emitted.files ) {
		console.log( `  + ${ file }` );
	}

	// 2b. Shared-mirror deploy: no per-site repo. The generated source lives
	//     wherever the operator keeps it (the monorepo sites/ convention); the
	//     workflow ships dist/ to the mirror branch. Here we mirror a prebuilt
	//     dist/ directly — the operator staging path. Same emitted script as
	//     the workflow, so the two paths cannot drift.
	if ( options.mirrorRepo ) {
		const dist = join( options.target, 'dist' );
		if ( ! existsSync( dist ) ) {
			console.log( `\u26a0 No dist/ under ${ options.target } — run \`npm install && npm run build\` in the project (ASTRO_SITE=${ String( profile.url ) }), then re-run this command to mirror it.` );
			return;
		}
		const token = process.env.GITHUB_TOKEN ?? ( await new Vault().get( 'github' ).catch( () => undefined ) );
		if ( ! token ) {
			console.error( 'No GitHub token — set GITHUB_TOKEN or store one via `node scripts/forge.mjs vault set github` (value on stdin).' );
			process.exit( 1 );
		}
		console.log( `\u2713 Mirroring dist/ -> ${ options.mirrorRepo }@${ options.siteBranch }` );
		try {
			execFileSync( 'node', [ join( options.target, 'scripts', 'mirror-push.mjs' ) ], {
				stdio: 'inherit',
				env: {
					...process.env,
					MIRROR_URL: `https://github.com/${ options.mirrorRepo }.git`,
					MIRROR_TOKEN: token,
					SITE_BRANCH: options.siteBranch,
					SOURCE_DIR: dist,
				},
			} );
		} catch ( error ) {
			console.error( error instanceof Error ? error.message : error );
			process.exitCode = 1;
		}
		return;
	}

	// 3. Resolve per-invocation auth — env first, then the vault. Never
	//    persisted here, never printed.
	const token = process.env.GITHUB_TOKEN ?? ( await new Vault().get( 'github' ).catch( () => undefined ) );
	if ( ! token ) {
		console.error( 'No GitHub token — set GITHUB_TOKEN or store one via `node scripts/forge.mjs vault set github` (value on stdin).' );
		process.exit( 1 );
	}
	console.log( `\u2713 GitHub auth: ${ maskSecret( token ) } (per-invocation, not persisted)` );

	// 4. Collect the source payload (binary + build artifacts excluded).
	const files = await collectProjectFiles( options.target );

	// 5. Create (private by default) + push.
	const deploy = new GitHubDeploy( await createOctokitClient( token ) );
	const repoName = options.repo ?? slugify( String( profile.name ?? profile.url ) );
	const owner = options.org;
	let repoResult;
	try {
		repoResult = await deploy.createRepo( {
			name: repoName,
			description: `Generated frontend for ${ String( profile.url ) } (NV oOS Astro Forge).`,
			org: owner,
			isPrivate: ! options.public,
		} );
	} catch ( error ) {
		// The repo may already exist — reuse it (user account only) when the
		// name is taken; anything else fails the command.
		const message = error instanceof Error ? error.message : String( error );
		if ( owner || ! /name already exists|422/i.test( message ) ) {
			console.error( message );
			process.exitCode = 1;
			return;
		}
		try {
			const login = await deploy.login();
			repoResult = { owner: login, name: repoName, isPrivate: true, htmlUrl: '' };
			console.log( `\u2713 Repository ${ login }/${ repoName } already exists — reusing.` );
		} catch ( loginError ) {
			console.error( loginError instanceof Error ? loginError.message : loginError );
			process.exitCode = 1;
			return;
		}
	}
	console.log( `\u2713 Repository ${ repoResult.owner }/${ repoResult.name } (${ repoResult.isPrivate ? 'private' : 'PUBLIC' })` );

	try {
		const pushed = await deploy.commitFiles( {
			owner: owner ?? repoResult.owner,
			repo: repoResult.name,
			files,
			message: 'Forge: generated site (Phase 3 deploy)',
		} );
		console.log( `\u2713 Pushed ${ pushed.files } files -> ${ pushed.owner }/${ pushed.repo }@${ pushed.branch } (${ pushed.commitSha.slice( 0, 8 ) })` );
		console.log( `Next: configure repo secrets (FORGE_WEBHOOK_SECRET${ mode === 'velocity' ? ', VELOCITY_*' : '' }${ mode === 'cloudflare-pages' ? ', CLOUDFLARE_*' : '' }) and enable the Pages/mirror target.` );
	} catch ( error ) {
		console.error( error instanceof Error ? error.message : error );
		process.exitCode = 1;
	}
}

/**
 * Re-apply the profile's design tokens to an EXISTING forged project.
 *
 * The token block is rewritten only inside the scaffold pipeline, so a project
 * whose `global.css` was replaced or hand-copied (the usual way a template CSS
 * fix reaches a built site) silently loses every `--forge-*` token and builds
 * with the template defaults. A full re-scaffold fixes it but also wipes
 * `node_modules`; this rewrites just the FORGE:tokens span.
 */
async function runTokens( profileRef, options ) {
	if ( ! options.target ) {
		fail( '--target <dir> is required.' );
	}
	const profile = await loadProfile( profileRef );
	assertProfile( profile );
	const cssPath = join( options.target, 'src', 'styles', 'global.css' );
	const css = await readFile( cssPath, 'utf8' ).catch( () => null );
	if ( css === null ) {
		fail( `Cannot read ${ cssPath} — is that a forged project?` );
	}
	const tokens = mergeTokens( profile.design, profile.tokens, profile.colors );
	const audit = auditTokenBlock( css, tokens );
	if ( ! audit.markers ) {
		fail( 'global.css has no FORGE:tokens span — re-scaffold the project.' );
	}
	if ( options.check ) {
		if ( audit.drifted.length === 0 ) {
			console.log( `\u2713 Token block: all ${ Object.keys( tokens ).length } token(s) match the profile.` );
			return;
		}
		for ( const drift of audit.drifted ) {
			console.error( `\u2717 --forge-${ drift.name }: on disk ${ drift.actual ?? '(missing)' }, profile ${ drift.expected }` );
		}
		process.exitCode = 1;
		return;
	}
	await writeFile( cssPath, rewriteGlobalCss( css, tokens ), 'utf8' );
	console.log( `\u2713 Re-applied ${ Object.keys( tokens ).length } token(s) to ${ cssPath }` );
	for ( const drift of audit.drifted ) {
		console.log( `  ${ drift.name }: ${ drift.actual ?? '(missing)' } \u2192 ${ drift.expected }` );
	}
}

async function runScaffold( profileRef, options ) {
	if ( ! options.target ) {
		fail( '--target <dir> is required.' );
	}
	if ( options.preset && ! PRESET_NAMES.includes( options.preset ) ) {
		failPreset( options.preset );
	}
	if ( options.render && ! RENDER_MODES.includes( options.render ) ) {
		failRender( options.render );
	}
	assertThemePolicy( options.themePolicy );
	const profile = await loadProfile( profileRef );
	assertProfile( profile );

	if ( options.check ) {
		const clean = await checkProfile( profile );
		console.log( clean ? 'All collections fit within their page caps.' : 'Some collections exceed their caps — raise maxPages in the profile.' );
		// Graceful exit — see the analyze branch (Windows libuv teardown).
		process.exitCode = clean ? 0 : 1;
		return;
	}

	// scaffold() applies the preset's collection profile + surface toggles.
	// The profile reference doubles as the report's site slug
	// (`sites/<slug>.json` -> `<slug>`), so the generated README can print an
	// exact rebuild command instead of guessing one from the display name.
	const siteSlug =
		typeof profileRef === 'string'
			? profileRef.replace( /\\/g, '/' ).split( '/' ).pop().replace( /\.json$/, '' )
			: undefined;
	const result = await scaffold( {
		profile,
		targetDir: options.target,
		template: options.template,
		overwrite: options.overwrite === true,
		preset: options.preset,
		render: options.render,
		surfaces: options.ogImages ? { ogImages: true } : undefined,
		agents: options.agents,
		themePolicy: options.themePolicy,
		strictContrast: options.strictContrast === true,
		demoContent: options.demoContent === true,
		unlock: options.unlock === true,
		siteSlug,
	} );
	console.log( `\u2713 Scaffolded ${ result.collections.length } collection(s) into ${ result.targetDir }` );
	for ( const collection of result.manifest.collections ) {
		console.log( `  - ${ collection.slug } <- ${ collection.restBase }` );
	}
	for ( const warning of result.warnings ) {
		console.warn( `\u26a0 ${ warning }` );
	}
	console.log( 'Next: cd into the project, `npm install`, `npm run dev` (or `npm run build`).' );
}

async function checkProfile( profile ) {
	const client = WpClient.fromProfile( profile );
	let clean = true;
	try {
		const root = await client.root();
		console.log( `\u2713 ${ profile.url } — "${ root.name }" (${ root.namespaces.length } namespaces)` );
	} catch ( error ) {
		console.error( `\u2717 ${ profile.url } — unreachable: ${ error instanceof Error ? error.message : error }` );
		process.exit( 1 );
	}
	for ( const collection of profile.collections ) {
		const perPage = collection.perPage ?? 100;
		const maxPages = collection.maxPages ?? 50;
		try {
			const { headers } = await client.get( collection.restBase, { page: '1', per_page: '1' } );
			const total = Number( headers.get( 'x-wp-total' ) ?? '0' );
			const pages = Math.ceil( total / perPage );
			const state = pages > maxPages ? 'TRUNCATED' : 'ok';
			if ( pages > maxPages ) clean = false;
			console.log( `  ${ state === 'ok' ? '\u2713' : '\u26a0' } ${ collection.slug }: ${ total } entries / ${ pages } pages (cap ${ maxPages }) — ${ state }` );
		} catch ( error ) {
			clean = false;
			console.error( `  \u2717 ${ collection.slug }: fetch failed — ${ error instanceof Error ? error.message : error }` );
		}
	}
	return clean;
}

/** Emit the deploy pipeline into a scaffolded target (no network, no token). */
async function runDeployConfig( profileRef, options ) {
	const mode = options.to ?? 'github-pages';
	if ( ! DEPLOY_MODES.includes( mode ) ) {
		failDeployMode( mode );
	}
	assertMirrorOptions( options );
	assertRuntime( options );
	let siteUrl = options.siteUrl;
	if ( profileRef ) {
		const profile = await loadProfile( profileRef );
		siteUrl = resolveSiteUrl( profile, options.siteUrl );
	}
	try {
		const emitted = await emitDeployConfig( options.target, {
			mode,
			siteUrl,
			cloudflareProject: options.cloudflareProject,
			mirrorRepo: options.mirrorRepo,
			siteBranch: options.siteBranch,
			runtime: options.runtime,
		} );
		console.log( `\u2713 Emitted the deploy pipeline into ${ options.target }:` );
		for ( const file of emitted.files ) {
			console.log( `  + ${ file }` );
		}
		console.log( 'Next: configure the repo secrets (FORGE_WEBHOOK_SECRET, target-specific) and push.' );
	} catch ( error ) {
		console.error( error instanceof Error ? error.message : error );
		process.exitCode = 1;
	}
}

async function runWizard( url, options ) {
	const mode = options.to ?? 'github-pages';
	if ( ! DEPLOY_MODES.includes( mode ) ) {
		failDeployMode( mode );
	}
	if ( options.preset && ! PRESET_NAMES.includes( options.preset ) ) {
		failPreset( options.preset );
	}
	if ( options.render && ! RENDER_MODES.includes( options.render ) ) {
		failRender( options.render );
	}
	assertThemePolicy( options.themePolicy );
	// Interactive target prompt (clack text) when the flag is missing on a
	// TTY; a hard error otherwise (the old CLI required it, and CI does).
	let target = options.target;
	if ( ! target && isInteractive() ) {
		const answer = await text( {
			message: 'Where should the project be scaffolded?',
			placeholder: './customer-frontend',
		} );
		if ( isCancel( answer ) ) {
			console.log( 'Wizard cancelled.' );
			process.exitCode = 0;
			return;
		}
		target = String( answer ?? '' ).trim();
	}
	if ( ! target ) {
		fail( '--target <dir> is required.' );
	}

	// Interactive preset + render choice and the final confirm (clack
	// select/confirm). Non-TTY (CI/e2e) runs stay deterministic:
	// suggestPreset() picks the preset, the render stays 'static', no
	// prompts.
	const ui = isInteractive()
		? {
				selectPreset: async ( suggested, report ) => {
					const chosen = await select( {
						message: `Preset (suggested: ${ suggested }${ report.woocommerce ? ' — WooCommerce detected' : '' }):`,
						initialValue: suggested,
						options: PRESET_NAMES.map( ( name ) => ( {
							value: name,
							label: name,
							hint: PRESETS[ name ].description,
						} ) ),
					} );
					if ( isCancel( chosen ) ) return null;
					return chosen;
				},
				selectRender: async ( suggested, preset ) => {
					// Only the storefront preset offers the choice (104 Q4) —
					// blogs stay static unless --render says otherwise.
					const chosen = await select( {
						message: `Render mode for the ${ preset } preset:`,
						initialValue: suggested,
						options: [
							{ value: 'static', label: 'static', hint: 'prerendered product pages (the default)' },
							{ value: 'ssr', label: 'ssr', hint: 'on-demand product route + server output' },
						],
					} );
					if ( isCancel( chosen ) ) return null;
					return chosen;
				},
				selectThemePolicy: async ( suggested ) => {
					// The site-level theme decision (105 §4), default auto.
					const chosen = await select( {
						message: 'Theme policy (site-level; auto respects the visitor preference):',
						initialValue: suggested,
						options: [
							{ value: 'auto', label: 'auto', hint: 'stored preference → system → default (the default)' },
							{ value: 'light', label: 'light', hint: 'forced light — the toggle disables' },
							{ value: 'dark', label: 'dark', hint: 'forced dark — the toggle disables' },
							{ value: 'brand', label: 'brand:<slug>', hint: 'force a registered brand theme' },
						],
					} );
					if ( isCancel( chosen ) ) return null;
					if ( chosen === 'brand' ) {
						const slugAnswer = await text( {
							message: 'Brand theme slug (a-z0-9-):',
							placeholder: 'lenas-chair',
						} );
						if ( isCancel( slugAnswer ) ) return null;
						const slug = String( slugAnswer ?? '' ).trim();
						if ( ! slug || ! THEME_POLICY_SLUG_REGEX.test( slug ) ) {
							fail( `Invalid brand slug "${ slug }" — must match a-z0-9- (e.g. lenas-chair).` );
						}
						return `brand:${ slug }`;
					}
					return chosen;
				},
				confirmScaffold: async ( preset, targetDir ) => {
					const ok = await confirm( {
						message: `Scaffold the ${ preset } preset into ${ targetDir }?`,
					} );
					return ok === true;
				},
				confirmDesign: async ( design ) => {
					// The design step (114 P6) — confirm the extracted language
					// before it becomes the rebuild theme.
					const ok = await confirm( {
						message: `Design language extracted: ${ design.concept } Apply it as the rebuild theme?`,
					} );
					return ok === true;
				},
			}
		: undefined;

	const s = isInteractive() ? spinner() : undefined;
	try {
		if ( s ) s.start( 'Running the setup wizard…' );
		const result = await setupWizard( {
			url,
			targetDir: target,
			name: options.name,
			mode,
			cloudflareProject: options.cloudflareProject,
			build: options.build === true,
			preset: options.preset,
			render: options.render,
			ogImages: options.ogImages === true,
			agents: options.agents,
			themePolicy: options.themePolicy,
			design: options.design === true,
			strictContrast: options.strictContrast === true,
			unlock: options.unlock === true,
			ui,
			// Local-dev opt-in only — the MCP surface never sets this.
			allowLoopback: process.env.NVOOS_FORGE_ALLOW_LOOPBACK === '1',
		} );
		if ( s ) s.stop( 'Setup wizard complete.' );
		console.log( `\u2713 Wizard complete — site "${ result.siteName }" -> ${ result.targetDir }` );
		console.log( `  built: ${ result.built }, git: ${ result.gitInitialized ? 'initialized' : 'skipped' }` );
		for ( const file of result.deployFiles ) {
			console.log( `  + ${ file }` );
		}
		for ( const warning of result.warnings ) {
			console.warn( `\u26a0 ${ warning }` );
		}
	} catch ( error ) {
		if ( s ) s.stop( 'Setup wizard failed.' );
		if ( error instanceof Error && error.message === 'Wizard cancelled.' ) {
			console.log( 'Wizard cancelled.' );
			process.exitCode = 0;
			return;
		}
		console.error( error instanceof Error ? error.message : error );
		process.exitCode = 1;
	}
}

// -- verify (114 P5) ---------------------------------------------------------

/**
 * One colour region per renderable band anchor. The default ladder only covers
 * body/header/footer, so a source whose SURFACES carry the design
 * (virtuallyjamaica's red #dd3333 hero band) was never measured — the gate read
 * a transparent header and skipped the very surface that was wrong.
 */
function bandColorRegions( bands ) {
	const anchors = bands
		.map( ( band ) => band.anchor )
		.filter( ( anchor, index, list ) => Boolean( anchor ) && list.indexOf( anchor ) === index );
	// `descendantFill`: a builder paints a band's surface on a child layer, so the
	// ancestor walk alone reads the page body for every band.
	return anchors.map( ( anchor ) => ( {
		name: `band:${ anchor }`,
		selectors: [ `#${ anchor }` ],
		descendantFill: true,
	} ) );
}

/** Every built HTML page (homepage first), bounded — the SEO gate walks them. */
async function collectDistHtml( target, limit = 8 ) {
	const root = existsSync( join( target, 'dist', 'index.html' ) )
		? join( target, 'dist' )
		: join( target, 'dist', 'client' );
	const pages = [];
	const rootIndex = join( root, 'index.html' );
	if ( existsSync( rootIndex ) ) {
		pages.push( { absolute: rootIndex, pathname: '/' } );
	}
	async function walk( dir ) {
		if ( pages.length >= limit ) {
			return;
		}
		for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
			if ( pages.length >= limit ) {
				return;
			}
			if ( entry.isDirectory() ) {
				if ( entry.name === '_astro' || entry.name === 'pagefind' ) {
					continue;
				}
				await walk( join( dir, entry.name ) );
				continue;
			}
			if ( ! entry.name.endsWith( '.html' ) || entry.name === '404.html' ) {
				continue;
			}
			const absolute = join( dir, entry.name );
			if ( absolute === rootIndex ) {
				continue;
			}
			let pathname = absolute.slice( root.length ).split( '\\' ).join( '/' ).replace( /index\.html$/, '' );
			if ( ! pathname.startsWith( '/' ) ) {
				pathname = `/${ pathname }`;
			}
			if ( ! pathname.endsWith( '/' ) ) {
				pathname = `${ pathname }/`;
			}
			pages.push( { absolute, pathname } );
		}
	}
	try {
		await walk( root );
	} catch {
		// Missing dist — the caller reports it.
	}
	return pages;
}

/** The Proposal 131 gate — title/branding, robots, social head, JSON-LD. */
async function runSeoVerify( profile, options ) {
	const pages = await collectDistHtml( options.target, Number( options.seoPages ?? 8 ) );
	if ( pages.length === 0 ) {
		fail( `Cannot read any built HTML under ${ options.target }/dist — run \`npm run build\` first.` );
	}
	const siteName = profile.name ?? 'Forge';
	const findings = [];
	for ( const page of pages ) {
		const html = await readFile( page.absolute, 'utf8' );
		for ( const finding of auditSeo( html, { siteName, pathname: page.pathname, structured: Boolean( options.structured ) } ) ) {
			findings.push( { ...finding, page: page.pathname } );
		}
	}
	const failures = findings.filter( ( finding ) => finding.severity === 'fail' );
	const warnings = findings.filter( ( finding ) => finding.severity === 'warn' );
	if ( findings.length === 0 ) {
		console.log( `\u2713 SEO: ${ pages.length } page(s) — title, robots, social head and JSON-LD all hold.` );
	}
	for ( const finding of failures ) {
		console.warn( `\u2717 SEO [${ finding.code }] ${ finding.page }: ${ finding.message }` );
	}
	// Warnings are usually site-wide — print each code once with a count.
	const grouped = new Map();
	for ( const finding of warnings ) {
		const entry = grouped.get( finding.code ) ?? { message: finding.message, count: 0, page: finding.page };
		entry.count += 1;
		grouped.set( finding.code, entry );
	}
	for ( const [ code, entry ] of grouped ) {
		console.log( `\u26a0 SEO [${ code }] ${ entry.count } page(s), first ${ entry.page }: ${ entry.message }` );
	}
	if ( failures.length > 0 || ( options.strictSeo === true && warnings.length > 0 ) ) {
		process.exitCode = 1;
	}
	return {
		status: failures.length > 0 ? 'fail' : warnings.length > 0 ? 'warn' : 'pass',
		note: `${ pages.length } page(s), ${ failures.length } fail, ${ warnings.length } warn`,
	};
}

/** The built front-page HTML (static: dist/index.html; server output:
 * dist/client/index.html) — the band-flow gate walks its section wraps. */
async function readBuiltHtml( target ) {
	for ( const candidate of [ join( target, 'dist', 'index.html' ), join( target, 'dist', 'client', 'index.html' ) ] ) {
		if ( existsSync( candidate ) ) {
			return await readFile( candidate, 'utf8' );
		}
	}
	return undefined;
}

/** The emitted `_headers` (the deploy CSP lives here) — optional. */
async function readBuiltHeaders( target ) {
	for ( const candidate of [ join( target, 'dist', '_headers' ), join( target, 'dist', 'client', '_headers' ) ] ) {
		if ( existsSync( candidate ) ) {
			return await readFile( candidate, 'utf8' );
		}
	}
	return undefined;
}

/** The Proposal 130 gate — consent ordering, verification, ownership, CSP. */
function runTrackingVerify( profile, html, options, headers ) {
	if ( ! profile.tracking ) {
		console.log( '\u2713 Tracking: the profile has no tracking block — nothing to verify.' );
		return { status: 'na', note: 'no tracking block on the profile' };
	}
	const findings = auditTracking( profile.tracking, html, {
		headers,
		hasDistFile: ( filePath ) => {
			const relative = String( filePath ).replace( /^\//, '' );
			return (
				existsSync( join( options.target, 'dist', relative ) ) ||
				existsSync( join( options.target, 'dist', 'client', relative ) )
			);
		},
	} );
	const failures = findings.filter( ( finding ) => finding.severity === 'fail' );
	const warnings = findings.filter( ( finding ) => finding.severity === 'warn' );
	if ( findings.length === 0 ) {
		console.log( '\u2713 Tracking: consent ordering, verification and event ownership all hold.' );
	}
	for ( const finding of failures ) {
		console.warn( `\u2717 Tracking [${ finding.code }]: ${ finding.message }` );
	}
	for ( const finding of warnings ) {
		console.log( `\u26a0 Tracking [${ finding.code }]: ${ finding.message }` );
	}
	if ( failures.length > 0 || ( options.strictTracking === true && warnings.length > 0 ) ) {
		process.exitCode = 1;
	}
	return {
		status: failures.length > 0 ? 'fail' : warnings.length > 0 ? 'warn' : 'pass',
		note: `${ failures.length } fail, ${ warnings.length } warn`,
	};
}

/** Concatenate the built CSS bundle under dist/ (bounded — parity only needs
 * the token layer + type/scale signals, never the full bundle). */
async function collectDistCss( target ) {
	const chunks = [];
	async function walk( dir ) {
		for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
			if ( entry.isDirectory() ) {
				// `media` holds the LOCALIZED source assets (Proposal 139) — an
				// imported theme sheet, not the rebuild's own styling, so folding
				// it into the parity sample would score the source against itself.
				if ( entry.name === 'node_modules' || entry.name === 'pagefind' || entry.name === 'media' ) {
					continue;
				}
				await walk( join( dir, entry.name ) );
				continue;
			}
			if ( entry.name.endsWith( '.css' ) ) {
				chunks.push( ( await readFile( join( dir, entry.name ), 'utf8' ) ).slice( 0, 1024 * 1024 ) );
			}
		}
	}
	await walk( join( target, 'dist' ) );
	return chunks.join( '\n' ).slice( 0, 4 * 1024 * 1024 );
}

/**
 * Proposal 139 — the localizer's URL -> local-path map, when the build left
 * one. Absent (mirror/proxy mode, or localization disabled) means band flow
 * compares the raw URLs exactly as before.
 */
async function readLocalizedMedia( target ) {
	const raw = await readFile( join( target, '.forge-cache', 'media-localize.json' ), 'utf8' ).catch( () => null );
	if ( ! raw ) {
		return undefined;
	}
	try {
		const report = JSON.parse( raw );
		const entries = Array.isArray( report.localized ) ? report.localized : [];
		const pairs = entries
			.filter( ( entry ) => entry && typeof entry.url === 'string' && typeof entry.file === 'string' )
			.map( ( entry ) => [ entry.url, entry.file ] );
		return pairs.length > 0 ? new Map( pairs ) : undefined;
	} catch {
		return undefined;
	}
}

/**
 * Proposal 140 — the media budget gate.
 *
 * The localizer's report is the source of truth: every file the pass shipped,
 * its bytes, and which raster images re-encoding improved. A JPEG or PNG still
 * shipping means either the source could not be improved or the image pass
 * never ran — both worth surfacing, neither worth blocking a deploy over (a
 * source image that does not shrink is a legitimate outcome), so this warns by
 * default and fails under `--strict-media`.
 *
 * @param {string} target The forged project root.
 * @returns {Promise<{status: string, note: string, legacy?: Array}>} The verdict.
 */
async function runMediaBudgetVerify( target ) {
	const raw = await readFile( join( target, '.forge-cache', 'media-localize.json' ), 'utf8' ).catch( () => null );
	if ( ! raw ) {
		return { status: 'skip', note: 'no localization report (media not localized)' };
	}
	let report;
	try {
		report = JSON.parse( raw );
	} catch {
		return { status: 'skip', note: 'unreadable localization report' };
	}
	const entries = Array.isArray( report.localized ) ? report.localized : [];
	if ( entries.length === 0 ) {
		return { status: 'skip', note: `mode ${ report.mode ?? 'unknown' } localized nothing` };
	}
	const isImage = /\.(?:avif|webp|svg|ico|gif|jpe?g|png)$/i;
	const isLegacy = /\.(?:jpe?g|png)$/i;
	const images = entries.filter( ( entry ) => isImage.test( String( entry.file ?? '' ) ) );
	const legacy = images.filter( ( entry ) => isLegacy.test( String( entry.file ?? '' ) ) );
	// Documents (Proposal 139 §documents) land in the same list; they are never
	// re-encoded, so they are reported by weight only — a 50 MB catalogue is a
	// deploy-size fact, not a failure.
	const isDocument = /\.(?:pdf|docx?|xlsx?|pptx?|od[ts]p?|csv|txt|rtf|epub|ics|zip)$/i;
	const documents = entries.filter( ( entry ) => isDocument.test( String( entry.file ?? '' ) ) );
	const documentBytes = documents.reduce( ( total, entry ) => total + ( Number( entry.bytes ) || 0 ), 0 );
	const bytes = images.reduce( ( total, entry ) => total + ( Number( entry.bytes ) || 0 ), 0 );
	const saved = Array.isArray( report.optimized )
		? report.optimized.reduce( ( total, entry ) => total + ( ( Number( entry.bytes ) || 0 ) - ( Number( entry.optimizedBytes ) || 0 ) ), 0 )
		: 0;
	const note =
		`${ images.length } image(s), ${ Math.round( bytes / 1024 ) } KB shipped` +
		( documents.length > 0 ? ` + ${ documents.length } document(s), ${ Math.round( documentBytes / 1024 ) } KB` : '' ) +
		( saved > 0 ? `, ${ Math.round( saved / 1024 ) } KB saved by re-encoding` : '' );
	if ( legacy.length === 0 ) {
		return { status: 'pass', note };
	}
	return { status: 'warn', note: `${ note }; ${ legacy.length } JPEG/PNG still shipped`, legacy };
}

const GATE_ORDER = [
	'design language',
	'token block',
	'band flow',
	'nav anchors',
	'fact consumption',
	'media budget',
	'text parity',
	'colour system',
	'tracking',
	'SEO head',
	'box geometry',
	'interaction states',
	'pixel parity',
	'pixel regression',
	'visual parity',
];

const GATE_MARK = { pass: '\u2713', warn: '\u26a0', fail: '\u2717', skip: '\u2013', na: '\u00b7', advisory: '\u2139' };

/** Parse a comma list of colour schemes; unknown values are dropped. */
function parseColorModes( value, fallback = [ 'light', 'dark' ] ) {
	const list = String( value ?? '' )
		.split( ',' )
		.map( ( entry ) => entry.trim().toLowerCase() )
		.filter( ( mode ) => mode === 'light' || mode === 'dark' );
	return list.length > 0 ? list : fallback;
}

/**
 * The parity scorecard: every gate type that ran, its verdict, and the
 * pass rate. 100% means every gate was APPLIED and PASSED — a skipped gate
 * (no baseline) counts against it, which is what makes the number honest.
 */
function printGateScorecard( gates ) {
	if ( gates.length === 0 ) {
		return;
	}
	const ordered = [ ...gates ].sort(
		( a, b ) => ( GATE_ORDER.indexOf( a.name ) + 1 || 99 ) - ( GATE_ORDER.indexOf( b.name ) + 1 || 99 )
	);
	const counts = { pass: 0, warn: 0, fail: 0, skip: 0, na: 0, advisory: 0 };
	console.log( '\nParity scorecard' );
	for ( const gate of ordered ) {
		counts[ gate.status ] = ( counts[ gate.status ] ?? 0 ) + 1;
		const policy = gate.policy ? ` \u00b7 ${ gate.policy }` : '';
		console.log(
			`  ${ GATE_MARK[ gate.status ] ?? '?' } ${ gate.name }${ gate.note ? ` \u2014 ${ gate.note }` : '' }${ policy }`
		);
	}
	const total = gates.length;
	// `na` = structurally inapplicable (no bands to measure, no tracking block).
	// `skip` = applicable but not applied (no baseline) — it counts against 100%,
	// which is what keeps the percentage honest. `advisory` = measured AND
	// reported, downgraded by the site profile: excluded from the pass rate
	// precisely because the downgrade is printed on its own line, so the number
	// and the policy can never disagree in silence.
	const applicable = total - counts.na - counts.advisory;
	const percent = applicable <= 0 ? 100 : Math.round( ( counts.pass / applicable ) * 100 );
	const tail = [
		counts.warn > 0 ? `${ counts.warn } warn` : '',
		counts.fail > 0 ? `${ counts.fail } fail` : '',
		counts.skip > 0 ? `${ counts.skip } skipped` : '',
		counts.advisory > 0 ? `${ counts.advisory } advisory` : '',
		counts.na > 0 ? `${ counts.na } n/a` : '',
	]
		.filter( Boolean )
		.join( ', ' );
	console.log( `  ${ counts.pass }/${ applicable } applicable gates passed (${ percent }%)${ tail ? ` \u2014 ${ tail }` : '' }` );
	if ( percent === 100 && counts.advisory === 0 ) {
		console.log( '  \u2713 Every gate applied and passed.' );
	} else if ( percent === 100 ) {
		console.log( '  \u2713 Every applicable gate applied and passed \u2014 the advisory gate(s) above are measured, not enforced.' );
	} else {
		console.log( '  \u2192 100% needs every gate APPLIED (baselines recorded) and PASSING.' );
	}
}

/**
 * The browser-backed gates (geometry, colours, pixel) need Playwright AND its
 * downloaded Chromium build. A runner that installed the dependency but never
 * fetched the browser must not abort the whole verify run: the gate degrades to
 * `skip`, which still counts against 100% — the scorecard stays honest without
 * a missing binary killing a deploy. Probed once per process.
 */
let browserProbe;
async function browserAvailable() {
	if ( browserProbe === undefined ) {
		try {
			const { chromium } = await import( 'playwright' );
			const executable = chromium.executablePath();
			browserProbe = Boolean( executable ) && existsSync( executable );
		} catch {
			browserProbe = false;
		}
	}
	return browserProbe;
}

/** Any recorded screenshot to diff against (a baseline or a source capture). */
async function hasPixelArtifacts( dir ) {
	const files = await readdir( dir ).catch( () => [] );
	return files.some( ( file ) => /^(?:baseline|source)-.+\.png$/.test( file ) );
}

/**
 * Does a SOURCE seam exist? That is the parity gate's reference — as opposed to
 * a build regression baseline, which cannot prove fidelity (see gate-policy.ts).
 * `pixel parity` resolves `auto` off this answer.
 */
async function hasSourceSeam( dir ) {
	const files = await readdir( dir ).catch( () => [] );
	return files.some( ( file ) => /^source-.+\.png$/.test( file ) );
}

async function runVerify( profileRef, options ) {
	const profile = await loadProfile( profileRef );
	assertProfile( profile );
	// Every applicable gate runs by default: putting a site through the forge
	// should produce the complete scorecard without a human remembering six
	// flags. `--minimal` keeps only the deterministic, browser-free gates (the
	// CI blocking step uses it); `--all` survives as an explicit alias so
	// existing scripts keep working, and it outranks --minimal. --visual stays
	// opt-in: it needs credentials and is advisory.
	if ( options.all || ! options.minimal ) {
		options.tracking = true;
		options.seo = true;
		options.text = true;
		options.colors = true;
		options.geometry = true;
		options.interactions = true;
		options.pixel = true;
	}
	const gates = [];
	// `policy` is the human provenance of a DOWNGRADE (e.g. `advisory (default ·
	// auto · no source seam)`), printed on the gate's own scorecard line so an
	// advisory gate can never look like a pass.
	const record = ( name, status, note = '', policy = '' ) => {
		gates.push( { name, status, note, policy } );
	};
	if ( ! profile.design ) {
		if ( options.tracking || options.seo ) {
			console.warn(
				'\u26a0 The profile has no design block \u2014 the language, token, band and geometry gates cannot run. Run `node scripts/forge.mjs analyze <url> --design --write <profile>` to add one.'
			);
			if ( options.seo ) {
				const seo = await runSeoVerify( profile, options );
				record( 'SEO head', seo.status, seo.note );
			}
			if ( options.tracking ) {
				const html = await readBuiltHtml( options.target );
				if ( ! html ) {
					fail( `Cannot read the built HTML under ${ options.target }/dist — run \`npm run build\` in the project first.` );
				}
				const tracking = runTrackingVerify( profile, html, options, await readBuiltHeaders( options.target ) );
				record( 'tracking', tracking.status, tracking.note );
			}
			printGateScorecard( gates );
			return;
		}
		fail( 'The profile has no design block — run `node scripts/forge.mjs analyze <url> --design --write <profile>` first.' );
	}
	let css;
	try {
		css = await collectDistCss( options.target );
	} catch ( error ) {
		fail(
			`Cannot read the built CSS under ${ options.target }/dist — run \`npm run build\` in the project first (${ error instanceof Error ? error.message : error }).`
		);
	}
	// Re-extract the BUILT site's design language with the same analyzer and
	// score it against the source design — language parity, never pixel parity.
	const built = analyzeBuiltCss( css, String( profile.url ) );
	const report = scoreParity( profile.design, built, Number( options.threshold ?? 0.6 ) );
	console.log(
		`\u2713 Language parity: ${ ( report.score * 100 ).toFixed( 0 ) }% (threshold ${ report.threshold }) — ${ report.pass ? 'PASS' : 'FAIL' }`
	);
	console.log( `  role distance: ${ report.metrics.roleDistance.toFixed( 2 ) } (0 = identical roles)` );
	console.log( `  family overlap: ${ ( report.metrics.familyOverlap * 100 ).toFixed( 0 ) }%` );
	console.log( `  scale overlap: ${ ( report.metrics.scaleOverlap * 100 ).toFixed( 0 ) }%` );
	console.log( `  spacing overlap: ${ ( report.metrics.spacingOverlap * 100 ).toFixed( 0 ) }%` );
	console.log( `  concept match: ${ report.metrics.conceptMatch === 1 ? 'yes' : 'no' }` );
	record(
		'design language',
		report.pass ? 'pass' : 'fail',
		`${ ( report.score * 100 ).toFixed( 0 ) }% (threshold ${ report.threshold })`
	);
	if ( options.baselines ) {
		// The baseline step is opt-in and browser-backed (114 §3.6): the
		// deterministic parity gate above runs without any browser.
		console.log(
			'\u26a0 Screenshot baselines need Playwright — install it in the forge (npm i -D playwright) to enable --baselines; the parity gate above already ran.'
		);
	}
	process.exitCode = report.pass ? 0 : 1;
	// Token block (114 §3.3 — the "buttons turned green" gate): the generated
	// project's global.css must carry the tokens scaffold would write for THIS
	// profile. When it does not, the site still builds — with the template
	// defaults (green accent, template CTA pad/radius) instead of the site's.
	const tokenSource = await readFile(
		join( options.target, 'src', 'styles', 'global.css' ),
		'utf8'
	).catch( () => null );
	if ( tokenSource === null ) {
		console.warn(
			`\u2717 Token block: cannot read ${ options.target }/src/styles/global.css.`
		);
		process.exitCode = 1;
		record( 'token block', 'fail', 'global.css unreadable' );
	} else {
		const audit = auditTokenBlock( tokenSource, mergeTokens( profile.design, profile.tokens, profile.colors ) );
		if ( ! audit.markers ) {
			console.warn(
				'\u2717 Token block: global.css has no FORGE:tokens span — the tokens were never applied (re-scaffold, or run tokens_apply).'
			);
			process.exitCode = 1;
			record( 'token block', 'fail', 'no FORGE:tokens span' );
		} else if ( audit.drifted.length === 0 ) {
			console.log( '\u2713 Token block: the project carries this profile\u2019s tokens.' );
			record( 'token block', 'pass', 'all tokens match the profile' );
		} else {
			for ( const drift of audit.drifted ) {
				console.warn(
					`\u2717 Token block: --forge-${ drift.name } is ${ drift.actual ?? 'missing' }, expected ${ drift.expected }.`
				);
			}
			process.exitCode = 1;
			record( 'token block', 'fail', `${ audit.drifted.length } token(s) drifted` );
		}
	}
	// 124 §7 — the band-flow gate: every source band with visual facts must
	// render in the built page carrying the baked background. A dropped
	// band is exactly the failure the source's continuous band flow depends
	// on (jayainc's gray bridge + flush contact band, pre-124 §5).
	const indexHtml = await readBuiltHtml( options.target );
	// The bands a build can render: Elementor sections when present, else the
	// promoted outline (WPBakery/Divi/Bricks…). Band flow must check the same
	// set the renderer themes, or an outline-only site passes vacuously.
	const bands = renderableBands( profile.design );
	const positional =
		profile.design?.bandSource === 'outline' || ( profile.design?.sections?.length ?? 0 ) === 0;
	if ( indexHtml ) {
		const localizedMedia = await readLocalizedMedia( options.target );
		const flow = verifyBandFlow( bands, indexHtml, localizedMedia );
		if ( flow.dropped.length === 0 && flow.mismatched.length === 0 ) {
			console.log( '\u2713 Band flow: every source band renders with its baked background.' );
			record( 'band flow', 'pass', `${ bands.length } band(s)${ positional ? ' (positional)' : '' }` );
		} else {
			for ( const id of flow.dropped ) {
				console.warn( `\u2717 Band flow: source band ${ id } is missing from the built page (dropped or unclassified).` );
			}
			for ( const mismatch of flow.mismatched ) {
				console.warn( `\u2717 Band flow: band ${ mismatch.id } renders ${ mismatch.actual ?? 'nothing' }, expected ${ mismatch.expected }.` );
			}
			process.exitCode = positional ? process.exitCode : 1;
			record(
				'band flow',
				positional ? 'warn' : 'fail',
				`${ flow.dropped.length } dropped, ${ flow.mismatched.length } mismatched${ positional ? ' (positional outline)' : '' }`
			);
		}
		for ( const seam of flow.seams ) {
			console.log(
				`  source hard cut: ${ seam.fromId ?? '?' } ${ seam.fromEdge ?? '' } → ${ seam.toId ?? '?' } ${ seam.toEdge ?? '' } (ΔE ${ seam.distance })`
			);
		}
		// The per-band audit (band-audit.ts): presence, surface, content, facts,
		// and — when the baselines exist — text and geometry for EVERY band. The
		// page-level gates can pass while a single band ships empty, mis-painted
		// or with its facts unrendered; judging a band at a time is the
		// section-scoped guidance the visual-regression tooling converged on.
		if ( options.bands !== false ) {
			const audit = auditBands( {
				sections: bands,
				builtHtml: indexHtml,
				positional,
				localizedMedia,
				text: profile.text,
				geometry: profile.geometry,
			} );
			const reportPath = options.bandsJson ?? join( options.target, 'verify-artifacts', 'bands.json' );
			try {
				await mkdir( join( options.target, 'verify-artifacts' ), { recursive: true } );
				await writeFile( reportPath, `${ JSON.stringify( audit, null, 2 ) }\n`, 'utf8' );
			} catch ( error ) {
				console.warn( `\u26a0 Bands: could not write ${ reportPath } (${ error?.message ?? error }).` );
			}
			console.log( formatBandReport( audit ) );
			if ( audit.summary.fail === 0 ) {
				console.log( `\u2713 Bands: ${ audit.bands.length }/${ audit.bands.length } band(s) passed the per-band audit.` );
				record( 'bands', 'pass', `${ audit.summary.pass } pass, ${ audit.summary.warn } warn, ${ audit.summary.skip } skip` );
			} else {
				const failing = audit.bands.filter( ( band ) => band.status === 'fail' );
				for ( const band of failing ) {
					console.warn( `\u2717 Band ${ band.index + 1 }${ band.anchor ? ` (#${ band.anchor })` : ` (${ band.id })` }: ${ band.notes[ 0 ] ?? 'failed' }` );
				}
				if ( options.bandsWarn !== true ) {
					process.exitCode = 1;
				}
				record( 'bands', options.bandsWarn === true ? 'warn' : 'fail', `${ audit.summary.fail } of ${ audit.bands.length } band(s) failed` );
			}
		}
	}
	// Class coverage (report-only): every class name the rebuilt bands carry that
	// no stylesheet rule reaches — the inert-class inventory. The sanitizer keeps
	// the source's classes and the builder's sheet never ships, so this list is
	// where "why does this band look unstyled" is answered from data instead of
	// from a screenshot. A class is not REQUIRED to be styled, hence warn unless
	// `--strict-classes` asks for a gate.
	if ( options.classes !== false && indexHtml ) {
		const report = auditClassCoverage( indexHtml, await collectDistCss( options.target ) );
		const reportPath = options.classesJson ?? join( options.target, 'verify-artifacts', 'class-coverage.json' );
		try {
			await mkdir( join( options.target, 'verify-artifacts' ), { recursive: true } );
			await writeFile( reportPath, `${ JSON.stringify( report, null, 2 ) }\n`, 'utf8' );
		} catch ( error ) {
			console.warn( `\u26a0 Class coverage: could not write ${ reportPath } (${ error?.message ?? error }).` );
		}
		if ( report.unresolved.length === 0 ) {
			console.log( `\u2713 Class coverage: all ${ report.used } class name(s) in the rebuilt bands match a rule.` );
			record( 'class coverage', 'pass', `${ report.used } class name(s)` );
		} else {
			const limit = Number( options.classLimit ?? 15 );
			const ordered = report.unresolved.slice( 0, limit );
			for ( const entry of ordered ) {
				console.log(
					`  ${ entry.name } \u00d7${ entry.count }${ entry.bands.length > 0 ? ` (${ entry.bands.join( ', ' ) })` : '' }`
				);
			}
			if ( report.unresolved.length > limit ) {
				console.log( `  \u2026 ${ report.unresolved.length - limit } more; see ${ reportPath }` );
			}
			const mark = options.strictClasses === true ? '\u2717' : '\u26a0';
			console.warn(
				`${ mark } Class coverage: ${ report.unresolved.length } of ${ report.used } class name(s) match no stylesheet rule (${ report.resolved } resolved).`
			);
			if ( options.strictClasses === true ) {
				process.exitCode = 1;
			}
			record(
				'class coverage',
				options.strictClasses === true ? 'fail' : 'warn',
				`${ report.unresolved.length } inert of ${ report.used } class name(s)`
			);
		}
	}

	// One-pager nav (profile `shape`): every anchor the header links must land
	// somewhere. A source band the rebuild dropped — e.g. a section whose only
	// content was a contact form — leaves a dead nav link. Reported, never silent.
	if ( indexHtml && resolveSiteShape( profile ) === 'one-pager' ) {
		const anchors = siteNavAnchors( profile );
		const escape = ( value ) => value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		const dead = anchors.filter( ( anchor ) => ! new RegExp( `id="${ escape( anchor ) }"` ).test( indexHtml ) );
		if ( anchors.length > 0 && dead.length === 0 ) {
			console.log( `✓ Nav anchors: all ${ anchors.length } one-pager target(s) exist in the built page.` );
			record( 'nav anchors', 'pass', `${ anchors.length } target(s)` );
		}
		for ( const anchor of dead ) {
			console.warn( `⚠ Nav anchor: #${ anchor } has no matching element id in the built page.` );
		}
		if ( anchors.length > 0 && dead.length > 0 ) {
			record( 'nav anchors', 'warn', `dead: #${ dead.join( ', #' ) }` );
		}
	}
	// Fact consumption (the "a component ignored the fact" class): every fact a
	// band declares must leave its marker in that band's built HTML — jayainc's
	// titleStyle/titleDivider/titleAlign were baked in and never rendered.
	if ( indexHtml ) {
		const unrendered = auditFacts( profile.design?.sections ?? [], indexHtml );
		if ( unrendered.length === 0 ) {
			console.log( '\u2713 Fact consumption: every declared band fact renders.' );
			record( 'fact consumption', 'pass', 'all declared facts rendered' );
		} else {
			for ( const item of unrendered ) {
				console.warn( `\u2717 Fact consumption: band ${ item.sectionId } declares ${ item.fact } but the build has no ${ item.marker }.` );
			}
			if ( options.strictFacts ) {
				process.exitCode = 1;
			}
			record(
				'fact consumption',
				options.strictFacts ? 'fail' : 'warn',
				`${ unrendered.length } unrendered fact(s)`
			);
		}
	}
	// Proposal 140 — what the artifact actually ships. The design gates prove
	// the site LOOKS like the source; this one proves the bytes are local and
	// modern, which is what the CMS-independence promise depends on.
	const media = await runMediaBudgetVerify( options.target );
	if ( media.status === 'skip' ) {
		record( 'media budget', 'skip', media.note );
	} else {
		const line = `Media budget: ${ media.note }`;
		if ( media.status === 'pass' ) {
			console.log( `\u2713 ${ line }` );
		} else {
			console.warn( `\u26a0 ${ line }` );
			for ( const entry of media.legacy ?? [] ) {
				console.warn( `  still shipping ${ entry.file }` );
			}
			if ( options.strictMedia ) {
				process.exitCode = 1;
			}
		}
		record( 'media budget', options.strictMedia && media.status !== 'pass' ? 'fail' : media.status, media.note );
	}

	// Content parity (the words gate): the design-language score and the fact
	// audit both pass while copy goes missing. Coverage of the SOURCE is the
	// gate (w-shingles, Manning/Schütze §19.6); added copy only informs.
	if ( options.text && ! indexHtml ) {
		console.warn( '\u26a0 Text parity: no built index.html found — the gate did not run.' );
		if ( options.strictFacts ) {
			process.exitCode = 1;
		}
	}
	if ( options.text && indexHtml && ! profile.text ) {
		console.warn( '\u26a0 Text parity: no baseline on the profile — run `analyze <url> --design --text --write <profile>`.' );
		record( 'text parity', 'skip', 'no baseline (analyze --text)' );
	}
	if ( options.text && indexHtml && profile.text ) {
		const textOptions = {
			shingleSize: Number( options.textShingle ?? 3 ),
			passAt: Number( options.textPass ?? 0.9 ),
			warnAt: Number( options.textWarn ?? 0.75 ),
		};
		const includeAlt = options.textAlt === true;
		const pageReport = compareText(
			profile.text.page,
			textFromHtml( indexHtml, { includeAlt } ),
			textOptions
		);
		const pageMark = pageReport.verdict === 'fail' ? '\u2717' : pageReport.verdict === 'warn' ? '\u26a0' : '\u2713';
		console.log(
			`${ pageMark } Text parity (page): coverage ${ ( pageReport.coverage * 100 ).toFixed( 1 ) }% of the source — ${ pageReport.verdict.toUpperCase() }`
		);
		console.log(
			`  shingles: ${ pageReport.shared }/${ pageReport.sourceShingles } shared, precision ${ ( pageReport.precision * 100 ).toFixed( 1 ) }%, jaccard ${ ( pageReport.jaccard * 100 ).toFixed( 1 ) }%`
		);
		if ( pageReport.verdict !== 'pass' && pageReport.missing.length > 0 ) {
			console.log( `  missing from the build: ${ pageReport.missing.map( ( shingle ) => `"${ shingle }"` ).join( ', ' ) }` );
		}
		if ( pageReport.verdict === 'fail' ) {
			process.exitCode = 1;
		}
		const bandIds = profile.text.sections.map( ( section ) => section.id );
		const builtSections = extractSectionTexts( indexHtml, bandIds, { includeAlt } );
		let bandFailures = 0;
		for ( const section of profile.text.sections ) {
			const builtText = builtSections.texts[ section.id ];
			if ( builtText === undefined ) {
				console.warn( `\u26a0 Text: band ${ section.id } has no [data-section-id] node in the build — cannot diff its words.` );
				continue;
			}
			if ( builtText.length === 0 ) {
				console.warn( `\u2717 Text: band ${ section.id } renders no text (source band has ${ section.text.length } chars).` );
				bandFailures++;
				continue;
			}
			const bandReport = compareText( section.text, builtText, textOptions );
			if ( bandReport.verdict !== 'pass' ) {
				const bandMark = bandReport.verdict === 'fail' ? '\u2717' : '\u26a0';
				console.warn(
					`${ bandMark } Text: band ${ section.id } coverage ${ ( bandReport.coverage * 100 ).toFixed( 1 ) }% — ${ bandReport.verdict.toUpperCase() }${ bandReport.missing.length > 0 ? ` (missing: ${ bandReport.missing.map( ( shingle ) => `"${ shingle }"` ).join( ', ' ) })` : '' }`
				);
			}
			if ( bandReport.verdict === 'fail' ) {
				bandFailures++;
			}
		}
		if ( bandFailures > 0 ) {
			process.exitCode = 1;
		} else if ( profile.text.sections.length > 0 ) {
			console.log( `\u2713 Text parity (bands): ${ profile.text.sections.length } band(s) within tolerance.` );
		} else {
			console.log( '  text parity: page-level only (the baseline carries no addressable band text).' );
		}
		record(
			'text parity',
			pageReport.verdict === 'fail' || bandFailures > 0
				? 'fail'
				: pageReport.verdict === 'warn'
					? 'warn'
					: 'pass',
			`coverage ${ ( pageReport.coverage * 100 ).toFixed( 1 ) }%${ bandFailures > 0 ? `, ${ bandFailures } band(s) failing` : '' }`
		);
	}

	// Tracking (130 §3.3): consent ordering, verification, ownership and CSP.
	if ( options.tracking && indexHtml ) {
		const tracking = runTrackingVerify( profile, indexHtml, options, await readBuiltHeaders( options.target ) );
		record( 'tracking', tracking.status, tracking.note );
	}

	// SEO head (131 §2.6): title/branding, robots, social head, JSON-LD graph.
	if ( options.seo ) {
		const seo = await runSeoVerify( profile, options );
		record( 'SEO head', seo.status, seo.note );
	}

	// Geometry (114 §3.6): the build's real boxes against the recorded source
	// boxes. Language parity cannot see a 903px card or a flat 170px pull; this can.
	// `--diff` asks for the band diff, which needs the same baseline.
	const geometryRequested = options.geometry === true || options.diff === true;
	if ( geometryRequested && ( ! profile.geometry || Object.keys( profile.geometry ).length === 0 ) ) {
		const hasBands = ( profile.design?.sections ?? [] ).length > 0;
		if ( hasBands ) {
			console.warn( '\u26a0 Box geometry: no baseline on the profile — run `analyze <url> --geometry --write <profile>`.' );
			record( 'box geometry', 'skip', 'no baseline (analyze --geometry)' );
		} else {
			console.log( '  box geometry: n/a — the site has no addressable band ids to measure (outline-only).' );
			record( 'box geometry', 'na', 'no addressable band ids (outline-only site)' );
		}
	}
	if ( geometryRequested && profile.geometry && ! ( await browserAvailable() ) ) {
		console.warn( '\u26a0 Box geometry: skipped \u2014 Playwright/Chromium is not available in this environment.' );
		record( 'box geometry', 'skip', 'browser unavailable' );
	} else if ( geometryRequested && profile.geometry ) {
		const ids = Object.keys( profile.geometry );
		// Box geometry is one of the three addressable gates: a band of long prose
		// can absorb sub-pixel font-metric drift that no CSS change fixes. A site
		// that accepts it downgrades the gate here instead of shipping a red CI.
		const geometryPolicy = resolveGateMode( 'box geometry', profile.verify );
		const served = await serveDir( await builtStaticRoot( join( options.target, 'dist' ) ) );
		try {
			const built = await measureGeometry( { url: served.url, ids, kind: 'built' } );
			const deviations = diffGeometry( profile.geometry, built );
			const failures = deviations.filter( ( deviation ) => deviation.severity === 'fail' );
			const warnings = deviations.filter( ( deviation ) => deviation.severity === 'warn' );
			if ( deviations.length === 0 ) {
				console.log( '\u2713 Geometry: every band matches the source box at every viewport.' );
			} else {
				const limit = Number( options.geometryLimit ?? 12 );
				const ordered = [ ...failures, ...warnings ];
				for ( const deviation of ordered.slice( 0, limit ) ) {
					const mark = deviation.severity === 'fail' ? '\u2717' : '\u26a0';
					const actual = deviation.actual === null ? 'missing' : `${ deviation.actual }px`;
					console.warn( `${ mark } Geometry: band ${ deviation.sectionId } @ ${ deviation.viewport } ${ deviation.metric } — source ${ deviation.expected }px, build ${ actual }` );
				}
				if ( ordered.length > limit ) {
					console.warn( `  \u2026 ${ ordered.length - limit } more deviation(s); raise --geometry-limit to see them.` );
				}
				console.log( `  geometry: ${ failures.length } fail, ${ warnings.length } warn across ${ ids.length } band(s)` );
			}
			if ( failures.length > 0 ) {
				if ( geometryPolicy.mode === 'gate' ) {
					process.exitCode = 1;
				} else {
					console.warn(
						`\u2139 Box geometry: ${ failures.length } failure(s) treated as ADVISORY \u2014 the profile downgraded this gate, so the run is not failed.`
					);
				}
			}
			record(
				'box geometry',
				failures.length > 0
					? geometryPolicy.mode === 'advisory'
						? 'advisory'
						: 'fail'
					: warnings.length > 0
						? 'warn'
						: 'pass',
				`${ failures.length } fail, ${ warnings.length } warn across ${ ids.length } band(s)`,
				gateNote( geometryPolicy )
			);

			// Per-page bands (`analyze --pages`): every measured sub-page gets the
			// same box diff against its OWN recorded geometry — the homepage's boxes
			// are not a sub-page's (a 7,497px article band, 2026-11-11).
			if ( options.pages && profile.pageBands ) {
				let compared = 0;
				let pageFailures = 0;
				let pageWarnings = 0;
				for ( const [ slug, page ] of Object.entries( profile.pageBands ) ) {
					const pageIds = Object.keys( page.geometry ?? {} );
					if ( pageIds.length === 0 ) {
						continue;
					}
					try {
						const builtPage = await measureGeometry( { url: `${ served.url }${ slug }/`, ids: pageIds, kind: 'built' } );
						const pageDeviations = diffGeometry( page.geometry, builtPage );
						compared += pageIds.length;
						pageFailures += pageDeviations.filter( ( deviation ) => deviation.severity === 'fail' ).length;
						pageWarnings += pageDeviations.filter( ( deviation ) => deviation.severity === 'warn' ).length;
						if ( pageDeviations.length === 0 ) {
							console.log( `\u2713 Geometry /${ slug }/: every band matches its own source box.` );
						} else {
							for ( const deviation of pageDeviations.slice( 0, 6 ) ) {
								const mark = deviation.severity === 'fail' ? '\u2717' : '\u26a0';
								const actual = deviation.actual === null ? 'missing' : `${ deviation.actual }px`;
								console.warn(
									`${ mark } Geometry /${ slug }/ band ${ deviation.sectionId } @ ${ deviation.viewport } ${ deviation.metric } \u2014 source ${ deviation.expected }px, build ${ actual }`
								);
							}
						}
					} catch ( error ) {
						console.warn( `  \u26a0 Geometry /${ slug }/: skipped (${ error instanceof Error ? error.message : error }).` );
					}
				}
				if ( compared > 0 ) {
					console.log( `  per-page geometry: ${ pageFailures } fail, ${ pageWarnings } warn across ${ compared } band(s)` );
					if ( pageFailures > 0 ) {
						// A sub-page band that outgrows its source box is usually CONTENT
						// SCALE (an image gallery rendering a row the source cropped away),
						// not a missing fact — the homepage gate stays the blocking one and
						// `--strict-pages` opts into failing here.
						if ( options.strictPages ) {
							process.exitCode = 1;
						} else {
							console.warn(
								`  \u2139 Per-page geometry: ${ pageFailures } failure(s) reported ADVISORY (content-scale overshoot) \u2014 pass --strict-pages to fail the run.`
							);
						}
					}
				}
			}

			// -----------------------------------------------------------------
			// The BAND DIFF: WHERE the delta comes from.
			//
			// "source 1525px, build 1540px" is the symptom; the diagnosis is
			// always "walk both band trees and compare the boxes", which is the
			// step every investigation re-invents. It runs automatically for
			// EVERY deviating band (so the answer is on screen the moment a gate
			// fails) and for every band with `--diff`. A clean run pays nothing.
			// -----------------------------------------------------------------
			const wantsDiff = options.diff === true || deviations.length > 0;
			if ( wantsDiff ) {
				const sectionsById = new Map(
					( profile.design?.sections ?? [] )
						.filter( ( section ) => section.id )
						.map( ( section ) => [ section.id, section ] )
				);
				const viewportNames = DEFAULT_GEOMETRY_VIEWPORTS.map( ( viewport ) => viewport.name );
				const perBand = new Map();
				const addTarget = ( id, viewport ) => {
					if ( ! perBand.has( id ) ) {
						perBand.set( id, { id, anchor: sectionsById.get( id )?.anchor, viewports: [] } );
					}
					const entry = perBand.get( id );
					if ( ! entry.viewports.includes( viewport ) ) {
						entry.viewports.push( viewport );
					}
				};
				if ( options.diff === true ) {
					for ( const id of ids ) {
						for ( const viewport of viewportNames ) {
							addTarget( id, viewport );
						}
					}
				} else {
					for ( const deviation of deviations ) {
						if ( deviation.metric === 'height' && deviation.sectionId ) {
							addTarget( deviation.sectionId, deviation.viewport );
						}
					}
				}
				if ( perBand.size > 0 ) {
					try {
						const reports = await measureBandDiff( {
							sourceUrl: profile.url,
							builtUrl: served.url,
							bands: [ ...perBand.values() ],
							viewports: DEFAULT_GEOMETRY_VIEWPORTS,
							limit: Number( options.diffLimit ?? 5 ),
						} );
						if ( reports.length === 0 ) {
							console.warn( '  \u26a0 Band diff: no comparable band trees (the source may be unreachable, or the band ids do not resolve).' );
						} else {
							const px = ( value ) => `${ value > 0 ? '+' : '' }${ Math.round( value ) }px`;
							const sigOf = ( key ) => key.split( '>' ).pop().replace( /\[\d+\]$/, '' );
							for ( const report of reports ) {
								const band = sectionsById.get( report.bandId );
								console.log(
									`  \u25b8 Band diff ${ report.bandId }${ band?.anchor ? ` (#${ band.anchor })` : '' } @ ${ report.viewport } \u2014 source ${ Math.round( report.sourceHeight ) }px \u00b7 build ${ Math.round( report.builtHeight ) }px (${ px( report.heightDelta ) })`
								);
								if ( report.hotPath.length > 0 ) {
									console.log( `    hot path  ${ report.hotPath.map( ( entry ) => sigOf( entry.key ) ).join( ' \u203a ' ) }` );
								}
								for ( const entry of report.contributors ) {
									const source = entry.source ? Math.round( entry.source.h ) : null;
									const built = entry.built ? Math.round( entry.built.h ) : null;
									// `added` = the build has it and the source does not; `removed` = the reverse.
									const detail =
										entry.status === 'added'
											? `not in source (build ${ built }px)`
											: entry.status === 'removed'
											? `missing from build (source ${ source }px)`
											: `build ${ built } \u00b7 source ${ source }`;
									console.log( `    ${ px( entry.dH ).padEnd( 8 ) } ${ sigOf( entry.key ).slice( 0, 44 ).padEnd( 44 ) } ${ detail.padEnd( 32 ) } \u00b7 ${ entry.cause }` );
								}
								for ( const hint of report.hints ) {
									console.log( `    hint: ${ hint }` );
								}
								if ( report.truncated ) {
									console.log( '    note: the tree walk hit its node/depth cap \u2014 deeper nodes were not compared.' );
								}
							}
							const diffPath = options.diffJson ?? join( options.target, 'verify-artifacts', 'band-diff.json' );
							try {
								await mkdir( join( options.target, 'verify-artifacts' ), { recursive: true } );
								await writeFile( diffPath, `${ JSON.stringify( reports, null, 2 ) }\n`, 'utf8' );
								console.log( `  band diff: ${ reports.length } report(s) \u2192 ${ diffPath }` );
							} catch ( error ) {
								console.warn( `  \u26a0 Band diff: could not write ${ diffPath } (${ error?.message ?? error }).` );
							}
						}
					} catch ( error ) {
						// The diff is a diagnostic, never a gate: a browser/source failure
						// must not change the geometry verdict.
						console.warn( `  \u26a0 Band diff skipped: ${ error instanceof Error ? error.message : error }` );
					}
				}
			}
		} finally {
			served.server.close();
		}
	}

	// Interaction states (hover / focus / focus-visible / active). The rebuilt
	// bands are the source's own markup with the vendor sheet gone, so a control
	// can look right at rest and be inert on hover — and a source's own stylesheet
	// is linked into the build, so a vendor selector can out-specify the emitted
	// state (which is exactly how the accordion titles once went grey). This
	// measures the BUILT page with the same forced-pseudo-class pass the analyzer
	// used, then compares state PRESENCE, not declaration strings: the template
	// legitimately adds things the source never had (the focus ring).
	if ( options.interactions ) {
		const expected = Object.fromEntries(
			Object.entries( profile.bandFacts ?? {})
				.map( ( [ id, facts ] ) => [ id, facts.interactions ?? [] ] )
				.filter( ( [ , list ] ) => list.length > 0 )
		);
		const ids = Object.keys( expected );
		if ( ids.length === 0 ) {
			console.warn(
				'\u26a0 Interaction states: no baseline on the profile \u2014 run `analyze <url> --geometry --write <profile>`.'
			);
			record( 'interaction states', 'skip', 'no baseline (analyze --geometry)' );
		} else if ( ! ( await browserAvailable() ) ) {
			console.warn( '\u26a0 Interaction states: skipped \u2014 Playwright/Chromium is not available in this environment.' );
			record( 'interaction states', 'skip', 'browser unavailable' );
		} else {
			const policy = resolveGateMode( 'interaction states', profile.verify );
			const served = await serveDir( await builtStaticRoot( join( options.target, 'dist' ) ) );
			try {
				// The built page addresses its bands by data attribute (the anchor ids
				// survive sanitisation, but a one-pager repeats them on the wrapper).
				const selectors = Object.fromEntries(
					ids.map( ( id ) => [ id, `[data-section-id="${ id }"]` ] )
				);
				const { facts: built } = await measureInteractions( { url: served.url, ids, selectors, kind: 'built' } );
				const deviations = diffInteractionStates( expected, built );
				const failures = deviations.filter( ( deviation ) => deviation.severity === 'fail' );
				const warnings = deviations.filter( ( deviation ) => deviation.severity === 'warn' );
				const expectedStates = countInteractionStates( expected );
				// "Present" is measured against the EXPECTED set: the build legitimately
				// adds states the source never declared (the template's focus ring), so a
				// raw count of the built side reads 18-of-13 and says nothing. A state is
				// absent whether the control vanished or only lost the state.
				const presentStates =
					expectedStates -
					deviations.filter(
						( deviation ) =>
							deviation.kind === 'state-missing' || deviation.kind === 'control-missing'
					).length;
				if ( deviations.length === 0 ) {
					console.log(
						`\u2713 Interaction states: ${ expectedStates } state(s) across ${ ids.length } band(s) \u2014 every control keeps its hover/focus/press and shows a focus ring.`
					);
				} else {
					const limit = Number( options.interactionsLimit ?? 12 );
					for ( const deviation of deviations.slice( 0, limit ) ) {
						const mark = deviation.severity === 'fail' ? '\u2717' : '\u26a0';
						console.warn( `${ mark } Interaction states: ${ describeInteractionDeviation( deviation ) }` );
					}
					if ( deviations.length > limit ) {
						console.warn( `  \u2026 ${ deviations.length - limit } more finding(s); raise --interactions-limit to see them.` );
					}
					console.log(
						`  interaction states: ${ failures.length } fail, ${ warnings.length } warn (${ presentStates } of ${ expectedStates } expected state(s) present)`
					);
				}
				if ( failures.length > 0 ) {
					if ( policy.mode === 'gate' ) {
						process.exitCode = 1;
					} else {
						console.warn(
							`\u2139 Interaction states: ${ failures.length } failure(s) treated as ADVISORY \u2014 the profile downgraded this gate, so the run is not failed.`
						);
					}
				}
				record(
					'interaction states',
					failures.length > 0
						? policy.mode === 'advisory'
							? 'advisory'
							: 'fail'
						: warnings.length > 0
							? 'warn'
							: 'pass',
					`${ failures.length } fail, ${ warnings.length } warn across ${ ids.length } band(s)`,
					gateNote( policy )
				);
			} finally {
				served.server.close();
			}
		}
	}

	// Animated effects (2026-11-12): the source inventory's finite CSS keyframe
	// rebuilds should appear on the built page. Presence-based, like the
	// interaction gate: the generated animation name is deterministic, so a
	// `forge-anim-<hash>` missing from the build is a real regression. Mapped and
	// deferred facts are reported, never failed (a JS runtime cannot be rebuilt
	// by a no-JS template; infinite motion needs a pause control first).
	if ( options.animations ) {
		const inventory = profile.animations;
		const sourceFacts = planAnimationRebuild( inventory?.facts ?? [], profile.design?.behavior );
		const rebuiltFacts = sourceFacts.filter( ( fact ) => fact.rebuild === 'css' );
		if ( ! inventory || ( ( inventory.facts?.length ?? 0 ) === 0 && ( inventory.libraries?.length ?? 0 ) === 0 ) ) {
			console.warn(
				'\u26a0 Animated effects: no baseline on the profile \u2014 run `analyze <url> --animations --write <profile>`.'
			);
			record( 'animated effects', 'skip', 'no baseline (analyze --animations)' );
		} else if ( ! ( await browserAvailable() ) ) {
			console.warn( '\u26a0 Animated effects: skipped \u2014 Playwright/Chromium is not available in this environment.' );
			record( 'animated effects', 'skip', 'browser unavailable' );
		} else {
			const policy = resolveGateMode( 'animated effects', profile.verify );
			const served = await serveDir( await builtStaticRoot( join( options.target, 'dist' ) ) );
			try {
				const built = await measureAnimations( { url: served.url, maxFacts: 400 } );
				if ( built.error ) {
					console.warn( `\u26a0 Animated effects: ${ built.error }` );
					record( 'animated effects', 'skip', built.error );
				} else {
					// The built HTML is the presence fallback for a finished animation
					// that getAnimations() no longer reports; it also carries the CSS for
					// the timing check. A failed fetch is non-fatal (facts still decide).
					let builtHtml = '';
					try {
						const response = await fetch( served.url );
						if ( response.ok ) {
							builtHtml = await response.text();
						}
					} catch {
						// Presence falls back to the measured facts only.
					}
					const diff = diffAnimations( sourceFacts, built.facts, builtHtml );
					if ( rebuiltFacts.length === 0 ) {
						console.log(
							`\u2713 Animated effects: ${ sourceFacts.length } fact(s) inventoried \u2014 none needs a CSS rebuild (mapped/deferred).`
						);
					} else if ( diff.missing.length === 0 ) {
						console.log( `\u2713 Animated effects: ${ diff.matched }/${ rebuiltFacts.length } CSS rebuild(s) present on the built page.` );
					} else {
						for ( const fact of diff.missing.slice( 0, 12 ) ) {
							console.warn(
								`\u2717 Animated effects: ${ fact.bandId ?? '?' } ${ fact.name } (${ fact.target ?? '?' }) is missing from the built page.`
							);
						}
						if ( diff.missing.length > 12 ) {
							console.warn( `  \u2026 ${ diff.missing.length - 12 } more missing rebuild(s).` );
						}
						if ( policy.mode === 'gate' ) {
							process.exitCode = 1;
						} else {
							console.warn(
								`\u2139 Animated effects: ${ diff.missing.length } missing rebuild(s) treated as ADVISORY \u2014 ${ gateNote( policy ) }.`
							);
						}
					}
					for ( const mismatch of diff.timing.slice( 0, 12 ) ) {
						console.warn(
							`\u26a0 Animated effects: ${ mismatch.fact.bandId ?? '?' } ${ mismatch.fact.name } ${ mismatch.property } ${ mismatch.expected } \u2192 ${ mismatch.actual }`
						);
					}
					if ( diff.timing.length > 12 ) {
						console.warn( `  \u2026 ${ diff.timing.length - 12 } more timing warning(s).` );
					}
					record(
						'animated effects',
						diff.missing.length > 0 ? ( policy.mode === 'advisory' ? 'advisory' : 'fail' ) : diff.timing.length > 0 ? 'warn' : 'pass',
						`${ diff.matched }/${ rebuiltFacts.length } rebuilt fact(s) present, ${ diff.timing.length } timing warning(s)`,
						gateNote( policy )
					);
				}
			} finally {
				served.server.close();
			}
		}
	}

	// Pixel gate (the renders gate): per-pixel delta with the anti-alias filter
	// plus SSIM. The SOURCE screenshot comes from `verify-artifacts/` (the
	// capture seam) or, with --pixel-live, from a headless capture of the
	// source — but both sides must be the SAME viewport or the diff measures
	// the capture, not the site.
	if ( options.pixel && ! ( await browserAvailable() ) ) {
		console.warn( '\u26a0 Pixel: skipped \u2014 Playwright/Chromium is not available in this environment.' );
		record( 'pixel parity', 'skip', 'browser unavailable' );
	} else if ( options.pixel && options.pixelRecordSource ) {
		// Record the SOURCE side of the pixel gate. `--pixel-baseline` captures the
		// BUILD (a regression baseline); real parity needs the source, and the
		// capture seam is `verify-artifacts/source-<viewport>.png`. Storing it once
		// keeps the gate repeatable and offline afterwards.
		const artifacts = join( options.target, 'verify-artifacts' );
		const masks = resolvePixelMasks( profile.verify, options.pixelMask );
		const sourceModes =
			options.pixelMode === 'both' ? [ 'light', 'dark' ] : [ options.pixelMode === 'dark' ? 'dark' : 'light' ];
		const multiMode = sourceModes.length > 1;
		await mkdir( artifacts, { recursive: true } ).catch( () => {} );
		let written = 0;
		for ( const colorMode of sourceModes ) {
			const suffix = multiMode ? `-${ colorMode }` : '';
			for ( const viewport of DEFAULT_GEOMETRY_VIEWPORTS ) {
				try {
					const shot = await capturePage( { url: profile.url, viewport, masks, mode: colorMode } );
					await writeFile( join( artifacts, `source-${ viewport.name }${ suffix }.png` ), shot.png );
					written++;
					console.log( `  source @ ${ viewport.name } [${ colorMode }] ${ viewport.width }\u00d7${ viewport.height } \u2014 recorded` );
				} catch ( error ) {
					console.warn( `\u26a0 Pixel: source capture failed @ ${ viewport.name } [${ colorMode }] \u2014 ${ error instanceof Error ? error.message : error }` );
				}
			}
		}
		if ( written > 0 ) {
			console.log( `\u2713 Pixel source: recorded ${ written } capture(s) under ${ artifacts } \u2014 run \`verify --pixel\` to diff against them.` );
		}
		record( 'pixel parity', 'skip', `source recorded (${ written } capture(s))` );
	} else if (
		options.pixel &&
		! options.pixelBaseline &&
		! options.pixelLive &&
		! ( await hasPixelArtifacts( join( options.target, 'verify-artifacts' ) ) )
	) {
		// Cheap preflight: with no baseline, no source capture and no
		// --pixel-live there is nothing to diff — do not pay for a capture.
		console.warn( '\u26a0 Pixel: no baseline under verify-artifacts \u2014 record one with --pixel-baseline. The gate is NOT a pass.' );
		if ( options.strictPixel ) {
			process.exitCode = 1;
		}
		record( 'pixel parity', 'skip', 'no baseline or source screenshots' );
	} else if ( options.pixel ) {
		const artifacts = join( options.target, 'verify-artifacts' );
		const masks = resolvePixelMasks( profile.verify, options.pixelMask );
		const pixelModes =
			options.pixelMode === 'both'
				? [ 'light', 'dark' ]
				: [ options.pixelMode === 'dark' ? 'dark' : 'light' ];
		const multiMode = pixelModes.length > 1;
		await mkdir( artifacts, { recursive: true } ).catch( () => {} );
		// A site whose source cannot be reached opts into the build-vs-build
		// comparison; everyone else gets parity (the only comparison that can prove
		// fidelity). The two are DIFFERENT gates with their own policies.
		const preferBuildBaseline = profile.verify?.pixel?.baseline === 'build';
		const seamExists = options.pixelLive === true || ( await hasSourceSeam( artifacts ) );
		// `--strict-pixel` is the CLI override: it force-enables blocking, which is
		// what its name has always promised. The profile still decides the default.
		const flagPolicy = options.strictPixel ? 'gate' : undefined;
		const parityPolicy = resolveGateMode( 'pixel parity', profile.verify, { hasSourceSeam: seamExists }, flagPolicy );
		const regressionPolicy = resolveGateMode( 'pixel regression', profile.verify, {}, flagPolicy );
		for ( const resolution of [ parityPolicy, regressionPolicy ] ) {
			if ( resolution.mode === 'advisory' ) {
				console.warn( `\u2139 Pixel: running as ADVISORY \u2014 ${ gateNote( resolution ) }. Findings are reported, not enforced.` );
			}
		}
		// Per-origin stats: a SOURCE comparison is parity, a BASELINE comparison is
		// regression, and they are recorded as separate scorecard lines.
		const pixelStats = {
			source: { compared: 0, fail: 0, warn: 0 },
			baseline: { compared: 0, fail: 0, warn: 0 },
		};
		let recorded = 0;
		for ( const colorMode of pixelModes ) {
			const suffix = multiMode ? `-${ colorMode }` : '';
			let captured;
			try {
				captured = await captureDist( join( options.target, 'dist' ), DEFAULT_GEOMETRY_VIEWPORTS, { masks, mode: colorMode } );
			} catch ( error ) {
				fail( `Pixel capture failed — ${ error instanceof Error ? error.message : error }` );
			}
			// Baseline update mode needs a second capture of the SAME build: if two
			// captures disagree the page is non-deterministic and a baseline would
			// encode noise as truth.
			const second = options.pixelBaseline
				? await captureDist( join( options.target, 'dist' ), DEFAULT_GEOMETRY_VIEWPORTS, { masks, mode: colorMode } )
				: undefined;
			for ( const viewport of DEFAULT_GEOMETRY_VIEWPORTS ) {
				const build = captured.get( viewport.name );
				const label = `source-${ viewport.name }${ suffix }`;
				if ( options.pixelBaseline ) {
					const again = second?.get( viewport.name );
					if ( again ) {
						const noise = comparePngBuffers( build.png, again.png, {
							maxDiffRatio: Number( options.pixelMaxDiff ?? 0.001 ),
						} );
						if ( noise.verdict === 'fail' ) {
							console.warn(
								`\u2717 Pixel baseline @ ${ viewport.name } [${ colorMode }]: two captures of the SAME build differ by ${ ( noise.diffRatio * 100 ).toFixed( 3 ) }% — capture is non-deterministic, baseline not written. Mask the dynamic region with --pixel-mask.`
							);
							process.exitCode = 1;
							continue;
						}
					}
					await writeFile( join( artifacts, `baseline-${ viewport.name }${ suffix }.png` ), build.png ).catch( () => {} );
					recorded++;
					continue;
				}
				// Suffixed name first; the unsuffixed name is the light-mode legacy.
				// PREFER the SOURCE capture: `--pixel` is the parity gate, and letting a
				// recorded regression baseline shadow it silently downgrades the run to
				// build-vs-build — which then reports SSIM 1.0000 and hides real drift
				// (virtuallyjamaica, 2026-11-11). The baseline is the fallback for sites
				// whose source cannot be reached, and `verify.pixel.baseline: build`
				// reverses the preference deliberately.
				const seamShot =
					( await readArtifact( artifacts, label ) ) ??
					( colorMode === 'light' ? await readArtifact( artifacts, `source-${ viewport.name }` ) : undefined );
				const regressionShot =
					( await readArtifact( artifacts, `baseline-${ viewport.name }${ suffix }` ) ) ??
					( colorMode === 'light' ? await readArtifact( artifacts, `baseline-${ viewport.name }` ) : undefined );
				const preferred = preferBuildBaseline ? regressionShot : seamShot;
				const fallback = preferBuildBaseline ? seamShot : regressionShot;
				const chosen = preferred ?? fallback;
				const origin = preferred ? ( preferBuildBaseline ? 'baseline' : 'source' ) : preferBuildBaseline ? 'source' : 'baseline';
				let source;
				if ( chosen ) {
					source = Buffer.from( chosen.data, 'base64' );
				} else if ( options.pixelLive ) {
					try {
						source = ( await capturePage( { url: profile.url, viewport, masks, mode: colorMode } ) ).png;
					} catch ( error ) {
						console.warn( `\u26a0 Pixel: live source capture failed @ ${ viewport.name } [${ colorMode }] — ${ error instanceof Error ? error.message : error }` );
					}
				}
				if ( ! source ) {
					console.warn(
						`\u26a0 Pixel: no comparison image for ${ viewport.name } [${ colorMode }] \u2014 run \`verify --pixel-record-source\` (the parity seam), drop verify-artifacts/${ label }.png (at ${ viewport.width }\u00d7${ viewport.height }), or pass --pixel-live.`
					);
					continue;
				}
				let result = comparePngBuffers( source, build.png, {
					threshold: Number( options.pixelThreshold ?? 0.1 ),
					maxDiffRatio: Number( options.pixelMaxDiff ?? 0.001 ),
					minSsim: Number( options.pixelMinSsim ?? 0.98 ),
					includeDiff: true,
				} );
				// One retry on failure: a lazy image or a font swap can land late even
				// after settle. Re-capture before calling it a regression — a real
				// change fails twice.
				if ( result.verdict === 'fail' ) {
					const retry = await captureDist( join( options.target, 'dist' ), DEFAULT_GEOMETRY_VIEWPORTS, {
						masks,
						mode: colorMode,
					} ).catch( () => undefined );
					const retried = retry?.get( viewport.name );
					if ( retried ) {
						const secondPass = comparePngBuffers( source, retried.png, {
							threshold: Number( options.pixelThreshold ?? 0.1 ),
							maxDiffRatio: Number( options.pixelMaxDiff ?? 0.001 ),
							minSsim: Number( options.pixelMinSsim ?? 0.98 ),
							includeDiff: true,
						} );
						if ( secondPass.verdict !== 'fail' ) {
							console.log( `  ${ viewport.name } [${ colorMode }]: first capture differed, re-capture matched — treating as transient.` );
							result = secondPass;
						}
					}
				}
				const stat = pixelStats[ origin ];
				const resolution = origin === 'baseline' ? regressionPolicy : parityPolicy;
				const advisory = resolution.mode === 'advisory';
				stat.compared++;
				const mark = result.verdict === 'fail' ? '\u2717' : result.verdict === 'warn' ? '\u26a0' : '\u2713';
				const verdictLabel =
					advisory && result.verdict === 'fail'
						? 'FAIL \u00b7 advisory, does not fail the run'
						: result.verdict.toUpperCase();
				const kind = origin === 'baseline' ? 'Pixel regression' : 'Pixel parity';
				console.log(
					`${ mark } ${ kind } @ ${ viewport.name } [${ colorMode }] (${ origin }): ${ ( result.diffRatio * 100 ).toFixed( 3 ) }% pixels differ, SSIM ${ result.ssim.toFixed( 4 ) } — ${ verdictLabel }`
				);
				for ( const reason of result.reasons ) {
					console.log( `  ${ reason }` );
				}
				const diff = encodeDiff( result );
				if ( diff ) {
					await writeFile( join( artifacts, `pixel-diff-${ viewport.name }${ suffix }.png` ), diff ).catch( () => {} );
				}
				if ( result.verdict === 'fail' ) {
					stat.fail++;
					if ( ! advisory ) {
						process.exitCode = 1;
					}
				} else if ( result.verdict === 'warn' ) {
					stat.warn++;
				}
			}
		}
		if ( recorded > 0 ) {
			console.log( `\u2713 Pixel baseline: recorded ${ recorded } capture(s) under ${ join( options.target, 'verify-artifacts' ) }.` );
		}
		const comparedTotal = pixelStats.source.compared + pixelStats.baseline.compared;
		const failureTotal = pixelStats.source.fail + pixelStats.baseline.fail;
		if ( comparedTotal === 0 && recorded === 0 ) {
			console.warn( '\u26a0 Pixel: nothing was compared (no source screenshots). The gate is NOT a pass.' );
			if ( options.strictPixel ) {
				process.exitCode = 1;
			}
		} else if ( comparedTotal > 0 && failureTotal === 0 ) {
			console.log( `\u2713 Pixel: ${ comparedTotal } capture(s) within tolerance.` );
		}
		// One scorecard line per comparison KIND, so a build-vs-build diff can never
		// be read as parity — the distinction the baseline-review discipline rests on.
		for ( const [ origin, gateName, resolution ] of [
			[ 'source', 'pixel parity', parityPolicy ],
			[ 'baseline', 'pixel regression', regressionPolicy ],
		] ) {
			const stat = pixelStats[ origin ];
			if ( stat.compared === 0 ) {
				continue;
			}
			const status =
				stat.fail > 0
					? resolution.mode === 'advisory'
						? 'advisory'
						: 'fail'
					: stat.warn > 0
						? 'warn'
						: 'pass';
			record(
				gateName,
				status,
				`${ stat.compared } capture(s) (${ pixelModes.join( '+' ) }), ${ stat.fail } fail, ${ stat.warn } warn`,
				gateNote( resolution )
			);
		}
		if ( comparedTotal === 0 ) {
			if ( recorded > 0 ) {
				record( 'pixel parity', 'skip', `baseline recorded (${ recorded } capture(s))` );
			} else {
				record( 'pixel parity', 'skip', 'no baseline or source screenshots' );
			}
		}
	}

	// Colour system (chrome + page surface): the computed background/text of
	// body, header and footer, diffed with CIEDE2000. The token block proves the
	// CSS carries the colours; this proves the browser RENDERS them.
	if ( options.colors && ! profile.colors ) {
		console.warn( '\u26a0 Colour system: no baseline on the profile — run `analyze <url> --colors --write <profile>`.' );
		record( 'colour system', 'skip', 'no baseline (analyze --colors)' );
	}
	if ( options.colors && profile.colors && ! ( await browserAvailable() ) ) {
		console.warn( '\u26a0 Colour system: skipped \u2014 Playwright/Chromium is not available in this environment.' );
		record( 'colour system', 'skip', 'browser unavailable' );
	} else if ( options.colors && profile.colors ) {
		const modes = parseColorModes( options.colorsModes );
		const served = await serveDir( await builtStaticRoot( join( options.target, 'dist' ) ) );
		// Measure the same regions the baseline recorded — including the band
		// anchors (`band:home`) — so a band surface that stopped rendering is a
		// reported deviation, not a silent skip.
		const bandNames = [
			...new Set(
				Object.values( profile.colors ).flatMap( ( regions ) =>
					Object.keys( regions ).filter( ( name ) => name.startsWith( 'band:' ) )
				)
			),
		];
		const regions = [
			...DEFAULT_COLOR_REGIONS,
			// `descendantFill`: the source baseline was measured that way, so the
			// build must be too — a rebuilt band's surface can sit on the wrap or on
			// a child layer, and a gradient has no `background-color` at all.
			...bandNames.map( ( name ) => ( { name, selectors: [ `#${ name.slice( 5 ) }` ], descendantFill: true } ) ),
		];
		let builtColors;
		try {
			builtColors = await measureRegionColors( { url: served.url, modes, regions, effective: true } );
		} catch ( error ) {
			fail( `Colour measurement failed — ${ error instanceof Error ? error.message : error }` );
		} finally {
			served.server.close();
		}
		const report = compareRegionColorModes( profile.colors, builtColors, {
			warnAt: Number( options.colorsWarn ?? 3 ),
			failAt: Number( options.colorsFail ?? 6 ),
		} );
		const failures = report.deviations.filter( ( deviation ) => deviation.severity === 'fail' );
		for ( const deviation of [ ...failures, ...report.deviations.filter( ( d ) => d.severity === 'warn' ) ] ) {
			const mark = deviation.severity === 'fail' ? '\u2717' : '\u26a0';
			const metric = deviation.deltaE === null ? '' : ` (\u0394E00 ${ deviation.deltaE })`;
			console.warn(
				`${ mark } Colour [${ deviation.mode }]: ${ deviation.region } ${ deviation.metric } \u2014 source ${ deviation.expected }, build ${ deviation.actual }${ metric }`
			);
		}
		for ( const region of report.missing ) {
			console.warn( `\u26a0 Colour: ${ region } exists in the source baseline but not in the build (dropped chrome, scheme or markup change).` );
		}
		if ( report.skipped.length > 0 ) {
			console.log( `  colour: ${ report.skipped.length } surface(s) skipped (transparent \u2014 not comparable)` );
		}
		if ( report.unimplemented.length > 0 ) {
			console.log(
				`  colour: ${ report.unimplemented.join( ', ' ) } not compared \u2014 the source defines no such scheme (a build-side dark theme is an addition, not a parity regression).`
			);
		}
		if ( failures.length > 0 || ( options.strictColors && report.missing.length > 0 ) ) {
			process.exitCode = 1;
		}
		if ( report.deviations.length === 0 && report.missing.length === 0 ) {
			console.log( `\u2713 Colour system: ${ report.compared } surface metric(s) across ${ modes.join( '+' ) } within \u0394E00 ${ options.colorsWarn ?? 3 }.` );
		}
			record(
			'colour system',
			failures.length > 0 ? 'fail' : report.deviations.length > 0 || report.missing.length > 0 ? 'warn' : 'pass',
			`${ report.compared } surface metric(s) (${ modes.join( '+' ) }), ${ report.deviations.length } drift, ${ report.missing.length } missing${ report.unimplemented.length > 0 ? `, ${ report.unimplemented.join( '/' ) } n/a (source has none)` : '' }`
		);
	}

	if ( options.visual ) {
		// The vision layer (118 §4) — advisory unless --strict-visual; never
		// downgrades a parity failure, and stays out of the scorecard (it is
		// non-deterministic and needs credentials).
		await runVisualVerify( profile, options );
	}

	printGateScorecard( gates );
}

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/** Boot the built site with `astro preview` (detached — survives the CLI). */
function servePreview( target, port ) {
	const bin = join( target, 'node_modules', 'astro', 'bin', 'astro.mjs' );
	const child = spawn( process.execPath, [ bin, 'preview', '--port', String( port ) ], {
		cwd: target,
		detached: true,
		stdio: 'ignore',
		windowsHide: true,
	} );
	child.unref();
	console.log( `\u27a1 Preview server started on http://localhost:${ port } (astro preview) — stop it by killing the process on that port.` );
}

/**
 * The visual comparison (118 §4): source + built screenshots through a
 * vision model. Sources: NVOOS_FORGE_CAPTURE_URL (source only — a remote
 * endpoint cannot reach the local build) and dropped artifacts under
 * <target>/verify-artifacts/<label>-{source,built}.{png,jpg,webp}.
 * Advisory by default; --strict-visual gates on the --min-fidelity score.
 */
async function runVisualVerify( profile, options ) {
	const config = await resolveVisionConfig();
	if ( ! config ) {
		if ( options.strictVisual ) {
			fail( '--strict-visual needs a vision model: set NVOOS_FORGE_VISION_BASE_URL / NVOOS_FORGE_VISION_KEY / NVOOS_FORGE_VISION_MODEL, or run a local Ollama daemon (autodetected on 127.0.0.1:11434).' );
		}
		console.log(
			'\u26a0 Visual verify skipped: no vision model. Set NVOOS_FORGE_VISION_* or start Ollama (autodetected at 127.0.0.1:11434, override with NVOOS_FORGE_OLLAMA_URL).'
		);
		return;
	}
	if ( ! process.env.NVOOS_FORGE_VISION_KEY ) {
		console.log( `  vision: local Ollama at ${ config.baseUrl } (${ config.model })` );
	}

	const target = options.target;
	const host = ( () => { try { return new URL( String( profile.url ) ).hostname; } catch { return ''; } } )();
	const bareHost = host.replace( /^www\./, '' );
	const label = slugify( profile.name ?? ( bareHost || 'site' ) );
	const dir = join( target, 'verify-artifacts' );
	const sourceLabel = `${ label }-source`;
	const builtLabel = `${ label }-built`;
	await mkdir( dir, { recursive: true } );

	// 1. Source screenshot: the capture endpoint when configured, else a
	//    dropped artifact.
	let source = undefined;
	const endpoint = captureEndpoint();
	if ( endpoint ) {
		source = await captureRemote( endpoint, String( profile.url ), sourceLabel );
	}
	source = source ?? await readArtifact( dir, sourceLabel );

	// 2. Built screenshot: a dropped artifact (the endpoint cannot reach
	//    localhost). --serve boots the preview so the operator can capture.
	let built = await readArtifact( dir, builtLabel );
	if ( ! built && options.serve ) {
		servePreview( target, Number( options.serve ?? 4173 ) );
	}
	if ( ! source || ! built ) {
		const missing = [];
		if ( ! source ) {
			missing.push( `${ sourceLabel }.png` );
		}
		if ( ! built ) {
			missing.push( `${ builtLabel }.png` );
		}
		console.log( `\u26a0 Visual verify needs screenshots: drop ${ missing.join( ', ' ) } in ${ dir } (${ options.serve ? 'the preview server is running,' : 'pass --serve <port> to boot the built site,' } set NVOOS_FORGE_CAPTURE_URL to auto-capture the source).` );
		const waitSeconds = Number( options.wait ?? 90 );
		console.log( `   Polling for the artifacts (${ waitSeconds }s)…` );
		const deadline = Date.now() + waitSeconds * 1000;
		while ( Date.now() < deadline ) {
			await sleep( 2000 );
			source = source ?? await readArtifact( dir, sourceLabel );
			built = await readArtifact( dir, builtLabel );
			if ( source && built ) {
				break;
			}
		}
	}
	if ( ! source || ! built ) {
		if ( options.strictVisual ) {
			fail( 'Visual verify could not obtain both screenshots (--strict-visual).' );
		}
		console.log( '\u26a0 Both screenshots were not available — the visual comparison did not run (advisory).' );
		return;
	}

	// 3. The vision comparison (one request, both images, data URLs).
	console.log( '\u27a1 Vision model comparing the source and built screenshots…' );
	const outcome = await visionCompare( config, source, built );
	if ( ! outcome.ok ) {
		console.warn( `\u26a0 Vision comparison failed: ${ outcome.error }` );
		if ( options.strictVisual ) {
			fail( 'Vision comparison failed (--strict-visual).' );
		}
		return;
	}
	const verdict = outcome.verdict;
	const fidelity = typeof verdict.fidelity === 'number' ? verdict.fidelity : null;
	if ( fidelity === null ) {
		console.warn( '\u26a0 The model returned no numeric fidelity — see the audit report.' );
	} else {
		console.log( `\u2713 Visual fidelity: ${ fidelity }/100` );
	}
	const divergences = Array.isArray( verdict.divergences ) ? verdict.divergences : [];
	for ( const divergence of divergences ) {
		const area = divergence && divergence.area ? divergence.area : 'general';
		const severity = divergence && divergence.severity ? divergence.severity : 'low';
		const note = divergence && divergence.note ? divergence.note : '';
		console.log( `  - [${ severity }] ${ area }: ${ note }` );
	}
	if ( typeof verdict.summary === 'string' && verdict.summary ) {
		console.log( `  summary: ${ verdict.summary }` );
	}
	await writeFile(
		join( dir, 'visual-audit.json' ),
		`${ JSON.stringify( { generatedAt: new Date().toISOString(), sourceUrl: profile.url, fidelity, verdict }, null, '\t' ) }\n`,
		'utf8'
	);
	console.log( `  audit: ${ join( dir, 'visual-audit.json' ) }` );

	// 4. The gate — strict mode only.
	if ( options.strictVisual ) {
		const min = Number( options.minFidelity ?? 70 );
		if ( fidelity === null || fidelity < min ) {
			console.error( `\u26a0 Visual fidelity ${ fidelity === null ? 'n/a' : fidelity } below the ${ min } gate — FAIL` );
			process.exitCode = 1;
		}
	}
}

// -- program -----------------------------------------------------------------

const pkg = JSON.parse( await readFile( new URL( '../package.json', import.meta.url ), 'utf8' ) );
const program = new Command();

program
	.name( 'nvoos-forge' )
	.description( 'Proposal 096 — the WP→Astro generator CLI (analyze, scaffold, wizard, site, vault, deploy).' )
	.version( pkg.version );

program
	.command( 'analyze' )
	.description( 'discover a WordPress site and draft a site.json (site_analyze)' )
	.argument( '<url>', 'the WordPress URL (https://…)' )
	.option( '--write <path>', 'write the draft profile to a file (MERGE into an existing profile — hand-tuned fields survive)' )
	.option( '--fresh', 'with --write: overwrite an existing profile with a clean draft instead of merging' )
	.option( '--design', 'extract the design language and attach the rebuild block (Proposal 114)' )
	.option( '--no-cms', 'skip WordPress (wp-json) discovery \u2014 for bot-protected sources; analyzes the rendered page only' )
	.option( '--inventory', 'discover the full page inventory + site shape (one-pager vs multi-page, Proposal 115)' )
	.option( '--wireframes', 'imply --inventory and extract wireframes for the missing pages (Proposal 115)' )
	.option( '--wayback', 'add Wayback CDX discovery to the inventory (deleted pages)' )
	.option( '--vocabulary', 'learn the widget/block vocabulary (block-types registry + rendered widget names, Proposal 116)' )
	.option( '--animations', 'inventory the source\'s animated effects (CSS keyframes, transitions, WAAPI, libraries, video/SMIL) with Playwright' )
	.option( '--animations-pages <n>', 'with --animations: also measure this many sub-pages (default 0 = homepage only)', '0' )
	.option( '--geometry', 'measure each source band\u0027s box at 3 viewports so `verify --geometry` can diff the build (needs --design; requires Playwright)' )
	.option( '--pages', 'measure EVERY page\u0027s bands too (per-page geometry + surfaces; implies --geometry)' )
	.option( '--pages-limit <n>', 'with --pages: how many pages to measure (default 8)', '8' )
	.option( '--text', 'record the homepage\u0027s normalised visible text so `verify --text` can diff the build\u0027s content (needs --design)' )
	.option( '--text-alt', 'with --text: include <img alt> text in the baseline' )
	.option( '--colors', 'record the computed background/text colour of body, header and footer so `verify --colors` can diff the build (requires Playwright)' )
	.option( '--colors-modes <modes>', 'with --colors: colour schemes to measure, comma-separated (default light,dark)', 'light,dark' )
	.option( '--tracking', 'detect analytics, ad pixels, a CMP and verification tokens; draft the tracking block with every tracker DISABLED (Proposal 130)' )
	.option( '--tracking-pages <n>', 'how many representative pages to scan for trackers (default 3)', '3' )
	.option( '--probe', 'with --tracking: also load the page in Playwright and record its network requests, globals and cookie NAMES' )
	.action( async ( url, options ) => {
		await runAnalyze( url, options );
	} );

program
	.command( 'wizard' )
	.description( 'one command: URL → analyze → preset → scaffold → build (opt) → git → pipeline' )
	.argument( '<url>', 'the WordPress URL (https://…)' )
	.option( '--target <dir>', 'scaffold target directory (prompted on a TTY when missing)' )
	.option( '--name <name>', 'registry site name (default: derived from the hostname)' )
	.option( '--to <mode>', 'deploy mode', 'github-pages' )
	.option( '--preset <name>', 'blog | storefront | minimal (default: suggested from the analysis)' )
	.option( '--render <mode>', 'static | ssr (default: static; prompted for storefront on a TTY)' )
	.option( '--theme-policy <policy>', 'auto | light | dark | brand:<slug> (default: auto)' )
	.option( '--og-images', 'scaffold the per-post OG card endpoint (adds satori/resvg deps)' )
	.option( '--design', 'extract the source design language and apply it as the rebuild theme' )
	.option( '--strict-contrast', 'fail the build when a rendered pair fails WCAG (designAudit: strict)' )
	.option( '--unlock', 're-forge a target that is LOCKED for hand-editing (destroys custom work)' )
	.option( '--build', 'run npm install + astro build before finishing' )
	.option( '--cloudflare-project <name>', 'Cloudflare Pages project name' )
	.option( '--no-agents', 'skip the AGENTS.md/.context agent stubs' )
	.action( async ( url, options ) => {
		await runWizard( url, options );
	} );

program
	.command( 'deploy-config' )
	.description( 'emit the deploy pipeline into a scaffolded target (no network, no token)' )
	.argument( '[profileRef]', 'site.json path or registered site name (for the site URL)' )
	.requiredOption( '--target <dir>', 'scaffold target directory' )
	.option( '--to <mode>', 'deploy mode', 'github-pages' )
	.option( '--site-url <url>', 'site URL override' )
	.option( '--cloudflare-project <name>', 'Cloudflare Pages project name' )
	.option( '--mirror-repo <owner/repo>', 'velocity shared mirror — one repo, branch per site (no per-site repo)' )
	.option( '--site-branch <branch>', 'shared mirror site branch (e.g. site/jayainc; required with --mirror-repo)' )
	.option( '--runtime <mode>', 'static | managed — managed ships a Node entry + the BFF (Proposal 135)', 'static' )
	.action( async ( profileRef, options ) => {
		await runDeployConfig( profileRef, options );
	} );

program
	.command( 'deploy' )
	.description( 'scaffold if needed, emit the pipeline, create a private repo, push' )
	.argument( '<profileRef>', 'site.json path or registered site name' )
	.requiredOption( '--target <dir>', 'scaffold target directory' )
	.option( '--to <mode>', 'deploy mode', 'github-pages' )
	.option( '--repo <name>', 'repository name (default: slugified site name)' )
	.option( '--org <org>', 'create the repository under an organization' )
	.option( '--public', 'make the repository public (default: private)' )
	.option( '--preset <name>', 'blog | storefront | minimal (applied when scaffolding)' )
	.option( '--render <mode>', 'static | ssr (applied when scaffolding)' )
	.option( '--theme-policy <policy>', 'auto | light | dark | brand:<slug> (applied when scaffolding)' )
	.option( '--og-images', 'scaffold the per-post OG card endpoint (adds satori/resvg deps)' )
	.option( '--strict-contrast', 'fail the build when a rendered pair fails WCAG (applied when scaffolding)' )
	.option( '--unlock', 're-forge a LOCKED site (destroys hand-written work after a handoff)' )
	.option( '--no-agents', 'skip the AGENTS.md/.context agent stubs' )
	.option( '--cloudflare-project <name>', 'Cloudflare Pages project name' )
	.option( '--mirror-repo <owner/repo>', 'velocity shared mirror — skip repo creation, mirror prebuilt dist/ to the site branch' )
	.option( '--site-branch <branch>', 'shared mirror site branch (e.g. site/jayainc; required with --mirror-repo)' )
	.option( '--runtime <mode>', 'static | managed — applied to the emitted pipeline (Proposal 135)', 'static' )
	.action( async ( profileRef, options ) => {
		await runDeploy( profileRef, options );
	} );

program
	.command( 'verify' )
	.description( 'score design-language parity between the source site and the built frontend (Proposal 114)' )
	.argument( '<profileRef>', 'site.json path or registered site name (must carry a design block)' )
	.requiredOption( '--target <dir>', 'the built forge project directory (dist/ must exist)' )
	.option( '--all', 'enable every gate (default \u2014 kept as an explicit alias)' )
	.option( '--minimal', 'run only the deterministic, browser-free gates (language, tokens, band flow, nav anchors, facts, text, SEO, tracking)' )
	.option( '--no-bands', 'skip the per-band audit (presence · surface · content · facts · text · geometry per band)' )
	.option( '--bands-warn', 'report per-band failures as warnings instead of failing the run' )
	.option( '--bands-json <path>', 'where to write the per-band report (default <target>/verify-artifacts/bands.json)' )
	.option( '--no-classes', 'skip the class-coverage audit (which class names in the rebuilt bands match no rule)' )
	.option( '--class-limit <n>', 'how many inert class names to print (default 15)', '15' )
	.option( '--classes-json <path>', 'where to write the class-coverage report (default <target>/verify-artifacts/class-coverage.json)' )
	.option( '--strict-classes', 'fail when any class name in the rebuilt bands matches no stylesheet rule (default: report-only)' )
	.option( '--threshold <n>', 'parity pass threshold 0-1 (default 0.6)', '0.6' )
	.option( '--geometry', 'diff the built bands\u0027 boxes against the recorded source geometry (needs `analyze --geometry`; requires Playwright)' )
	.option( '--pages', 'with --geometry: also diff every page measured by `analyze --pages`' )
	.option( '--strict-pages', 'with --pages: fail the run on a sub-page band that misses its own source box (default: advisory — content-scale overshoot)' )
	.option( '--geometry-limit <n>', 'how many geometry deviations to print (default 12)', '12' )
	.option( '--interactions', 'verify the rebuilt controls keep the source\'s hover / focus / focus-visible / active states and show a keyboard focus ring (needs `analyze --geometry`; requires Playwright)' )
	.option( '--interactions-limit <n>', 'how many interaction findings to print (default 12)', '12' )
	.option( '--animations', 'verify the source CSS keyframe rebuilds appear on the built page (needs `analyze --animations`; requires Playwright)' )
	.option( '--diff', 'with geometry: compare the box tree of EVERY band (not just the deviating ones) and print where the height delta comes from' )
	.option( '--diff-limit <n>', 'how many per-band contributor nodes to print (default 5)', '5' )
	.option( '--diff-json <path>', 'where to write the band-diff reports (default <target>/verify-artifacts/band-diff.json)' )
	.option( '--text', 'diff the build\u0027s visible text against the recorded baseline (needs `analyze --text`)' )
	.option( '--text-pass <n>', 'with --text: source coverage at/above this passes (default 0.9)', '0.9' )
	.option( '--text-warn <n>', 'with --text: coverage between warn and pass warns (default 0.75)', '0.75' )
	.option( '--text-shingle <n>', 'with --text: shingle width in words (default 3)', '3' )
	.option( '--text-alt', 'with --text: compare <img alt> text too' )
	.option( '--pixel', 'compare SOURCE and BUILT screenshots pixel-for-pixel (pixelmatch delta + SSIM; requires Playwright)' )
	.option( '--pixel-baseline', 'with --pixel: record THIS build as the pixel baseline (verify-artifacts/baseline-<viewport>.png); later runs diff against it' )
	.option( '--pixel-live', 'with --pixel: capture the SOURCE live instead of using verify-artifacts/source-<viewport>.png' )
	.option( '--pixel-record-source', 'capture the SOURCE screenshots into verify-artifacts/source-<viewport>.png (the parity seam) — no comparison; run `verify --pixel` afterwards' )
	.option( '--pixel-mask <selector...>', 'with --pixel: CSS selectors of dynamic regions to mask (carousels, clocks)', [] )
	.option( '--pixel-mode <mode>', 'with --pixel: colour scheme to capture — light, dark, or both (default light)', 'light' )
	.option( '--pixel-threshold <n>', 'with --pixel: per-pixel sensitivity 0-1 (default 0.1)', '0.1' )
	.option( '--pixel-max-diff <n>', 'with --pixel: fail above this differing-pixel ratio (default 0.001 = 0.1%)', '0.001' )
	.option( '--pixel-min-ssim <n>', 'with --pixel: fail below this SSIM (default 0.98)', '0.98' )
	.option( '--strict-pixel', 'with --pixel: force the parity gate to BLOCK, overriding verify.gates (also fails when nothing could be compared)' )
	.option( '--colors', 'diff the build\u0027s computed body/header/footer colours against the recorded baseline (CIEDE2000; needs `analyze --colors`; requires Playwright)' )
	.option( '--colors-warn <n>', 'with --colors: \u0394E00 at/above this warns (default 3)', '3' )
	.option( '--colors-fail <n>', 'with --colors: \u0394E00 at/above this fails (default 6)', '6' )
	.option( '--colors-modes <modes>', 'with --colors: colour schemes to measure, comma-separated (default light,dark)', 'light,dark' )
	.option( '--strict-colors', 'with --colors: fail when a region the source had is missing from the build' )
	.option( '--strict-facts', 'fail when a declared band fact did not reach the built HTML (default: report only)' )
	.option( '--strict-media', 'fail when the artifact still ships a JPEG/PNG the localizer could not re-encode (Proposal 140)' )
	.option( '--tracking', 'gate the tracking block: consent ordering, verification tokens/files, event ownership, CSP allowlist (Proposal 130)' )
	.option( '--strict-tracking', 'with --tracking: treat warnings as failures (a stale CSP host, a double-count risk)' )
	.option( '--seo', 'gate the SEO head: double-branded titles, robots directives, Open Graph/Twitter tags, JSON-LD graph (Proposal 131)' )
	.option( '--strict-seo', 'with --seo: treat warnings as failures (a missing canonical, an incomplete social head)' )
	.option( '--structured', 'with --seo: require every JSON-LD price/number/date to appear in the visible page text (Proposal 136)' )
	.option( '--seo-pages <n>', 'with --seo: how many built pages to audit (default 8)', '8' )
	.option( '--baselines', 'note the opt-in Playwright baseline step' )
	.option( '--visual', 'also compare SOURCE and BUILT screenshots with a vision model (Proposal 118)' )
	.option( '--serve <port>', 'with --visual: boot the built site with `astro preview` on <port> while you capture it' )
	.option( '--wait <seconds>', 'with --visual: poll for the dropped built screenshot this many seconds (default 90)', '90' )
	.option( '--min-fidelity <n>', 'with --visual --strict-visual: fail below this 0-100 score (default 70)', '70' )
	.option( '--strict-visual', 'fail the command when the visual comparison cannot run or scores below --min-fidelity' )
	.action( async ( profileRef, options ) => {
		await runVerify( profileRef, options );
	} );

// -- handoff lock ------------------------------------------------------------

/** The profile's on-disk path, when the ref is a file (registered sites have none). */
function profilePathOf( ref ) {
	return existsSync( ref ) ? ref : undefined;
}

async function generatorLabel() {
	try {
		const pkgUrl = new URL( '../package.json', import.meta.url );
		const pkg = JSON.parse( await readFile( fileURLToPath( pkgUrl ), 'utf8' ) );
		return `${ pkg.name }@${ pkg.version }`;
	} catch {
		return 'nvoos-astro-forge';
	}
}

program
	.command( 'lock' )
	.description( 'hand a forged project over to custom development (the forge will stop overwriting it, Proposal 141)' )
	.argument( '<profileRef>', 'site.json path or registered site name' )
	.requiredOption( '--target <dir>', 'the scaffolded forge project to lock' )
	.option( '--by <name>', 'who is taking the site over (recorded in the lock)' )
	.option( '--reason <text>', 'why (recorded in the lock, e.g. "custom dev handoff")' )
	.option( '--force', 're-lock a project that is already locked (refreshes the fingerprint)' )
	.option( '--no-profile', 'do not mark the site profile as locked (project lock only)' )
	.action( async ( profileRef, options ) => {
		const profile = await loadProfile( profileRef );
		if ( ! existsSync( options.target ) ) {
			console.error( `No such target: ${ options.target }` );
			process.exit( 1 );
		}
		if ( isLocked( options.target ) && options.force !== true ) {
			const existing = await readLock( options.target );
			console.error( `Already locked: ${ describeLock( existing ) }` );
			console.error( 'Pass --force to re-lock and refresh the fingerprint.' );
			process.exit( 1 );
		}
		const lock = await computeLock( options.target, {
			site: profile.name ?? 'site',
			source: profile.url,
			generator: await generatorLabel(),
			by: options.by,
			reason: options.reason,
		} );
		await writeLock( options.target, lock );
		console.log( `\u2713 Locked ${ lock.fingerprint ? Object.keys( lock.fingerprint ).length : 0 } managed file(s) in ${ options.target }.` );
		console.log( `  ${ describeLock( lock ) }` );
		const profilePath = profilePathOf( profileRef );
		if ( profilePath && options.profile !== false ) {
			profile.locked = { at: lock.lockedAt, by: lock.lockedBy, reason: lock.reason };
			await writeFile( profilePath, `${ JSON.stringify( profile, null, '\t' ) }\n`, 'utf8' );
			console.log( `  Profile marked locked: ${ profilePath }` );
		}
		console.log( '\nWhat changes now:' );
		console.log( '  \u00b7 scaffold/deploy refuse to overwrite this target (pass --unlock to re-forge)' );
		console.log( '  \u00b7 the project\u2019s `npm run check` reports hand-edits as expected drift, not failures' );
		console.log( '  \u00b7 `forge status` shows the owner record and everything changed since handoff' );
	} );

program
	.command( 'unlock' )
	.description( 'return a locked project to the forge (destructive for hand-edits on the next re-forge)' )
	.argument( '<profileRef>', 'site.json path or registered site name' )
	.requiredOption( '--target <dir>', 'the locked forge project' )
	.option( '--yes', 'required in non-interactive runs (CI, scripts)' )
	.option( '--no-profile', 'do not clear the site profile\u2019s locked flag' )
	.action( async ( profileRef, options ) => {
		if ( ! isLocked( options.target ) ) {
			console.log( `${ options.target } is not locked \u2014 nothing to unlock.` );
			return;
		}
		const lock = await readLock( options.target );
		if ( options.yes !== true ) {
			if ( ! isInteractive() ) {
				console.error( 'Refusing to unlock without --yes in a non-interactive run.' );
				process.exit( 1 );
			}
			const proceed = await confirm( {
				message: `Unlock ${ options.target }? The next re-forge will overwrite hand-written work.`,
				initialValue: false,
			} );
			if ( isCancel( proceed ) || proceed !== true ) {
				console.log( 'Cancelled.' );
				return;
			}
		}
		await removeLock( options.target );
		console.log( `\u2713 Unlocked ${ options.target } (${ describeLock( lock ) } removed).` );
		const profilePath = profilePathOf( profileRef );
		if ( profilePath && options.profile !== false ) {
			const profile = await loadProfile( profileRef );
			delete profile.locked;
			await writeFile( profilePath, `${ JSON.stringify( profile, null, '\t' ) }\n`, 'utf8' );
			console.log( `  Profile flag cleared: ${ profilePath }` );
		}
	} );

program
	.command( 'status' )
	.description( 'report who owns a forged project and what changed since handoff' )
	.argument( '<profileRef>', 'site.json path or registered site name' )
	.requiredOption( '--target <dir>', 'the scaffolded project' )
	.action( async ( profileRef, options ) => {
		const profile = await loadProfile( profileRef );
		const lock = await readLock( options.target );
		console.log( `Site:   ${ profile.name ?? 'site' } (${ profile.url ?? 'unknown source' })` );
		console.log( `Target: ${ options.target }` );
		console.log( `Owner:  ${ describeLock( lock ) }` );
		if ( lock ) {
			const drift = await lockDrift( options.target, lock );
			const total = Object.keys( lock.fingerprint ).length;
			console.log( `Locked: ${ total } managed file(s), ${ lock.generator }` );
			console.log( `Since handoff: ${ drift.changed.length } edited, ${ drift.added.length } added, ${ drift.removed.length } removed.` );
			for ( const path of drift.changed.slice( 0, 10 ) ) {
				console.log( `  ~ ${ path }` );
			}
			for ( const path of drift.added.slice( 0, 10 ) ) {
				console.log( `  + ${ path }` );
			}
			for ( const path of drift.removed.slice( 0, 10 ) ) {
				console.log( `  - ${ path }` );
			}
		}
		const indexHtml = await readBuiltHtml( options.target );
		if ( indexHtml ) {
			const bands = renderableBands( profile.design );
			const audit = auditBands( {
				sections: bands,
				builtHtml: indexHtml,
				positional:
					profile.design?.bandSource === 'outline' || ( profile.design?.sections?.length ?? 0 ) === 0,
				localizedMedia: await readLocalizedMedia( options.target ),
				text: profile.text,
				geometry: profile.geometry,
			} );
			console.log( '\nBuilt bands:' );
			console.log( formatBandReport( audit ) );
		} else {
			console.log( '\n(No dist/ yet \u2014 run `npm run build` in the target to audit its bands.)' );
		}
	} );

const siteCommand = program
	.command( 'site' )
	.description( 'manage registered sites (the multi-site registry)' );
siteCommand
	.command( 'add' )
	.description( 'register a profile under a name' )
	.argument( '<name>' )
	.argument( '<profilePath>', 'path to the site.json profile' )
	.option( '--url <url>', 'site URL (defaults to the profile\'s url)' )
	.option( '--default', 'make this the default site' )
	.action( async ( name, profilePath, options ) => {
		await runSiteAdd( name, profilePath, options );
	} );
siteCommand
	.command( 'remove' )
	.description( 'remove a registered site' )
	.argument( '<name>' )
	.action( async ( name ) => {
		await runSiteRemove( name );
	} );
siteCommand
	.command( 'list' )
	.description( 'list registered sites' )
	.action( async () => {
		await runSiteList();
	} );
siteCommand
	.command( 'use' )
	.description( 'set the default site' )
	.argument( '<name>' )
	.action( async ( name ) => {
		await runSiteUse( name );
	} );

const vaultCommand = program
	.command( 'vault' )
	.description( 'manage the credential vault (AES-256-GCM)' );
vaultCommand
	.command( 'set' )
	.description( 'store a secret (value on stdin — never argv)' )
	.argument( '<name>' )
	.action( async ( name ) => {
		await runVaultSet( name );
	} );
vaultCommand
	.command( 'delete' )
	.description( 'delete a vault entry' )
	.argument( '<name>' )
	.action( async ( name ) => {
		await runVaultDelete( name );
	} );
vaultCommand
	.command( 'list' )
	.description( 'list vault entry names' )
	.action( async () => {
		await runVaultList();
	} );

/** Shared scaffold options — the default command and the `forge` alias. */
function scaffoldOptions( cmd ) {
	return cmd
		.argument( '<profileRef>', 'site.json path or registered site name' )
		.option( '--target <dir>', 'scaffold target directory' )
		.option( '--template <nameOrPath>', 'built-in template name or a directory path' )
		.option( '--preset <name>', 'blog | storefront | minimal' )
		.option( '--render <mode>', 'static | ssr (default: the preset\'s render mode, static)' )
		.option( '--theme-policy <policy>', 'auto | light | dark | brand:<slug> (default: auto)' )
		.option( '--og-images', 'scaffold the per-post OG card endpoint (adds satori/resvg deps)' )
		.option( '--overwrite', 'replace a non-forge target directory' )
		.option( '--unlock', 're-forge a target that is LOCKED for hand-editing (destroys custom work)' )
		.option( '--check', 'verify collection sizes against the page caps (no scaffold)' )
		.option( '--strict-contrast', 'fail the build when a rendered pair fails WCAG (designAudit: strict)' )
		.option( '--no-agents', 'skip the AGENTS.md/.context agent stubs' )
		.option( '--demo-content', 'keep the template placeholder post/page (off by default: a real site must not ship them)' );
}

// The scaffold is the DEFAULT command (`isDefault`), so the bare form
// `node scripts/forge.mjs site.json --target <dir> …` and the explicit
// `forge` word parse identically — and the scaffold options are scoped to
// this command (program-level options would shadow the wizard/deploy
// subcommands' own --target flags).
const forgeCommand = program
	.command( 'forge', { isDefault: true } )
	.description( 'scaffold the Astro project (the default command)' );
scaffoldOptions( forgeCommand ).action( async ( profileRef, options ) => {
	if ( ! profileRef ) {
		fail( 'Missing <site.json|name> — see --help.' );
	}
	await runScaffold( profileRef, options );
} );

program
	.command( 'tokens' )
	.description( 're-apply a profile\'s design tokens to an existing forged project (no re-scaffold)' )
	.argument( '<profileRef>', 'site.json path or registered site name' )
	.option( '--target <dir>', 'the forged project directory' )
	.option( '--check', 'report drift only; exit 1 when the block does not match the profile' )
	.action( async ( profileRef, options ) => {
		await runTokens( profileRef, options );
	} );

await program.parseAsync( process.argv );

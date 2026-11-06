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

import { confirm, isCancel, select, spinner, text } from '@clack/prompts';
import { Command } from 'commander';

import { analyzeBandContinuity, analyzeBuiltCss, analyzeDesignInput, fetchDesignInput, scoreParity, verifyBandFlow } from '../lib/design.ts';
import { emitDeployConfig } from '../lib/deploy-config.ts';
import { analyzeSite, mergeProfileDraft, profileFromDiscovery, renderReport } from '../lib/discovery.ts';
import { captureEndpoint, captureRemote, listArtifacts, readArtifact } from '../lib/capture.ts';
import { createOctokitClient, GitHubDeploy } from '../lib/github.ts';
import { discoverInventory, fetchText } from '../lib/inventory.ts';
import { PRESETS, PRESET_NAMES, RENDER_MODES, isValidThemePolicy } from '../lib/presets.ts';
import { collectProjectFiles, slugify } from '../lib/project-files.ts';
import { scaffold } from '../lib/scaffold.ts';
import { SitesStore } from '../lib/sites.ts';
import { maskSecret, Vault } from '../lib/vault.ts';
import { COMPARE_PROMPT, visionCompare, visionConfig } from '../lib/vision.ts';
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
}

// -- modes -------------------------------------------------------------------

async function runAnalyze( url, options ) {
	if ( ! url || ! /^https?:\/\//.test( url ) ) {
		fail( 'analyze needs an https?:// URL.' );
	}

	const report = await analyzeSite( new WpClient( { baseUrl: url.replace( /\/+$/, '' ) } ) );
	console.log( renderReport( report ) );
	const profile = profileFromDiscovery( report );

	// --design (114 P1): run the design-language extraction and attach the v2
	// design block to the drafted profile. Extraction failures never block the
	// draft (the operator can still scaffold content-only).
	let designCss; // The --design stylesheets, fed to breakpoint extraction (115).
	if ( options.design ) {
		try {
			const input = await fetchDesignInput( profile.url, fetch, {
				// The CLI-only loopback opt-in (local dev sites) — mirrors the
				// wizard's allowLoopback plumbing.
				allowLoopback: process.env.NVOOS_FORGE_ALLOW_LOOPBACK === '1',
			} );
			const design = analyzeDesignInput( input );
			profile.design = design;
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

	const profileJson = `${ JSON.stringify( profile, null, '\t' ) }\n`;
	if ( options.write ) {
		// Merge into an existing profile by default (Proposal 118 §3): the
		// operator's hand-tuned collections/tokens/frontPage/forms survive;
		// the analyzed blocks refresh. `--fresh` forces a clean draft.
		let text = profileJson;
		let mode = 'Draft profile written to';
		try {
			const existing = JSON.parse( await readFile( options.write, 'utf8' ) );
			if ( ! options.fresh && existing && typeof existing === 'object' ) {
				text = `${ JSON.stringify( mergeProfileDraft( existing, profile ), null, '\t' ) }\n`;
				mode = 'Draft profile MERGED into';
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
		siteUrl: String( profile.url ),
		cloudflareProject: options.cloudflareProject,
		mirrorRepo: options.mirrorRepo,
		siteBranch: options.siteBranch,
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
	let siteUrl = options.siteUrl;
	if ( ! siteUrl && profileRef ) {
		const profile = await loadProfile( profileRef );
		siteUrl = String( profile.url ?? '' );
	}
	try {
		const emitted = await emitDeployConfig( options.target, {
			mode,
			siteUrl,
			cloudflareProject: options.cloudflareProject,
			mirrorRepo: options.mirrorRepo,
			siteBranch: options.siteBranch,
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

/** Concatenate the built CSS bundle under dist/ (bounded — parity only needs
 * the token layer + type/scale signals, never the full bundle). */
async function collectDistCss( target ) {
	const chunks = [];
	async function walk( dir ) {
		for ( const entry of await readdir( dir, { withFileTypes: true } ) ) {
			if ( entry.isDirectory() ) {
				if ( entry.name === 'node_modules' || entry.name === 'pagefind' ) {
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

async function runVerify( profileRef, options ) {
	const profile = await loadProfile( profileRef );
	assertProfile( profile );
	if ( ! profile.design ) {
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
	if ( options.baselines ) {
		// The baseline step is opt-in and browser-backed (114 §3.6): the
		// deterministic parity gate above runs without any browser.
		console.log(
			'\u26a0 Screenshot baselines need Playwright — install it in the forge (npm i -D playwright) to enable --baselines; the parity gate above already ran.'
		);
	}
	process.exitCode = report.pass ? 0 : 1;
	// 124 §7 — the band-flow gate: every source band with visual facts must
	// render in the built page carrying the baked background. A dropped
	// band is exactly the failure the source's continuous band flow depends
	// on (jayainc's gray bridge + flush contact band, pre-124 §5).
	const indexHtml = await readBuiltHtml( options.target );
	if ( indexHtml ) {
		const flow = verifyBandFlow( profile.design?.sections ?? [], indexHtml );
		if ( flow.dropped.length === 0 && flow.mismatched.length === 0 ) {
			console.log( '\u2713 Band flow: every source band renders with its baked background.' );
		} else {
			for ( const id of flow.dropped ) {
				console.warn( `\u2717 Band flow: source band ${ id } is missing from the built page (dropped or unclassified).` );
			}
			for ( const mismatch of flow.mismatched ) {
				console.warn( `\u2717 Band flow: band ${ mismatch.id } renders ${ mismatch.actual ?? 'nothing' }, expected ${ mismatch.expected }.` );
			}
			process.exitCode = 1;
		}
		for ( const seam of flow.seams ) {
			console.log(
				`  source hard cut: ${ seam.fromId ?? '?' } ${ seam.fromEdge ?? '' } → ${ seam.toId ?? '?' } ${ seam.toEdge ?? '' } (ΔE ${ seam.distance })`
			);
		}
	}
	if ( options.visual ) {
		// The vision layer (118 §4) — advisory unless --strict-visual; never
		// downgrades a parity failure.
		await runVisualVerify( profile, options );
	}
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
	const config = visionConfig();
	if ( ! config ) {
		if ( options.strictVisual ) {
			fail( '--strict-visual needs vision credentials: set NVOOS_FORGE_VISION_BASE_URL / NVOOS_FORGE_VISION_KEY / NVOOS_FORGE_VISION_MODEL.' );
		}
		console.log(
			'\u26a0 Visual verify skipped: set NVOOS_FORGE_VISION_BASE_URL / NVOOS_FORGE_VISION_KEY / NVOOS_FORGE_VISION_MODEL to enable the vision comparison.'
		);
		return;
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
	.option( '--inventory', 'discover the full page inventory + site shape (one-pager vs multi-page, Proposal 115)' )
	.option( '--wireframes', 'imply --inventory and extract wireframes for the missing pages (Proposal 115)' )
	.option( '--wayback', 'add Wayback CDX discovery to the inventory (deleted pages)' )
	.option( '--vocabulary', 'learn the widget/block vocabulary (block-types registry + rendered widget names, Proposal 116)' )
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
	.option( '--no-agents', 'skip the AGENTS.md/.context agent stubs' )
	.option( '--cloudflare-project <name>', 'Cloudflare Pages project name' )
	.option( '--mirror-repo <owner/repo>', 'velocity shared mirror — skip repo creation, mirror prebuilt dist/ to the site branch' )
	.option( '--site-branch <branch>', 'shared mirror site branch (e.g. site/jayainc; required with --mirror-repo)' )
	.action( async ( profileRef, options ) => {
		await runDeploy( profileRef, options );
	} );

program
	.command( 'verify' )
	.description( 'score design-language parity between the source site and the built frontend (Proposal 114)' )
	.argument( '<profileRef>', 'site.json path or registered site name (must carry a design block)' )
	.requiredOption( '--target <dir>', 'the built forge project directory (dist/ must exist)' )
	.option( '--threshold <n>', 'parity pass threshold 0-1 (default 0.6)', '0.6' )
	.option( '--baselines', 'note the opt-in Playwright baseline step' )
	.option( '--visual', 'also compare SOURCE and BUILT screenshots with a vision model (Proposal 118)' )
	.option( '--serve <port>', 'with --visual: boot the built site with `astro preview` on <port> while you capture it' )
	.option( '--wait <seconds>', 'with --visual: poll for the dropped built screenshot this many seconds (default 90)', '90' )
	.option( '--min-fidelity <n>', 'with --visual --strict-visual: fail below this 0-100 score (default 70)', '70' )
	.option( '--strict-visual', 'fail the command when the visual comparison cannot run or scores below --min-fidelity' )
	.action( async ( profileRef, options ) => {
		await runVerify( profileRef, options );
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
		.option( '--check', 'verify collection sizes against the page caps (no scaffold)' )
		.option( '--strict-contrast', 'fail the build when a rendered pair fails WCAG (designAudit: strict)' )
		.option( '--no-agents', 'skip the AGENTS.md/.context agent stubs' );
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

await program.parseAsync( process.argv );

#!/usr/bin/env node
// ---------------------------------------------------------------------------
// forge.mjs — the Forge CLI (Proposal 096).
//
//   node scripts/forge.mjs analyze <url> [--write <site.json>]
//   node scripts/forge.mjs forge <site.json|name> --target <dir> [--check]
//   node scripts/forge.mjs site add|remove|list|use …
//   node scripts/forge.mjs vault set|delete|list …
//   node scripts/forge.mjs deploy <site.json|name> --target <dir> --to <mode>
//
// `analyze` performs the polite discovery pass (site_analyze). `forge`
// scaffolds the Astro project from an operator-written SiteProfile (by file
// path, or by name when registered via `site add`). `deploy` scaffolds if
// needed, emits the deploy pipeline (Phase 3.3), creates a PRIVATE repo by
// default, and pushes the source with per-invocation auth (GITHUB_TOKEN env
// or the vault entry `github` — never persisted, always masked in output).
//
// Requires Node >= 22.6 (native TypeScript type stripping).
// ---------------------------------------------------------------------------

import { readFile, stat, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

import { emitDeployConfig } from '../lib/deploy-config.ts';
import { analyzeSite, profileFromDiscovery, renderReport } from '../lib/discovery.ts';
import { createOctokitClient, GitHubDeploy } from '../lib/github.ts';
import { collectProjectFiles, slugify } from '../lib/project-files.ts';
import { scaffold } from '../lib/scaffold.ts';
import { SitesStore } from '../lib/sites.ts';
import { maskSecret, Vault } from '../lib/vault.ts';
import { setupWizard } from '../lib/wizard.ts';
import { WpClient } from '../lib/wp-client.ts';

const DEPLOY_MODES = [ 'github-pages', 'cloudflare-pages', 'velocity' ];

function usage() {
	console.error( 'usage:' );
	console.error( '  node scripts/forge.mjs analyze <url> [--write <site.json>]' );
	console.error( '  node scripts/forge.mjs forge <site.json|name> --target <dir> [--template <name|path>] [--overwrite] [--check]' );
	console.error( '  node scripts/forge.mjs site add <name> <profile.json> [--url <url>] [--default]' );
	console.error( '  node scripts/forge.mjs site remove <name>' );
	console.error( '  node scripts/forge.mjs site list' );
	console.error( '  node scripts/forge.mjs site use <name>' );
	console.error( '  node scripts/forge.mjs vault set <name>   (value on stdin — never argv)' );
	console.error( '  node scripts/forge.mjs vault delete <name>' );
	console.error( '  node scripts/forge.mjs vault list' );
	console.error( '  node scripts/forge.mjs wizard <url> --target <dir> [--name <name>] [--to <mode>] [--build]' );
	console.error( '  node scripts/forge.mjs deploy-config <site.json|name> --target <dir> --to <mode> [--site-url <url>] [--cloudflare-project <name>]' );
	console.error( '  node scripts/forge.mjs deploy <site.json|name> --target <dir> --to <github-pages|cloudflare-pages|velocity> [--repo <name>] [--org <org>] [--public] [--cloudflare-project <name>]' );
	process.exit( 2 );
}

// -- argument parsing --------------------------------------------------------

function parseForgeArgs( argv ) {
	const args = { profileRef: undefined, target: undefined, template: undefined, overwrite: false, check: false };
	for ( let i = 0; i < argv.length; i++ ) {
		const arg = argv[ i ];
		if ( arg === '--target' ) args.target = argv[ ++i ];
		else if ( arg === '--template' ) args.template = argv[ ++i ];
		else if ( arg === '--overwrite' ) args.overwrite = true;
		else if ( arg === '--check' ) args.check = true;
		else if ( ! arg.startsWith( '--' ) && ! args.profileRef ) args.profileRef = arg;
		else usage();
	}
	if ( ! args.profileRef || ! args.target ) usage();
	return args;
}

function parseDeployArgs( argv ) {
	const args = { profileRef: undefined, target: undefined, mode: 'github-pages', repo: undefined, org: undefined, isPublic: false, cloudflareProject: undefined };
	for ( let i = 0; i < argv.length; i++ ) {
		const arg = argv[ i ];
		if ( arg === '--target' ) args.target = argv[ ++i ];
		else if ( arg === '--to' ) args.mode = argv[ ++i ];
		else if ( arg === '--repo' ) args.repo = argv[ ++i ];
		else if ( arg === '--org' ) args.org = argv[ ++i ];
		else if ( arg === '--cloudflare-project' ) args.cloudflareProject = argv[ ++i ];
		else if ( arg === '--public' ) args.isPublic = true;
		else if ( ! arg.startsWith( '--' ) && ! args.profileRef ) args.profileRef = arg;
		else usage();
	}
	if ( ! args.profileRef || ! args.target ) usage();
	if ( ! DEPLOY_MODES.includes( args.mode ) ) {
		console.error( `Unknown deploy mode "${ args.mode }" — use one of: ${ DEPLOY_MODES.join( ', ' ) }` );
		process.exit( 2 );
	}
	return args;
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

async function runAnalyze( rest ) {
	const url = rest[ 0 ];
	let writePath;
	for ( let i = 1; i < rest.length; i++ ) {
		if ( rest[ i ] === '--write' ) writePath = rest[ ++i ];
		else usage();
	}
	if ( ! url || ! /^https?:\/\//.test( url ) ) usage();

	const report = await analyzeSite( new WpClient( { baseUrl: url.replace( /\/+$/, '' ) } ) );
	console.log( renderReport( report ) );
	const profile = profileFromDiscovery( report );
	const profileJson = `${ JSON.stringify( profile, null, '\t' ) }\n`;
	if ( writePath ) {
		await writeFile( writePath, profileJson, 'utf8' );
		console.log( `\u2713 Draft profile written to ${ writePath } — review excludes/frontPage, then scaffold.` );
	} else {
		console.log( '--- draft site.json ---' );
		console.log( profileJson.trimEnd() );
	}
	// Graceful exit — process.exit() races undici's teardown on Windows and
	// dies with the libuv UV_HANDLE_CLOSING assertion (0xC0000409).
}

async function runSite( rest ) {
	const command = rest[ 0 ];
	const sites = new SitesStore();
	if ( command === 'add' ) {
		const name = rest[ 1 ];
		const profilePath = rest[ 2 ];
		if ( ! name || ! profilePath ) usage();
		let url;
		let isDefault = false;
		for ( let i = 3; i < rest.length; i++ ) {
			if ( rest[ i ] === '--url' ) url = rest[ ++i ];
			else if ( rest[ i ] === '--default' ) isDefault = true;
			else usage();
		}
		const profile = JSON.parse( await readFile( profilePath, 'utf8' ) );
		if ( ! url && profile.url ) url = profile.url;
		if ( ! url ) {
			console.error( 'No URL — pass --url <url> or include "url" in the profile.' );
			process.exit( 1 );
		}
		await sites.add( name, { url, profile: profilePath }, { isDefault } );
		console.log( `\u2713 Registered site "${ name }" -> ${ url } (${ profilePath })` );
		return;
	}
	if ( command === 'remove' ) {
		const name = rest[ 1 ];
		if ( ! name ) usage();
		await sites.remove( name );
		console.log( `\u2713 Removed site "${ name }".` );
		return;
	}
	if ( command === 'list' ) {
		const listed = await sites.list();
		const defaultName = await sites.defaultName();
		if ( Object.keys( listed ).length === 0 ) {
			console.log( 'No registered sites.' );
			return;
		}
		for ( const [ name, ref ] of Object.entries( listed ) ) {
			console.log( `${ name === defaultName ? '*' : ' '} ${ name } -> ${ ref.url } (${ ref.profile })` );
		}
		return;
	}
	if ( command === 'use' ) {
		const name = rest[ 1 ];
		if ( ! name ) usage();
		await sites.setDefault( name );
		console.log( `\u2713 Default site is now "${ name }".` );
		return;
	}
	usage();
}

async function runVault( rest ) {
	const command = rest[ 0 ];
	const vault = new Vault();
	if ( command === 'set' ) {
		const name = rest[ 1 ];
		if ( ! name ) usage();
		// The secret arrives on stdin (never argv, never the shell history).
		const value = ( await new Promise( ( resolvePromise ) => {
			let input = '';
			process.stdin.setEncoding( 'utf8' );
			process.stdin.on( 'data', ( chunk ) => ( input += chunk ) );
			process.stdin.on( 'end', () => resolvePromise( input.replace( /[\r\n]+$/, '' ) ) );
			process.stdin.resume();
		} ) );
		if ( ! value ) {
			console.error( 'No value on stdin — nothing stored.' );
			process.exit( 1 );
		}
		await vault.set( name, value );
		console.log( `\u2713 Stored vault entry "${ name }" (${ maskSecret( value ) }).` );
		return;
	}
	if ( command === 'delete' ) {
		const name = rest[ 1 ];
		if ( ! name ) usage();
		await vault.delete( name );
		console.log( `\u2713 Deleted vault entry "${ name }".` );
		return;
	}
	if ( command === 'list' ) {
		console.log( ( await vault.list() ).join( '\n' ) || '(empty)' );
		return;
	}
	usage();
}

async function runDeploy( argv ) {
	const args = parseDeployArgs( argv );
	const profile = await loadProfile( args.profileRef );
	assertProfile( profile );

	// 1. Scaffold (idempotent — an existing forge project is reused).
	try {
		await stat( join( args.target, 'nvoos.json' ) );
		console.log( `\u2713 Target ${ args.target } is already a forge project — reusing.` );
	} catch {
		const result = await scaffold( { profile, targetDir: args.target } );
		console.log( `\u2713 Scaffolded ${ result.collections.length } collection(s) into ${ result.targetDir }` );
		for ( const warning of result.warnings ) {
			console.warn( `\u26a0 ${ warning }` );
		}
	}

	// 2. Emit the deploy pipeline (adapter-generic — rebuild wrapper, deploy
	//    workflow, and the fail-closed dispatch verifier).
	const emitted = await emitDeployConfig( args.target, {
		mode: args.mode,
		siteUrl: String( profile.url ),
		cloudflareProject: args.cloudflareProject,
	} );
	for ( const file of emitted.files ) {
		console.log( `  + ${ file }` );
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
	const files = await collectProjectFiles( args.target );

	// 5. Create (private by default) + push.
	const deploy = new GitHubDeploy( await createOctokitClient( token ) );
	const repoName = args.repo ?? slugify( String( profile.name ?? profile.url ) );
	const owner = args.org;
	let repoResult;
	try {
		repoResult = await deploy.createRepo( {
			name: repoName,
			description: `Generated frontend for ${ String( profile.url ) } (NV oOS Astro Forge).`,
			org: owner,
			isPrivate: ! args.isPublic,
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
		console.log( `Next: configure repo secrets (FORGE_WEBHOOK_SECRET${ args.mode === 'velocity' ? ', VELOCITY_*' : '' }${ args.mode === 'cloudflare-pages' ? ', CLOUDFLARE_*' : '' }) and enable the Pages/mirror target.` );
	} catch ( error ) {
		console.error( error instanceof Error ? error.message : error );
		process.exitCode = 1;
	}
}

async function runScaffold( argv ) {
	const args = parseForgeArgs( argv );
	const profile = await loadProfile( args.profileRef );
	assertProfile( profile );

	if ( args.check ) {
		const clean = await checkProfile( profile );
		console.log( clean ? 'All collections fit within their page caps.' : 'Some collections exceed their caps — raise maxPages in the profile.' );
		// Graceful exit — see the analyze branch (Windows libuv teardown).
		process.exitCode = clean ? 0 : 1;
		return;
	}

	const result = await scaffold( { profile, targetDir: args.target, template: args.template, overwrite: args.overwrite } );
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

// -- dispatch ----------------------------------------------------------------

const argv = process.argv.slice( 2 );

switch ( argv[ 0 ] ) {
	case 'analyze':
		await runAnalyze( argv.slice( 1 ) );
		break;
	case 'site':
		await runSite( argv.slice( 1 ) );
		break;
	case 'vault':
		await runVault( argv.slice( 1 ) );
		break;
	case 'wizard':
		await runWizard( argv.slice( 1 ) );
		break;
	case 'deploy-config':
		await runDeployConfig( argv.slice( 1 ) );
		break;
	case 'deploy':
		await runDeploy( argv.slice( 1 ) );
		break;
	default:
		// The documented `forge` subcommand word is optional — strip it when
		// present so `forge <site.json|name> --target …` parses like the
		// bare form.
		await runScaffold( argv[ 0 ] === 'forge' ? argv.slice( 1 ) : argv );
		break;
}

/** Emit the deploy pipeline into a scaffolded target (no network, no token). */
async function runDeployConfig( rest ) {
	const args = { profileRef: undefined, target: undefined, mode: 'github-pages', siteUrl: undefined, cloudflareProject: undefined };
	for ( let i = 0; i < rest.length; i++ ) {
		const arg = rest[ i ];
		if ( arg === '--target' ) args.target = rest[ ++i ];
		else if ( arg === '--to' ) args.mode = rest[ ++i ];
		else if ( arg === '--site-url' ) args.siteUrl = rest[ ++i ];
		else if ( arg === '--cloudflare-project' ) args.cloudflareProject = rest[ ++i ];
		else if ( ! arg.startsWith( '--' ) && ! args.profileRef ) args.profileRef = arg;
		else usage();
	}
	if ( ! args.target ) usage();
	if ( ! DEPLOY_MODES.includes( args.mode ) ) {
		console.error( `Unknown deploy mode "${ args.mode }" — use one of: ${ DEPLOY_MODES.join( ', ' ) }` );
		process.exit( 2 );
	}
	let siteUrl = args.siteUrl;
	if ( ! siteUrl && args.profileRef ) {
		const profile = await loadProfile( args.profileRef );
		siteUrl = String( profile.url ?? '' );
	}
	try {
		const emitted = await emitDeployConfig( args.target, {
			mode: args.mode,
			siteUrl,
			cloudflareProject: args.cloudflareProject,
		} );
		console.log( `\u2713 Emitted the deploy pipeline into ${ args.target }:` );
		for ( const file of emitted.files ) {
			console.log( `  + ${ file }` );
		}
		console.log( 'Next: configure the repo secrets (FORGE_WEBHOOK_SECRET, target-specific) and push.' );
	} catch ( error ) {
		console.error( error instanceof Error ? error.message : error );
		process.exitCode = 1;
	}
}

async function runWizard( rest ) {
	const args = { url: undefined, target: undefined, name: undefined, mode: 'github-pages', build: false, cloudflareProject: undefined };
	for ( let i = 0; i < rest.length; i++ ) {
		const arg = rest[ i ];
		if ( arg === '--target' ) args.target = rest[ ++i ];
		else if ( arg === '--name' ) args.name = rest[ ++i ];
		else if ( arg === '--to' ) args.mode = rest[ ++i ];
		else if ( arg === '--build' ) args.build = true;
		else if ( arg === '--cloudflare-project' ) args.cloudflareProject = rest[ ++i ];
		else if ( ! arg.startsWith( '--' ) && ! args.url ) args.url = arg;
		else usage();
	}
	if ( ! args.url || ! args.target ) usage();
	if ( ! DEPLOY_MODES.includes( args.mode ) ) {
		console.error( `Unknown deploy mode "${ args.mode }" — use one of: ${ DEPLOY_MODES.join( ', ' ) }` );
		process.exit( 2 );
	}
	try {
		const result = await setupWizard( {
			url: args.url,
			targetDir: args.target,
			name: args.name,
			mode: args.mode,
			cloudflareProject: args.cloudflareProject,
			build: args.build,
			// Local-dev opt-in only — the MCP surface never sets this.
			allowLoopback: process.env.NVOOS_FORGE_ALLOW_LOOPBACK === '1',
		} );
		console.log( `\u2713 Wizard complete — site "${ result.siteName }" -> ${ result.targetDir }` );
		console.log( `  built: ${ result.built }, git: ${ result.gitInitialized ? 'initialized' : 'skipped' }` );
		for ( const file of result.deployFiles ) {
			console.log( `  + ${ file }` );
		}
		for ( const warning of result.warnings ) {
			console.warn( `\u26a0 ${ warning }` );
		}
	} catch ( error ) {
		console.error( error instanceof Error ? error.message : error );
		process.exitCode = 1;
	}
}

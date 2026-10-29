#!/usr/bin/env node
/**
 * Assembles the nvoos-funiq-velocity mirror tree from a BUILT Payload CMS
 * checkout in the private repo nvdigitalsolutions/nvoos-funiq-pwa.
 *
 * The mirror is the Cloudways Velocity deploy tree (flat history, assembled
 * by the sync workflow). It contains the licensed build output ONLY — the
 * mirror is private and the workflow enforces that before pushing.
 *
 * Mirror layout (validated locally 2026-10-29, Payload 3.64 / Next 15.4):
 *
 *   server.js           Next standalone entry (Velocity entry file)
 *   src/                Payload config + migrations (standalone copy)
 *   .next/              Full build output, minus cache/standalone
 *   package.json        Vendor manifest — Velocity runs `npm install`
 *   package-lock.json   Reproducible install
 *   .npmrc              legacy-peer-deps=true (install succeeds)
 *   .env.example        NV-authored: DATABASE_URI, PAYLOAD_SECRET, HOSTNAME
 *   LICENSE.txt         Envato purchase proof (from the private repo root)
 *   README.md           Deploy runbook (copy of the monorepo example README)
 *   scripts/smoke.mjs   Hermetic contract smoke (boot + Payload envelope)
 *   .gitignore          node_modules/, .env, logs — Velocity installs deps
 *
 * Usage: node scripts/assemble-mirror.mjs <cms-dir> <target-dir>
 *
 * No third-party dependencies — Node >= 20.9 only.
 */

import {
	cpSync,
	existsSync,
	mkdirSync,
	readdirSync,
	rmSync,
	statSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const exampleRoot = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

function fail( message ) {
	console.error( `[assemble] ERROR: ${ message }` );
	process.exit( 1 );
}

const [ , , cmsArg, targetArg ] = process.argv;
if ( ! cmsArg || ! targetArg ) {
	fail( 'usage: node scripts/assemble-mirror.mjs <cms-dir> <target-dir>' );
}

const cmsDir = resolve( cmsArg );
const targetDir = resolve( targetArg );

// --- Gates: the CMS checkout must be built (standalone) --------------------
const standaloneServer = join( cmsDir, '.next', 'standalone', 'server.js' );
if ( ! existsSync( standaloneServer ) ) {
	fail(
		`${ standaloneServer } missing — run scripts/enable-standalone.mjs and ` +
		'`npm run build` in the CMS checkout first.'
	);
}
if ( ! existsSync( join( cmsDir, '.next', 'BUILD_ID' ) ) ) {
	fail( `${ join( cmsDir, '.next', 'BUILD_ID' ) } missing — incomplete build.` );
}
for ( const file of [ 'package.json', 'package-lock.json', '.npmrc' ] ) {
	if ( ! existsSync( join( cmsDir, file ) ) ) {
		fail( `${ join( cmsDir, file ) } missing in the CMS checkout.` );
	}
}

rmSync( targetDir, { recursive: true, force: true } );
mkdirSync( targetDir, { recursive: true } );

const copy = ( from, to, label ) => {
	cpSync( from, to, { recursive: true } );
	console.log( `[assemble] + ${ label }` );
};

// 1. Standalone entry + its runtime `src/` (Payload loads its config at
//    runtime; migrations are added after build — see step 5).
copy( standaloneServer, join( targetDir, 'server.js' ), 'server.js (Next standalone entry)' );
copy( join( cmsDir, '.next', 'standalone', 'src' ), join( targetDir, 'src' ), 'src/ (Payload config, standalone copy)' );

// 2. Full .next/ output — the standalone server reads BUILD_ID, manifests
//    and static assets from ./.next relative to the repo root. Exclude the
//    cache and the standalone sub-tree itself (already flattened).
mkdirSync( join( targetDir, '.next' ), { recursive: true } );
for ( const entry of readdirSync( join( cmsDir, '.next' ) ) ) {
	if ( entry === 'cache' || entry === 'standalone' || entry === 'diagnostics' ) {
		continue;
	}
	const stat = statSync( join( cmsDir, '.next', entry ) );
	if ( stat.isDirectory() ) {
		copy( join( cmsDir, '.next', entry ), join( targetDir, '.next', entry ), `.next/${ entry }/` );
	} else {
		cpSync( join( cmsDir, '.next', entry ), join( targetDir, '.next', entry ) );
	}
}
console.log( '[assemble] + .next/ (build output, no cache/standalone)' );

// 3. Fresh migrations (generated AFTER the build by `payload migrate:create`).
const migrations = join( cmsDir, 'src', 'migrations' );
if ( existsSync( migrations ) ) {
	copy( migrations, join( targetDir, 'src', 'migrations' ), 'src/migrations/ (deploy migrations)' );
}

// 4. Static public assets, if the vendor template ships any.
const publicDir = join( cmsDir, 'public' );
if ( existsSync( publicDir ) ) {
	copy( publicDir, join( targetDir, 'public' ), 'public/' );
}

// 5. Vendor manifest + npm config — Velocity runs its normal `npm install`.
for ( const file of [ 'package.json', 'package-lock.json', '.npmrc' ] ) {
	cpSync( join( cmsDir, file ), join( targetDir, file ) );
}
console.log( '[assemble] + package.json, package-lock.json, .npmrc' );

// 6. Envato purchase proof (private repo root) + NV-authored files.
const licenseFile = join( cmsDir, '..', '..', 'LICENSE.txt' );
if ( existsSync( licenseFile ) ) {
	cpSync( licenseFile, join( targetDir, 'LICENSE.txt' ) );
	console.log( '[assemble] + LICENSE.txt (Envato purchase proof)' );
} else {
	// The sync workflow hard-fails on this; local dev builds may not have
	// the private repo root handy.
	console.warn( '[assemble] WARN: LICENSE.txt not found at the private repo root.' );
}
cpSync( join( exampleRoot, 'README.md' ), join( targetDir, 'README.md' ) );
cpSync( join( exampleRoot, 'THIRD_PARTY_NOTICES.md' ), join( targetDir, 'THIRD_PARTY_NOTICES.md' ) );
copy( join( exampleRoot, 'scripts', 'smoke.mjs' ), join( targetDir, 'scripts', 'smoke.mjs' ), 'scripts/smoke.mjs' );

// 7. NV-authored env template + mirror gitignore.
writeFileSync(
	join( targetDir, '.env.example' ),
	[
		'# Copy to .env (or set as app-level env vars in Velocity).',
		'',
		'# PostgreSQL connection string (Payload 3 postgres adapter).',
		'DATABASE_URI=postgres://user:password@db-host:5432/funiq',
		'',
		'# Secret used to sign Payload auth cookies/JWTs. Generate with:',
		'#   node -e "console.log(require(\'crypto\').randomBytes(32).toString(\'hex\'))"',
		'PAYLOAD_SECRET=replace-me',
		'',
		'# Bind host. Velocity injects PORT; do not set PORT manually.',
		'HOSTNAME=0.0.0.0',
		'',
		'# Production mode skips devDependencies during `npm install`.',
		'NODE_ENV=production',
		'',
	].join( '\n' )
);
writeFileSync(
	join( targetDir, '.gitignore' ),
	[
		'# Installed by Velocity from package.json — never committed.',
		'node_modules/',
		'',
		'# Secrets.',
		'.env',
		'',
		'# Logs.',
		'*.log',
		'',
	].join( '\n' )
);
console.log( '[assemble] + .env.example, .gitignore' );

// 8. Final gate: the exact tree we validated boots on Velocity.
for ( const required of [ 'server.js', 'src/payload-types.ts', '.next/BUILD_ID', 'package.json', 'package-lock.json', '.npmrc', 'README.md', '.env.example', '.gitignore' ] ) {
	if ( ! existsSync( join( targetDir, required ) ) ) {
		fail( `final gate failed: ${ required } missing from the mirror tree` );
	}
}
console.log( `[assemble] mirror tree assembled at ${ targetDir }` );

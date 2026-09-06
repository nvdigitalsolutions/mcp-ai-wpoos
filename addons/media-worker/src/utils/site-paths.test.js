/**
 * Tests for the multi-tenant site path namespacing utility.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs';
import {
	isMultiTenant,
	isStrictPaths,
	isValidSlug,
	siteBaseDir,
	siteDirFor,
	groupTtl,
	resolveSitePath,
	pathGuard,
	cleanupSiteTemp,
	tempStats,
	TTL_GROUPS,
} from './site-paths.js';

const originalTokens = process.env.SITE_TOKENS;
const originalRoot = process.env.TEMP_ROOT;
const originalStrict = process.env.STRICT_PATHS;
const originalStrictPdf = process.env.STRICT_PDF_PATHS;

function resetEnv() {
	if ( originalTokens ) {
		process.env.SITE_TOKENS = originalTokens;
	} else {
		delete process.env.SITE_TOKENS;
	}
	if ( originalRoot ) {
		process.env.TEMP_ROOT = originalRoot;
	} else {
		delete process.env.TEMP_ROOT;
	}
	if ( originalStrict ) {
		process.env.STRICT_PATHS = originalStrict;
	} else {
		delete process.env.STRICT_PATHS;
	}
	if ( originalStrictPdf ) {
		process.env.STRICT_PDF_PATHS = originalStrictPdf;
	} else {
		delete process.env.STRICT_PDF_PATHS;
	}
}

test( 'isValidSlug accepts safe slugs', () => {
	assert.equal( isValidSlug( 'site-a' ), true );
	assert.equal( isValidSlug( 'client123' ), true );
	assert.equal( isValidSlug( 'a'.repeat( 32 ) ), true );
} );

test( 'isValidSlug rejects traversal and unsafe characters', () => {
	assert.equal( isValidSlug( '..' ), false );
	assert.equal( isValidSlug( '../etc' ), false );
	assert.equal( isValidSlug( 'site/a' ), false );
	assert.equal( isValidSlug( 'site a' ), false );
	assert.equal( isValidSlug( 'SITE' ), false );
	assert.equal( isValidSlug( '' ), false );
	assert.equal( isValidSlug( 'x'.repeat( 33 ) ), false );
	assert.equal( isValidSlug( null ), false );
	assert.equal( isValidSlug( undefined ), false );
} );

test( 'single-tenant mode falls back to os.tmpdir()', () => {
	delete process.env.SITE_TOKENS;
	assert.equal( isMultiTenant(), false );
	assert.equal( siteBaseDir( 'default' ), os.tmpdir() );
	resetEnv();
} );

test( 'single-tenant strict mode honours TEMP_ROOT as the allowlisted root (Q5)', () => {
	delete process.env.SITE_TOKENS;
	process.env.STRICT_PATHS = '1';
	process.env.TEMP_ROOT = path.join( os.tmpdir(), 'mw-q5' );
	assert.equal( siteBaseDir( 'default' ), path.resolve( process.env.TEMP_ROOT ) );
	assert.equal( siteDirFor( 'default', 'scratch' ), path.resolve( process.env.TEMP_ROOT ) );
	resetEnv();
} );

test( 'single-tenant strict mode without TEMP_ROOT stays on os.tmpdir()', () => {
	delete process.env.SITE_TOKENS;
	process.env.STRICT_PATHS = '1';
	delete process.env.TEMP_ROOT;
	assert.equal( siteBaseDir( 'default' ), os.tmpdir() );
	resetEnv();
} );

test( 'single-tenant permissive mode ignores TEMP_ROOT (legacy behaviour unchanged)', () => {
	delete process.env.SITE_TOKENS;
	delete process.env.STRICT_PATHS;
	delete process.env.STRICT_PDF_PATHS;
	process.env.TEMP_ROOT = path.join( os.tmpdir(), 'mw-ignored' );
	assert.equal( siteBaseDir( 'default' ), os.tmpdir() );
	resetEnv();
} );

test( 'STRICT_PDF_PATHS also activates the TEMP_ROOT allowlist in single-tenant mode', () => {
	delete process.env.SITE_TOKENS;
	delete process.env.STRICT_PATHS;
	process.env.STRICT_PDF_PATHS = '1';
	process.env.TEMP_ROOT = path.join( os.tmpdir(), 'mw-pdf' );
	assert.equal( isStrictPaths(), true );
	assert.equal( siteBaseDir( 'default' ), path.resolve( process.env.TEMP_ROOT ) );
	resetEnv();
} );

test( 'resolveSitePath enforces the TEMP_ROOT allowlist in single-tenant strict mode', () => {
	delete process.env.SITE_TOKENS;
	process.env.STRICT_PATHS = '1';
	process.env.TEMP_ROOT = path.join( os.tmpdir(), 'mw-q5' );
	const inside = path.join( process.env.TEMP_ROOT, 'file.pdf' );
	assert.equal( resolveSitePath( 'default', inside ), path.resolve( inside ) );
	assert.equal( resolveSitePath( 'default', '/etc/passwd' ), null );
	assert.equal( resolveSitePath( 'default', path.join( os.tmpdir(), 'other.pdf' ) ), null );
	resetEnv();
} );

test( 'multi-tenant mode namespaces per site under TEMP_ROOT', () => {
	process.env.SITE_TOKENS = '{"site-a":"t1","site-b":"t2"}';
	process.env.TEMP_ROOT = '/srv/worker-tmp';
	assert.equal( isMultiTenant(), true );
	assert.equal( siteBaseDir( 'site-a' ), path.join( '/srv/worker-tmp', 'sites', 'site-a' ) );
	assert.equal( siteBaseDir( 'site-b' ), path.join( '/srv/worker-tmp', 'sites', 'site-b' ) );
	resetEnv();
} );

test( 'resolveSitePath keeps in-namespace paths', () => {
	process.env.SITE_TOKENS = '{"site-a":"t1"}';
	process.env.TEMP_ROOT = '/srv/worker-tmp';
	const base = siteBaseDir( 'site-a' );
	assert.equal( resolveSitePath( 'site-a', path.join( base, 'file.pdf' ) ), path.resolve( path.join( base, 'file.pdf' ) ) );
	assert.equal( resolveSitePath( 'site-a', base ), path.resolve( base ) );
	resetEnv();
} );

test( 'resolveSitePath rejects escapes', () => {
	process.env.SITE_TOKENS = '{"site-a":"t1"}';
	process.env.TEMP_ROOT = '/srv/worker-tmp';
	assert.equal( resolveSitePath( 'site-a', '/etc/passwd' ), null );
	assert.equal( resolveSitePath( 'site-a', path.join( os.tmpdir(), 'other.pdf' ) ), null );
	assert.equal( resolveSitePath( 'site-a', '/srv/worker-tmp/sites/site-b/file.pdf' ), null );
	assert.equal( resolveSitePath( 'site-a', 'relative.pdf' ), null );
	assert.equal( resolveSitePath( '../evil', 'x.pdf' ), null );
	resetEnv();
} );

test( 'pathGuard is permissive in legacy single-tenant mode', () => {
	delete process.env.SITE_TOKENS;
	assert.equal( isStrictPaths(), false );
	assert.equal( pathGuard( 'default', '/any/where.pdf' ), '/any/where.pdf' );
	assert.equal( pathGuard( 'default', null ), null );
	resetEnv();
} );

test( 'pathGuard enforces the namespace in multi-tenant mode', () => {
	process.env.SITE_TOKENS = '{"site-a":"t1"}';
	process.env.TEMP_ROOT = '/srv/worker-tmp';
	const base = siteBaseDir( 'site-a' );
	const out = path.resolve( path.join( base, 'out.pdf' ) );
	assert.equal( pathGuard( 'site-a', path.join( base, 'out.pdf' ) ), out );
	assert.throws( () => pathGuard( 'site-a', '/etc/passwd' ), ( err ) => err.status === 403 );
	resetEnv();
} );

test( 'pathGuard honors STRICT_PATHS in single-tenant mode', () => {
	delete process.env.SITE_TOKENS;
	process.env.STRICT_PATHS = '1';
	assert.equal( isStrictPaths(), true );
	assert.throws( () => pathGuard( 'default', '/etc/passwd' ), ( err ) => err.status === 403 );
	resetEnv();
} );

// ── Grouped temp dirs, TTLs, cleanup, stats (Phase 2c) ────────

test( 'siteDirFor returns group subdirs in multi-tenant mode', () => {
	process.env.SITE_TOKENS = '{"site-a":"t1"}';
	process.env.TEMP_ROOT = '/srv/worker-tmp';
	assert.equal(
		siteDirFor( 'site-a', 'video' ),
		path.join( '/srv/worker-tmp', 'sites', 'site-a', 'video' )
	);
	// Unknown groups fall back to 'scratch'.
	assert.equal(
		siteDirFor( 'site-a', 'nonsense' ),
		path.join( '/srv/worker-tmp', 'sites', 'site-a', 'scratch' )
	);
	resetEnv();
} );

test( 'siteDirFor falls back to os.tmpdir() in single-tenant mode', () => {
	delete process.env.SITE_TOKENS;
	assert.equal( siteDirFor( 'default', 'video' ), os.tmpdir() );
	resetEnv();
} );

test( 'groupTtl uses defaults and env overrides', () => {
	assert.equal( groupTtl( 'upload' ), TTL_GROUPS.upload );
	assert.equal( groupTtl( 'video' ), TTL_GROUPS.video );
	assert.equal( groupTtl( 'nonsense' ), TTL_GROUPS.scratch );

	const original = process.env.TEMP_TTL_VIDEO;
	process.env.TEMP_TTL_VIDEO = '60000';
	assert.equal( groupTtl( 'video' ), 60000 );
	if ( original ) {
		process.env.TEMP_TTL_VIDEO = original;
	} else {
		delete process.env.TEMP_TTL_VIDEO;
	}

	const originalGlobal = process.env.TEMP_TTL;
	process.env.TEMP_TTL = '43200000';
	assert.equal( groupTtl( 'scratch' ), 43200000 );
	if ( originalGlobal ) {
		process.env.TEMP_TTL = originalGlobal;
	} else {
		delete process.env.TEMP_TTL;
	}
} );

test( 'cleanupSiteTemp prunes per group and reports stats', () => {
	const tempRoot = fs.mkdtempSync( path.join( os.tmpdir(), 'mw-test-' ) );
	process.env.SITE_TOKENS = '{"site-a":"t1"}';
	process.env.TEMP_ROOT = tempRoot;

	const videoDir = siteDirFor( 'site-a', 'video' );
	const uploadDir = siteDirFor( 'site-a', 'upload' );
	const oldFile = path.join( videoDir, 'old.mp4' );
	const newFile = path.join( videoDir, 'new.mp4' );
	const oldUpload = path.join( uploadDir, 'old.pdf' );
	fs.writeFileSync( oldFile, 'x' );
	fs.writeFileSync( newFile, 'x' );
	fs.writeFileSync( oldUpload, 'xxxx' );

	const past = new Date( Date.now() - 10 * 24 * 60 * 60 * 1000 ); // 10 days ago
	fs.utimesSync( oldFile, past, past );
	fs.utimesSync( oldUpload, past, past );

	const pruned = cleanupSiteTemp();

	// Video TTL is 1h: old.mp4 pruned, new.mp4 kept.
	assert.equal( fs.existsSync( oldFile ), false );
	assert.equal( fs.existsSync( newFile ), true );
	// Upload TTL is 7d: the 10-day-old upload is pruned.
	assert.equal( fs.existsSync( oldUpload ), false );
	assert.equal( pruned.files, 2 );

	const stats = tempStats();
	assert.equal( stats.totals.files, 1 ); // new.mp4
	assert.equal( stats.per_site[ 'site-a' ].files, 1 );
	assert.ok( stats.oldest_ms > 0 );

	fs.rmSync( tempRoot, { recursive: true, force: true } );
	resetEnv();
} );

test( 'cleanupSiteTemp and tempStats are no-ops in single-tenant mode', () => {
	delete process.env.SITE_TOKENS;
	assert.equal( cleanupSiteTemp(), null );
	assert.equal( tempStats(), null );
	resetEnv();
} );

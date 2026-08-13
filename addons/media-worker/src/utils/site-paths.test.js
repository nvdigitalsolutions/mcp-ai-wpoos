/**
 * Tests for the multi-tenant site path namespacing utility.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import os from 'node:os';
import {
	isMultiTenant,
	isStrictPaths,
	isValidSlug,
	siteBaseDir,
	resolveSitePath,
	pathGuard,
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

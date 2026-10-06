'use strict';

/**
 * Sync-gate test for @nvdigitalsolutions/nvoos-mcp-bridge.
 *
 * Asserts the package's bin/ copies are byte-identical to the repo's
 * canonical sources (bin/mcp-bridge.js, bin/mcp-bridge-ssh.js,
 * bin/utils/env-file.js) plus the repo LICENSE. Runs the same --check path
 * the CI publish workflow gates on.
 *
 * Run: node --test test/sync.test.js
 */

const assert = require( 'node:assert' );
const path = require( 'node:path' );
const { spawnSync } = require( 'node:child_process' );
const { test } = require( 'node:test' );

const SYNC = path.join( __dirname, '..', 'sync-from-bin.js' );

test( 'package bin/ copies are byte-identical to the repo bin/ sources', () => {
	const result = spawnSync( process.execPath, [ SYNC, '--check' ], { encoding: 'utf8' } );
	assert.strictEqual(
		result.status,
		0,
		`sync check failed:\n${ result.stderr }\nRun "npm run sync" from packages/nvoos-mcp-bridge.`
	);
	assert.match( result.stderr, /byte-identical/ );
} );

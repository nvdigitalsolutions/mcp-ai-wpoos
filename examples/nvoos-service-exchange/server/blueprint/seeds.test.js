import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdir, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { validateBlueprint } from './validate.js';

const BLUEPRINTS_DIR = fileURLToPath( new URL( '../../blueprints/', import.meta.url ) );

async function loadSeeds() {
	const files = ( await readdir( BLUEPRINTS_DIR ) ).filter(
		( name ) => name.endsWith( '.json' ) && name !== 'schema.json'
	);
	const seeds = [];
	for ( const name of files ) {
		const raw = await readFile( join( BLUEPRINTS_DIR, name ), 'utf8' );
		seeds.push( { name, blueprint: JSON.parse( raw ) } );
	}
	return seeds;
}

test( 'every seed blueprint validates and slugs are unique', async () => {
	const seeds = await loadSeeds();
	assert.ok( seeds.length >= 5, 'at least five seeds ship with the app' );
	const slugs = new Set();
	for ( const seed of seeds ) {
		const result = validateBlueprint( seed.blueprint );
		assert.equal(
			result.ok,
			true,
			`${ seed.name } failed: ${ result.ok ? '' : JSON.stringify( result.errors ) }`
		);
		assert.ok( ! slugs.has( result.blueprint.slug ), `duplicate seed slug ${ result.blueprint.slug }` );
		slugs.add( result.blueprint.slug );
	}
} );

test( 'no seed contains a secret-shaped key or credential value', async () => {
	const seeds = await loadSeeds();
	for ( const seed of seeds ) {
		const serialized = JSON.stringify( seed.blueprint );
		assert.ok( ! /op_[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/.test( serialized ), `${ seed.name } contains a credential` );
		assert.ok( ! /"api_key"\s*:/.test( serialized ), `${ seed.name } contains an api_key field` );
	}
} );

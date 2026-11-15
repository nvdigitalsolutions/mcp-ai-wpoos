import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createEstateStore } from './store.js';
import { readEvents } from '../util/jsonl.js';

function blueprint( slug = 'clinic-ops' ) {
	return { slug, name: 'Clinic Ops' };
}

test( 'creates, reads and lists estates', async () => {
	const store = createEstateStore();
	const created = await store.create( { slug: 'clinic-ops', blueprint: blueprint() } );
	assert.equal( created.slug, 'clinic-ops' );
	assert.equal( store.get( 'clinic-ops' ).name, 'Clinic Ops' );
	assert.equal( store.list().length, 1 );
	assert.equal( store.get( 'missing' ), null );
} );

test( 'rejects duplicate slugs with a conflict error', async () => {
	const store = createEstateStore();
	await store.create( { slug: 'clinic-ops', blueprint: blueprint() } );
	await assert.rejects(
		() => store.create( { slug: 'clinic-ops', blueprint: blueprint() } ),
		( error ) => error.code === 'slug_conflict' && error.status === 409
	);
} );

test( 'updates evidence and appends advisory events', async () => {
	const dir = await mkdtemp( join( tmpdir(), 'exchange-store-' ) );
	const eventsFile = join( dir, 'jobs.jsonl' );
	try {
		const store = createEstateStore( { eventsFile } );
		await store.create( { slug: 'clinic-ops', blueprint: blueprint() } );
		await store.setEvidence( 'clinic-ops', { cloudwaysApp: true } );
		assert.equal( store.get( 'clinic-ops' ).evidence.cloudwaysApp, true );

		const events = await readEvents( eventsFile );
		assert.equal( events[ 0 ].type, 'estate.created' );
		assert.equal( events[ 1 ].type, 'estate.evidence' );
		assert.deepEqual( events[ 1 ].evidence, { cloudwaysApp: true } );
	} finally {
		await rm( dir, { recursive: true, force: true } );
	}
} );

test( 'missing estate updates raise not_found', async () => {
	const store = createEstateStore();
	await assert.rejects(
		() => store.update( 'missing', { name: 'x' } ),
		( error ) => error.code === 'not_found'
	);
} );

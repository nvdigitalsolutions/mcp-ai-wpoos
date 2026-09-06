/**
 * Tests for storage-util.js
 *
 * Loads the IIFE directly (same pattern as session-key-sanitization.test.js)
 * and exercises the Web Worker offload decision points:
 * threshold boundary, worker message routing, fallbacks, and the
 * per-call threshold override (proposal 032).
 *
 * @package WP_MCP_AI
 */

const fs = require( 'fs' );
const path = require( 'path' );

const storageUtilCode = fs.readFileSync(
	path.join( __dirname, '../../assets/js/storage-util.js' ),
	'utf8'
);

function loadStorageUtil( config ) {
	global.window = global;
	global.wpMcpAiChat = config || {};
	eval( storageUtilCode );
	return window.wpMcpAiStorageUtil;
}

function createMockWorker() {
	const instances = [];

	class MockWorker {
		constructor( url ) {
			this.url = url;
			this.listeners = {};
			this.posted = [];
			instances.push( this );
		}
		addEventListener( type, cb ) {
			this.listeners[ type ] = cb;
		}
		postMessage( msg ) {
			this.posted.push( msg );
		}
		terminate() {}
		respond( msg ) {
			if ( this.listeners.message ) {
				this.listeners.message( { data: msg } );
			}
		}
	}

	return { MockWorker, instances };
}

describe( 'StorageUtil', () => {
	let mocks;

	beforeEach( () => {
		jest.resetModules();
		mocks = createMockWorker();
		global.Worker = mocks.MockWorker;
		delete global.wpMcpAiChat;
		delete global.window;
	} );

	afterEach( () => {
		delete global.Worker;
		delete global.wpMcpAiChat;
		delete global.window;
	} );

	it( 'exposes the global API', () => {
		const util = loadStorageUtil( { storageWorkerUrl: '/storage-worker.js' } );
		expect( util ).toBeDefined();
		expect( typeof util.stringifyJSON ).toBe( 'function' );
		expect( typeof util.parseJSON ).toBe( 'function' );
	} );

	it( 'stringifies small payloads synchronously without spawning a worker', async () => {
		const util = loadStorageUtil( { storageWorkerUrl: '/storage-worker.js' } );
		const result = await util.stringifyJSON( { small: 'payload' } );

		expect( result ).toBe( JSON.stringify( { small: 'payload' } ) );
		expect( mocks.instances.length ).toBe( 0 );
	} );

	it( 'offloads large stringify payloads to the worker', async () => {
		const util = loadStorageUtil( { storageWorkerUrl: '/storage-worker.js' } );
		const big = { big: 'x'.repeat( 11000 ) };

		const promise = util.stringifyJSON( big );

		expect( mocks.instances.length ).toBe( 1 );
		expect( mocks.instances[ 0 ].posted[ 0 ].action ).toBe( 'stringify' );
		expect( mocks.instances[ 0 ].posted[ 0 ].id ).toBe( 1 );

		mocks.instances[ 0 ].respond( { id: 1, success: true, result: '{"done":true}' } );
		await expect( promise ).resolves.toBe( '{"done":true}' );
	} );

	it( 'offloads large parse payloads to the worker', async () => {
		const util = loadStorageUtil( { storageWorkerUrl: '/storage-worker.js' } );
		const big = '{"x":"' + 'y'.repeat( 11000 ) + '"}';

		const promise = util.parseJSON( big );

		expect( mocks.instances[ 0 ].posted[ 0 ].action ).toBe( 'parse' );

		mocks.instances[ 0 ].respond( { id: 1, success: true, result: { done: true } } );
		await expect( promise ).resolves.toEqual( { done: true } );
	} );

	it( 'falls back to the main thread when the worker cannot be created', async () => {
		class ThrowingWorker {
			constructor() {
				throw new Error( 'Workers disabled' );
			}
		}
		global.Worker = ThrowingWorker;

		const util = loadStorageUtil( { storageWorkerUrl: '/storage-worker.js' } );
		const big = { big: 'x'.repeat( 11000 ) };

		await expect( util.stringifyJSON( big ) ).resolves.toBe( JSON.stringify( big ) );
	} );

	it( 'falls back to the main thread when the worker URL is missing', async () => {
		const util = loadStorageUtil( {} );
		const big = { big: 'x'.repeat( 11000 ) };

		await expect( util.stringifyJSON( big ) ).resolves.toBe( JSON.stringify( big ) );
		expect( mocks.instances.length ).toBe( 0 );
	} );

	it( 'posts directly to the worker when the caller provides an explicit threshold', async () => {
		const util = loadStorageUtil( { storageWorkerUrl: '/storage-worker.js' } );
		const big = { big: 'x'.repeat( 11000 ) };

		// An explicit threshold means the caller already measured the payload —
		// the util must skip its estimate and go straight to the worker.
		const promise = util.stringifyJSON( big, 99999999 );

		expect( mocks.instances.length ).toBe( 1 );
		expect( mocks.instances[ 0 ].posted[ 0 ].action ).toBe( 'stringify' );

		mocks.instances[ 0 ].respond( { id: 1, success: true, result: '{"done":true}' } );
		await expect( promise ).resolves.toBe( '{"done":true}' );
	} );

	it( 'disables offload when the per-call threshold is zero', async () => {
		const util = loadStorageUtil( { storageWorkerUrl: '/storage-worker.js' } );
		const big = { big: 'x'.repeat( 11000 ) };

		const result = await util.stringifyJSON( big, 0 );

		expect( result ).toBe( JSON.stringify( big ) );
		expect( mocks.instances.length ).toBe( 0 );
	} );
} );

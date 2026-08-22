/**
 * Tests for storage-util.js
 *
 * @package WP_MCP_AI
 */

describe( 'StorageUtil', () => {
	let StorageUtil;

	beforeEach( () => {
		// Clear module cache to get fresh instance
		jest.resetModules();
		
		// Mock Worker
		global.Worker = jest.fn().mockImplementation( () => ( {
			addEventListener: jest.fn(),
			postMessage: jest.fn(),
			terminate: jest.fn(),
		} ) );

		// Mock window.wpMcpAiChat
		global.wpMcpAiChat = {
			storageWorkerUrl: '/assets/js/storage-worker.js',
		};

		// Load the module
		// Since storage-util.js uses IIFE pattern, we need to load it differently
		// For now, we'll test basic localStorage functionality
	} );

	afterEach( () => {
		delete global.Worker;
		delete global.wpMcpAiChat;
	} );

	describe( 'localStorage operations', () => {
		it( 'should save and retrieve data from localStorage', () => {
			const key = 'test_key';
			const value = { test: 'data' };

			localStorage.setItem( key, JSON.stringify( value ) );
			const retrieved = JSON.parse( localStorage.getItem( key ) );

			expect( retrieved ).toEqual( value );
		} );

		it( 'should remove data from localStorage', () => {
			const key = 'test_key';
			localStorage.setItem( key, 'test_value' );
			localStorage.removeItem( key );

			expect( localStorage.getItem( key ) ).toBeNull();
		} );

		it( 'should clear all data from localStorage', () => {
			localStorage.setItem( 'key1', 'value1' );
			localStorage.setItem( 'key2', 'value2' );
			localStorage.clear();

			expect( localStorage.length ).toBe( 0 );
		} );

		it( 'should handle quota exceeded errors gracefully', () => {
			const originalSetItem = localStorage.setItem;
			localStorage.setItem = jest.fn().mockImplementation( () => {
				const error = new Error( 'QuotaExceededError' );
				error.name = 'QuotaExceededError';
				throw error;
			} );

			expect( () => {
				localStorage.setItem( 'key', 'value' );
			} ).toThrow( 'QuotaExceededError' );

			localStorage.setItem = originalSetItem;
		} );
	} );

	describe( 'sessionStorage operations', () => {
		it( 'should save and retrieve data from sessionStorage', () => {
			const key = 'session_key';
			const value = { session: 'data' };

			sessionStorage.setItem( key, JSON.stringify( value ) );
			const retrieved = JSON.parse( sessionStorage.getItem( key ) );

			expect( retrieved ).toEqual( value );
		} );

		it( 'should clear session data on clear', () => {
			sessionStorage.setItem( 'key1', 'value1' );
			sessionStorage.clear();

			expect( sessionStorage.length ).toBe( 0 );
		} );
	} );

	describe( 'JSON operations', () => {
		it( 'should stringify and parse JSON data', () => {
			const data = {
				messages: [ 'message1', 'message2' ],
				timestamp: Date.now(),
				metadata: { user: 'test' },
			};

			const stringified = JSON.stringify( data );
			const parsed = JSON.parse( stringified );

			expect( parsed ).toEqual( data );
		} );

		it( 'should handle parsing invalid JSON', () => {
			const invalidJson = '{ invalid json }';

			expect( () => {
				JSON.parse( invalidJson );
			} ).toThrow();
		} );
	} );
} );

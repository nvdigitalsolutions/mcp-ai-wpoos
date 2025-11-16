/**
 * Jest setup file
 *
 * This file is run before each test file.
 * Use it to set up global test utilities, mocks, and configurations.
 *
 * @package WP_MCP_AI
 */

import '@testing-library/jest-dom';

// Mock WordPress globals
global.wp = {
	i18n: {
		__: ( text ) => text,
		_x: ( text ) => text,
		_n: ( single, plural, number ) => ( number === 1 ? single : plural ),
		sprintf: ( format, ...args ) => {
			let i = 0;
			return format.replace( /%s/g, () => args[ i++ ] );
		},
	},
	hooks: {
		addAction: jest.fn(),
		doAction: jest.fn(),
		addFilter: jest.fn(),
		applyFilters: jest.fn( ( hook, value ) => value ),
	},
	data: {
		select: jest.fn(),
		dispatch: jest.fn(),
	},
};

// Mock jQuery (if needed for tests)
global.$ = global.jQuery = jest.fn( ( selector ) => {
	const element = {
		on: jest.fn().mockReturnThis(),
		off: jest.fn().mockReturnThis(),
		trigger: jest.fn().mockReturnThis(),
		addClass: jest.fn().mockReturnThis(),
		removeClass: jest.fn().mockReturnThis(),
		toggleClass: jest.fn().mockReturnThis(),
		attr: jest.fn().mockReturnThis(),
		removeAttr: jest.fn().mockReturnThis(),
		prop: jest.fn().mockReturnThis(),
		val: jest.fn().mockReturnThis(),
		text: jest.fn().mockReturnThis(),
		html: jest.fn().mockReturnThis(),
		append: jest.fn().mockReturnThis(),
		prepend: jest.fn().mockReturnThis(),
		remove: jest.fn().mockReturnThis(),
		hide: jest.fn().mockReturnThis(),
		show: jest.fn().mockReturnThis(),
		css: jest.fn().mockReturnThis(),
		find: jest.fn().mockReturnThis(),
		closest: jest.fn().mockReturnThis(),
		parent: jest.fn().mockReturnThis(),
		children: jest.fn().mockReturnThis(),
		siblings: jest.fn().mockReturnThis(),
		get: jest.fn(),
		length: 1,
	};
	return element;
} );

global.$.ajax = jest.fn();
global.$.post = jest.fn();
global.$.get = jest.fn();

// Mock console to reduce noise in tests (optional)
// Uncomment if you want cleaner test output
// global.console = {
// 	...console,
// 	error: jest.fn(),
// 	warn: jest.fn(),
// 	log: jest.fn(),
// };

// Mock localStorage
const localStorageMock = ( () => {
	let store = {};
	return {
		getItem: ( key ) => store[ key ] || null,
		setItem: ( key, value ) => {
			store[ key ] = value.toString();
		},
		removeItem: ( key ) => {
			delete store[ key ];
		},
		clear: () => {
			store = {};
		},
		get length() {
			return Object.keys( store ).length;
		},
		key: ( index ) => {
			const keys = Object.keys( store );
			return keys[ index ] || null;
		},
	};
} )();

Object.defineProperty( window, 'localStorage', {
	value: localStorageMock,
} );

// Mock sessionStorage
const sessionStorageMock = ( () => {
	let store = {};
	return {
		getItem: ( key ) => store[ key ] || null,
		setItem: ( key, value ) => {
			store[ key ] = value.toString();
		},
		removeItem: ( key ) => {
			delete store[ key ];
		},
		clear: () => {
			store = {};
		},
		get length() {
			return Object.keys( store ).length;
		},
		key: ( index ) => {
			const keys = Object.keys( store );
			return keys[ index ] || null;
		},
	};
} )();

Object.defineProperty( window, 'sessionStorage', {
	value: sessionStorageMock,
} );

// Reset all mocks before each test
beforeEach( () => {
	jest.clearAllMocks();
	localStorageMock.clear();
	sessionStorageMock.clear();
} );

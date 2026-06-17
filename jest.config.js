/**
 * Jest configuration for WP Open Operator System
 *
 * @package WP_MCP_AI
 */

const path = require( 'path' );

module.exports = {
	// Use the directory of this config file as the root
	rootDir: __dirname,

	// Test environment
	testEnvironment: 'jsdom',

	// Test match patterns
	testMatch: [
		'**/tests/js/**/*.test.js',
		'**/tests/js/**/*.test.ts',
		'**/tests/js/**/*.spec.js',
		'**/tests/js/**/*.spec.ts',
	],

	// Coverage configuration
	collectCoverageFrom: [
		'assets/js/**/*.js',
		'assets/js/src/**/*.ts',
		'assets/js/src/**/*.tsx',
		'!assets/js/**/*.min.js',
		'!assets/js/vendor/**',
	],

	coverageDirectory: 'coverage',
	coverageReporters: [ 'text', 'lcov', 'html' ],

	// Module paths
	modulePaths: [ '<rootDir>' ],

	// Setup files - use absolute path to ensure Jest can find it
	setupFilesAfterEnv: [
		path.resolve( __dirname, 'tests', 'js', 'setup.js' ),
	],

	// Ensure proper module resolution
	resolver: undefined,
	moduleDirectories: [ 'node_modules', '<rootDir>' ],

	// Transform files
	transform: {
		'^.+\\.tsx?$': [
			'ts-jest',
			{
				tsconfig: './tsconfig.json',
			},
		],
		'^.+\\.js$': [ 'babel-jest', { configFile: './babel.config.js' } ],
	},

	// Module name mapper for aliases
	moduleNameMapper: {
		'^@shared/(.*)$': '<rootDir>/assets/js/src/shared/$1',
		'^@services/(.*)$': '<rootDir>/assets/js/src/services/$1',
		'^@chat/(.*)$': '<rootDir>/assets/js/src/chat/$1',
		'^@admin/(.*)$': '<rootDir>/assets/js/src/admin/$1',
		'^@/(.*)$': '<rootDir>/assets/js/$1',
	},

	// Globals available in tests
	globals: {
		wp: {},
	},

	// Ignore patterns
	testPathIgnorePatterns: [
		'/node_modules/',
		'/vendor/',
		'/.git/',
		'/addons/pro/assets/vendor/',
	],

	// Module path ignore patterns to avoid naming collisions
	modulePathIgnorePatterns: [
		'<rootDir>/addons/pro/assets/vendor/',
	],

	// Coverage thresholds (start low, increase over time)
	coverageThreshold: {
		global: {
			branches: 0,
			functions: 0,
			lines: 0,
			statements: 0,
		},
	},

	// Verbose output
	verbose: true,

	// Clear mocks between tests
	clearMocks: true,
	resetMocks: true,
	restoreMocks: true,
};

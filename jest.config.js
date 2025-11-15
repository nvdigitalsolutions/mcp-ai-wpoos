/**
 * Jest configuration for WP Open Operator System
 *
 * @package WP_MCP_AI
 */

module.exports = {
	// Test environment
	testEnvironment: 'jsdom',

	// Test match patterns
	testMatch: [
		'**/tests/js/**/*.test.js',
		'**/tests/js/**/*.spec.js',
	],

	// Coverage configuration
	collectCoverageFrom: [
		'assets/js/**/*.js',
		'!assets/js/**/*.min.js',
		'!assets/js/vendor/**',
	],

	coverageDirectory: 'coverage',
	coverageReporters: [ 'text', 'lcov', 'html' ],

	// Module paths
	modulePaths: [ '<rootDir>' ],

	// Setup files
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.js' ],

	// Transform files
	transform: {
		'^.+\\.js$': [ 'babel-jest', { configFile: './babel.config.js' } ],
	},

	// Module name mapper for aliases
	moduleNameMapper: {
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

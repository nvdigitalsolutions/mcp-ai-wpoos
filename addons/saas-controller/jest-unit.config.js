/**
 * Jest unit config for the NV oOS SaaS Controller addon.
 *
 * Self-contained on purpose: @wordpress/jest-preset-default forces its
 * jsdom setup globals (`window.tinyMCEPreInit`, …) as `setupFiles`, which
 * cannot be removed by overriding the key in a derived config, and its
 * babel-jest transform cannot drive the @babel/core v8 in this addon's
 * dependency tree. The addon's jest suites are the ported Cloud Worker
 * unit tests and run in Node's test environment (per-file
 * `@jest-environment node` docblock) so the Web Crypto API is available;
 * TypeScript is transpiled by the dependency-free `jest-transform.cjs`.
 *
 * If jsdom-based admin-UI tests are added later, switch to the WordPress
 * preset (or jest `projects`) for those suites.
 */

module.exports = {
	testEnvironment: 'node',
	testMatch: [ '**/worker/tests/**/*.test.[jt]s?(x)' ],
	testPathIgnorePatterns: [ '/node_modules/', '<rootDir>/vendor/' ],
	transform: {
		'\\.[jt]sx?$': '<rootDir>/jest-transform.cjs',
	},
	moduleNameMapper: {
		'\\.(scss|css)$': require.resolve(
			'@wordpress/jest-preset-default/scripts/style-mock.js'
		),
	},
};

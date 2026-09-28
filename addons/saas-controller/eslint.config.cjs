/**
 * ESLint flat config for the NV oOS SaaS Controller addon.
 *
 * Why not `wp-scripts lint-js` defaults: @wordpress/scripts@32 detects only
 * flat configs, and its bundled fallback config targets the ESLint-10-era
 * @wordpress/eslint-plugin@25 — running it against this addon's eslint 8
 * tree crashes with a circular-config error. This file pins the stack the
 * addon installs (eslint 8.57 + @wordpress/eslint-plugin 23 +
 * @typescript-eslint 6) and is invoked directly by `npm run lint:js`.
 */

/* eslint-disable import/no-extraneous-dependencies -- dev-only config file. */

const { FlatCompat } = require( '@eslint/eslintrc' );

const compat = new FlatCompat( {
	baseDirectory: __dirname,
	resolvePluginsRelativeTo: __dirname,
} );

const tsFiles = [
	'assets/src/**/*.ts',
	'assets/src/**/*.tsx',
	'worker/src/**/*.ts',
];

module.exports = [
	{
		ignores: [
			'**/build/**',
			'**/dist/**',
			'**/node_modules/**',
			'**/vendor/**',
			'.wrangler/**',
		],
	},

	// WordPress recommended rules (eslintrc-era configs converted to flat).
	...compat.extends( 'plugin:@wordpress/eslint-plugin/recommended' ),

	// Babel defaults for any plain JS/JSX files (mirrors wp-scripts when the
	// project has no babel.config — this addon does not).
	{
		files: [ '**/*.{js,jsx}' ],
		languageOptions: {
			parser: require( '@babel/eslint-parser' ),
			parserOptions: {
				requireConfigFile: false,
				babelOptions: {
					presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
				},
			},
		},
	},

	// Relaxations mirroring the repo-root config (docs-heavy jsdoc rules,
	// formatting delegated to prettier, WP-API-specific rules that don't
	// apply to this addon).
	{
		files: [ '**/*.{js,jsx,ts,tsx}' ],
		rules: {
			'no-console': 'off',
			camelcase: [
				'error',
				{
					properties: 'never',
					ignoreDestructuring: true,
					ignoreImports: true,
					ignoreGlobals: true,
				},
			],
			'no-nested-ternary': 'off',
			'import/no-unresolved': 'off',
			'import/no-extraneous-dependencies': 'off',
			'import/named': 'off',
			'import/default': 'off',
			'@wordpress/no-unsafe-wp-apis': 'off',
			'@wordpress/i18n-no-variables': 'off',
			'@wordpress/i18n-ellipsis': 'off',
			'prettier/prettier': 'off',
			'jsdoc/require-param': 'off',
			'jsdoc/require-param-description': 'off',
			'jsdoc/require-returns-description': 'off',
			'jsdoc/no-undefined-types': 'off',
			'jsdoc/check-types': 'off',
			'jsdoc/check-param-names': 'off',
			'jsdoc/check-line-alignment': 'off',
			'jsdoc/empty-tags': 'off',
		},
	},

	// TypeScript: parse with @typescript-eslint and apply the same rule set
	// the repo root uses for its TS sources, with two addon-specific
	// accommodations:
	//
	//  • Property names may be snake_case: the addon's type shapes mirror
	//    the D1 schema, Stripe API, and WP REST payloads (prompt_tokens,
	//    balance_usd, …), so renaming them would fight the wire contract.
	//  • no-bitwise is off for the Worker: utils.timingSafeEqual compares
	//    signatures in constant time with `|=`/`^`, which is the point.
	{
		files: tsFiles,
		languageOptions: {
			parser: require( '@typescript-eslint/parser' ),
			parserOptions: {
				project: './tsconfig.json',
				ecmaVersion: 2020,
				sourceType: 'module',
				ecmaFeatures: { jsx: true },
			},
		},
		plugins: {
			'@typescript-eslint': require( '@typescript-eslint/eslint-plugin' ),
		},
		rules: {
			camelcase: 'off',
			'@typescript-eslint/no-explicit-any': 'warn',
			'@typescript-eslint/explicit-function-return-type': 'off',
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
				},
			],
			'@typescript-eslint/naming-convention': [
				'error',
				{
					selector: 'variable',
					format: [ 'camelCase', 'UPPER_CASE' ],
					leadingUnderscore: 'allow',
				},
				{
					selector: 'variable',
					modifiers: [ 'destructured' ],
					format: [ 'camelCase', 'snake_case' ],
				},
				{
					selector: 'function',
					format: [ 'camelCase', 'PascalCase' ],
				},
				{
					selector: 'import',
					format: [ 'camelCase', 'PascalCase' ],
				},
				{
					selector: 'typeLike',
					format: [ 'PascalCase' ],
				},
				{
					selector: 'typeProperty',
					format: [ 'camelCase', 'snake_case', 'UPPER_CASE', 'PascalCase' ],
				},
				{
					selector: 'objectLiteralProperty',
					format: null,
					filter: {
						// HTTP header names (Content-Type, X-NV-Wholesale-Cost, …).
						regex: '^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+$',
						match: true,
					},
				},
				{
					selector: 'objectLiteralProperty',
					format: [ 'camelCase', 'snake_case' ],
				},
				{
					selector: 'default',
					format: [ 'camelCase' ],
					leadingUnderscore: 'allow',
				},
			],
		},
	},
	{
		files: [ 'worker/src/**/*.ts' ],
		rules: {
			'no-bitwise': 'off',
		},
	},
];

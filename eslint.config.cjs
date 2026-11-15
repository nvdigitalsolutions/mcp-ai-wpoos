/**
 * ESLint flat config (ESLint 9).
 *
 * Replaces the legacy `.eslintrc.json` + `.eslintignore` pair after the
 * @wordpress/eslint-plugin v23 -> v25 migration. v25 ships flat configs only,
 * so the eslintrc-shaped `extends: ["plugin:@wordpress/eslint-plugin/recommended"]`
 * has no direct equivalent; the preset is spread below and re-scoped to
 * `src/**` to preserve the previous behaviour (the preset never applied to
 * `assets/js/**`).
 */

const globals = require( 'globals' );
const tseslint = require( '@typescript-eslint/eslint-plugin' );
const wordpress = require( '@wordpress/eslint-plugin' );

const WP_FILES = [ 'src/**/*.js', 'src/**/*.jsx' ];
const TS_FILES = [ 'assets/js/src/**/*.ts', 'assets/js/src/**/*.tsx' ];

// The legacy config applied the WordPress preset through an eslintrc override
// scoped to `src/**/*.{js,jsx}`. Re-scope the preset's globally-scoped entries
// and drop its TypeScript-only entries: TS sources get
// `@typescript-eslint/recommended` instead, exactly as before.
const isTsOnly = ( config ) =>
	Array.isArray( config.files ) &&
	config.files.every( ( pattern ) => /\.(m|c)?tsx?$/.test( pattern ) );

const wordpressRecommended = wordpress.configs.recommended
	.filter( ( config ) => ! isTsOnly( config ) )
	.map( ( config ) =>
		config.files ? config : { ...config, files: WP_FILES }
	);

const typescriptRecommended = tseslint.configs[ 'flat/recommended' ].map(
	( config ) => ( config.files ? config : { ...config, files: TS_FILES } )
);

module.exports = [
	{
		ignores: [
			'assets/js/vendor/**',
			'**/*.min.js',
			'**/node_modules/**',
			'**/dist/**',
			'**/build/**',
		],
	},

	// Base rules for every file the lint scripts target.
	{
		files: [
			'**/*.js',
			'**/*.jsx',
			'**/*.ts',
			'**/*.tsx',
			'**/*.mjs',
		],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'script',
			globals: {
				...globals.browser,
				...globals.es2020,
				...globals.jquery,
				wp: 'readonly',
				wpMcpAiChat: 'readonly',
				wpMcpAiChatInstances: 'readonly',
				wpMcpAiChatStorage: 'readonly',
				wpMcpAiChatClipboard: 'readonly',
				wpMcpAiChatMarkdown: 'readonly',
				wpMcpAiChatDomBatcher: 'readonly',
				wpMcpAiChatUIUtils: 'readonly',
				wpMcpAiChatAudio: 'readonly',
				wpMcpAiUserChats: 'readonly',
				wpMcpAiAdmin: 'readonly',
			},
		},
		rules: {
			'no-console': 'off',
			camelcase: [
				'error',
				{
					properties: 'never',
					ignoreDestructuring: true,
					ignoreImports: true,
					ignoreGlobals: true,
					allow: [ '^WP_MCP_AI_' ],
				},
			],
			'no-var': 'error',
			'prefer-const': 'error',
			'no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
					// ESLint 9 defaults caughtErrors to "all"; the codebase was
					// linted under the ESLint 8 default ("none").
					caughtErrors: 'none',
				},
			],
		},
	},

	// ES modules (legacy override 1).
	{
		files: [
			'**/chat-markdown-service.js',
			'**/chat-chart-recipes.js',
			'**/chat-http-client-service.js',
			'**/chat-bundle.js',
			'**/orchestration/autonomous-orchestrator.js',
			'**/orchestration/browser-circuit-breaker.js',
			'**/orchestration/research-compiler.js',
			'**/sse-service.js',
		],
		languageOptions: {
			sourceType: 'module',
		},
	},

	// Node ESM build scripts (legacy override 2).
	{
		files: [ 'bin/**/*.mjs' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {
				...globals.node,
				...globals.es2022,
			},
		},
	},

	// WordPress preset for `src/**` (legacy override 3).
	...wordpressRecommended,
	{
		files: WP_FILES,
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'module',
			parserOptions: {
				ecmaFeatures: {
					jsx: true,
				},
			},
		},
		rules: {
			'no-console': 'off',
			// WordPress' preset replaces the rule options with
			// `{ ignoreRestSiblings: true }`; re-assert the ESLint 8
			// `caughtErrors` default so existing catch blocks stay clean.
			'no-unused-vars': [
				'error',
				{
					ignoreRestSiblings: true,
					caughtErrors: 'none',
				},
			],
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

	// TypeScript sources (legacy override 4).
	...typescriptRecommended,
	{
		files: TS_FILES,
		languageOptions: {
			parser: require( '@typescript-eslint/parser' ),
			parserOptions: {
				project: './tsconfig.json',
				ecmaVersion: 2020,
				sourceType: 'module',
			},
		},
		rules: {
			'no-console': 'off',
			camelcase: 'off',
			'@typescript-eslint/no-explicit-any': 'warn',
			'@typescript-eslint/explicit-function-return-type': 'off',
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
					caughtErrors: 'none',
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
					selector: 'function',
					format: [ 'camelCase', 'PascalCase' ],
				},
				{
					selector: 'typeLike',
					format: [ 'PascalCase' ],
				},
				{
					selector: 'default',
					format: [ 'camelCase' ],
					leadingUnderscore: 'allow',
				},
			],
		},
	},
];

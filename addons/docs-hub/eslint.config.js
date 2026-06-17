/**
 * ESLint flat config — NV oOS Docs Hub.
 *
 * Enforces jsx-a11y rules (WCAG 2.1 AA baseline) on all React TSX/JSX sources.
 *
 * @see https://github.com/jsx-eslint/eslint-plugin-jsx-a11y
 */
// @ts-check
import tsParser from '@typescript-eslint/parser';
import tsPlugin from '@typescript-eslint/eslint-plugin';
import jsxA11y from 'eslint-plugin-jsx-a11y';

/** @type {import('eslint').Linter.Config[]} */
export default [
	// Global ignores — test files use jest-dom helpers that the a11y plugin
	// doesn't need to lint, and they would otherwise need their own parser
	// project. ESLint flat-config treats a config object with only `ignores`
	// as a global ignore list.
	{
		ignores: [ 'src/**/__tests__/**', 'src/test-setup.ts' ],
	},
	{
		files: [ 'src/**/*.{ts,tsx,js,jsx}' ],
		...jsxA11y.flatConfigs.recommended,
		languageOptions: {
			parser: tsParser,
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
		},
		// Register the @typescript-eslint plugin so the existing
		// `eslint-disable-next-line @typescript-eslint/no-explicit-any`
		// pragmas in the source tree resolve to a real rule definition.
		plugins: {
			...jsxA11y.flatConfigs.recommended.plugins,
			'@typescript-eslint': tsPlugin,
		},
		linterOptions: {
			reportUnusedDisableDirectives: 'off',
		},
	},
];


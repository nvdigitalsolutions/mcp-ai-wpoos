/**
 * ESLint flat config — NV oOS Cloudways Dashboard SPA Addon.
 *
 * Enforces jsx-a11y rules (WCAG 2.1 AA baseline) on all React TSX/JSX sources.
 *
 * @see https://github.com/jsx-eslint/eslint-plugin-jsx-a11y
 */
// @ts-check
import tsParser from '@typescript-eslint/parser';
import jsxA11y from 'eslint-plugin-jsx-a11y';

/** @type {import('eslint').Linter.Config[]} */
export default [
	// a11y rules for all TSX/JSX sources
	{
		...jsxA11y.flatConfigs.recommended,
		files: [ 'src/**/*.{ts,tsx,js,jsx}' ],
		languageOptions: {
			parser: tsParser,
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
		},
		linterOptions: {
			reportUnusedDisableDirectives: 'off',
		},
	},
];

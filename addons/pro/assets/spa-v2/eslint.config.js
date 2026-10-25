/**
 * ESLint flat config — NV oOS Pro SPA v2.
 *
 * Enforces jsx-a11y rules (WCAG 2.1 AA baseline) on all React TSX/JSX sources
 * plus react-hooks/rules-of-hooks — the hook-order guard that catches the
 * early-return-before-hooks class of bug (React error #310, "Rendered more
 * hooks than during the previous render") which shipped to production twice
 * (Schedule Manager, Workflows). exhaustive-deps stays off until a dedicated
 * dependency-array cleanup pass.
 *
 * @see https://github.com/jsx-eslint/eslint-plugin-jsx-a11y
 * @see https://github.com/facebook/react/tree/main/packages/eslint-plugin-react-hooks
 */
// @ts-check
import tsParser from '@typescript-eslint/parser';
import jsxA11y from 'eslint-plugin-jsx-a11y';
import reactHooks from 'eslint-plugin-react-hooks';

/** @type {import('eslint').Linter.Config[]} */
export default [
	{
		...jsxA11y.flatConfigs.recommended,
		files: [ 'src/**/*.{ts,tsx,js,jsx}' ],
		ignores: [ 'src/features/knowledge-graph/upstream/**', 'src/features/docs-hub/upstream/**' ],
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
	{
		plugins: {
			'react-hooks': reactHooks,
		},
		files: [ 'src/**/*.{ts,tsx,js,jsx}' ],
		ignores: [ 'src/features/knowledge-graph/upstream/**', 'src/features/docs-hub/upstream/**' ],
		languageOptions: {
			parser: tsParser,
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
		},
		rules: {
			'react-hooks/rules-of-hooks': 'error',
			'react-hooks/exhaustive-deps': 'off',
		},
	},
];

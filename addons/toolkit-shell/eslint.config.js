/**
 * ESLint flat config — NV oOS SPA Addon.
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
// Allow inline disable comments for @typescript-eslint/* rules that are
// handled by tsc/typecheck rather than this a11y-scoped ESLint config.
linterOptions: {
reportUnusedDisableDirectives: 'off',
},
			rules: {
				/**
				 * Dual-React guard: this bundle ships its own React 19. Importing
				 * @wordpress/element or @wordpress/components would pull WP core's
				 * React 18 renderer into the same tree and corrupt hooks state.
				 * Only @wordpress/i18n (React-free) may be imported.
				 */
				'no-restricted-imports': [
					'error',
					{
						paths: [
							{
								name: '@wordpress/element',
								message: 'WP core React 18 conflicts with the bundled React 19 — use the bundled react package instead.',
							},
							{
								name: '@wordpress/components',
								message: 'WP core components depend on React 18 — use src/components/ui primitives instead.',
							},
						],
					},
				],
			},
},
];

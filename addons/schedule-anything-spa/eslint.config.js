/**
 * ESLint flat config — Schedule Anything SPA.
 *
 * Enforces jsx-a11y rules (WCAG 2.1 AA baseline) on all React TSX/JSX
 * sources. Mirrors the toolkit-shell / chat-spa addon config.
 */
// @ts-check
import tsParser from '@typescript-eslint/parser';
import jsxA11y from 'eslint-plugin-jsx-a11y';

/** @type {import('eslint').Linter.Config[]} */
export default [
  {
    ...jsxA11y.flatConfigs.recommended,
    files: ['src/**/*.{ts,tsx,js,jsx}'],
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

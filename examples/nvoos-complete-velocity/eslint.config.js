/**
 * ESLint flat config — NV oOS Complete Console host.
 *
 * Enforces jsx-a11y rules (WCAG 2.1 AA baseline) on the host sources.
 * spa-v2 keeps its own lint gates in addons/pro/assets/spa-v2.
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

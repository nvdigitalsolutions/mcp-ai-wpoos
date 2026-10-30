// ESLint flat config — Astro + TypeScript. (A11y linting is covered by the
// Phase 2 Lighthouse/Playwright gates instead of a plugin — jsx-a11y's
// peer range conflicts with eslint-plugin-astro 3's ESLint 10 requirement.)
// Repo JS style: tabs, single quotes.
import eslint from '@eslint/js';
import astro from 'eslint-plugin-astro';
import tseslint from 'typescript-eslint';

export default tseslint.config(
	{ ignores: ['dist/**', '.astro/**', 'scripts/**'] },
	eslint.configs.recommended,
	...tseslint.configs.recommended,
	...astro.configs.recommended,
	{
		rules: {
			indent: ['error', 'tab'],
			quotes: ['error', 'single', { avoidEscape: true }],
			semi: ['error', 'always'],
		},
	}
);

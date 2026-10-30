// ---------------------------------------------------------------------------
// tokens-build.mjs — ADS generator core (zero-dep). Reads the DTCG token
// sources in src/tokens/ and emits:
//   - src/styles/generated/tokens.css      (CSS custom properties + themes)
//   - src/lib/tokens.generated.ts          (typed TS constants)
//   - src/tokens/bundle.generated.json     (flattened bundle for the runtime
//                                            token service + MCP tools)
// Rejects (no writes) when validation fails — the 058/095 discipline.
// ---------------------------------------------------------------------------

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	MAX_FILE_BYTES,
	findReferences,
	resolveTokenGroups,
	validateGroups,
	validateThemes,
} from './dtcg.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const TOKENS_DIR = join(HERE, '..', '..', 'src', 'tokens');

export function readTokenSources(tokensDir = TOKENS_DIR) {
	const sources = { groups: {}, themes: {} };
	for (const file of readdirSync(tokensDir)) {
		if (!file.endsWith('.json') || file.startsWith('bundle.generated')) continue;
		const full = join(tokensDir, file);
		if (statSync(full).size > MAX_FILE_BYTES) {
			throw new Error(`token file exceeds ${MAX_FILE_BYTES} bytes: ${file}`);
		}
		sources.groups[file.replace(/\.json$/, '')] = JSON.parse(readFileSync(full, 'utf8'));
	}
	const themesDir = join(tokensDir, 'themes');
	for (const file of readdirSync(themesDir)) {
		if (!file.endsWith('.json')) continue;
		const full = join(themesDir, file);
		if (statSync(full).size > MAX_FILE_BYTES) {
			throw new Error(`theme file exceeds ${MAX_FILE_BYTES} bytes: themes/${file}`);
		}
		sources.themes[file.replace(/\.json$/, '')] = JSON.parse(readFileSync(full, 'utf8'));
	}
	return sources;
}

/** @param {Record<string, unknown>} resolvedGroup */
function flattenGroup(groupName, resolvedGroup) {
	const out = {};
	const walk = (node, pathParts) => {
		if (Array.isArray(node)) return;
		if (node === null || typeof node !== 'object') return;
		if (typeof node.$value === 'string' || Array.isArray(node.$value)) {
			const name = [groupName, ...pathParts].join('-');
			out[`--ads-${name}`] = Array.isArray(node.$value) ? node.$value.join(', ') : node.$value;
			return;
		}
		for (const [key, child] of Object.entries(node)) {
			if (key.startsWith('$')) continue;
			walk(child, [...pathParts, key]);
		}
	};
	walk(resolvedGroup, []);
	return out;
}

function cssBlock(selector, vars) {
	const lines = Object.entries(vars).map(([name, value]) => `\t${name}: ${value};`);
	return `${selector} {\n${lines.join('\n')}\n}`;
}

function tsBlock(groupName, resolvedGroup) {
	const entries = [];
	const walk = (node, pathParts) => {
		if (node === null || typeof node !== 'object') return;
		if (typeof node.$value === 'string' || Array.isArray(node.$value)) {
			const value = Array.isArray(node.$value) ? node.$value.join(', ') : node.$value;
			entries.push(
				`\t\t${JSON.stringify([...pathParts].join('.'))}: ${JSON.stringify(value)},`
			);
			return;
		}
		for (const [key, child] of Object.entries(node)) {
			if (key.startsWith('$')) continue;
			walk(child, [...pathParts, key]);
		}
	};
	walk(resolvedGroup, []);
	return `export const ${groupName} = {\n${entries.join('\n')}\n} as const;`;
}

/**
 * @param {{groups: Record<string, unknown>, themes: Record<string, unknown>}} sources
 * @returns {{ css: string, ts: string, bundle: string, report: {errors: string[], warnings: string[]} }}
 */
export function buildTokens(sources) {
	const report = { errors: [], warnings: [] };
	validateGroups(sources.groups, report);
	validateThemes(sources.themes, sources.groups.semantic || {}, report);

	const { errors: resolveErrors, resolved } = resolveTokenGroups(sources.groups);
	report.errors.push(...resolveErrors);

	if (report.errors.length > 0) {
		return { css: '', ts: '', bundle: '', report };
	}

	const cssParts = [];
	// Core + component layers are theme-independent (component tokens are
	// static var() references into the semantic layer).
	cssParts.push(cssBlock(':root', { ...flattenGroup('core', resolved.core), ...flattenGroup('component', resolved.component) }));
	// Light mode = default mode semantic values (already resolved).
	cssParts.push(cssBlock(':root', flattenGroup('semantic', resolved.semantic)));

	// @property typed declarations for semantic colors (NDS typed-props parity).
	const semanticResolved = resolved.semantic || {};
	const propertyDecls = [];
	const walkProps = (node, pathParts) => {
		if (node === null || typeof node !== 'object') return;
		if (node.$type === 'color' && typeof node.$value === 'string') {
			propertyDecls.push(
				`@property --ads-semantic-${[...pathParts].join('-')} { syntax: '<color>'; inherits: true; initial-value: ${node.$value}; }`
			);
			return;
		}
		for (const [key, child] of Object.entries(node)) {
			if (key.startsWith('$')) continue;
			walkProps(child, [...pathParts, key]);
		}
	};
	walkProps(semanticResolved, []);
	cssParts.push(propertyDecls.join('\n'));

	// Modes of the default theme + brand theme overrides.
	const defaultTheme = sources.themes.default || {};
	for (const [mode, modeTokens] of Object.entries(defaultTheme)) {
		if (mode === 'light' || mode.startsWith('$')) continue; // :root already carries light values.
		const modeVars = {};
		for (const [name, value] of Object.entries(modeTokens)) {
			if (name.startsWith('$') || typeof value !== 'string') continue;
			modeVars[`--ads-semantic-${name}`] = value;
		}
		cssParts.push(cssBlock(`[data-theme="${mode}"]`, modeVars));
	}
	for (const [themeName, theme] of Object.entries(sources.themes)) {
		if (themeName === 'default') continue;
		const tokens = theme.tokens || {};
		const brandVars = {};
		for (const [name, value] of Object.entries(tokens)) {
			if (name.startsWith('$') || typeof value !== 'string') continue;
			brandVars[`--ads-semantic-${name}`] = value;
		}
		cssParts.push(cssBlock(`[data-theme="${themeName}"]`, brandVars));
	}

	const css =
		'/* Generated by scripts/build-tokens.mjs — DO NOT EDIT. */\n' +
		'/* Source of truth: src/tokens/*.json (DTCG 2025.10). */\n\n' +
		cssParts.filter(Boolean).join('\n\n') +
		'\n';

	const ts =
		'// Generated by scripts/build-tokens.mjs — DO NOT EDIT.\n' +
		'// Source of truth: src/tokens/*.json (DTCG 2025.10).\n\n' +
		`${tsBlock('core', resolved.core)}\n` +
		`${tsBlock('semantic', resolved.semantic)}\n` +
		`${tsBlock('component', resolved.component)}\n\n` +
		`export const adsThemeNames = ${JSON.stringify(
			['light', ...Object.keys(defaultTheme).filter((m) => m !== 'light' && !m.startsWith('$')), ...Object.keys(sources.themes).filter((n) => n !== 'default')]
		)} as const;\n`;

	const bundle = {
		groups: sources.groups,
		themes: sources.themes,
		_resolved: { core: resolved.core, semantic: resolved.semantic, component: resolved.component },
	};
	return {
		css,
		ts,
		bundle: `${JSON.stringify(bundle, null, '\t')}\n`,
		report,
	};
}

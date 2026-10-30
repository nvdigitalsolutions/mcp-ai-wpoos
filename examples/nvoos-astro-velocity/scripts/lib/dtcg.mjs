// ---------------------------------------------------------------------------
// dtcg.mjs — DTCG 2025.10 validation + reference resolution (zero-dep, Node).
//
// Shared build-time implementation for scripts/build-tokens.mjs. The runtime
// twin is src/lib/tokens/dtcg.ts — KEEP THE TWO IN SYNC. Rules follow the
// 058/095 discipline: per-type value checks, reference resolution, cycle
// detection, size caps, reject-with-report (never write on error).
// ---------------------------------------------------------------------------

export const TOKEN_TYPE_VALIDATORS = {
	color: (value) => {
		if (typeof value !== 'string') return false;
		if (/^#[0-9a-fA-F]{3,8}$/.test(value)) return true;
		if (/^rgba?\(\s*[\d.\s,%/-]+\)$/.test(value)) return true;
		if (/^hsla?\(\s*[\d.\s,%/-]+(deg)?\)$/.test(value)) return true;
		return false;
	},
	dimension: (value) => {
		if (typeof value !== 'string') return false;
		// Unitless line-heights (1.6) and plain lengths; clamp() with inner
		// length values is allowed (Utopia-style fluid scales).
		if (/^-?\d*\.?\d+$/.test(value)) return true;
		if (/^-?\d*\.?\d+(px|rem|em|ch|vw|vh|%)$/.test(value)) return true;
		if (/^clamp\([^;{}()]+\)$/.test(value) && !/[;{}]/.test(value)) return true;
		return false;
	},
	fontFamily: (value) => {
		if (!Array.isArray(value) || value.length === 0 || value.length > 8) return false;
		return value.every(
			(entry) =>
				typeof entry === 'string' &&
				entry.length > 0 &&
				entry.length <= 128 &&
				!/[;{}()]/.test(entry) &&
				!/url\s*\(/i.test(entry)
		);
	},
};

export const MAX_FILE_BYTES = 256 * 1024;
export const MAX_TOKENS = 2000;

/**
 * @param {string} value
 * @returns {string[]} reference paths found in a token value (e.g. ["core.palette.ink"]).
 */
export function findReferences(value) {
	if (typeof value !== 'string') return [];
	const found = [];
	const re = /\{([a-z0-9._-]+)\}/g;
	let match;
	while ((match = re.exec(value)) !== null) {
		found.push(match[1]);
	}
	return found;
}

/**
 * @param {Record<string, unknown>} groups group name → group token tree.
 * @returns {{ errors: string[], warnings: string[], resolved: Record<string, unknown> }}
 *   `resolved` is a deep clone with every {ref} replaced by its resolved
 *   value. Unknown or circular references are reported as errors and left
 *   unresolved in the clone (callers must check `errors` before emitting).
 */
export function resolveTokenGroups(groups) {
	const errors = [];
	const warnings = [];
	const resolved = JSON.parse(JSON.stringify(groups));
	const seen = new Set();
	const stack = [];

	/** @returns {*} */
	function lookup(path) {
		let node = resolved;
		for (const part of path.split('.')) {
			if (node === null || typeof node !== 'object' || !(part in node)) {
				return undefined;
			}
			node = node[part];
		}
		return node;
	}

	/** @returns {*} */
	function resolveNode(node, pathLabel) {
		if (Array.isArray(node)) {
			return node.map((entry, i) => resolveNode(entry, `${pathLabel}[${i}]`));
		}
		if (node !== null && typeof node === 'object') {
			if (typeof node.$value === 'string') {
				const refs = findReferences(node.$value);
				if (refs.length === 0) return node;
				const resolvedValues = refs.map((ref) => {
					if (stack.includes(ref)) {
						errors.push(`circular reference: {${ref}} at ${pathLabel}`);
						return node.$value;
					}
					const target = lookup(ref);
					if (target === undefined) {
						errors.push(`unknown reference: {${ref}} at ${pathLabel}`);
						return node.$value;
					}
					if (typeof target !== 'object' || (typeof target.$value !== 'string' && !Array.isArray(target.$value))) {
						errors.push(`reference {${ref}} does not resolve to a token at ${pathLabel}`);
						return node.$value;
					}
					stack.push(ref);
					try {
						const targetResolved = target.__resolved ? target : resolveNode(target, ref);
						// Store memoized resolved value on a safe clone to avoid re-walk.
						// The resolved value may be an array (fontFamily refs).
						return targetResolved.$value;
					} finally {
						stack.pop();
					}
				});
				// A single reference keeps the target's value verbatim (string OR
				// array — fontFamily). Multiple references concatenate strings.
				const resolvedValue =
					refs.length === 1
						? resolvedValues[0]
						: resolvedValues.filter((v) => typeof v === 'string').join(' ');
				return { ...node, $value: resolvedValue, __resolved: true };
			}
			const out = { ...node };
			for (const [key, child] of Object.entries(node)) {
				if (key.startsWith('$')) continue;
				out[key] = resolveNode(child, `${pathLabel}.${key}`);
			}
			return out;
		}
		return node;
	}

	// Resolve group files topologically by reference depth: pass over groups
	// repeatedly until no refs remain or no progress is made (handles
	// core → semantic → component ordering without hardcoding it).
	for (let pass = 0; pass < 8; pass++) {
		let changed = false;
		for (const [groupName, tree] of Object.entries(resolved)) {
			resolved[groupName] = resolveNode(tree, groupName);
			changed = changed || JSON.stringify(resolved[groupName]) !== JSON.stringify(tree);
		}
		if (!changed) break;
	}

	// Strip the __resolved memo marker from the output tree.
	for (const [groupName, tree] of Object.entries(resolved)) {
		const cleaned = JSON.parse(JSON.stringify(tree, (key, value) => (key === '__resolved' ? undefined : value)));
		resolved[groupName] = cleaned;
	}

	if (stack.length > 0) {
		warnings.push('reference resolution used an internal stack; verify cycle reporting');
	}

	return { errors, warnings, resolved };
}

/**
 * Validate the raw (unresolved) token tree.
 * @param {Record<string, unknown>} groups
 * @param {{errors: string[], warnings: string[]}} report
 */
export function validateGroups(groups, report) {
	let count = 0;
	const walk = (node, path) => {
		if (Array.isArray(node)) {
			node.forEach((entry, i) => walk(entry, `${path}[${i}]`));
			return;
		}
		if (node === null || typeof node !== 'object') return;
		if (typeof node.$type === 'string') {
			count++;
			if (count > MAX_TOKENS) {
				report.errors.push(`token count exceeds ${MAX_TOKENS} (found more at ${path})`);
				return;
			}
			const validator = TOKEN_TYPE_VALIDATORS[node.$type];
			if (!validator) {
				report.warnings.push(`unknown $type "${node.$type}" at ${path} — skipped by generators`);
				return;
			}
			const value = node.$value;
			const refs = typeof value === 'string' ? findReferences(value) : [];
			if (refs.length === 0 && !validator(value)) {
				report.errors.push(`invalid $type "${node.$type}" value at ${path}: ${JSON.stringify(value)}`);
			}
			return;
		}
		for (const [key, child] of Object.entries(node)) {
			if (key.startsWith('$')) continue;
			walk(child, `${path}.${key}`);
		}
	};
	for (const [groupName, tree] of Object.entries(groups)) {
		walk(tree, groupName);
	}
}

/**
 * @param {Record<string, unknown>} themes
 * @param {Record<string, unknown>} semantic semantic token tree (raw).
 * @param {{errors: string[], warnings: string[]}} report
 */
export function validateThemes(themes, semantic, report) {
	for (const [themeName, theme] of Object.entries(themes)) {
		const overrides = theme && typeof theme === 'object' && theme.tokens ? theme.tokens : theme;
		if (themeName === 'default') {
			// { light: {...}, dark: {...} } — mode names are arbitrary.
			if (!overrides || typeof overrides !== 'object') {
				report.errors.push(`theme "default" must contain mode objects`);
				continue;
			}
			for (const [mode, tokens] of Object.entries(overrides)) {
				if (mode.startsWith('$')) continue;
				if (!tokens || typeof tokens !== 'object') {
					report.errors.push(`theme default.${mode} must be an object`);
					continue;
				}
				for (const [name, value] of Object.entries(tokens)) {
					if (!(name in semantic)) {
						report.warnings.push(`theme default.${mode}.${name} overrides an unknown semantic token`);
					}
					if (typeof value !== 'string' && !Array.isArray(value)) {
						report.errors.push(`theme default.${mode}.${name} must be a string or fontFamily array`);
					}
				}
			}
			continue;
		}
		const tokens = overrides;
		if (!tokens || typeof tokens !== 'object' || Array.isArray(tokens)) {
			report.errors.push(`theme "${themeName}" must have a "tokens" object`);
			continue;
		}
		for (const [name, value] of Object.entries(tokens)) {
			if (name.startsWith('$')) continue;
			if (!(name in semantic)) {
				report.warnings.push(`theme ${themeName}.${name} overrides an unknown semantic token`);
			}
			const type = semantic[name] && semantic[name].$type;
			if (type && !TOKEN_TYPE_VALIDATORS[type](value) && findReferences(typeof value === 'string' ? value : '').length === 0) {
				report.errors.push(`theme ${themeName}.${name} invalid for $type "${type}": ${JSON.stringify(value)}`);
			}
		}
	}
}

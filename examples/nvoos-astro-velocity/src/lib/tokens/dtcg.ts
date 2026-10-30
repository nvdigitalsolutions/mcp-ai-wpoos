// ---------------------------------------------------------------------------
// dtcg.ts — DTCG 2025.10 validation + reference resolution (runtime twin).
//
// KEEP IN SYNC with scripts/lib/dtcg.mjs (the build-time implementation).
// Rules follow the 058/095 discipline: per-type value checks, reference
// resolution, cycle detection, size caps, reject-with-report.
// ---------------------------------------------------------------------------

export type TokenValue = string | string[];

export interface DTCGToken {
	$type: string;
	$value: TokenValue;
	$description?: string;
	[key: string]: unknown;
}

export interface TokenTree {
	[key: string]: TokenTree | DTCGToken | unknown;
}

export interface ValidationReport {
	errors: string[];
	warnings: string[];
}

export const TOKEN_TYPE_VALIDATORS: Record<string, (value: TokenValue) => boolean> = {
	color: (value: TokenValue): boolean => {
		if (typeof value !== 'string') return false;
		if (/^#[0-9a-fA-F]{3,8}$/.test(value)) return true;
		if (/^rgba?\(\s*[\d.\s,%/-]+\)$/.test(value)) return true;
		if (/^hsla?\(\s*[\d.\s,%/-]+(deg)?\)$/.test(value)) return true;
		return false;
	},
	dimension: (value: TokenValue): boolean => {
		if (typeof value !== 'string') return false;
		if (/^-?\d*\.?\d+$/.test(value)) return true;
		if (/^-?\d*\.?\d+(px|rem|em|ch|vw|vh|%)$/.test(value)) return true;
		if (/^clamp\([^;{}()]+\)$/.test(value) && !/[;{}]/.test(value)) return true;
		return false;
	},
	fontFamily: (value: TokenValue): boolean => {
		if (!Array.isArray(value) || value.length === 0 || value.length > 8) return false;
		return value.every(
			(entry: string) =>
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

export function findReferences(value: TokenValue): string[] {
	if (typeof value !== 'string') return [];
	const found: string[] = [];
	const re = /\{([a-z0-9._-]+)\}/g;
	let match: RegExpExecArray | null;
	while ((match = re.exec(value)) !== null) {
		found.push(match[1]);
	}
	return found;
}

export function validateGroups(
	groups: Record<string, TokenTree>,
	report: ValidationReport
): void {
	let count = 0;
	const walk = (node: unknown, path: string): void => {
		if (Array.isArray(node)) {
			node.forEach((entry, i) => walk(entry, `${path}[${i}]`));
			return;
		}
		if (node === null || typeof node !== 'object') return;
		if (typeof (node as DTCGToken).$type === 'string') {
			const token = node as DTCGToken;
			count++;
			if (count > MAX_TOKENS) {
				report.errors.push(`token count exceeds ${MAX_TOKENS} (found more at ${path})`);
				return;
			}
			const validator = TOKEN_TYPE_VALIDATORS[token.$type];
			if (!validator) {
				report.warnings.push(`unknown $type "${token.$type}" at ${path} — skipped by generators`);
				return;
			}
			const refs = findReferences(token.$value);
			if (refs.length === 0 && !validator(token.$value)) {
				report.errors.push(`invalid $type "${token.$type}" value at ${path}: ${JSON.stringify(token.$value)}`);
			}
			return;
		}
		for (const [key, child] of Object.entries(node as TokenTree)) {
			if (key.startsWith('$')) continue;
			walk(child, `${path}.${key}`);
		}
	};
	for (const [groupName, tree] of Object.entries(groups)) {
		walk(tree, groupName);
	}
}

export function validateThemes(
	themes: Record<string, unknown>,
	semantic: TokenTree,
	report: ValidationReport
): void {
	for (const [themeName, theme] of Object.entries(themes)) {
		if (themeName === 'default') {
			const modes = theme as Record<string, unknown>;
			for (const [mode, tokens] of Object.entries(modes)) {
				if (mode.startsWith('$')) continue;
				if (!tokens || typeof tokens !== 'object' || Array.isArray(tokens)) {
					report.errors.push(`theme default.${mode} must be an object`);
					continue;
				}
				for (const [name, value] of Object.entries(tokens as Record<string, unknown>)) {
					if (name.startsWith('$')) continue;
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
		const tokens = (theme as { tokens?: Record<string, unknown> }).tokens;
		if (!tokens || typeof tokens !== 'object' || Array.isArray(tokens)) {
			report.errors.push(`theme "${themeName}" must have a "tokens" object`);
			continue;
		}
		for (const [name, value] of Object.entries(tokens)) {
			if (name.startsWith('$')) continue;
			if (!(name in semantic)) {
				report.warnings.push(`theme ${themeName}.${name} overrides an unknown semantic token`);
			}
			const semanticToken = semantic[name] as DTCGToken | undefined;
			const type = semanticToken?.$type;
			if (type && !TOKEN_TYPE_VALIDATORS[type](value as TokenValue) && findReferences(value as TokenValue).length === 0) {
				report.errors.push(`theme ${themeName}.${name} invalid for $type "${type}": ${JSON.stringify(value)}`);
			}
		}
	}
}

export function resolveTokenGroups(
	groups: Record<string, TokenTree>
): { errors: string[]; warnings: string[]; resolved: Record<string, TokenTree> } {
	const errors: string[] = [];
	const warnings: string[] = [];
	const resolved = JSON.parse(JSON.stringify(groups)) as Record<string, TokenTree>;
	const stack: string[] = [];

	function lookup(path: string): DTCGToken | undefined {
		let node: unknown = resolved;
		for (const part of path.split('.')) {
			if (node === null || typeof node !== 'object' || !(part in (node as Record<string, unknown>))) {
				return undefined;
			}
			node = (node as Record<string, unknown>)[part];
		}
		return node as DTCGToken;
	}

	function resolveNode(node: unknown, pathLabel: string): unknown {
		if (Array.isArray(node)) {
			return node.map((entry, i) => resolveNode(entry, `${pathLabel}[${i}]`));
		}
		if (node !== null && typeof node === 'object') {
			const candidate = node as DTCGToken;
			if (typeof candidate.$value === 'string') {
				const refs = findReferences(candidate.$value);
				if (refs.length === 0) return node;
				const resolvedValues = refs.map((ref) => {
					if (stack.includes(ref)) {
						errors.push(`circular reference: {${ref}} at ${pathLabel}`);
						return candidate.$value;
					}
					const target = lookup(ref);
					if (target === undefined) {
						errors.push(`unknown reference: {${ref}} at ${pathLabel}`);
						return candidate.$value;
					}
					if (typeof target.$value !== 'string' && !Array.isArray(target.$value)) {
						errors.push(`reference {${ref}} does not resolve to a token at ${pathLabel}`);
						return candidate.$value;
					}
					stack.push(ref);
					try {
						return (resolveNode(target, ref) as DTCGToken).$value;
					} finally {
						stack.pop();
					}
				});
				const resolvedValue: TokenValue =
					refs.length === 1
						? resolvedValues[0]
						: resolvedValues.filter((v): v is string => typeof v === 'string').join(' ');
				return { ...candidate, $value: resolvedValue };
			}
			const out: Record<string, unknown> = { ...(node as Record<string, unknown>) };
			for (const [key, child] of Object.entries(out)) {
				if (key.startsWith('$')) continue;
				out[key] = resolveNode(child, `${pathLabel}.${key}`);
			}
			return out;
		}
		return node;
	}

	for (let pass = 0; pass < 8; pass++) {
		for (const [groupName, tree] of Object.entries(resolved)) {
			resolved[groupName] = resolveNode(tree, groupName) as TokenTree;
		}
	}
	return { errors, warnings, resolved };
}

// ---------------------------------------------------------------------------
// service.ts — ADS token runtime service (Proposal 095).
//
// Loads the generated token bundle (static import — bundler-agnostic, works
// in Vite, esbuild and vitest), applies runtime theme/token overrides with
// the 058 transactional discipline (snapshot → all-or-nothing apply →
// rollback), and exposes the WCAG contrast checker used by the MCP tools.
// Phase 4: when a persist path is configured (constructor option or
// NVOOS_MCP_STATE_FILE), the design state hydrates on construction and is
// re-persisted after every successful mutation (best-effort, never on dry
// runs).
// ---------------------------------------------------------------------------

import bundle from '../../tokens/bundle.generated.json';
import {
	MAX_FILE_BYTES,
	TOKEN_TYPE_VALIDATORS,
	findReferences,
	validateGroups,
	validateThemes,
	type TokenTree,
	type ValidationReport,
} from './dtcg';
import { loadDesignState, saveDesignState, type PersistedDesignState } from './persistence';

export interface TokenBundle {
	groups: Record<string, TokenTree>;
	themes: Record<string, unknown>;
	_resolved: Record<string, TokenTree>;
}

export interface ThemeDescriptor {
	name: string;
	description?: string;
	tokens: Record<string, string>;
}

export interface TokenSnapshot {
	id: string;
	takenAt: string;
	overrides: Record<string, string>;
	activeTheme: string;
	runtimeThemes: Record<string, ThemeDescriptor>;
}

export interface ApplyResult {
	applied: boolean;
	report: ValidationReport;
	diff: Array<{ name: string; before: string; after: string }>;
	snapshotId?: string;
}

export interface ContrastResult {
	ratio: number;
	aaNormal: boolean;
	aaLarge: boolean;
	aaaNormal: boolean;
}

function cloneBundle(): TokenBundle {
	return JSON.parse(JSON.stringify(bundle)) as TokenBundle;
}

export function semanticTokensOf(bundleData: TokenBundle): Record<string, string> {
	// The resolved tree carries reference-free values (the raw groups keep
	// {core.x} references for validation/round-tripping).
	const tree = bundleData._resolved.semantic ?? bundleData.groups.semantic ?? {};
	const out: Record<string, string> = {};
	const walk = (node: unknown, parts: string[]): void => {
		if (node === null || typeof node !== 'object') return;
		const token = node as { $value?: unknown };
		if (typeof token.$value === 'string' || Array.isArray(token.$value)) {
			out[parts.join('.')] = Array.isArray(token.$value) ? token.$value.join(', ') : (token.$value as string);
			return;
		}
		for (const [key, child] of Object.entries(node as Record<string, unknown>)) {
			if (key.startsWith('$')) continue;
			walk(child, [...parts, key]);
		}
	};
	walk(tree, []);
	return out;
}

function hexToRgb(hex: string): [number, number, number] | null {
	const cleaned = hex.replace('#', '');
	const full =
		cleaned.length === 3
			? cleaned.split('').map((c) => c + c).join('')
			: cleaned.length === 6
				? cleaned
				: '';
	if (!/^[0-9a-fA-F]{6}$/.test(full)) return null;
	return [
		parseInt(full.slice(0, 2), 16),
		parseInt(full.slice(2, 4), 16),
		parseInt(full.slice(4, 6), 16),
	];
}

function luminance(hex: string): number | null {
	const rgb = hexToRgb(hex);
	if (!rgb) return null;
	const [r, g, b] = rgb.map((c) => {
		const s = c / 255;
		return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
	});
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrastRatio(fg: string, bg: string): number | null {
	const l1 = luminance(fg);
	const l2 = luminance(bg);
	if (l1 === null || l2 === null) return null;
	const [hi, lo] = l1 > l2 ? [l1, l2] : [l2, l1];
	return (hi + 0.05) / (lo + 0.05);
}

export function contrastCheck(fg: string, bg: string): ContrastResult | null {
	const ratio = contrastRatio(fg, bg);
	if (ratio === null) return null;
	return {
		ratio: Math.round(ratio * 100) / 100,
		aaNormal: ratio >= 4.5,
		aaLarge: ratio >= 3,
		aaaNormal: ratio >= 7,
	};
}

/**
 * The runtime token service. The static generated bundle is immutable; MCP
 * writes land in an override layer (snapshotted, rollback-able) that is
 * merged over the bundle when resolving the active semantic set.
 */
export class TokenService {
	private readonly bundle: TokenBundle;
	private readonly persistPath: string | null;
	private overrides: Record<string, string> = {};
	private activeTheme = 'light';
	private runtimeThemes: Record<string, ThemeDescriptor> = {};
	private snapshots: TokenSnapshot[] = [];

	constructor(bundleData?: TokenBundle, options?: { persistPath?: string }) {
		this.bundle = bundleData ?? cloneBundle();
		// Persistence is OFF unless explicitly configured (keeps the service
		// hermetic in tests and on pages that just read the static bundle).
		this.persistPath = options?.persistPath ?? process.env.NVOOS_MCP_STATE_FILE ?? null;
		if (this.persistPath) {
			const persisted = loadDesignState(this.persistPath);
			if (persisted) this.hydrate(persisted);
		}
	}

	/**
	 * Adopt persisted state — only the fields the loaded shape carries.
	 * Runtime themes hydrate before the active theme so the theme gate can
	 * verify it still resolves; snapshots keep the same 50-entry cap as
	 * takeSnapshot.
	 */
	private hydrate(persisted: PersistedDesignState): void {
		if (persisted.overrides && typeof persisted.overrides === 'object') {
			this.overrides = { ...persisted.overrides };
		}
		if (persisted.runtimeThemes && typeof persisted.runtimeThemes === 'object') {
			this.runtimeThemes = JSON.parse(JSON.stringify(persisted.runtimeThemes)) as Record<string, ThemeDescriptor>;
		}
		if (Array.isArray(persisted.snapshots)) {
			this.snapshots = JSON.parse(JSON.stringify(persisted.snapshots)) as TokenSnapshot[];
			if (this.snapshots.length > 50) this.snapshots = this.snapshots.slice(-50);
		}
		if (typeof persisted.activeTheme === 'string' && this.themeDescriptor(persisted.activeTheme)) {
			this.activeTheme = persisted.activeTheme;
		}
	}

	/**
	 * Persist the current design state (best-effort). Called only after a
	 * successful mutation — rejected writes and dry runs never reach here.
	 * Snapshot entries mirror takeSnapshot's shape (overrides, activeTheme,
	 * runtimeThemes, takenAt, id).
	 */
	private persist(): void {
		if (!this.persistPath) return;
		saveDesignState(this.persistPath, {
			overrides: { ...this.overrides },
			activeTheme: this.activeTheme,
			runtimeThemes: JSON.parse(JSON.stringify(this.runtimeThemes)),
			snapshots: this.snapshots.map((snapshot) => JSON.parse(JSON.stringify(snapshot))),
		});
	}

	groups(): TokenTree {
		return this.bundle.groups;
	}

	/** Resolved group trees (reference-free) — what /tokens and MCP display. */
	resolvedGroups(): Record<string, TokenTree> {
		return this.bundle._resolved ?? this.bundle.groups;
	}

	themes(): Record<string, unknown> {
		return this.bundle.themes;
	}

	listThemes(): string[] {
		const staticThemes = Object.keys(this.bundle.themes).filter((n) => n !== 'default');
		return ['light', 'dark', ...staticThemes, ...Object.keys(this.runtimeThemes)];
	}

	getActiveTheme(): string {
		return this.activeTheme;
	}

	themeDescriptor(name: string): ThemeDescriptor | null {
		if (name === 'light' || name === 'dark') {
			const modes = (this.bundle.themes.default ?? {}) as Record<string, Record<string, string>>;
			const tokens = modes[name] ?? {};
			return { name, tokens };
		}
		const staticTheme = this.bundle.themes[name] as { tokens?: Record<string, string>; description?: string } | undefined;
		if (staticTheme?.tokens) {
			return { name, description: staticTheme.description, tokens: staticTheme.tokens };
		}
		return this.runtimeThemes[name] ?? null;
	}

	/**
	 * Resolved semantic values for the active theme + override layer.
	 */
	resolveActiveSemantic(): Record<string, string> {
		const base = semanticTokensOf(this.bundle);
		const themeTokens = this.themeDescriptor(this.activeTheme)?.tokens ?? {};
		return { ...base, ...themeTokens, ...this.overrides };
	}

	applyOverrides(changes: Record<string, string>, dryRun = false): ApplyResult {
		const semantic = this.bundle.groups.semantic ?? {};
		const report: ValidationReport = { errors: [], warnings: [] };
		const diff: ApplyResult['diff'] = [];
		const next = { ...this.overrides };

		for (const [name, value] of Object.entries(changes)) {
			const semanticToken = semantic[name] as { $type?: string } | undefined;
			if (!semanticToken?.$type) {
				report.errors.push(`unknown semantic token: ${name}`);
				continue;
			}
			const type = semanticToken.$type;
			const validator = validateGroupValue;
			if (!validator(type, value) && findReferences(value).length === 0) {
				report.errors.push(`invalid value for ${name} ($type "${type}"): ${JSON.stringify(value)}`);
				continue;
			}
			const before = this.resolveActiveSemantic()[name] ?? '';
			diff.push({ name, before, after: value });
			next[name] = value;
		}

		if (report.errors.length > 0) {
			return { applied: false, report, diff: [] };
		}
		if (dryRun) {
			return { applied: false, report, diff };
		}
		const snapshotId = this.takeSnapshot();
		this.overrides = next;
		this.persist();
		return { applied: true, report, diff, snapshotId };
	}

	importDTCG(input: string | Record<string, unknown>, dryRun = false): ApplyResult & { parsed?: Record<string, unknown> } {
		const report: ValidationReport = { errors: [], warnings: [] };
		let parsed: Record<string, unknown>;
		try {
			parsed = typeof input === 'string' ? (JSON.parse(input) as Record<string, unknown>) : input;
		} catch (error) {
			report.errors.push(`invalid JSON: ${(error as Error).message}`);
			return { applied: false, report, diff: [] };
		}
		if (JSON.stringify(parsed).length > MAX_FILE_BYTES) {
			report.errors.push(`import exceeds ${MAX_FILE_BYTES} bytes`);
			return { applied: false, report, diff: [] };
		}
		// The import may carry DTCG groups (core/semantic/...) — semantic
		// groups map onto overrides; everything else is validated + reported
		// but only semantic values are applied (058: skip-but-report).
		const groups = parsed.groups && typeof parsed.groups === 'object'
			? (parsed.groups as Record<string, TokenTree>)
			: (parsed as Record<string, TokenTree>);
		validateGroups(groups, report);
		validateThemes(parsed.themes && typeof parsed.themes === 'object' ? (parsed.themes as Record<string, unknown>) : {}, groups.semantic ?? {}, report);
		if (report.errors.length > 0) {
			return { applied: false, report, diff: [], parsed };
		}
		const semanticTree = groups.semantic ?? {};
		const changes: Record<string, string> = {};
		const walk = (node: unknown, parts: string[]): void => {
			if (node === null || typeof node !== 'object') return;
			const token = node as { $value?: unknown };
			if (typeof token.$value === 'string') {
				changes[parts.join('.')] = token.$value;
				return;
			}
			for (const [key, child] of Object.entries(node as Record<string, unknown>)) {
				if (key.startsWith('$')) continue;
				walk(child, [...parts, key]);
			}
		};
		walk(semanticTree, []);
		// Persistence happens inside applyOverrides on the applied (non-dry)
		// path — the parsed document itself is not part of the persisted
		// state, so there is nothing extra to save here.
		return { ...this.applyOverrides(changes, dryRun), parsed };
	}

	takeSnapshot(): string {
		const id = `snap-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
		this.snapshots.push({
			id,
			takenAt: new Date().toISOString(),
			overrides: { ...this.overrides },
			activeTheme: this.activeTheme,
			runtimeThemes: JSON.parse(JSON.stringify(this.runtimeThemes)),
		});
		if (this.snapshots.length > 50) this.snapshots.shift();
		return id;
	}

	listSnapshots(): TokenSnapshot[] {
		return [...this.snapshots].reverse();
	}

	rollback(snapshotId?: string): { ok: boolean; message: string } {
		const target = snapshotId
			? this.snapshots.find((s) => s.id === snapshotId)
			: this.snapshots[this.snapshots.length - 1];
		if (!target) {
			return { ok: false, message: 'no snapshot to roll back to' };
		}
		this.overrides = { ...target.overrides };
		this.activeTheme = target.activeTheme;
		this.runtimeThemes = JSON.parse(JSON.stringify(target.runtimeThemes));
		this.persist();
		return { ok: true, message: `rolled back to ${target.id}` };
	}

	applyTheme(name: string): { ok: boolean; message: string } {
		if (!this.themeDescriptor(name)) {
			return { ok: false, message: `unknown theme: ${name}` };
		}
		this.activeTheme = name;
		this.persist();
		return { ok: true, message: `active theme is now ${name}` };
	}

	createTheme(descriptor: ThemeDescriptor): ApplyResult {
		const report: ValidationReport = { errors: [], warnings: [] };
		if (!/^[a-z0-9-]+$/.test(descriptor.name) || ['light', 'dark'].includes(descriptor.name)) {
			report.errors.push(`invalid theme name: ${descriptor.name}`);
			return { applied: false, report, diff: [] };
		}
		validateThemes({ [descriptor.name]: { tokens: descriptor.tokens } }, this.bundle.groups.semantic ?? {}, report);
		if (report.errors.length > 0) {
			return { applied: false, report, diff: [] };
		}
		const snapshotId = this.takeSnapshot();
		this.runtimeThemes[descriptor.name] = descriptor;
		this.persist();
		return { applied: true, report, diff: [], snapshotId };
	}

	deleteTheme(name: string): { ok: boolean; message: string } {
		if (name in (this.bundle.themes ?? {})) {
			return { ok: false, message: `static theme "${name}" cannot be deleted at runtime` };
		}
		if (!(name in this.runtimeThemes)) {
			return { ok: false, message: `unknown theme: ${name}` };
		}
		this.takeSnapshot();
		delete this.runtimeThemes[name];
		if (this.activeTheme === name) this.activeTheme = 'light';
		this.persist();
		return { ok: true, message: `theme "${name}" deleted` };
	}
}

function validateGroupValue(type: string, value: string): boolean {
	const validator = TOKEN_TYPE_VALIDATORS[type];
	return validator ? validator(value) : false;
}

export const defaultTokenService = new TokenService();

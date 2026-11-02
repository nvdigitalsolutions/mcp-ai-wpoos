// ---------------------------------------------------------------------------
// Theme policy — Proposal 105 §3 (forge canonical module). The velocity
// storefront mirrors this file's ladder — keep the two copies textually
// identical in logic when either side changes (velocity copy:
// examples/nvoos-astro-velocity/src/lib/theme/policy.ts). Known deliberate
// divergences: velocity adds contrastForced + a storedContrast param and
// consumes the live globals/theme doc; this copy bakes the policy at
// scaffold time via --theme-policy.
//
// The policy doc is the SITE-level theme decision (baked into
// policy.generated.ts at scaffold time by `--theme-policy`); the visitor's
// stored preference is per-browser state. The resolution ladder:
//
//   1. `brand:<slug>` with the slug in the registry → forced brand theme;
//      an UNKNOWN slug fails open to the `auto` ladder (never brick a page).
//   2. `light` / `dark` → forced, the stored preference is ignored.
//   3. `auto` → stored (when valid for the registry) → media query
//      (light/dark) → `defaultTheme` (the media-less fallback).
//   4. contrast: `standard` / `high` forced; `auto` → stored contrast
//      (high when saved) → `standard`.
//
// No Astro imports — forge-portable, pure for unit coverage.
// ---------------------------------------------------------------------------

export const POLICY_BRAND_PREFIX = 'brand:';
export const POLICY_SLUG_REGEX = /^[a-z0-9-]+$/;
export const POLICY_MODES = ['auto', 'light', 'dark'] as const;
export type PolicyMode = (typeof POLICY_MODES)[number];
export type ThemePolicy = PolicyMode | `brand:${string}`;
export type ContrastPolicy = 'auto' | 'standard' | 'high';
export type DefaultTheme = 'light' | 'dark';

/** The `GET /globals/theme` document shape (Proposal 105 §1 contract). */
export interface PolicyDoc {
	policy: ThemePolicy;
	contrast: ContrastPolicy;
	defaultTheme: DefaultTheme;
	/** Server-stamped ISO-8601 UTC — the bake time on the forge side. */
	updatedAt?: string;
	/** Always `{}` in v1 (reserved for Proposal 097). */
	$extensions?: Record<string, unknown>;
}

/** The default doc — auto respects the visitor, never forces a theme. */
export const DEFAULT_POLICY_DOC: PolicyDoc = {
	policy: 'auto',
	contrast: 'auto',
	defaultTheme: 'dark',
	$extensions: {},
};

/** The resolved theme + contrast axes, plus the forced flag the UI reads. */
export interface ResolvedPolicy {
	theme: string;
	contrast: 'standard' | 'high';
	/** True when the policy owns the theme — toggles disable, storage is skipped. */
	forced: boolean;
}

/** Validate a policy value (the enum + the brand-slug regex). */
export function isValidPolicyValue(value: unknown): value is ThemePolicy {
	if (typeof value !== 'string') {
		return false;
	}
	if ((POLICY_MODES as readonly string[]).includes(value)) {
		return true;
	}
	if (value.startsWith(POLICY_BRAND_PREFIX)) {
		const slug = value.slice(POLICY_BRAND_PREFIX.length);
		return slug.length > 0 && POLICY_SLUG_REGEX.test(slug);
	}
	return false;
}

/** Parse an arbitrary wire document — lenient but typed; invalid → the default. */
export function parsePolicyDoc(json: unknown): PolicyDoc {
	if (!json || typeof json !== 'object') {
		return DEFAULT_POLICY_DOC;
	}
	const raw = json as Record<string, unknown>;
	return {
		policy: isValidPolicyValue(raw.policy) ? raw.policy : DEFAULT_POLICY_DOC.policy,
		contrast: normalizeContrast(raw.contrast),
		defaultTheme: raw.defaultTheme === 'light' ? 'light' : 'dark',
		...(typeof raw.updatedAt === 'string' && raw.updatedAt.length > 0 ? { updatedAt: raw.updatedAt } : {}),
		$extensions:
			raw.$extensions && typeof raw.$extensions === 'object'
				? (raw.$extensions as Record<string, unknown>)
				: {},
	};
}

function normalizeContrast(value: unknown): ContrastPolicy {
	return value === 'standard' || value === 'high' ? value : 'auto';
}

/**
 * The resolution ladder (105 §3). `stored`/`storedContrast` are the visitor's
 * localStorage values (null when unavailable); `mediaMatches` is the
 * prefers-color-scheme light query result (null when matchMedia is absent);
 * `registry` is the selectable-theme allowlist ('light' is always implicit).
 */
export function resolvePolicyTheme(
	doc: PolicyDoc,
	stored: string | null,
	mediaMatches: boolean | null,
	registry: readonly string[],
	storedContrast: string | null = null
): ResolvedPolicy {
	const raw = doc.policy;

	if (raw.startsWith(POLICY_BRAND_PREFIX)) {
		const slug = raw.slice(POLICY_BRAND_PREFIX.length);
		if (registry.includes(slug)) {
			return {
				theme: slug,
				contrast: resolveContrast(doc.contrast, storedContrast),
				forced: true,
			};
		}
		// Unknown brand — fail open to the auto ladder (105 §3.1).
		return {
			theme: resolveAutoTheme(doc, stored, mediaMatches, registry),
			contrast: resolveContrast(doc.contrast, storedContrast),
			forced: false,
		};
	}

	if (raw === 'light' || raw === 'dark') {
		// Forced — the stored preference is ignored.
		return {
			theme: raw,
			contrast: resolveContrast(doc.contrast, storedContrast),
			forced: true,
		};
	}

	// 'auto' (and anything unparseable — defensive fail-open).
	return {
		theme: resolveAutoTheme(doc, stored, mediaMatches, registry),
		contrast: resolveContrast(doc.contrast, storedContrast),
		forced: false,
	};
}

/** The auto ladder: stored → media → defaultTheme. */
function resolveAutoTheme(
	doc: PolicyDoc,
	stored: string | null,
	mediaMatches: boolean | null,
	registry: readonly string[]
): string {
	if (stored && (stored === 'light' || registry.includes(stored))) {
		return stored;
	}
	if (mediaMatches === true) {
		return 'light';
	}
	if (mediaMatches === false) {
		return 'dark';
	}
	return doc.defaultTheme;
}

/** The contrast ladder: forced standard/high, auto → stored high → standard. */
function resolveContrast(contrast: ContrastPolicy, storedContrast: string | null): 'standard' | 'high' {
	if (contrast === 'standard' || contrast === 'high') {
		return contrast;
	}
	return storedContrast === 'high' ? 'high' : 'standard';
}

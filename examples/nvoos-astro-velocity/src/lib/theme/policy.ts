// ---------------------------------------------------------------------------
// Theme policy — Proposal 105 §3 (storefront side of the bridge theme-policy
// contract). Forge-portable: no Astro imports, mirrors theme.ts style.
//
// CANONICAL LADDER: the forge template copy lives at
// examples/nvoos-astro-forge/templates/blog/src/lib/theme/policy.ts — the
// ladder and policy values are identical between the two files. Known
// deliberate divergences: this storefront copy carries additive fields
// (contrastForced on the resolver result, an optional storedContrast param)
// and uses its own type names (ThemePolicyValue vs forge's PolicyMode); the
// forge copy bakes the policy at scaffold time while this one consumes the
// generated doc + the live globals/theme fetch. Change the ladder here —
// mirror it there.
//
// The bridge serves a policy doc at `globals/theme` (105 §1):
//   { policy: auto | light | dark | brand:<slug>, contrast: auto | standard | high,
//     defaultTheme: light | dark, updatedAt, $extensions }
//
// The resolution ladder (plan §3):
//   1. brand:<slug> with slug ∈ registry → forced brand theme; unknown slug
//      fails open to the `auto` ladder (forced: false).
//   2. light/dark → forced, storage ignored.
//   3. auto → stored (∈ ['light', ...registry]) → media (light/dark) →
//      defaultTheme (media unavailable — e.g. non-browser contexts).
//   4. contrast: standard|high forced; auto → stored contrast → standard.
// ---------------------------------------------------------------------------

/** Valid policy values per the bridge contract (105 §1). */
export type ThemePolicyValue = 'auto' | 'light' | 'dark' | `brand:${string}`;
export type ThemeContrastValue = 'auto' | 'standard' | 'high';
export type DefaultThemeValue = 'light' | 'dark';

/** The full policy doc — `$extensions` is always `{}` in v1 (105 §1). */
export interface ThemePolicy {
	policy: ThemePolicyValue;
	contrast: ThemeContrastValue;
	defaultTheme: DefaultThemeValue;
	/** Server-stamped ISO-8601 UTC — the SSR cache-key component. */
	updatedAt: string;
	$extensions: Record<string, unknown>;
}

/** The default (auto) doc — used when no backend policy exists (105 §3). */
export const DEFAULT_POLICY_DOC: ThemePolicy = {
	policy: 'auto',
	contrast: 'auto',
	defaultTheme: 'dark',
	updatedAt: '',
	$extensions: {},
};

const BRAND_SLUG_RE = /^brand:[a-z0-9-]+$/;
const POLICY_VALUES = ['auto', 'light', 'dark'] as const;
const CONTRAST_VALUES = ['auto', 'standard', 'high'] as const;
const DEFAULT_THEME_VALUES = ['light', 'dark'] as const;

function isRecord(value: unknown): value is Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Lenient but typed parse (plan §3): every field falls back to the default
 * doc on an invalid value — a malformed backend doc must never break a page.
 */
export function parsePolicyDoc(json: unknown): ThemePolicy {
	if (!isRecord(json)) {
		return { ...DEFAULT_POLICY_DOC };
	}
	const rawPolicy = json.policy;
	const policy =
		typeof rawPolicy === 'string' &&
		(POLICY_VALUES.includes(rawPolicy as (typeof POLICY_VALUES)[number]) ||
			BRAND_SLUG_RE.test(rawPolicy))
			? (rawPolicy as ThemePolicyValue)
			: DEFAULT_POLICY_DOC.policy;
	const contrast = CONTRAST_VALUES.includes(json.contrast as ThemeContrastValue)
		? (json.contrast as ThemeContrastValue)
		: DEFAULT_POLICY_DOC.contrast;
	const defaultTheme = DEFAULT_THEME_VALUES.includes(json.defaultTheme as DefaultThemeValue)
		? (json.defaultTheme as DefaultThemeValue)
		: DEFAULT_POLICY_DOC.defaultTheme;
	const updatedAt = typeof json.updatedAt === 'string' ? json.updatedAt : '';
	const extensions = isRecord(json.$extensions) ? json.$extensions : {};
	return { policy, contrast, defaultTheme, updatedAt, $extensions: extensions };
}

/** The resolution result — `forced` covers the theme axis (plan §3 shape). */
export interface PolicyResolution {
	theme: string;
	contrast: 'standard' | 'high';
	/** True when the policy forces the theme (light/dark/valid brand). */
	forced: boolean;
	/** True when the policy forces the contrast axis. */
	contrastForced: boolean;
}

/** Auto-ladder step 3: stored → media → defaultTheme. */
function resolveAuto(
	stored: string | null,
	mediaMatches: boolean | null,
	defaultTheme: DefaultThemeValue,
	registry: readonly string[]
): string {
	if (stored !== null && (stored === 'light' || registry.includes(stored))) {
		return stored;
	}
	if (mediaMatches !== null) {
		return mediaMatches ? 'light' : 'dark';
	}
	return defaultTheme;
}

/**
 * The ladder from plan §3, as a pure function.
 *
 * `storedContrast` is an additive 5th parameter (defaults to null) so the
 * contrast axis can be resolved against its own storage key — the bridge
 * contract keeps the two axes independent (104 ContrastToggle).
 */
export function resolvePolicyTheme(
	policy: ThemePolicy,
	stored: string | null,
	mediaMatches: boolean | null,
	registry: readonly string[],
	storedContrast: string | null = null
): PolicyResolution {
	let theme: string;
	let forced = false;
	if (policy.policy.startsWith('brand:')) {
		const slug = policy.policy.slice('brand:'.length);
		if (registry.includes(slug)) {
			theme = slug;
			forced = true;
		} else {
			// Unknown brand slug → fail open to the auto ladder (plan §3.1).
			theme = resolveAuto(stored, mediaMatches, policy.defaultTheme, registry);
		}
	} else if (policy.policy === 'light' || policy.policy === 'dark') {
		theme = policy.policy;
		forced = true;
	} else {
		theme = resolveAuto(stored, mediaMatches, policy.defaultTheme, registry);
	}
	const contrastForced = policy.contrast === 'standard' || policy.contrast === 'high';
	const contrast: 'standard' | 'high' =
		policy.contrast === 'standard' || policy.contrast === 'high'
			? policy.contrast
			: storedContrast === 'high'
				? 'high'
				: 'standard';
	return { theme, contrast, forced, contrastForced };
}

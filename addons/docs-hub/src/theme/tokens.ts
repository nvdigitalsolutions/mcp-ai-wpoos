/**
 * CSS custom-property name constants for the Docs Hub theme.
 *
 * Using constants here ensures we don't have magic strings scattered across
 * the TypeScript component tree — IDEs can auto-complete the values and
 * a rename-refactor just works.
 *
 * @since 1.0.0
 */
export const TOKENS = {
	// Base surface colors
	BG_PRIMARY: '--dh-bg-primary',
	BG_SECONDARY: '--dh-bg-secondary',
	BG_SIDEBAR: '--dh-bg-sidebar',
	BG_CODE: '--dh-bg-code',
	BG_CALLOUT: '--dh-bg-callout',

	// Text colors
	TEXT_PRIMARY: '--dh-text-primary',
	TEXT_SECONDARY: '--dh-text-secondary',
	TEXT_MUTED: '--dh-text-muted',
	TEXT_LINK: '--dh-text-link',
	TEXT_LINK_HOVER: '--dh-text-link-hover',
	TEXT_CODE: '--dh-text-code',

	// Border colors
	BORDER_DEFAULT: '--dh-border-default',
	BORDER_SUBTLE: '--dh-border-subtle',

	// Accent / brand
	ACCENT_PRIMARY: '--dh-accent-primary',
	ACCENT_SECONDARY: '--dh-accent-secondary',

	// Callout variants
	CALLOUT_NOTE_BG: '--dh-callout-note-bg',
	CALLOUT_NOTE_BORDER: '--dh-callout-note-border',
	CALLOUT_TIP_BG: '--dh-callout-tip-bg',
	CALLOUT_TIP_BORDER: '--dh-callout-tip-border',
	CALLOUT_WARNING_BG: '--dh-callout-warning-bg',
	CALLOUT_WARNING_BORDER: '--dh-callout-warning-border',
	CALLOUT_DANGER_BG: '--dh-callout-danger-bg',
	CALLOUT_DANGER_BORDER: '--dh-callout-danger-border',

	// Spacing
	SIDEBAR_WIDTH: '--dh-sidebar-width',
	TOC_WIDTH: '--dh-toc-width',
	CONTENT_MAX_WIDTH: '--dh-content-max-width',
	HEADER_HEIGHT: '--dh-header-height',
	SPACING_SM: '--dh-spacing-sm',
	SPACING_MD: '--dh-spacing-md',
	SPACING_LG: '--dh-spacing-lg',
	SPACING_XL: '--dh-spacing-xl',

	// Typography
	FONT_SANS: '--dh-font-sans',
	FONT_MONO: '--dh-font-mono',
	FONT_SIZE_SM: '--dh-font-size-sm',
	FONT_SIZE_BASE: '--dh-font-size-base',
	FONT_SIZE_LG: '--dh-font-size-lg',
	LINE_HEIGHT: '--dh-line-height',

	// Misc
	BORDER_RADIUS: '--dh-border-radius',
	SHADOW_SM: '--dh-shadow-sm',
	SHADOW_MD: '--dh-shadow-md',
	TRANSITION_SPEED: '--dh-transition-speed',
	ACTIVE_SIDEBAR_BG: '--dh-active-sidebar-bg',
	ACTIVE_SIDEBAR_TEXT: '--dh-active-sidebar-text',
	SCROLLBAR_WIDTH: '--dh-scrollbar-width',
} as const;

/** TypeScript helper: extract the literal string values as a union type. */
export type TokenName = ( typeof TOKENS )[keyof typeof TOKENS];

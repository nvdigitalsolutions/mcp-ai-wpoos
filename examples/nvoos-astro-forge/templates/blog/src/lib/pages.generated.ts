// ---------------------------------------------------------------------------
// pages.generated.ts — the per-PAGE band lists (scaffold overwrites this file).
//
// A positional band list is per-page data: the homepage's measured heights,
// padding and typography are not a sub-page's. `analyze --geometry --pages`
// measures each page and the scaffold bakes one list per slug here; the
// renderer applies a page's own list positionally. Absent → the sub-page keeps
// the homepage list (surfaces only), the pre-existing behaviour.
// ---------------------------------------------------------------------------

import type { SectionTheme } from './sections.generated.ts';

export const pageBands: Record<string, SectionTheme[]> = {};

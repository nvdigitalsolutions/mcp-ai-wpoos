/**
 * Branding helpers shared by the console shell (nav logo) and the entry
 * point (document title). Both surfaces show the first few words of the
 * WordPress site title and fall back to the product name when it is missing.
 */

/** Product name shown whenever the WordPress site title is unavailable. */
export const BRAND_FALLBACK = 'NV oOS Complete';

/** Maximum word count taken from the site title for branding surfaces. */
export const BRAND_MAX_WORDS = 3;

/** First `max` whitespace-separated words of a string; '' when there are none. */
export function firstWords(text: string, max: number): string {
  const words = text.trim().split(/\s+/).filter(Boolean);
  return words.slice(0, max).join(' ');
}

/** Brand text for a surface: site-title words, or the product fallback. */
export function brandText(siteName: string | undefined | null): string {
  return firstWords(siteName ?? '', BRAND_MAX_WORDS) || BRAND_FALLBACK;
}

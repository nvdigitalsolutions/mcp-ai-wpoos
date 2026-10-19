/**
 * Minimal standalone stand-in for `@wordpress/i18n`.
 *
 * The spa-v2 sources import `{ __ }` and `{ __, sprintf }` from
 * `@wordpress/i18n`. In WordPress that module reads `window.wp.i18n` (see the
 * esbuild plugin in spa-v2's esbuild.config.cjs); in this standalone app the
 * Vite alias maps it here. No translations are shipped — strings pass through
 * untranslated, matching the behaviour of the WordPress plugin when a locale
 * pack is absent.
 */

/** Passthrough translation — returns the English source string. */
export function __(text: string, _domain?: string): string {
  return text;
}

/** Singular/plural selection. */
export function _n(single: string, plural: string, number: number, _domain?: string): string {
  return number === 1 ? single : plural;
}

/** Passthrough translation with context. */
export function _x(text: string, _context: string, _domain?: string): string {
  return text;
}

/**
 * WP-style sprintf covering the placeholders the SPA uses: %s, %d, %f and
 * positional variants (%1$s …).
 */
export function sprintf(format: string, ...args: unknown[]): string {
  let index = 0;
  return format.replace(/%(\d+\$)?([sdf])/g, (_match, pos: string | undefined, type: string) => {
    const target = pos ? parseInt(pos, 10) - 1 : index;
    index += 1;
    const value = args[target];
    if (type === 'd') {
      return String(Math.trunc(Number(value)));
    }
    if (type === 'f') {
      return String(Number(value));
    }
    return String(value ?? '');
  });
}

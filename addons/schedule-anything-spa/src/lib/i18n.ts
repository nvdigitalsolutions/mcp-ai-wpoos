/**
 * i18n bootstrap — re-exports @wordpress/i18n for app-wide use.
 *
 * The SPA runs standalone (Vite build) and, when served from a WordPress
 * site, translations can be seeded via `setLocaleData` by the embedding
 * plugin before this module is used. Defaults to en_US.
 *
 * Usage: `import { __ } from '@/lib/i18n';`
 */

export {
  __,
  _n,
  _x,
  sprintf,
  setLocaleData,
  isRTL,
} from '@wordpress/i18n';

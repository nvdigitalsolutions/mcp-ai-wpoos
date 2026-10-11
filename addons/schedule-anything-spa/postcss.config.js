/**
 * PostCSS config — Tailwind CSS v4.
 *
 * v4 ships its own PostCSS plugin (@tailwindcss/postcss); the legacy
 * `tailwindcss` plugin entry and autoprefixer are no longer needed
 * (vendor prefixing is handled by Lightning CSS internally).
 */
export default {
  plugins: {
    '@tailwindcss/postcss': {},
  },
};

/**
 * Standalone entry — the Astro CMS bundle served by the storefront's
 * /manage surface.
 *
 * Unlike the wp-admin build (index.tsx, externals mapped to wp.* globals),
 * this entry bundles @wordpress/* and React from node_modules so the SPA
 * runs with no wp-admin script chain, and pulls the component stylesheets
 * into the CSS output (build/standalone/astro-admin.css).
 */
import './index';
import '@wordpress/components/build-style/style.css';
import './styles/standalone.css';

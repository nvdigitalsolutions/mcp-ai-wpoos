/**
 * Ambient declarations for the CSS side-effect imports in the standalone
 * entry (esbuild bundles them; tsc has no loader for .css).
 */
declare module '*.css';

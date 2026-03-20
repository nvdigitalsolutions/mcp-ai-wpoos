/**
 * Cornerstone3D Local Bundle – Imaging Viewer
 *
 * Bundles @cornerstonejs/core, @cornerstonejs/tools, and
 * @cornerstonejs/dicom-image-loader as a single self-contained JS file
 * so the Medical Imaging Viewer admin page does NOT need CDN access or
 * ES importmaps to render DICOM images.
 *
 * This follows the same pattern as the TMA Template Builder (tma-builder):
 * - Static imports → webpack bundles everything locally
 * - No dynamic import() calls, no CDN dependency at runtime
 * - Works as a normal enqueued WordPress script (no type="module" needed)
 *
 * The three Cornerstone packages are exposed on `window.nvCs` so that the
 * main imaging-viewer.js IIFE can read them directly instead of performing
 * CDN dynamic imports.
 *
 * Build command:  npm run build:imaging-viewer
 * Output:         addons/pro/build/imaging-viewer/imaging-viewer-bundle.js
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.4
 */

/* eslint-disable no-console */

import * as csCore from '@cornerstonejs/core';
import * as csTools from '@cornerstonejs/tools';
import * as csDicomImageLoader from '@cornerstonejs/dicom-image-loader';

/**
 * Wire dicom-image-loader to the core so they share a single internal
 * cornerstone instance.  This MUST happen before any image is loaded.
 */
if ( csDicomImageLoader.external ) {
	csDicomImageLoader.external.cornerstone = csCore;
}

/**
 * Expose packages for the plain-JS imaging-viewer.js IIFE.
 *
 * @type {{ csCore: object, csTools: object, csDicomImageLoader: object }}
 */
window.nvCs = { csCore, csTools, csDicomImageLoader };

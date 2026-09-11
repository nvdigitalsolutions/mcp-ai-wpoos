/**
 * Copy vendored JS libraries from node_modules to assets/vendor/.
 *
 * Cross-platform replacement for shell cp commands.
 * Run via: node scripts/copy-vendor.js
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

const vendorMap = [
	{
		src:  'node_modules/cytoscape/dist/cytoscape.min.js',
		dest: 'assets/vendor/cytoscape/cytoscape.min.js',
	},
	{
		src:  'node_modules/cytoscape-fcose/cytoscape-fcose.js',
		dest: 'assets/vendor/cytoscape-fcose/cytoscape-fcose.js',
	},
	{
		src:  'node_modules/layout-base/layout-base.js',
		dest: 'assets/vendor/layout-base/layout-base.js',
	},
	{
		src:  'node_modules/cose-base/cose-base.js',
		dest: 'assets/vendor/cose-base/cose-base.js',
	},
];

// MIT license texts must accompany the bundled code (readme.txt
// == Third-Party Libraries == references these copies).
const licenseMap = [
	{
		src:  'node_modules/cytoscape/LICENSE',
		dest: 'assets/vendor/cytoscape/LICENSE',
	},
	{
		src:  'node_modules/cytoscape-fcose/LICENSE',
		dest: 'assets/vendor/cytoscape-fcose/LICENSE',
	},
	{
		src:  'node_modules/layout-base/LICENSE',
		dest: 'assets/vendor/layout-base/LICENSE',
	},
	{
		src:  'node_modules/cose-base/LICENSE',
		dest: 'assets/vendor/cose-base/LICENSE',
	},
];

let errors = 0;

for (const { src, dest } of [ ...vendorMap, ...licenseMap ]) {
	const srcPath  = path.join(ROOT, src);
	const destPath = path.join(ROOT, dest);
	const destDir  = path.dirname(destPath);

	if (!fs.existsSync(srcPath)) {
		console.error('[copy-vendor] MISSING: %s', srcPath);
		errors++;
		continue;
	}

	fs.mkdirSync(destDir, { recursive: true });
	fs.copyFileSync(srcPath, destPath);
	console.log('[copy-vendor] %s → %s', src, dest);
}

if (errors) {
	console.error('[copy-vendor] %d file(s) missing — run `npm install` first.', errors);
	process.exitCode = 1;
} else {
	console.log('[copy-vendor] Done — all vendor files copied.');
}

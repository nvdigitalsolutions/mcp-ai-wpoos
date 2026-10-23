/**
 * Build SPA Addon ZIPs
 *
 * Creates standalone ZIPs for each SPA addon, versioned from the addon's own
 * plugin header. Excludes node_modules, .git, tests/, package-lock.json.
 *
 * Usage: node bin/build-spa-zips.js
 */

const AdmZip = require('adm-zip');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const BUILD_DIR = path.join(ROOT, 'build');
const EXCLUDES = ['node_modules', '.git', 'tests', 'package-lock.json', '.DS_Store'];

// Map of addon directory name → output ZIP slug prefix.
// NOTE: chat-spa, comic-reader, and docs-hub are built by bin/build-addon-zips.sh
// (which handles npm build steps, source exclusions, and version stamping).
// This script handles only the remaining SPA addons that are NOT built there.
//
// schedule-anything-spa is the Pattern-B Vite product SPA (no PHP entry
// file): its version lives in package.json and its build output is
// assets/dist/ (index.html + hashed chunks), so it declares `versionFile`
// instead of `entryFile` and ships extra dev-file excludes.
const ADDONS = [
	{ dir: 'canvas-toolkit',  slug: 'nvoos-canvas-toolkit',  entryFile: 'nvoos-canvas-toolkit.php' },
	{ dir: 'document-editor', slug: 'nvoos-document-editor', entryFile: 'nvoos-document-editor.php' },
	{ dir: 'media-studio',    slug: 'nvoos-media-studio',    entryFile: 'nvoos-media-studio.php' },
	{ dir: 'toolkit-shell',   slug: 'nvoos-toolkit-shell',   entryFile: 'nvoos-toolkit-shell.php' },
	{
		dir: 'schedule-anything-spa',
		slug: 'nvoos-schedule-anything-spa',
		versionFile: 'package.json',
		extraExcludes: [ 'src', 'index.html', 'vite.config.ts', 'vitest.config.ts', 'postcss.config.js', 'tsconfig.json', 'eslint.config.js' ],
	},
];

function readVersion(versionFile) {
	const content = fs.readFileSync(versionFile, 'utf8');
	const phpMatch = content.match(/^\s*\*\s*Version:\s*(.+)$/m);
	if (phpMatch) {
		return phpMatch[1].trim();
	}
	// Pattern-B addons carry the version in package.json instead of a PHP header.
	const pkgMatch = content.match(/"version"\s*:\s*"([^"]+)"/);
	return pkgMatch ? pkgMatch[1].trim() : 'dev';
}

// Ensure build dir exists
fs.mkdirSync(BUILD_DIR, { recursive: true });

let totalSize = 0;

for (const addon of ADDONS) {
	const addonSrc = path.join(ROOT, 'addons', addon.dir);
	const versionFile = path.join(addonSrc, addon.versionFile || addon.entryFile);
	const version = readVersion(versionFile);
	const zipName = `${addon.slug}-v${version}.zip`;
	const zipPath = path.join(BUILD_DIR, zipName);

	// Remove old ZIPs with this slug (may carry stale version stamp)
	const existing = fs.readdirSync(BUILD_DIR).filter(f => f.startsWith(addon.slug + '-v') && f.endsWith('.zip'));
	for (const old of existing) {
		fs.unlinkSync(path.join(BUILD_DIR, old));
	}

	console.log(`Building ${zipName}...`);

	const zip = new AdmZip();
	const innerPrefix = addon.slug + '/';

	function addDir(dirPath, zipPathPrefix) {
		const entries = fs.readdirSync(dirPath, { withFileTypes: true });
		for (const entry of entries) {
			const fullPath = path.join(dirPath, entry.name);
			const relativePath = path.relative(addonSrc, fullPath);
			const topLevel = relativePath.split(path.sep)[0];

			if (EXCLUDES.includes(topLevel) || (addon.extraExcludes || []).includes(topLevel)) continue;

			if (entry.isDirectory()) {
				addDir(fullPath, zipPathPrefix);
			} else {
				const zipEntryPath = zipPathPrefix + relativePath.replace(/\\/g, '/');
				zip.addLocalFile(fullPath, path.dirname(zipEntryPath));
			}
		}
	}

	addDir(addonSrc, innerPrefix);
	zip.writeZip(zipPath);

	const sizeMB = (fs.statSync(zipPath).size / (1024 * 1024)).toFixed(1);
	totalSize += parseFloat(sizeMB);
	console.log(`  ✅ ${zipName} (${sizeMB} MB)`);
}

console.log(`\n✅ All ${ADDONS.length} SPA ZIPs built (${totalSize.toFixed(1)} MB total)`);
console.log(`📦 Output: ${BUILD_DIR}`);

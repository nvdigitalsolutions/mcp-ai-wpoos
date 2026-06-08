/**
 * Shared utilities for demo video capture scripts.
 *
 * Provides:
 *   - VIDEO_CONFIG: standard viewport and recording settings.
 *   - createVideoContext(): factory for a browser context with recordVideo enabled.
 *   - optimizeVideo(): FFmpeg wrapper to convert .webm → .mp4.
 *   - PAUSE constants: standard wait durations for readable video pacing.
 */

const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');

// ── Configuration ─────────────────────────────────────────────

const BASE_URL = process.env.BASE_URL || 'http://localhost:8000';

const VIDEO_CONFIG = {
	viewport: { width: 1920, height: 1080 },
	size: { width: 1920, height: 1080 },
	baseUrl: BASE_URL,
	adminUrl: `${BASE_URL}/wp-admin`,
};

// ── Pause Constants (milliseconds) ────────────────────────────

const PAUSE = {
	/** After a quick UI action (click, fill) */
	SHORT: 800,
	/** After navigation or a change the viewer should absorb */
	MEDIUM: 1500,
	/** After page load for JS-heavy (React) pages */
	LONG: 3000,
	/** Extra wait for streaming responses / network requests */
	STREAM: 5000,
};

// ── Context Factory ───────────────────────────────────────────

/**
 * Creates a new browser context with video recording enabled.
 *
 * @param {import('playwright').Browser} browser
 * @param {string} outputDir - Directory where .webm will be saved.
 * @returns {Promise<import('playwright').BrowserContext>}
 */
async function createVideoContext(browser, outputDir) {
	fs.mkdirSync(outputDir, { recursive: true });

	return browser.newContext({
		viewport: VIDEO_CONFIG.viewport,
		recordVideo: {
			dir: outputDir,
			size: VIDEO_CONFIG.size,
		},
	});
}

// ── Video Optimization ────────────────────────────────────────

/**
 * Converts a .webm video to optimized .mp4 using FFmpeg.
 * Skips silently if FFmpeg is not available.
 *
 * @param {string} inputPath  - Path to the .webm file.
 * @param {string} outputPath - Desired .mp4 output path.
 * @returns {boolean} True if optimization succeeded.
 */
function optimizeVideo(inputPath, outputPath) {
	if (!fs.existsSync(inputPath)) {
		console.warn(`  ⚠️  Input not found, skipping optimize: ${inputPath}`);
		return false;
	}

	try {
		execSync('ffmpeg -version', { stdio: 'ignore' });
	} catch {
		console.warn('  ⚠️  FFmpeg not found — video remains as .webm');
		return false;
	}

	try {
		execSync(
			`ffmpeg -y -i "${inputPath}" ` +
				`-c:v libx264 -preset fast -crf 28 ` +
				`-c:a aac -b:a 128k ` +
				`-movflags +faststart ` +
				`"${outputPath}"`,
			{ stdio: 'inherit' }
		);
		console.log(`  ✅ Optimized: ${path.basename(outputPath)}`);
		return true;
	} catch (err) {
		console.warn(`  ⚠️  FFmpeg optimize failed: ${err.message}`);
		return false;
	}
}

// ── Batch Optimizer ───────────────────────────────────────────

/**
 * Converts all .webm files in a directory to .mp4.
 *
 * @param {string} dir - Directory to scan.
 * @returns {number} Count of files optimized.
 */
function optimizeDirectory(dir) {
	if (!fs.existsSync(dir)) {
		return 0;
	}

	const files = fs.readdirSync(dir).filter((f) => f.endsWith('.webm'));
	let count = 0;

	for (const file of files) {
		const inputPath = path.join(dir, file);
		const outputPath = inputPath.replace(/\.webm$/, '.mp4');
		if (optimizeVideo(inputPath, outputPath)) {
			count++;
		}
	}

	return count;
}

// ── Helpers ───────────────────────────────────────────────────

/**
 * Resolves output directory from script location.
 *
 * @param {string} scriptDir  - __dirname of the calling script.
 * @param {string} [subDir]   - Subdirectory (e.g., 'base', 'pro').
 * @returns {string}
 */
function resolveOutputDir(scriptDir, subDir = 'base') {
	return path.resolve(scriptDir, '..', 'docs', 'videos', subDir);
}

module.exports = {
	VIDEO_CONFIG,
	PAUSE,
	createVideoContext,
	optimizeVideo,
	optimizeDirectory,
	resolveOutputDir,
};

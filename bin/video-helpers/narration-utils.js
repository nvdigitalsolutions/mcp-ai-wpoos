/**
 * Narration utilities for demo video scripts.
 *
 * Provides helpers for parsing narration .txt files, estimating TTS durations,
 * and managing the narration pipeline from text → audio → merge.
 */

const fs = require('fs');
const path = require('path');

/**
 * Parses a narration .txt file into an array of segment objects.
 *
 * Format rules:
 *   - One line = one spoken segment.
 *   - Blank lines are ignored (they become silence gaps in the merge step).
 *   - Lines starting with # are comments and are ignored.
 *   - Leading/trailing whitespace is trimmed from each line.
 *
 * @param {string} filePath - Path to the .txt narration file.
 * @returns {{ id: string, text: string }[]}
 */
function parseNarrationScript(filePath) {
	const content = fs.readFileSync(filePath, 'utf-8');
	const lines = content
		.split('\n')
		.map((l) => l.trim())
		.filter((l) => l.length > 0 && !l.startsWith('#'));

	return lines.map((text, i) => ({
		id: `seg-${String(i).padStart(2, '0')}`,
		text,
	}));
}

/**
 * Estimate audio duration from MP3 buffer size.
 *
 * Approximate: OpenAI tts-1 outputs ~16 KB/s at default quality.
 * For more accurate measurement, use ffprobe:
 *   ffprobe -v error -show_entries format=duration -of csv=p=0 file.mp3
 *
 * @param {Buffer} buffer - MP3 audio buffer.
 * @param {number} [bytesPerSecond=16000] - Approximate bytes per second.
 * @returns {number} Duration in milliseconds.
 */
function estimateDuration(buffer, bytesPerSecond = 16000) {
	return Math.round((buffer.length / bytesPerSecond) * 1000);
}

/**
 * Get the precise duration of an audio file using ffprobe.
 * Falls back to buffer-size estimation if ffprobe is unavailable.
 *
 * @param {string} filePath - Path to audio file.
 * @returns {number} Duration in milliseconds.
 */
function getAudioDuration(filePath) {
	try {
		const { execSync } = require('child_process');
		const output = execSync(
			`ffprobe -v error -show_entries format=duration -of csv=p=0 "${filePath}"`,
			{ stdio: 'pipe', encoding: 'utf-8' }
		).trim();

		const seconds = parseFloat(output);
		if (!isNaN(seconds) && seconds > 0) {
			return Math.round(seconds * 1000);
		}
	} catch {
		// ffprobe not available — fall through to estimation
	}

	// Fallback: estimate from file size
	try {
		const stats = fs.statSync(filePath);
		return estimateDuration(Buffer.alloc(stats.size)); // rough estimate
	} catch {
		return 3000; // safe default
	}
}

/**
 * Read a durations.json manifest file.
 *
 * Expected format:
 *   { "seg-00": 8420, "seg-01": 6180, ... }
 *
 * @param {string} dirPath - Directory containing durations.json.
 * @returns {object} Parsed durations object, or empty object if not found.
 */
function readDurations(dirPath) {
	const durationsPath = path.join(dirPath, 'durations.json');
	if (!fs.existsSync(durationsPath)) {
		return {};
	}
	return JSON.parse(fs.readFileSync(durationsPath, 'utf-8'));
}

/**
 * Write a durations.json manifest file.
 *
 * @param {string} dirPath - Directory to write to.
 * @param {object} durations - { "seg-00": 8420, ... }
 */
function writeDurations(dirPath, durations) {
	fs.mkdirSync(dirPath, { recursive: true });
	fs.writeFileSync(
		path.join(dirPath, 'durations.json'),
		JSON.stringify(durations, null, 2),
		'utf-8'
	);
}

/**
 * Calculate total narration duration from a durations object.
 *
 * @param {object} durations - { "seg-00": 8420, ... }
 * @param {number} [gapMs=500] - Silence gap between segments.
 * @returns {number} Total duration in milliseconds.
 */
function totalDuration(durations, gapMs = 500) {
	const entries = Object.entries(durations);
	if (entries.length === 0) return 0;

	const totalSegmentMs = entries.reduce((sum, [, ms]) => sum + ms, 0);
	const totalGapMs = (entries.length - 1) * gapMs;
	return totalSegmentMs + totalGapMs;
}

/**
 * Format milliseconds as a human-readable timestamp.
 *
 * @param {number} ms
 * @returns {string} e.g., "1m 45s"
 */
function formatDuration(ms) {
	const totalSec = Math.round(ms / 1000);
	const mins = Math.floor(totalSec / 60);
	const secs = totalSec % 60;
	if (mins > 0) return `${mins}m ${secs}s`;
	return `${secs}s`;
}

/**
 * Find all narration scripts in the narration directory.
 *
 * @param {string} narrationDir - Path to docs/videos/narration/.
 * @returns {string[]} Array of video names (without .txt extension).
 */
function findNarrationScripts(narrationDir) {
	if (!fs.existsSync(narrationDir)) return [];

	return fs.readdirSync(narrationDir)
		.filter((f) => f.endsWith('.txt') && !f.startsWith('.'))
		.map((f) => f.replace(/\.txt$/, ''));
}

module.exports = {
	parseNarrationScript,
	estimateDuration,
	getAudioDuration,
	readDurations,
	writeDurations,
	totalDuration,
	formatDuration,
	findNarrationScripts,
};

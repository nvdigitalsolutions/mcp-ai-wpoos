#!/usr/bin/env node
/**
 * NV oOS — Video-Audio Merger (Phase 3)
 *
 * Merges a silent Playwright .webm recording with narration .mp3 segments
 * produced by generate-narration-audio.js. Uses FFmpeg to concatenate audio
 * segments with silence gaps, then mux with the video.
 *
 * Workflow:
 *   1. Read durations.json for segment timing
 *   2. Generate silence gaps between segments
 *   3. FFmpeg concat: seg0.mp3 | silence | seg1.mp3 | silence | ...
 *   4. FFmpeg mux: combined_audio.mp3 + video.webm → final.mp4
 *
 * Usage:
 *   node bin/merge-demo-video.js add-assistant-tools
 *   node bin/merge-demo-video.js --all              # merge all videos
 *   GAP_MS=800 node bin/merge-demo-video.js ...     # custom silence gap
 *
 * Prereq:
 *   - FFmpeg installed and on PATH
 *   - generate-narration-audio.js has been run first
 *   - The silent .webm video exists in docs/videos/base/ or docs/videos/pro/
 *
 * Output:
 *   docs/videos/base/<name>.mp4  (or docs/videos/pro/<name>.mp4)
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// ── Configuration ─────────────────────────────────────────────

const REPO_ROOT = path.resolve(__dirname, '..');
const VIDEO_DIR = path.join(REPO_ROOT, 'docs', 'videos');
const NARRATION_DIR = path.join(VIDEO_DIR, 'narration');
const AUDIO_DIR = path.join(NARRATION_DIR, 'audio');
const GAP_MS = parseInt(process.env.GAP_MS || '500', 10); // silence between segments

// ── Helpers ───────────────────────────────────────────────────

/**
 * Generates a silence WAV file of given duration in milliseconds.
 * WAV is used because FFmpeg concat handles it more reliably than raw silence.
 */
function generateSilence(durationMs, outputPath) {
	const durationSec = (durationMs / 1000).toFixed(3);
	execSync(
		`ffmpeg -y -f lavfi -i anullsrc=r=44100:cl=mono -t ${durationSec} -q:a 9 "${outputPath}"`,
		{ stdio: 'pipe' }
	);
}

/**
 * Find the video file in base/ or pro/ directories.
 */
function findVideoFile(videoName) {
	for (const subDir of ['base', 'pro']) {
		const webmPath = path.join(VIDEO_DIR, subDir, `${videoName}.webm`);
		if (fs.existsSync(webmPath)) return webmPath;

		// Also check .mp4 (already converted)
		const mp4Path = path.join(VIDEO_DIR, subDir, `${videoName}.mp4`);
		if (fs.existsSync(mp4Path)) return mp4Path;
	}
	return null;
}

/**
 * Get the output directory for a video name.
 */
function findOutputDir(videoName) {
	for (const subDir of ['base', 'pro']) {
		if (fs.existsSync(path.join(VIDEO_DIR, subDir, `${videoName}.webm`)) ||
		    fs.existsSync(path.join(VIDEO_DIR, subDir, `${videoName}.mp4`))) {
			return path.join(VIDEO_DIR, subDir);
		}
	}
	return path.join(VIDEO_DIR, 'base'); // default
}

// ── Merge Logic ───────────────────────────────────────────────

async function mergeVideo(videoName) {
	console.log(`  ▶ Merging: ${videoName}`);

	// Locate files
	const videoPath = findVideoFile(videoName);
	if (!videoPath) {
		console.warn(`    ⚠️  Video file not found for "${videoName}" in base/ or pro/`);
		return false;
	}

	const audioSegmentDir = path.join(AUDIO_DIR, videoName);
	const durationsPath = path.join(audioSegmentDir, 'durations.json');

	if (!fs.existsSync(durationsPath)) {
		console.warn(`    ⚠️  durations.json not found at ${durationsPath}`);
		console.warn('    Run generate-narration-audio.js first.');
		return false;
	}

	const durations = JSON.parse(fs.readFileSync(durationsPath, 'utf-8'));
	const segmentKeys = Object.keys(durations).sort();

	if (segmentKeys.length === 0) {
		console.warn('    ⚠️  No audio segments found');
		return false;
	}

	console.log(`    Found ${segmentKeys.length} audio segments`);

	// Build concat list
	const concatDir = path.join(audioSegmentDir, '.concat');
	fs.mkdirSync(concatDir, { recursive: true });

	const silencePath = path.join(concatDir, '_silence.wav');
	const concatListPath = path.join(concatDir, 'concat.txt');
	const combinedAudioPath = path.join(concatDir, 'combined.mp3');

	// Generate silence
	generateSilence(GAP_MS, silencePath);

	// Write concat list
	const concatLines = [];
	for (let i = 0; i < segmentKeys.length; i++) {
		const segKey = segmentKeys[i];
		const segPath = path.join(audioSegmentDir, `${segKey}.mp3`);

		if (!fs.existsSync(segPath)) {
			console.warn(`    ⚠️  Missing segment: ${segPath} — skipping`);
			continue;
		}

		concatLines.push(`file '${segPath.replace(/'/g, "'\\''")}'`);

		// Add silence between segments (not after the last)
		if (i < segmentKeys.length - 1) {
			concatLines.push(`file '${silencePath.replace(/'/g, "'\\''")}'`);
		}
	}

	if (concatLines.length === 0) {
		console.warn('    ⚠️  No valid audio files to concatenate');
		return false;
	}

	fs.writeFileSync(concatListPath, concatLines.join('\n'), 'utf-8');

	// Step 1: Concatenate all audio segments + silence into one file
	console.log('    Concatenating audio segments...');
	try {
		execSync(
			`ffmpeg -y -f concat -safe 0 -i "${concatListPath}" -c copy "${combinedAudioPath}"`,
			{ stdio: 'pipe' }
		);
	} catch (err) {
		// If copy fails (codec mismatch), re-encode
		console.log('    (re-encoding audio due to codec mismatch)');
		execSync(
			`ffmpeg -y -f concat -safe 0 -i "${concatListPath}" -c:a libmp3lame -q:a 2 "${combinedAudioPath}"`,
			{ stdio: 'pipe' }
		);
	}

	// Step 2: Mux audio + video
	const outputDir = findOutputDir(videoName);
	const outputPath = path.join(outputDir, `${videoName}.mp4`);

	console.log(`    Muxing video + audio → ${path.basename(outputPath)}`);
	execSync(
		`ffmpeg -y -i "${videoPath}" -i "${combinedAudioPath}" ` +
		`-c:v libx264 -preset fast -crf 28 ` +
		`-c:a aac -b:a 192k ` +
		`-movflags +faststart ` +
		`-shortest ` +
		`"${outputPath}"`,
		{ stdio: 'inherit' }
	);

	// Clean up temp files
	fs.rmSync(concatDir, { recursive: true, force: true });

	// Print timing summary
	console.log('    Segment timing:');
	let cumulativeMs = 0;
	for (const segKey of segmentKeys) {
		const segMs = durations[segKey];
		const mins = Math.floor(cumulativeMs / 60000);
		const secs = Math.floor((cumulativeMs % 60000) / 1000);
		const timestamp = `[${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}]`;
		console.log(`      ${timestamp} ${segKey} (${(segMs / 1000).toFixed(1)}s)`);
		cumulativeMs += segMs + GAP_MS;
	}
	const totalSec = (cumulativeMs / 1000).toFixed(1);
	console.log(`      Total: ${totalSec}s`);

	console.log(`    ✅ ${path.basename(outputPath)}`);
	return true;
}

// ── CLI ───────────────────────────────────────────────────────

(async () => {
	const args = process.argv.slice(2);

	if (args.length === 0 || args.includes('--help') || args.includes('-h')) {
		console.log('Usage: node bin/merge-demo-video.js <video-name> [--all]');
		console.log('');
		console.log('  <video-name>   Merge a specific video (e.g., add-assistant-tools)');
		console.log('  --all          Merge all videos that have narration audio');
		console.log('');
		console.log('Environment:');
		console.log('  GAP_MS         Silence gap between narration segments (default: 500)');
		console.log('');
		console.log('Prerequisites:');
		console.log('  - FFmpeg installed and on PATH');
		console.log('  - generate-narration-audio.js has been run');
		console.log('  - Silent .webm video exists in docs/videos/base/ or pro/');
		process.exit(0);
	}

	// Check FFmpeg
	try {
		execSync('ffmpeg -version', { stdio: 'ignore' });
	} catch {
		console.error('❌ FFmpeg is not installed or not on PATH.');
		console.error('   Install it: https://ffmpeg.org/download.html');
		process.exit(1);
	}

	console.log('🎬 Video-Audio Merger\n');

	if (args.includes('--all')) {
		if (!fs.existsSync(AUDIO_DIR)) {
			console.error(`❌ Audio directory not found: ${AUDIO_DIR}`);
			console.error('   Run generate-narration-audio.js first.');
			process.exit(1);
		}

		const dirs = fs.readdirSync(AUDIO_DIR, { withFileTypes: true })
			.filter((d) => d.isDirectory() && !d.name.startsWith('.'))
			.map((d) => d.name);

		if (dirs.length === 0) {
			console.error('❌ No audio segment directories found.');
			process.exit(1);
		}

		console.log(`Found ${dirs.length} video(s) with narration audio: ${dirs.join(', ')}\n`);

		let merged = 0;
		for (const name of dirs) {
			const ok = await mergeVideo(name);
			if (ok) merged++;
			console.log('');
		}
		console.log(`✅ Merged ${merged} of ${dirs.length} video(s)`);
	} else {
		const videoName = args[0];
		await mergeVideo(videoName);
	}

	console.log('✅ Done.');
})();

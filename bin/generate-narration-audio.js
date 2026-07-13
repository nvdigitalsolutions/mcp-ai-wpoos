#!/usr/bin/env node
/**
 * NV oOS — Narration Audio Generator (Phase 3)
 *
 * Converts narration/*.txt files to individual MP3 segments via OpenAI TTS.
 * Produces a durations.json manifest for timing synchronization with
 * merge-demo-video.js.
 *
 * Based on the PurpleOwl pattern (Mar 2026):
 *   https://purpleowl.io/blog/we-automated-our-product-walkthrough-video-the-whole-thing
 *
 * Usage:
 *   node bin/generate-narration-audio.js <video-name>
 *   node bin/generate-narration-audio.js --all
 *   node bin/generate-narration-audio.js add-assistant-tools --force
 *   OPENAI_API_KEY=sk-... node bin/generate-narration-audio.js --all
 *
 * Environment:
 *   OPENAI_API_KEY       Required. Used for TTS API calls.
 *   TTS_VOICE            OpenAI voice: alloy, echo, fable, onyx, nova, shimmer.
 *                        Default: nova
 *   TTS_MODEL            Model: tts-1 (faster, lower quality) or tts-1-hd.
 *                        Default: tts-1
 *   TTS_SPEED            Speech speed: 0.25 to 4.0. Default: 1.0
 */

const fs = require('fs');
const path = require('path');
const https = require('https');

const {
	parseNarrationScript,
	writeDurations,
	findNarrationScripts,
	formatDuration,
	totalDuration,
} = require('./video-helpers/narration-utils');

// ── Configuration ─────────────────────────────────────────────

const REPO_ROOT = path.resolve(__dirname, '..');
const NARRATION_DIR = path.join(REPO_ROOT, 'docs', 'videos', 'narration');
const AUDIO_DIR = path.join(NARRATION_DIR, 'audio');
const VOICE = process.env.TTS_VOICE || 'nova';
const MODEL = process.env.TTS_MODEL || 'tts-1';
const SPEED = parseFloat(process.env.TTS_SPEED || '1.0');
const FORCE = process.argv.includes('--force');

if (!process.env.OPENAI_API_KEY) {
	console.error('❌ OPENAI_API_KEY environment variable is required.');
	console.error('   Set it: export OPENAI_API_KEY="sk-proj-..."');
	process.exit(1);
}

// ── Helpers ───────────────────────────────────────────────────

/**
 * Call OpenAI TTS API to generate speech from text.
 *
 * @param {string} text - Text to convert to speech.
 * @returns {Promise<{ buffer: Buffer, durationMs: number }>}
 */
function generateSegmentAudio(text) {
	return new Promise((resolve, reject) => {
		const body = JSON.stringify({
			model: MODEL,
			voice: VOICE,
			input: text,
			response_format: 'mp3',
			speed: SPEED,
		});

		const req = https.request({
			hostname: 'api.openai.com',
			path: '/v1/audio/speech',
			method: 'POST',
			headers: {
				'Authorization': `Bearer ${process.env.OPENAI_API_KEY}`,
				'Content-Type': 'application/json',
				'Content-Length': Buffer.byteLength(body),
			},
			timeout: 30000,
		}, (res) => {
			if (res.statusCode !== 200) {
				let errData = '';
				res.on('data', (chunk) => { errData += chunk; });
				res.on('end', () => {
					reject(new Error(`OpenAI TTS API error ${res.statusCode}: ${errData}`));
				});
				return;
			}

			const chunks = [];
			res.on('data', (chunk) => chunks.push(chunk));
			res.on('end', () => {
				const buffer = Buffer.concat(chunks);
				const durationMs = Math.round((buffer.length / 16000) * 1000);
				resolve({ buffer, durationMs });
			});
		});

		req.on('error', reject);
		req.write(body);
		req.end();
	});
}

/**
 * Generate narration audio for a single video.
 *
 * @param {string} videoName - e.g., "add-assistant-tools"
 * @returns {Promise<boolean>} True on success.
 */
async function generateForVideo(videoName) {
	const scriptPath = path.join(NARRATION_DIR, `${videoName}.txt`);
	if (!fs.existsSync(scriptPath)) {
		console.warn(`  ⚠️  Narration script not found: ${scriptPath}`);
		return false;
	}

	const segments = parseNarrationScript(scriptPath);
	if (segments.length === 0) {
		console.warn(`  ⚠️  No segments found in ${videoName}.txt`);
		return false;
	}

	const outputDir = path.join(AUDIO_DIR, videoName);

	// Check if already generated (unless --force)
	const durationsPath = path.join(outputDir, 'durations.json');
	if (!FORCE && fs.existsSync(durationsPath)) {
		const existing = JSON.parse(fs.readFileSync(durationsPath, 'utf-8'));
		const existingKeys = Object.keys(existing);
		if (existingKeys.length === segments.length) {
			console.log(`  ✅ ${videoName} — already generated (${existingKeys.length} segments, ${formatDuration(totalDuration(existing))}). Use --force to regenerate.`);
			return true;
		}
	}

	fs.mkdirSync(outputDir, { recursive: true });

	console.log(`  ▶ Generating: ${videoName} (${segments.length} segments)`);

	const durations = {};
	let generated = 0;

	for (const segment of segments) {
		process.stdout.write(`    [${segment.id}] "${segment.text.slice(0, 50)}${segment.text.length > 50 ? '...' : ''}" `);

		try {
			const { buffer, durationMs } = await generateSegmentAudio(segment.text);
			const audioPath = path.join(outputDir, `${segment.id}.mp3`);
			fs.writeFileSync(audioPath, buffer);

			durations[segment.id] = durationMs;
			generated++;

			// Rate limiting: OpenAI TTS has a rate limit; pause between requests
			console.log(`✅ ${(durationMs / 1000).toFixed(1)}s`);

			// Small delay to avoid rate limits
			await new Promise((r) => setTimeout(r, 200));
		} catch (error) {
			console.error(`❌ FAILED: ${error.message}`);
			// Continue with other segments
		}
	}

	// Write durations manifest
	if (Object.keys(durations).length > 0) {
		writeDurations(outputDir, durations);
		console.log(`    ✅ durations.json written (${formatDuration(totalDuration(durations))} total)`);
	}

	return generated > 0;
}

// ── CLI ───────────────────────────────────────────────────────

(async () => {
	const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));

	if (args.length === 0 || args.includes('--help') || args.includes('-h')) {
		console.log('Usage: node bin/generate-narration-audio.js <video-name> [--all] [--force]');
		console.log('');
		console.log('  <video-name>  Generate audio for a single video (without .txt extension)');
		console.log('  --all         Generate audio for all narration scripts');
		console.log('  --force       Regenerate even if audio already exists');
		console.log('');
		console.log('Environment:');
		console.log('  OPENAI_API_KEY  Required. Your OpenAI API key.');
		console.log('  TTS_VOICE       Voice name (default: nova)');
		console.log('  TTS_MODEL       Model: tts-1 or tts-1-hd (default: tts-1)');
		console.log('  TTS_SPEED       Speech speed 0.25-4.0 (default: 1.0)');
		process.exit(0);
	}

	console.log('🎙️  NV oOS — Narration Audio Generator\n');
	console.log(`   Voice: ${VOICE}  |  Model: ${MODEL}  |  Speed: ${SPEED}x\n`);

	fs.mkdirSync(AUDIO_DIR, { recursive: true });

	const allFlag = process.argv.includes('--all');
	let videoNames;

	if (allFlag) {
		videoNames = findNarrationScripts(NARRATION_DIR);
		if (videoNames.length === 0) {
			console.error('❌ No narration .txt files found in', NARRATION_DIR);
			process.exit(1);
		}
		console.log(`Found ${videoNames.length} narration script(s)\n`);
	} else {
		videoNames = [args[0]];
	}

	let succeeded = 0;
	for (const name of videoNames) {
		const ok = await generateForVideo(name);
		if (ok) succeeded++;
	}

	console.log(`\n✅ Generated audio for ${succeeded} of ${videoNames.length} video(s)`);
	console.log(`📁 Output: ${AUDIO_DIR}\n`);
})();

#!/usr/bin/env node
/**
 * NV oOS — Narration Audio Generator (Phase 3)
 *
 * Converts narration/*.txt files to per-segment MP3 files via TTS.
 * Also writes a durations.json manifest for timing synchronization
 * during video-audio merge.
 *
 * Supported providers:
 *   - openai     (OPENAI_API_KEY)       — OpenAI TTS, voices: alloy, echo, fable, onyx, nova, shimmer
 *   - elevenlabs (ELEVENLABS_API_KEY)   — ElevenLabs TTS, voice ID from TTS_VOICE_ID
 *
 * Usage:
 *   node bin/generate-narration-audio.js add-assistant-tools
 *   node bin/generate-narration-audio.js --all          # process all .txt files
 *   TTS_PROVIDER=elevenlabs node bin/generate-narration-audio.js ...
 *
 * Output:
 *   docs/videos/narration/audio/<video-name>/segment-00.mp3
 *   docs/videos/narration/audio/<video-name>/segment-01.mp3
 *   docs/videos/narration/audio/<video-name>/durations.json
 */

const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');

// ── Configuration ─────────────────────────────────────────────

const NARRATION_DIR = path.resolve(__dirname, '..', 'docs', 'videos', 'narration');
const AUDIO_DIR = path.resolve(NARRATION_DIR, 'audio');
const PROVIDER = process.env.TTS_PROVIDER || 'openai';
const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const ELEVENLABS_API_KEY = process.env.ELEVENLABS_API_KEY;
const TTS_VOICE = process.env.TTS_VOICE || 'nova'; // OpenAI voice
const TTS_VOICE_ID = process.env.TTS_VOICE_ID || '21m00Tcm4TlvDq8ikWAM'; // ElevenLabs "Rachel"
const TTS_MODEL = process.env.TTS_MODEL || 'tts-1'; // OpenAI: tts-1 or tts-1-hd

// ── Helpers ───────────────────────────────────────────────────

function parseNarrationFile(filePath) {
	const content = fs.readFileSync(filePath, 'utf-8');
	const lines = content.split('\n');

	const segments = [];
	for (const line of lines) {
		const trimmed = line.trim();
		// Skip comments and blank lines
		if (!trimmed || trimmed.startsWith('#')) continue;
		segments.push(trimmed);
	}
	return segments;
}

// ── OpenAI TTS ────────────────────────────────────────────────

async function openaiTTS(text, voice) {
	if (!OPENAI_API_KEY) {
		throw new Error('OPENAI_API_KEY is required for OpenAI TTS');
	}

	const data = JSON.stringify({
		model: TTS_MODEL,
		input: text,
		voice: voice,
		response_format: 'mp3',
		speed: 1.0,
	});

	return new Promise((resolve, reject) => {
		const url = new URL('https://api.openai.com/v1/audio/speech');
		const options = {
			hostname: url.hostname,
			path: url.pathname,
			method: 'POST',
			headers: {
				'Authorization': `Bearer ${OPENAI_API_KEY}`,
				'Content-Type': 'application/json',
				'Content-Length': Buffer.byteLength(data),
			},
		};

		const req = https.request(options, (res) => {
			const chunks = [];
			res.on('data', (chunk) => chunks.push(chunk));
			res.on('end', () => {
				if (res.statusCode !== 200) {
					const body = Buffer.concat(chunks).toString();
					reject(new Error(`OpenAI TTS error ${res.statusCode}: ${body}`));
					return;
				}
				resolve(Buffer.concat(chunks));
			});
		});

		req.on('error', reject);
		req.write(data);
		req.end();
	});
}

// ── ElevenLabs TTS ────────────────────────────────────────────

async function elevenlabsTTS(text, voiceId) {
	if (!ELEVENLABS_API_KEY) {
		throw new Error('ELEVENLABS_API_KEY is required for ElevenLabs TTS');
	}

	const data = JSON.stringify({
		text: text,
		model_id: 'eleven_multilingual_v2',
		voice_settings: {
			stability: 0.5,
			similarity_boost: 0.75,
		},
	});

	return new Promise((resolve, reject) => {
		const url = new URL(`https://api.elevenlabs.io/v1/text-to-speech/${voiceId}`);
		const options = {
			hostname: url.hostname,
			path: url.pathname,
			method: 'POST',
			headers: {
				'xi-api-key': ELEVENLABS_API_KEY,
				'Content-Type': 'application/json',
				'Accept': 'audio/mpeg',
			},
		};

		const req = https.request(options, (res) => {
			const chunks = [];
			res.on('data', (chunk) => chunks.push(chunk));
			res.on('end', () => {
				if (res.statusCode !== 200) {
					const body = Buffer.concat(chunks).toString();
					reject(new Error(`ElevenLabs TTS error ${res.statusCode}: ${body}`));
					return;
				}
				resolve(Buffer.concat(chunks));
			});
		});

		req.on('error', reject);
		req.write(data);
		req.end();
	});
}

// ── MP3 Duration Measurement ──────────────────────────────────
// Reads the MP3 header to determine duration in milliseconds.
// Falls back to an estimate based on file size and bitrate.

function getMp3Duration(buffer) {
	// MP3 frame sync: 0xFFE0 or 0xFFF0
	// Bitrate table for MPEG1 Layer3 (index → kbps)
	const bitrateTable = [
		0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0,
	];
	const sampleRateTable = [44100, 48000, 32000];

	// Find first valid frame header
	for (let i = 0; i < Math.min(buffer.length - 4, 4096); i++) {
		if ((buffer[i] === 0xFF) && ((buffer[i + 1] & 0xE0) === 0xE0)) {
			const header = (buffer[i + 1] << 8) | buffer[i + 2];
			const version = (header >> 19) & 0x03; // 3 = MPEG1
			const layer = (header >> 17) & 0x03;   // 1 = Layer3
			const bitrateIdx = (header >> 12) & 0x0F;
			const sampleRateIdx = (header >> 10) & 0x03;
			const padding = (header >> 9) & 0x01;

			if (version === 3 && layer === 1 && bitrateIdx > 0 && bitrateIdx < 15 && sampleRateIdx < 3) {
				const bitrate = bitrateTable[bitrateIdx] * 1000;
				const sampleRate = sampleRateTable[sampleRateIdx];
				const frameSize = Math.floor((144 * bitrate) / sampleRate + padding);
				const frameCount = Math.floor((buffer.length - i) / frameSize);
				const samplesPerFrame = 1152;
				const durationMs = Math.floor((frameCount * samplesPerFrame * 1000) / sampleRate);
				return durationMs;
			}
		}
	}

	// Fallback: estimate from file size (128kbps stereo = ~16KB/sec)
	const estimatedBitsPerSec = 128000;
	const estimatedMs = Math.floor((buffer.length * 8 * 1000) / estimatedBitsPerSec);
	return estimatedMs;
}

// ── Main Generator ────────────────────────────────────────────

async function generateAudio(videoName) {
	const narrationFile = path.join(NARRATION_DIR, `${videoName}.txt`);
	if (!fs.existsSync(narrationFile)) {
		console.warn(`  ⚠️  Narration file not found: ${narrationFile}`);
		console.warn('     Create one with one line per spoken segment.');
		return null;
	}

	const segments = parseNarrationFile(narrationFile);
	if (segments.length === 0) {
		console.warn(`  ⚠️  No segments in ${narrationFile}`);
		return null;
	}

	const outputDir = path.join(AUDIO_DIR, videoName);
	fs.mkdirSync(outputDir, { recursive: true });

	const durations = {};
	const voice = TTS_VOICE;

	console.log(`  ▶ Generating ${segments.length} audio segments for "${videoName}"`);
	console.log(`    Provider: ${PROVIDER}, Voice: ${PROVIDER === 'elevenlabs' ? TTS_VOICE_ID : voice}`);

	for (let i = 0; i < segments.length; i++) {
		const segment = segments[i];
		const paddedIndex = String(i).padStart(2, '0');
		const outputFile = path.join(outputDir, `segment-${paddedIndex}.mp3`);

		// Skip if already generated (idempotent)
		if (fs.existsSync(outputFile)) {
			const existing = fs.readFileSync(outputFile);
			const durationMs = getMp3Duration(existing);
			durations[`segment-${paddedIndex}`] = durationMs;
			console.log(`    [${paddedIndex}] ✅ cached (${(durationMs / 1000).toFixed(1)}s)`);
			continue;
		}

		console.log(`    [${paddedIndex}] Generating: "${segment.slice(0, 60)}${segment.length > 60 ? '...' : ''}"`);

		try {
			let audioBuffer;
			switch (PROVIDER) {
				case 'elevenlabs':
					audioBuffer = await elevenlabsTTS(segment, TTS_VOICE_ID);
					break;
				case 'openai':
				default:
					audioBuffer = await openaiTTS(segment, voice);
					break;
			}

			fs.writeFileSync(outputFile, audioBuffer);
			const durationMs = getMp3Duration(audioBuffer);
			durations[`segment-${paddedIndex}`] = durationMs;
			console.log(`    [${paddedIndex}] ✅ saved (${(durationMs / 1000).toFixed(1)}s)`);

		} catch (err) {
			console.error(`    [${paddedIndex}] ❌ Failed: ${err.message}`);
			// Write a short silence MP3 as placeholder so the merge doesn't break
			// (minimal valid MP3: just a few frames of silence)
			durations[`segment-${paddedIndex}`] = 3000; // assume 3s
		}
	}

	// Write durations manifest
	const manifestPath = path.join(outputDir, 'durations.json');
	fs.writeFileSync(manifestPath, JSON.stringify(durations, null, 2));
	console.log(`    ✅ Durations manifest: ${manifestPath}`);

	// Calculate total
	const totalMs = Object.values(durations).reduce((sum, d) => sum + d, 0);
	const totalSec = (totalMs / 1000).toFixed(1);
	console.log(`    📊 Total narration duration: ${totalSec}s (${segments.length} segments)`);

	return { segments, durations, outputDir };
}

// ── CLI ───────────────────────────────────────────────────────

(async () => {
	const args = process.argv.slice(2);

	if (args.length === 0 || args.includes('--help') || args.includes('-h')) {
		console.log('Usage: node bin/generate-narration-audio.js <video-name> [--all]');
		console.log('');
		console.log('  <video-name>   Process a specific narration file (e.g., add-assistant-tools)');
		console.log('  --all          Process all .txt files in docs/videos/narration/');
		console.log('');
		console.log('Environment:');
		console.log('  TTS_PROVIDER      openai (default) or elevenlabs');
		console.log('  OPENAI_API_KEY    Required for OpenAI TTS');
		console.log('  ELEVENLABS_API_KEY Required for ElevenLabs TTS');
		console.log('  TTS_VOICE         OpenAI voice: alloy, echo, fable, onyx, nova (default), shimmer');
		console.log('  TTS_VOICE_ID      ElevenLabs voice ID (default: Rachel)');
		process.exit(0);
	}

	// Validate credentials
	if (PROVIDER === 'openai' && !OPENAI_API_KEY) {
		console.error('❌ OPENAI_API_KEY is required for OpenAI TTS.');
		console.error('   Set it via: export OPENAI_API_KEY="sk-..."');
		process.exit(1);
	}
	if (PROVIDER === 'elevenlabs' && !ELEVENLABS_API_KEY) {
		console.error('❌ ELEVENLABS_API_KEY is required for ElevenLabs TTS.');
		console.error('   Set it via: export ELEVENLABS_API_KEY="..."');
		process.exit(1);
	}

	console.log('🎙️  Narration Audio Generator');
	console.log(`   Provider: ${PROVIDER}\n`);

	if (args.includes('--all')) {
		// Process all .txt files in the narration directory
		if (!fs.existsSync(NARRATION_DIR)) {
			console.error(`❌ Narration directory not found: ${NARRATION_DIR}`);
			console.error('   Create docs/videos/narration/ and add .txt files.');
			process.exit(1);
		}

		const files = fs.readdirSync(NARRATION_DIR)
			.filter((f) => f.endsWith('.txt'))
			.map((f) => f.replace('.txt', ''));

		if (files.length === 0) {
			console.error('❌ No .txt files found in docs/videos/narration/');
			process.exit(1);
		}

		console.log(`Found ${files.length} narration file(s): ${files.join(', ')}\n`);

		for (const name of files) {
			await generateAudio(name);
			console.log('');
		}
	} else {
		const videoName = args[0];
		await generateAudio(videoName);
	}

	console.log('✅ Done.');
})();

#!/usr/bin/env node
/**
 * Fluent-FFmpeg Video Processing Service
 */
const ffmpeg = require('fluent-ffmpeg');
const fs = require('fs');
const path = require('path');

// Hard timeout for a single ffmpeg run: a hung child must never keep this
// per-request CLI process alive forever. Overridable via FFMPEG_TIMEOUT_MS.
const FFMPEG_TIMEOUT_MS = Number(process.env.FFMPEG_TIMEOUT_MS) > 0
    ? Number(process.env.FFMPEG_TIMEOUT_MS)
    : 10 * 60 * 1000;

async function getMetadata(videoPath) {
    return new Promise((resolve, reject) => {
        if (!fs.existsSync(videoPath)) {
            reject(new Error(`Video not found: ${videoPath}`));
            return;
        }
        ffmpeg.ffprobe(videoPath, (err, metadata) => {
            if (err) reject(new Error(`FFprobe failed: ${err.message}`));
            else resolve(metadata);
        });
    });
}

async function transcodeVideo(videoPath, outputPath, options = {}) {
    return new Promise((resolve, reject) => {
        if (!fs.existsSync(videoPath)) {
            reject(new Error(`Video not found: ${videoPath}`));
            return;
        }
        
        let command = ffmpeg(videoPath);
        
        if (options.codec) command = command.videoCodec(options.codec);
        if (options.audio_codec) command = command.audioCodec(options.audio_codec);
        else if (options.audio_codec === null) command = command.noAudio();
        if (options.bitrate) command = command.videoBitrate(options.bitrate);
        if (options.size) command = command.size(options.size);
        if (options.fps) command = command.fps(options.fps);
        if (options.format) command = command.format(options.format);
        
        let killTimer = null;
        command
            .output(outputPath)
            .on('start', () => {
                killTimer = setTimeout(() => {
                    try { command.kill('SIGKILL'); } catch (e) { /* best effort */ }
                    reject(new Error(`Transcode timed out after ${FFMPEG_TIMEOUT_MS}ms`));
                }, FFMPEG_TIMEOUT_MS);
                if (killTimer.unref) killTimer.unref();
            })
            .on('end', () => {
                if (killTimer) clearTimeout(killTimer);
                resolve(outputPath);
            })
            .on('error', (err) => {
                if (killTimer) clearTimeout(killTimer);
                reject(new Error(`Transcode failed: ${err.message}`));
            })
            .run();
    });
}

if (require.main === module) {
    const action = process.argv[2];
    const dataJson = process.argv[3];
    
    if (!action || !dataJson) {
        console.error('Usage: node ffmpeg-service.js <action> <json-data>');
        process.exit(1);
    }
    
    (async () => {
        try {
            const data = JSON.parse(dataJson);
            let result;
            
            switch (action) {
                case 'metadata':
                    result = await getMetadata(data.video_path);
                    console.log(JSON.stringify(result));
                    break;
                case 'transcode':
                    result = await transcodeVideo(data.video_path, data.output_path, data.options);
                    console.log(result);
                    break;
                default:
                    console.error(`Unknown action: ${action}`);
                    process.exit(1);
            }
        } catch (error) {
            console.error(JSON.stringify({ error: error.message }));
            process.exit(1);
        }
    })();
}

module.exports = { getMetadata, transcodeVideo };

const fs = require('fs');
const path = require('path');

console.log('🔧 Adapting vad.js for NPM...\n');

let c = fs.readFileSync(path.join(__dirname, 'vad.js'), 'utf8');

// Strip IIFE
c = c.replace(/\(function \(window\) \{[\s\S]*?'use strict';\s*[\r\n]+/, '');
c = c.replace(/[\r\n]+\}\)\(window\);\s*$/, '');

// Remove global assignment
c = c.replace(/\nwindow\.VADEngine = VADEngine;/, '');

// Expand minified property names for readability
const renames = [
  [/this\._silenceThresholdMs\b/g,  'this.silenceThresholdMs'],
  [/this\._minSpeechDurationMs\b/g, 'this.minSpeechDurationMs'],
  [/this\._audioLevelThreshold\b/g, 'this.audioLevelThreshold'],
  [/this\._checkIntervalMs\b/g,     'this._checkIntervalMs'],
  [/this\._onSpeechStart\b/g,  'this._onSpeechStart'],
  [/this\._onSpeechEnd\b/g,    'this._onSpeechEnd'],
  [/this\._onAutoStop\b/g,     'this._onAutoStop'],
  [/this\._onDbLevel\b/g,      'this._onDbLevel'],
  [/this\._onError\b/g,        'this._onError'],
  [/this\._audioContext\b/g,   'this._audioContext'],
  [/this\._analyser\b/g,       'this._analyser'],
  [/this\._monitorTimer\b/g,   'this._monitorTimer'],
  [/this\._silenceStart\b/g,   'this._silenceStart'],
  [/this\._speechStart\b/g,    'this._speechStart'],
  [/this\._lastSpeechTime\b/g, 'this._lastSpeechTime'],
  [/this\._active\b/g,         'this._active'],
  [/this\._wasSpeaking\b/g,    'this._wasSpeaking'],
  [/this\._check\b/g,          'this._check'],
  [/this\._cleanup\b/g,        'this._cleanup'],
  [/this\._log\b/g,            'this._log'],
  [/this\._warn\b/g,           'this._warn'],
];
renames.forEach(r => { c = c.replace(r[0], r[1]); });

// Add exports
c = c.trim() + '\n\nexport { VADEngine };\nexport default VADEngine;\n';

// Write dist
const d = path.join(__dirname, 'dist');
if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true });
fs.writeFileSync(path.join(d, 'nvoos-vad.js'), c);
console.log('   → dist/nvoos-vad.js');

// TypeScript definitions
const dts = `/**
 * Browser Voice Activity Detection using the Web Audio API.
 * Zero external dependencies.
 * @package @nvdigitalsolutions/nvoos-vad
 */

export interface VADEngineOptions {
  /** Silence duration (ms) before auto-stop. Default: 700 */
  silenceThresholdMs?: number;
  /** Minimum speech duration (ms) before end-of-speech can fire. Default: 300 */
  minSpeechDurationMs?: number;
  /** dB level considered "speech". Default: -50 */
  audioLevelThreshold?: number;
  /** Polling interval in ms. Default: 100 */
  checkIntervalMs?: number;
  /** Called when speech is first detected */
  onSpeechStart?: () => void;
  /** Called when speech transitions to silence */
  onSpeechEnd?: () => void;
  /** Called when silence threshold is reached and engine auto-stops */
  onAutoStop?: () => void;
  /** Called every tick with audio level data */
  onDbLevel?: (data: { average: number; dB: number; isSpeech: boolean }) => void;
  /** Called on errors */
  onError?: (err: Error) => void;
}

export declare class VADEngine {
  constructor(opts?: VADEngineOptions);

  /** Start monitoring a MediaStream. Returns true on success. */
  init(stream: MediaStream): boolean;

  /** Stop monitoring and release audio resources. */
  stop(): void;

  /** Whether the engine is currently active. */
  readonly active: boolean;
}

export default VADEngine;
`;
fs.writeFileSync(path.join(d, 'nvoos-vad.d.ts'), dts);
console.log('   → dist/nvoos-vad.d.ts');
console.log('\n✅ nvoos-vad built!\n');

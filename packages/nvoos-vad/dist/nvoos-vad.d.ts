/**
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

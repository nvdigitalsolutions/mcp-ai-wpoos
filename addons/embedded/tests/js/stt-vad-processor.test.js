/**
 * Tests for Voice Activity Detector (stt-vad-processor.js)
 *
 * Tests cover the VoiceActivityDetector: state machine transitions,
 * energy-based speech detection, adaptive thresholding, callbacks,
 * and boundary conditions.
 *
 * The VAD class is replicated as a pure helper mirroring the
 * implementation in assets/js/stt-vad-processor.js, avoiding
 * dependency on DOM window globals.
 *
 * @package NV_oOS_Embedded
 * @since   1.2.0
 */

// ---------------------------------------------------------------------------
// Pure extraction from assets/js/stt-vad-processor.js
// ---------------------------------------------------------------------------

const VAD_STATE = {
	SILENCE: 'silence',
	SPEECH: 'speech',
	TRANSITION: 'transition',
};

class VoiceActivityDetector {
	/**
	 * @param {Object} options
	 * @param {number} [options.sampleRate=16000]
	 * @param {number} [options.energyThreshold=0.01]
	 * @param {number} [options.silenceDurationMs=800]
	 * @param {number} [options.speechPadMs=200]
	 * @param {number} [options.minSpeechDurationMs=300]
	 */
	constructor( options ) {
		options = options || {};

		this.sampleRate = options.sampleRate || 16000;
		this.energyThreshold = options.energyThreshold || 0.01;
		this.silenceDurationMs = options.silenceDurationMs || 800;
		this.speechPadMs = options.speechPadMs || 200;
		this.minSpeechDurationMs = options.minSpeechDurationMs || 300;

		this.state = VAD_STATE.SILENCE;
		this._lastSpeechSample = 0;
		this._speechSampleCount = 0;
		this._silenceSampleCount = 0;
		this._totalSamples = 0;
		this._noiseFloor = 0.001;

		this.onSpeechStart = null;
		this.onSpeechEnd = null;
		this.onStateChange = null;
	}

	process( samples ) {
		if ( ! samples || samples.length === 0 ) {
			return;
		}

		const energy = this._computeRMS( samples );
		const isSpeech = energy > this.energyThreshold;
		const chunkDurationSamples = samples.length;
		const previousState = this.state;

		this._totalSamples += chunkDurationSamples;

		if ( isSpeech ) {
			this._speechSampleCount += chunkDurationSamples;
			this._silenceSampleCount = 0;
			this._noiseFloor = Math.max( this._noiseFloor, energy * 0.1 );

			if ( this.state === VAD_STATE.SILENCE ) {
				this.state = VAD_STATE.TRANSITION;
			}
		} else {
			this._silenceSampleCount += chunkDurationSamples;
			this._noiseFloor = 0.9 * this._noiseFloor + 0.1 * energy;

			if ( this.state === VAD_STATE.SPEECH || this.state === VAD_STATE.TRANSITION ) {
				const silenceMs =
					( this._silenceSampleCount / this.sampleRate ) * 1000;
				if ( silenceMs >= this.silenceDurationMs ) {
					this.state = VAD_STATE.SILENCE;
				}
			}
		}

		// Check if we should transition from TRANSITION to SPEECH.
		if ( this.state === VAD_STATE.TRANSITION ) {
			const speechMs =
				( this._speechSampleCount / this.sampleRate ) * 1000;
			if ( speechMs >= this.minSpeechDurationMs ) {
				this.state = VAD_STATE.SPEECH;
			}
		}

		// Fire callbacks.
		if ( this.state !== previousState ) {
			if ( typeof this.onStateChange === 'function' ) {
				this.onStateChange( this.state );
			}

			if (
				this.state === VAD_STATE.SPEECH &&
				typeof this.onSpeechStart === 'function'
			) {
				this.onSpeechStart();
			} else if (
				this.state === VAD_STATE.SILENCE &&
				previousState !== VAD_STATE.SILENCE
			) {
				if ( typeof this.onSpeechEnd === 'function' ) {
					this.onSpeechEnd();
				}
				this._speechSampleCount = 0;
			}
		}
	}

	reset() {
		this.state = VAD_STATE.SILENCE;
		this._speechSampleCount = 0;
		this._silenceSampleCount = 0;
		this._totalSamples = 0;
	}

	get isSpeech() {
		return (
			this.state === VAD_STATE.SPEECH ||
			this.state === VAD_STATE.TRANSITION
		);
	}

	get currentThreshold() {
		return Math.max( this.energyThreshold, this._noiseFloor * 3 );
	}

	_computeRMS( samples ) {
		let sum = 0;
		for ( let i = 0; i < samples.length; i++ ) {
			sum += samples[ i ] * samples[ i ];
		}
		return Math.sqrt( sum / samples.length );
	}
}

// Static state constants (mirrors source).
VoiceActivityDetector.SILENCE = VAD_STATE.SILENCE;
VoiceActivityDetector.SPEECH = VAD_STATE.SPEECH;
VoiceActivityDetector.TRANSITION = VAD_STATE.TRANSITION;

// ---------------------------------------------------------------------------
// Helpers – generate Float32Array test signals
// ---------------------------------------------------------------------------

/**
 * Create a Float32Array of silence (near-zero samples).
 *
 * @param {number} numSamples Number of samples.
 * @return {Float32Array}
 */
function makeSilence( numSamples ) {
	return new Float32Array( numSamples ).fill( 0.001 );
}

/**
 * Create a Float32Array of loud speech (max amplitude).
 *
 * @param {number} numSamples Number of samples.
 * @return {Float32Array}
 */
function makeSpeech( numSamples ) {
	const arr = new Float32Array( numSamples );
	for ( let i = 0; i < numSamples; i++ ) {
		arr[ i ] = 1.0;
	}
	return arr;
}

/**
 * Convert milliseconds to sample count at a given sample rate.
 *
 * @param {number} ms         Duration in milliseconds.
 * @param {number} sampleRate Sample rate in Hz.
 * @return {number} Number of samples.
 */
function msToSamples( ms, sampleRate ) {
	return Math.ceil( ( ms / 1000 ) * sampleRate );
}

// ---------------------------------------------------------------------------
// Tests – VoiceActivityDetector constructor
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – constructor', () => {
	it( 'sets default values when no options are provided', () => {
		const vad = new VoiceActivityDetector();

		expect( vad.sampleRate ).toBe( 16000 );
		expect( vad.energyThreshold ).toBe( 0.01 );
		expect( vad.silenceDurationMs ).toBe( 800 );
		expect( vad.speechPadMs ).toBe( 200 );
		expect( vad.minSpeechDurationMs ).toBe( 300 );
	} );

	it( 'sets default values when an empty options object is provided', () => {
		const vad = new VoiceActivityDetector( {} );

		expect( vad.sampleRate ).toBe( 16000 );
		expect( vad.energyThreshold ).toBe( 0.01 );
	} );

	it( 'accepts overridden options', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: 8000,
			energyThreshold: 0.05,
			silenceDurationMs: 1500,
			speechPadMs: 400,
			minSpeechDurationMs: 500,
		} );

		expect( vad.sampleRate ).toBe( 8000 );
		expect( vad.energyThreshold ).toBe( 0.05 );
		expect( vad.silenceDurationMs ).toBe( 1500 );
		expect( vad.speechPadMs ).toBe( 400 );
		expect( vad.minSpeechDurationMs ).toBe( 500 );
	} );

	it( 'handles null/undefined options gracefully', () => {
		const vadNull = new VoiceActivityDetector( null );
		expect( vadNull.sampleRate ).toBe( 16000 );

		const vadUndef = new VoiceActivityDetector( undefined );
		expect( vadUndef.energyThreshold ).toBe( 0.01 );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – initial state
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – initial state', () => {
	let vad;

	beforeEach( () => {
		vad = new VoiceActivityDetector();
	} );

	it( 'initial state is SILENCE', () => {
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
		expect( vad.state ).toBe( VoiceActivityDetector.SILENCE );
	} );

	it( 'isSpeech is false when in SILENCE', () => {
		expect( vad.isSpeech ).toBe( false );
	} );

	it( 'callbacks default to null', () => {
		expect( vad.onSpeechStart ).toBeNull();
		expect( vad.onSpeechEnd ).toBeNull();
		expect( vad.onStateChange ).toBeNull();
	} );

	it( 'currentThreshold starts at the configured energyThreshold', () => {
		// noiseFloor starts at 0.001, so noiseFloor*3 = 0.003
		// energyThreshold = 0.01, which is higher.
		expect( vad.currentThreshold ).toBe( 0.01 );
	} );

	it( 'static state constants are accessible on the class', () => {
		expect( VoiceActivityDetector.SILENCE ).toBe( 'silence' );
		expect( VoiceActivityDetector.SPEECH ).toBe( 'speech' );
		expect( VoiceActivityDetector.TRANSITION ).toBe( 'transition' );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – silence processing
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – silence processing', () => {
	it( 'stays in SILENCE when processing near-zero audio', () => {
		const vad = new VoiceActivityDetector();
		const silenceChunk = makeSilence( 512 );

		vad.process( silenceChunk );
		vad.process( silenceChunk );
		vad.process( silenceChunk );

		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'stays in SILENCE when processing all-zero audio', () => {
		const vad = new VoiceActivityDetector();
		const zeros = new Float32Array( 1024 ); // all 0.0

		vad.process( zeros );

		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'is a no-op when given null or an empty buffer', () => {
		const vad = new VoiceActivityDetector();

		vad.process( null );
		expect( vad.state ).toBe( VAD_STATE.SILENCE );

		vad.process( new Float32Array( 0 ) );
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'does not fire callbacks when staying in SILENCE', () => {
		const vad = new VoiceActivityDetector();
		const onSpeechStart = jest.fn();
		const onSpeechEnd = jest.fn();
		const onStateChange = jest.fn();

		vad.onSpeechStart = onSpeechStart;
		vad.onSpeechEnd = onSpeechEnd;
		vad.onStateChange = onStateChange;

		vad.process( makeSilence( 512 ) );
		vad.process( makeSilence( 512 ) );

		expect( onSpeechStart ).not.toHaveBeenCalled();
		expect( onSpeechEnd ).not.toHaveBeenCalled();
		expect( onStateChange ).not.toHaveBeenCalled();
	} );
} );

// ---------------------------------------------------------------------------
// Tests – speech detection (SILENCE → TRANSITION → SPEECH)
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – speech detection', () => {
	const SAMPLE_RATE = 16000;

	it( 'transitions from SILENCE to TRANSITION on loud audio', () => {
		const vad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );
		const loudChunk = makeSpeech( 256 );

		vad.process( loudChunk );

		expect( vad.state ).toBe( VAD_STATE.TRANSITION );
	} );

	it( 'transitions from TRANSITION to SPEECH after minSpeechDurationMs of loud audio', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
		} );

		// Need ≥ 300 ms of speech = ≥ 4800 samples.
		const samplesNeeded = msToSamples( 300, SAMPLE_RATE );

		// Feed a single large chunk so we hit SPEECH in one call.
		const loudChunk = makeSpeech( samplesNeeded );
		vad.process( loudChunk );

		expect( vad.state ).toBe( VAD_STATE.SPEECH );
	} );

	it( 'stays in TRANSITION while speech is active but below minSpeechDurationMs', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
		} );

		// Feed 100 ms of speech — not enough to trigger SPEECH.
		const shortChunk = makeSpeech( msToSamples( 100, SAMPLE_RATE ) );
		vad.process( shortChunk );

		expect( vad.state ).toBe( VAD_STATE.TRANSITION );
	} );

	it( 'accumulates speech samples across multiple chunks', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
		} );

		// Feed three 150 ms chunks — total 450 ms > 300 ms.
		const chunkSize = msToSamples( 150, SAMPLE_RATE );
		vad.process( makeSpeech( chunkSize ) );
		expect( vad.state ).toBe( VAD_STATE.TRANSITION );

		vad.process( makeSpeech( chunkSize ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		vad.process( makeSpeech( chunkSize ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – silence after speech (SPEECH → SILENCE)
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – return to silence', () => {
	const SAMPLE_RATE = 16000;

	let vad;

	beforeEach( () => {
		vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
			silenceDurationMs: 800,
		} );

		// First, put the VAD into SPEECH state.
		vad.process( makeSpeech( msToSamples( 500, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );
	} );

	it( 'transitions back to SILENCE after sustained silence', () => {
		// Need ≥ 800 ms of silence.
		const silenceNeeded = msToSamples( 800, SAMPLE_RATE );

		vad.process( makeSilence( silenceNeeded ) );

		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'stays in SPEECH during short silence pockets', () => {
		// 200 ms of silence is less than silenceDurationMs (800).
		const shortSilence = msToSamples( 200, SAMPLE_RATE );

		vad.process( makeSilence( shortSilence ) );

		expect( vad.state ).toBe( VAD_STATE.SPEECH );
	} );

	it( 'accumulates silence across multiple chunks before returning to SILENCE', () => {
		// Feed four 300 ms silence chunks = 1200 ms > 800 ms threshold.
		const chunkSize = msToSamples( 300, SAMPLE_RATE );

		vad.process( makeSilence( chunkSize ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		vad.process( makeSilence( chunkSize ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		vad.process( makeSilence( chunkSize ) );
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'resets silence counter when speech resumes mid-silence', () => {
		// Feed 500 ms silence (not enough to trigger SILENCE).
		vad.process( makeSilence( msToSamples( 500, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		// Interrupt with speech — silence counter resets to 0.
		vad.process( makeSpeech( msToSamples( 100, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		// Now 500 ms silence again — still not enough from the reset point.
		vad.process( makeSilence( msToSamples( 500, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		// Another 500 ms = 1000 ms total since speech interruption.
		vad.process( makeSilence( msToSamples( 500, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – callbacks
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – callbacks', () => {
	const SAMPLE_RATE = 16000;

	let vad;
	let onSpeechStart;
	let onSpeechEnd;
	let onStateChange;

	beforeEach( () => {
		vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
			silenceDurationMs: 800,
		} );

		onSpeechStart = jest.fn();
		onSpeechEnd = jest.fn();
		onStateChange = jest.fn();

		vad.onSpeechStart = onSpeechStart;
		vad.onSpeechEnd = onSpeechEnd;
		vad.onStateChange = onStateChange;
	} );

	it( 'fires onSpeechStart when entering SPEECH state', () => {
		// Transition through SILENCE → TRANSITION → SPEECH.
		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );

		expect( onSpeechStart ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does NOT fire onSpeechStart during TRANSITION', () => {
		vad.process( makeSpeech( msToSamples( 100, SAMPLE_RATE ) ) );

		expect( onSpeechStart ).not.toHaveBeenCalled();
	} );

	it( 'fires onSpeechEnd when returning to SILENCE from SPEECH', () => {
		// Enter SPEECH.
		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
		// Return to SILENCE.
		vad.process( makeSilence( msToSamples( 1000, SAMPLE_RATE ) ) );

		expect( onSpeechEnd ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fires onSpeechEnd when returning to SILENCE from TRANSITION', () => {
		// Brief loud audio (not enough for SPEECH), then silence.
		vad.process( makeSpeech( msToSamples( 100, SAMPLE_RATE ) ) );
		vad.process( makeSilence( msToSamples( 1000, SAMPLE_RATE ) ) );

		expect( vad.state ).toBe( VAD_STATE.SILENCE );
		expect( onSpeechEnd ).toHaveBeenCalledTimes( 1 );
		// onSpeechStart should never have fired.
		expect( onSpeechStart ).not.toHaveBeenCalled();
	} );

	it( 'fires onStateChange on every state transition', () => {
		// When both transitions happen in a single process() call
		// (large enough chunk), onStateChange fires once with the
		// final state, not per internal assignment.
		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
		expect( onStateChange ).toHaveBeenCalledTimes( 1 );
		expect( onStateChange ).toHaveBeenCalledWith(
			VAD_STATE.SPEECH
		);
	} );

	it( 'fires onStateChange for each transition across separate chunks', () => {
		const vad2 = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
			silenceDurationMs: 800,
		} );
		const cb = jest.fn();
		vad2.onStateChange = cb;

		// First chunk: 100 ms — SILENCE → TRANSITION.
		vad2.process( makeSpeech( msToSamples( 100, SAMPLE_RATE ) ) );
		expect( cb ).toHaveBeenCalledTimes( 1 );
		expect( cb ).toHaveBeenNthCalledWith( 1, VAD_STATE.TRANSITION );

		// Second chunk: 300 ms — TRANSITION → SPEECH (accumulated 400ms ≥ 300ms).
		vad2.process( makeSpeech( msToSamples( 300, SAMPLE_RATE ) ) );
		expect( cb ).toHaveBeenCalledTimes( 2 );
		expect( cb ).toHaveBeenNthCalledWith( 2, VAD_STATE.SPEECH );
	} );

	it( 'onStateChange receives the new state string', () => {
		vad.process( makeSpeech( msToSamples( 50, SAMPLE_RATE ) ) );

		expect( onStateChange ).toHaveBeenCalledWith( VAD_STATE.TRANSITION );
	} );

	it( 'does not fire callbacks when state does not change', () => {
		// Stay in SILENCE.
		vad.process( makeSilence( 512 ) );
		vad.process( makeSilence( 512 ) );

		expect( onSpeechStart ).not.toHaveBeenCalled();
		expect( onSpeechEnd ).not.toHaveBeenCalled();
		expect( onStateChange ).not.toHaveBeenCalled();
	} );

	it( 'handles null callbacks without throwing', () => {
		// Default callbacks are null.
		const simpleVad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );

		expect( () => {
			simpleVad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
			simpleVad.process( makeSilence( msToSamples( 1000, SAMPLE_RATE ) ) );
		} ).not.toThrow();
	} );
} );

// ---------------------------------------------------------------------------
// Tests – reset()
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – reset()', () => {
	const SAMPLE_RATE = 16000;

	it( 'returns state to SILENCE from SPEECH', () => {
		const vad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );

		// Enter SPEECH.
		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		vad.reset();
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'returns state to SILENCE from TRANSITION', () => {
		const vad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );

		vad.process( makeSpeech( msToSamples( 100, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.TRANSITION );

		vad.reset();
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'resets internal counters to zero after reset', () => {
		const vad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );

		// Process some audio to accumulate counts.
		vad.process( makeSpeech( msToSamples( 500, SAMPLE_RATE ) ) );
		vad.process( makeSilence( msToSamples( 200, SAMPLE_RATE ) ) );

		vad.reset();

		expect( vad._speechSampleCount ).toBe( 0 );
		expect( vad._silenceSampleCount ).toBe( 0 );
		expect( vad._totalSamples ).toBe( 0 );
	} );

	it( 'reset is a no-op when already in SILENCE', () => {
		const vad = new VoiceActivityDetector();

		vad.reset();

		expect( vad.state ).toBe( VAD_STATE.SILENCE );
		expect( vad._speechSampleCount ).toBe( 0 );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – isSpeech property
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – isSpeech property', () => {
	const SAMPLE_RATE = 16000;

	it( 'is false when state is SILENCE', () => {
		const vad = new VoiceActivityDetector();
		expect( vad.isSpeech ).toBe( false );
	} );

	it( 'is true when state is SPEECH', () => {
		const vad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );

		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );
		expect( vad.isSpeech ).toBe( true );
	} );

	it( 'is true when state is TRANSITION', () => {
		const vad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );

		vad.process( makeSpeech( msToSamples( 100, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.TRANSITION );
		expect( vad.isSpeech ).toBe( true );
	} );

	it( 'returns false after reset()', () => {
		const vad = new VoiceActivityDetector( { sampleRate: SAMPLE_RATE } );

		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
		expect( vad.isSpeech ).toBe( true );

		vad.reset();
		expect( vad.isSpeech ).toBe( false );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – currentThreshold (adaptive thresholding)
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – adaptive threshold', () => {
	const SAMPLE_RATE = 16000;

	it( 'starts at the configured energyThreshold when noise floor is low', () => {
		const vad = new VoiceActivityDetector( { energyThreshold: 0.05 } );

		// noiseFloor = 0.001 → noiseFloor*3 = 0.003. energyThreshold = 0.05 wins.
		expect( vad.currentThreshold ).toBe( 0.05 );
	} );

	it( 'increases after processing loud audio', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			energyThreshold: 0.01,
		} );

		// Feed loud audio (RMS ≈ 1.0).
		// noiseFloor becomes max(0.001, 1.0 * 0.1) = 0.1.
		// currentThreshold = max(0.01, 0.1 * 3) = 0.3.
		vad.process( makeSpeech( 512 ) );

		expect( vad.currentThreshold ).toBeGreaterThan( 0.01 );
	} );

	it( 'gradually decays after silence (exponential moving average)', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			energyThreshold: 0.01,
		} );

		// Saturate noise floor with loud audio.
		vad.process( makeSpeech( 512 ) );
		const thresholdAfterLoud = vad.currentThreshold;

		// Feed sustained silence (RMS ≈ 0.001 for makeSilence).
		// noiseFloor = 0.9 * prevNoiseFloor + 0.1 * silenceEnergy
		vad.process( makeSilence( msToSamples( 2000, SAMPLE_RATE ) ) );
		const thresholdAfterSilence = vad.currentThreshold;

		expect( thresholdAfterSilence ).toBeLessThan( thresholdAfterLoud );
	} );

	it( 'is never lower than the configured energyThreshold', () => {
		const vad = new VoiceActivityDetector( {
			energyThreshold: 0.02,
			sampleRate: SAMPLE_RATE,
		} );

		// Even starting with low noise floor, threshold should be at least 0.02.
		expect( vad.currentThreshold ).toBeGreaterThanOrEqual( 0.02 );

		// After processing silence, threshold still ≥ energyThreshold.
		vad.process( makeSilence( 1024 ) );
		expect( vad.currentThreshold ).toBeGreaterThanOrEqual( 0.02 );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – edge cases
// ---------------------------------------------------------------------------

describe( 'VoiceActivityDetector – edge cases', () => {
	const SAMPLE_RATE = 16000;

	it( 'very brief loud audio stays in TRANSITION, never reaches SPEECH', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
			silenceDurationMs: 800,
		} );

		const onSpeechStart = jest.fn();
		const onSpeechEnd = jest.fn();
		const onStateChange = jest.fn();

		vad.onSpeechStart = onSpeechStart;
		vad.onSpeechEnd = onSpeechEnd;
		vad.onStateChange = onStateChange;

		// Brief loud burst — 100 ms, below minSpeechDurationMs.
		vad.process( makeSpeech( msToSamples( 100, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.TRANSITION );

		// Then sustained silence — ≥ 800 ms.
		vad.process( makeSilence( msToSamples( 1000, SAMPLE_RATE ) ) );

		// Should go TRANSITION → SILENCE, never SPEECH.
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
		expect( onSpeechStart ).not.toHaveBeenCalled();
		expect( onSpeechEnd ).toHaveBeenCalledTimes( 1 );
		expect( onStateChange ).toHaveBeenCalledTimes( 2 );
		// First call: SILENCE → TRANSITION.
		expect( onStateChange ).toHaveBeenNthCalledWith(
			1,
			VAD_STATE.TRANSITION
		);
		// Second call: TRANSITION → SILENCE.
		expect( onStateChange ).toHaveBeenNthCalledWith(
			2,
			VAD_STATE.SILENCE
		);
	} );

	it( 'handles alternating speech and silence correctly', () => {
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE,
			minSpeechDurationMs: 300,
			silenceDurationMs: 800,
		} );

		// First utterance.
		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		// Pause.
		vad.process( makeSilence( msToSamples( 1000, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SILENCE );

		// Second utterance.
		vad.process( makeSpeech( msToSamples( 400, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		// Final pause.
		vad.process( makeSilence( msToSamples( 1000, SAMPLE_RATE ) ) );
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'works with non-default sampleRate', () => {
		const SAMPLE_RATE_8K = 8000;
		const vad = new VoiceActivityDetector( {
			sampleRate: SAMPLE_RATE_8K,
			minSpeechDurationMs: 500,
			silenceDurationMs: 1000,
		} );

		// Need 500 ms of speech at 8 kHz = 4000 samples.
		const speechNeeded = msToSamples( 500, SAMPLE_RATE_8K );
		vad.process( makeSpeech( speechNeeded ) );
		expect( vad.state ).toBe( VAD_STATE.SPEECH );

		// Need 1000 ms of silence at 8 kHz = 8000 samples.
		const silenceNeeded = msToSamples( 1000, SAMPLE_RATE_8K );
		vad.process( makeSilence( silenceNeeded ) );
		expect( vad.state ).toBe( VAD_STATE.SILENCE );
	} );

	it( 'process() with a large buffer does not throw', () => {
		const vad = new VoiceActivityDetector();
		const hugeChunk = makeSpeech( 160000 ); // 10 seconds at 16 kHz.

		expect( () => vad.process( hugeChunk ) ).not.toThrow();
	} );
} );

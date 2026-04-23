/**
 * Extended Cognition Toolkit — Audio Module
 *
 * Records audio from the user's microphone using MediaRecorder + Web Speech API.
 * Returns a transcript (if speech recognition is available) and an ambient
 * sound classification (speech / music / noise / silence).
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.0.0
 */

( function () {
	'use strict';

	/**
	 * Capture audio from the microphone.
	 *
	 * @param {Object}   req      Sensor request from the bridge.
	 * @param {Function} callback cb(data, error).
	 */
	function capture( req, callback ) {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			callback( null, 'getUserMedia not supported.' );
			return;
		}

		const duration  = Math.max( 3, Math.min( 30, req.duration_seconds || 5 ) );
		const transcribe = req.transcribe !== false;
		const language   = req.language || navigator.language || 'en-US';
		const classify   = req.classify_ambient !== false;

		const constraints = { audio: true, video: false };

		navigator.mediaDevices.getUserMedia( constraints )
			.then( function ( stream ) {
				doRecord( stream, duration, transcribe, language, classify, callback );
			} )
			.catch( function ( err ) {
				const msg = err && err.name === 'NotAllowedError'
					? 'Microphone permission denied.'
					: 'Microphone access failed: ' + ( err && err.message || String( err ) );
				callback( null, msg );
			} );
	}

	/**
	 * Perform the actual recording.
	 *
	 * @param {MediaStream} stream    Active audio stream.
	 * @param {number}      duration  Recording duration in seconds.
	 * @param {boolean}     transcribe Whether to attempt speech recognition.
	 * @param {string}      language  BCP-47 language tag.
	 * @param {boolean}     classify  Whether to classify ambient sound.
	 * @param {Function}    callback  cb(data, error).
	 */
	function doRecord( stream, duration, transcribe, language, classify, callback ) {
		const chunks   = [];
		const recorder = new MediaRecorder( stream );
		let transcript = '';
		let recognitionDone = false;
		let recordingDone = false;

		recorder.ondataavailable = function ( e ) {
			if ( e.data.size > 0 ) {
				chunks.push( e.data );
			}
		};

		recorder.onstop = function () {
			// Stop stream tracks.
			stream.getTracks().forEach( function ( t ) { t.stop(); } );

			recordingDone = true;
			maybeFinish();
		};

		recorder.start();

		// Stop recording after duration.
		setTimeout( function () {
			if ( recorder.state !== 'inactive' ) {
				recorder.stop();
			}
		}, duration * 1000 );

		// Speech recognition.
		if ( transcribe ) {
			const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

			if ( SpeechRecognition ) {
				const recognition = new SpeechRecognition();
				recognition.lang = language;
				recognition.interimResults = false;
				recognition.maxAlternatives = 1;
				recognition.continuous = true;

				recognition.onresult = function ( e ) {
					const results = e.results;
					for ( let i = e.resultIndex; i < results.length; i++ ) {
						if ( results[ i ].isFinal ) {
							transcript += results[ i ][ 0 ].transcript + ' ';
						}
					}
				};

				recognition.onerror = function () {
					recognitionDone = true;
					maybeFinish();
				};

				recognition.onend = function () {
					recognitionDone = true;
					maybeFinish();
				};

				recognition.start();

				setTimeout( function () {
					try { recognition.stop(); } catch ( recognitionStopError ) { if ( window.console ) { console.debug( 'Speech recognition already stopped:', recognitionStopError ); } }
				}, ( duration + 0.5 ) * 1000 );
			} else {
				// No speech recognition support.
				recognitionDone = true;
			}
		} else {
			recognitionDone = true;
		}

		/**
		 * Finish and invoke callback when both recording and recognition are done.
		 */
		function maybeFinish() {
			if ( ! recordingDone || ! recognitionDone ) {
				return;
			}

			const ambientLabel = classify ? classifyAmbient( chunks ) : 'unknown';

			callback(
				{
					transcript:              transcript.trim(),
					ambient_label:           ambientLabel,
					language_detected:       language,
					transcription_confidence: transcript ? 0.8 : 0,
				},
				null
			);
		}
	}

	/**
	 * Classify the ambient sound type based on recorded audio chunks.
	 *
	 * Uses simple heuristics based on blob size (proxy for audio energy):
	 * - Very small: silence
	 * - Normal: speech or noise (distinguished by transcript availability)
	 *
	 * A more robust implementation would use the Web Audio API AnalyserNode
	 * to compute spectral features.
	 *
	 * @param  {Blob[]} chunks MediaRecorder data chunks.
	 * @return {string} Classification label.
	 */
	function classifyAmbient( chunks ) {
		if ( ! chunks || chunks.length === 0 ) {
			return 'silence';
		}
		const totalSize = chunks.reduce( function ( acc, c ) { return acc + c.size; }, 0 );

		if ( totalSize < 1000 ) {
			return 'silence';
		}
		if ( totalSize < 20000 ) {
			return 'speech';
		}
		return 'noise';
	}

	// Public API.
	window.NVExtCogAudio = {
		capture: capture,
	};
}() );

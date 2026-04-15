/**
 * Extended Cognition Toolkit — Motion Module
 *
 * Reads device orientation (gyroscope) and motion (accelerometer) data
 * using DeviceOrientationEvent and DeviceMotionEvent APIs.
 * Returns averaged samples plus a device-class and activity inference.
 *
 * @package NV_oOS_Ext_Cognition
 * @since   1.0.0
 */

( function () {
	'use strict';

	/**
	 * Capture motion context samples.
	 *
	 * @param {Object}   req      Sensor request from the bridge.
	 * @param {Function} callback cb(data, error).
	 */
	function capture( req, callback ) {
		const sampleCount = Math.max( 1, Math.min( 20, req.sample_count || 5 ) );
		const samples = { orientation: [], motion: [] };

		const isMobile = /Mobi|Android|iPhone|iPad/i.test( navigator.userAgent );
		const deviceClass = isMobile ? ( /iPad|Tablet/i.test( navigator.userAgent ) ? 'tablet' : 'mobile' ) : 'desktop';

		// On desktop, neither event is typically supported.
		if ( ! window.DeviceOrientationEvent && ! window.DeviceMotionEvent ) {
			callback(
				{
					is_mobile:          isMobile,
					device_class:       deviceClass,
					alpha:              null,
					beta:               null,
					gamma:              null,
					absolute:           false,
					accel_x:            null,
					accel_y:            null,
					accel_z:            null,
					rot_alpha:          null,
					rot_beta:           null,
					rot_gamma:          null,
					activity_inference: 'stationary',
				},
				null
			);
			return;
		}

		// iOS 13+ requires permission request.
		if ( typeof DeviceOrientationEvent !== 'undefined'
			&& typeof DeviceOrientationEvent.requestPermission === 'function' ) {
			DeviceOrientationEvent.requestPermission()
				.then( function ( state ) {
					if ( state === 'granted' ) {
						collectSamples( sampleCount, isMobile, deviceClass, samples, callback );
					} else {
						callback( null, 'Device orientation permission denied on iOS.' );
					}
				} )
				.catch( function ( err ) {
					callback( null, 'Permission request failed: ' + err.message );
				} );
		} else {
			collectSamples( sampleCount, isMobile, deviceClass, samples, callback );
		}
	}

	/**
	 * Collect orientation and motion samples.
	 *
	 * @param {number}   count       Number of samples to collect.
	 * @param {boolean}  isMobile    Device is mobile.
	 * @param {string}   deviceClass Device class label.
	 * @param {Object}   samples     Sample accumulator.
	 * @param {Function} callback    cb(data, error).
	 */
	function collectSamples( count, isMobile, deviceClass, samples, callback ) {
		let orientationCollected = 0;
		let motionCollected = 0;
		let done = false;

		function finish() {
			if ( done ) {
				return;
			}
			done = true;

			window.removeEventListener( 'deviceorientation', onOrientation );
			window.removeEventListener( 'devicemotion', onMotion );

			const result = buildResult( samples, isMobile, deviceClass );
			callback( result, null );
		}

		function onOrientation( e ) {
			if ( orientationCollected >= count ) {
				return;
			}
			samples.orientation.push( {
				alpha:    e.alpha,
				beta:     e.beta,
				gamma:    e.gamma,
				absolute: !! e.absolute,
			} );
			orientationCollected++;
			if ( orientationCollected >= count && motionCollected >= count ) {
				finish();
			}
		}

		function onMotion( e ) {
			if ( motionCollected >= count ) {
				return;
			}
			const a = e.acceleration || {};
			const r = e.rotationRate || {};
			samples.motion.push( {
				ax: a.x || 0,
				ay: a.y || 0,
				az: a.z || 0,
				rAlpha: r.alpha || 0,
				rBeta:  r.beta  || 0,
				rGamma: r.gamma || 0,
			} );
			motionCollected++;
			if ( motionCollected >= count && orientationCollected >= count ) {
				finish();
			}
		}

		window.addEventListener( 'deviceorientation', onOrientation );
		window.addEventListener( 'devicemotion', onMotion );

		// Timeout safety: finish after 4 seconds regardless.
		setTimeout( finish, 4000 );
	}

	/**
	 * Build averaged result from collected samples.
	 *
	 * @param  {Object}  samples     Collected sample arrays.
	 * @param  {boolean} isMobile    Is mobile device.
	 * @param  {string}  deviceClass Device class.
	 * @return {Object}
	 */
	function buildResult( samples, isMobile, deviceClass ) {
		const ori = avgOrientation( samples.orientation );
		const mot = avgMotion( samples.motion );
		const activity = inferActivity( ori, mot );

		return {
			is_mobile:          isMobile,
			device_class:       deviceClass,
			alpha:              ori.alpha,
			beta:               ori.beta,
			gamma:              ori.gamma,
			absolute:           ori.absolute,
			accel_x:            mot.ax,
			accel_y:            mot.ay,
			accel_z:            mot.az,
			rot_alpha:          mot.rAlpha,
			rot_beta:           mot.rBeta,
			rot_gamma:          mot.rGamma,
			activity_inference: activity,
		};
	}

	/**
	 * Average orientation samples.
	 *
	 * @param  {Object[]} samples Orientation sample array.
	 * @return {Object}
	 */
	function avgOrientation( samples ) {
		if ( ! samples || samples.length === 0 ) {
			return { alpha: null, beta: null, gamma: null, absolute: false };
		}
		const n = samples.length;
		const sums = { alpha: 0, beta: 0, gamma: 0 };
		samples.forEach( function ( s ) {
			sums.alpha += ( s.alpha || 0 );
			sums.beta  += ( s.beta  || 0 );
			sums.gamma += ( s.gamma || 0 );
		} );
		return {
			alpha:    sums.alpha / n,
			beta:     sums.beta  / n,
			gamma:    sums.gamma / n,
			absolute: samples[ samples.length - 1 ].absolute,
		};
	}

	/**
	 * Average motion samples.
	 *
	 * @param  {Object[]} samples Motion sample array.
	 * @return {Object}
	 */
	function avgMotion( samples ) {
		if ( ! samples || samples.length === 0 ) {
			return { ax: null, ay: null, az: null, rAlpha: null, rBeta: null, rGamma: null };
		}
		const n = samples.length;
		const sums = { ax: 0, ay: 0, az: 0, rAlpha: 0, rBeta: 0, rGamma: 0 };
		samples.forEach( function ( s ) {
			sums.ax     += s.ax;
			sums.ay     += s.ay;
			sums.az     += s.az;
			sums.rAlpha += s.rAlpha;
			sums.rBeta  += s.rBeta;
			sums.rGamma += s.rGamma;
		} );
		return {
			ax:     sums.ax     / n,
			ay:     sums.ay     / n,
			az:     sums.az     / n,
			rAlpha: sums.rAlpha / n,
			rBeta:  sums.rBeta  / n,
			rGamma: sums.rGamma / n,
		};
	}

	/**
	 * Infer activity from orientation and motion averages.
	 *
	 * Simple heuristics:
	 * - High acceleration magnitude → walking/moving
	 * - Large beta angle (lying flat) → lying down
	 * - Near-upright beta → standing/sitting
	 *
	 * @param  {Object} ori Averaged orientation.
	 * @param  {Object} mot Averaged motion.
	 * @return {string} Activity label.
	 */
	function inferActivity( ori, mot ) {
		if ( mot.ax === null ) {
			return 'stationary';
		}

		const accelMag = Math.sqrt(
			( mot.ax || 0 ) * ( mot.ax || 0 )
			+ ( mot.ay || 0 ) * ( mot.ay || 0 )
			+ ( mot.az || 0 ) * ( mot.az || 0 )
		);

		if ( accelMag > 5 ) {
			return 'moving';
		}

		const beta = ori.beta || 0;

		if ( Math.abs( beta ) > 70 ) {
			return 'lying_down';
		}

		if ( Math.abs( beta ) > 30 ) {
			return 'tilted';
		}

		return 'stationary';
	}

	// Public API.
	window.NVExtCogMotion = {
		capture: capture,
	};
}() );

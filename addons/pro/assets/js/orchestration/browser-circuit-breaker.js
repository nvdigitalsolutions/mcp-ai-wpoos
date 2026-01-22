/**
 * Browser-Compatible Circuit Breaker
 * 
 * Simplified circuit breaker pattern for browser environments.
 * Based on opossum but without Node.js dependencies.
 * 
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

/**
 * Circuit Breaker States
 */
const STATE = {
	CLOSED: 'CLOSED',
	OPEN: 'OPEN',
	HALF_OPEN: 'HALF_OPEN',
};

/**
 * Browser-Compatible Circuit Breaker Class
 */
class BrowserCircuitBreaker {
	/**
	 * Constructor
	 * 
	 * @param {Function} action The action to wrap
	 * @param {Object} options Configuration options
	 */
	constructor( action, options = {} ) {
		this.action = action;
		this.options = {
			timeout: options.timeout || 30000,
			errorThresholdPercentage: options.errorThresholdPercentage || 50,
			resetTimeout: options.resetTimeout || 30000,
			volumeThreshold: options.volumeThreshold || 5,
			...options,
		};

		this.state = STATE.CLOSED;
		this.failures = 0;
		this.successes = 0;
		this.requests = 0;
		this.openedAt = null;
		this.nextAttempt = null;
		this.listeners = {
			open: [],
			close: [],
			halfOpen: [],
			failure: [],
			success: [],
		};

		this.stats = {
			fires: 0,
			failures: 0,
			successes: 0,
			rejects: 0,
			timeouts: 0,
		};
	}

	/**
	 * Add event listener
	 * 
	 * @param {string} event Event name
	 * @param {Function} callback Callback function
	 */
	on( event, callback ) {
		if ( this.listeners[ event ] ) {
			this.listeners[ event ].push( callback );
		}
	}

	/**
	 * Emit event
	 * 
	 * @param {string} event Event name
	 * @param {*} data Event data
	 */
	emit( event, data ) {
		if ( this.listeners[ event ] ) {
			this.listeners[ event ].forEach( ( callback ) => callback( data ) );
		}
	}

	/**
	 * Execute the wrapped action
	 * 
	 * @param {...*} args Arguments to pass to action
	 * @return {Promise} Result
	 */
	async fire( ...args ) {
		this.stats.fires++;
		this.requests++;

		// Check if circuit is open
		if ( this.state === STATE.OPEN ) {
			// Check if reset timeout has elapsed
			if ( Date.now() < this.nextAttempt ) {
				this.stats.rejects++;
				throw new Error( 'Circuit breaker is open' );
			}

			// Move to half-open state
			this.state = STATE.HALF_OPEN;
			this.emit( 'halfOpen' );
		}

		// Execute with timeout
		try {
			const result = await this.executeWithTimeout( args );
			this.onSuccess();
			return result;
		} catch ( error ) {
			this.onFailure( error );
			throw error;
		}
	}

	/**
	 * Execute action with timeout
	 * 
	 * @param {Array} args Arguments
	 * @return {Promise} Result
	 */
	async executeWithTimeout( args ) {
		return Promise.race( [
			this.action( ...args ),
			new Promise( ( _, reject ) => {
				setTimeout( () => {
					this.stats.timeouts++;
					reject( new Error( 'Circuit breaker timeout' ) );
				}, this.options.timeout );
			} ),
		] );
	}

	/**
	 * Handle successful execution
	 */
	onSuccess() {
		this.stats.successes++;
		this.successes++;
		this.emit( 'success' );

		// If in half-open state, close the circuit
		if ( this.state === STATE.HALF_OPEN ) {
			this.close();
		}
	}

	/**
	 * Handle failed execution
	 * 
	 * @param {Error} error Error object
	 */
	onFailure( error ) {
		this.stats.failures++;
		this.failures++;
		this.emit( 'failure', error );

		// Check if we should open the circuit
		if ( this.requests >= this.options.volumeThreshold ) {
			const errorRate = (this.failures / this.requests) * 100;
			if ( errorRate >= this.options.errorThresholdPercentage ) {
				this.open();
			}
		}

		// If in half-open state, reopen the circuit
		if ( this.state === STATE.HALF_OPEN ) {
			this.open();
		}
	}

	/**
	 * Open the circuit
	 */
	open() {
		if ( this.state !== STATE.OPEN ) {
			this.state = STATE.OPEN;
			this.openedAt = Date.now();
			this.nextAttempt = this.openedAt + this.options.resetTimeout;
			this.emit( 'open' );
		}
	}

	/**
	 * Close the circuit
	 */
	close() {
		if ( this.state !== STATE.CLOSED ) {
			this.state = STATE.CLOSED;
			this.failures = 0;
			this.successes = 0;
			this.requests = 0;
			this.emit( 'close' );
		}
	}

	/**
	 * Shutdown the circuit breaker
	 */
	shutdown() {
		this.listeners = {
			open: [],
			close: [],
			halfOpen: [],
			failure: [],
			success: [],
		};
	}

	/**
	 * Get current state
	 * 
	 * @return {string} Current state
	 */
	get opened() {
		return this.state === STATE.OPEN;
	}

	/**
	 * Get current state
	 * 
	 * @return {string} Current state
	 */
	get closed() {
		return this.state === STATE.CLOSED;
	}

	/**
	 * Get current state
	 * 
	 * @return {string} Current state
	 */
	get halfOpen() {
		return this.state === STATE.HALF_OPEN;
	}
}

export default BrowserCircuitBreaker;

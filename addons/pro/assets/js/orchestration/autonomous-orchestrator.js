/**
 * Autonomous Orchestrator
 * 
 * Manages autonomous task execution loops with circuit breaker pattern
 * and rate limiting using browser-compatible implementations.
 * 
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

import BrowserCircuitBreaker from './browser-circuit-breaker.js';
import PQueue from 'p-queue';

/**
 * Autonomous Orchestrator Class
 * 
 * Coordinates autonomous task execution with resilience patterns:
 * - Circuit breaker for error handling
 * - Rate limiting for API protection
 * - Session lifecycle management
 * - Health monitoring
 */
class AutonomousOrchestrator {
	/**
	 * Constructor
	 * 
	 * @param {Object} config Configuration options
	 * @param {number} config.maxIterations Maximum loop iterations (default: 25)
	 * @param {number} config.tokenBudget Token budget limit (default: 10000)
	 * @param {number} config.rateLimitPerHour Rate limit (default: 100)
	 * @param {number} config.circuitBreakerTimeout Circuit breaker timeout (default: 30000ms)
	 */
	constructor( config = {} ) {
		this.config = {
			maxIterations: config.maxIterations || 25,
			tokenBudget: config.tokenBudget || 10000,
			rateLimitPerHour: config.rateLimitPerHour || 100,
			circuitBreakerTimeout: config.circuitBreakerTimeout || 30000,
			circuitBreakerThreshold: config.circuitBreakerThreshold || 0.5,
			circuitBreakerResetTimeout: config.circuitBreakerResetTimeout || 30000,
		};

		// Initialize rate limiter (p-queue)
		// Convert hourly rate to interval between requests
		const intervalMs = (60 * 60 * 1000) / this.config.rateLimitPerHour;
		this.queue = new PQueue( {
			concurrency: 1,
			interval: intervalMs,
			intervalCap: 1,
		} );

		// Session state
		this.sessions = {};

		// Circuit breaker will be initialized per session
		this.circuitBreakers = {};
	}

	/**
	 * Create a circuit breaker for a session
	 * 
	 * @param {string} sessionId Session ID
	 * @param {Function} fn Function to wrap with circuit breaker
	 * @return {BrowserCircuitBreaker} Circuit breaker instance
	 */
	createCircuitBreaker( sessionId, fn ) {
		const breaker = new BrowserCircuitBreaker( fn, {
			timeout: this.config.circuitBreakerTimeout,
			errorThresholdPercentage: this.config.circuitBreakerThreshold * 100,
			resetTimeout: this.config.circuitBreakerResetTimeout,
		} );

		// Event handlers
		breaker.on( 'open', () => {
			console.warn( `[Orchestrator] Circuit breaker opened for session ${sessionId}` );
			if ( this.sessions[ sessionId ] ) {
				this.sessions[ sessionId ].circuitBreakerOpen = true;
				this.sessions[ sessionId ].health = 'critical';
			}
		} );

		breaker.on( 'halfOpen', () => {
			console.info( `[Orchestrator] Circuit breaker half-open for session ${sessionId}` );
		} );

		breaker.on( 'close', () => {
			console.info( `[Orchestrator] Circuit breaker closed for session ${sessionId}` );
			if ( this.sessions[ sessionId ] ) {
				this.sessions[ sessionId ].circuitBreakerOpen = false;
				this.sessions[ sessionId ].health = 'healthy';
			}
		} );

		breaker.on( 'failure', ( error ) => {
			console.error( `[Orchestrator] Circuit breaker failure for session ${sessionId}:`, error.message );
		} );

		this.circuitBreakers[ sessionId ] = breaker;
		return breaker;
	}

	/**
	 * Start a new autonomous session
	 * 
	 * @param {Object} params Session parameters
	 * @param {string} params.planId Task plan ID
	 * @param {Function} params.executor Function to execute each iteration
	 * @param {Object} params.config Session configuration
	 * @return {Promise<string>} Session ID
	 */
	async startSession( params ) {
		const { planId, executor, config = {} } = params;

		// Generate session ID
		const sessionId = this.generateSessionId();

		// Merge configs
		const sessionConfig = {
			...this.config,
			...config,
		};

		// Initialize session state
		this.sessions[ sessionId ] = {
			id: sessionId,
			planId,
			status: 'active',
			health: 'healthy',
			iterations: 0,
			tokensUsed: 0,
			startTime: Date.now(),
			lastActivity: Date.now(),
			circuitBreakerOpen: false,
			config: sessionConfig,
			history: [],
		};

		// Create circuit breaker
		this.createCircuitBreaker( sessionId, executor );

		console.info( `[Orchestrator] Started session ${sessionId} for plan ${planId}` );

		return sessionId;
	}

	/**
	 * Execute a single iteration with rate limiting and circuit breaker
	 * 
	 * @param {string} sessionId Session ID
	 * @param {*} input Input for the iteration
	 * @return {Promise<Object>} Execution result
	 */
	async executeIteration( sessionId, input ) {
		const session = this.sessions[ sessionId ];
		if ( ! session ) {
			throw new Error( `Session ${sessionId} not found` );
		}

		if ( session.status !== 'active' ) {
			throw new Error( `Session ${sessionId} is not active (status: ${session.status})` );
		}

		// Check limits
		if ( session.iterations >= session.config.maxIterations ) {
			throw new Error( `Max iterations reached (${session.config.maxIterations})` );
		}

		if ( session.tokensUsed >= session.config.tokenBudget ) {
			throw new Error( `Token budget exhausted (${session.config.tokenBudget})` );
		}

		if ( session.circuitBreakerOpen ) {
			throw new Error( 'Circuit breaker is open' );
		}

		// Add to queue with rate limiting
		return this.queue.add( async () => {
			try {
				// Execute through circuit breaker
				const breaker = this.circuitBreakers[ sessionId ];
				const result = await breaker.fire( input );

				// Update session
				session.iterations++;
				session.tokensUsed += result.tokensUsed || 0;
				session.lastActivity = Date.now();
				session.history.push( {
					iteration: session.iterations,
					timestamp: Date.now(),
					success: true,
					result,
				} );

				return {
					success: true,
					iteration: session.iterations,
					result,
					session: this.getSessionStatus( sessionId ),
				};
			} catch ( error ) {
				// Record failure
				session.history.push( {
					iteration: session.iterations + 1,
					timestamp: Date.now(),
					success: false,
					error: error.message,
				} );

				throw error;
			}
		} );
	}

	/**
	 * Pause a session
	 * 
	 * @param {string} sessionId Session ID
	 */
	pauseSession( sessionId ) {
		const session = this.sessions[ sessionId ];
		if ( session ) {
			session.status = 'paused';
			session.lastActivity = Date.now();
			console.info( `[Orchestrator] Paused session ${sessionId}` );
		}
	}

	/**
	 * Resume a session
	 * 
	 * @param {string} sessionId Session ID
	 */
	resumeSession( sessionId ) {
		const session = this.sessions[ sessionId ];
		if ( session && session.status === 'paused' ) {
			session.status = 'active';
			session.lastActivity = Date.now();
			console.info( `[Orchestrator] Resumed session ${sessionId}` );
		}
	}

	/**
	 * Stop a session
	 * 
	 * @param {string} sessionId Session ID
	 * @param {string} reason Stop reason
	 */
	stopSession( sessionId, reason = 'manual' ) {
		const session = this.sessions[ sessionId ];
		if ( session ) {
			session.status = 'completed';
			session.lastActivity = Date.now();
			session.stopReason = reason;

			// Clean up circuit breaker
			if ( this.circuitBreakers[ sessionId ] ) {
				this.circuitBreakers[ sessionId ].shutdown();
				delete this.circuitBreakers[ sessionId ];
			}

			console.info( `[Orchestrator] Stopped session ${sessionId} (reason: ${reason})` );
		}
	}

	/**
	 * Get session status
	 * 
	 * @param {string} sessionId Session ID
	 * @return {Object|null} Session status or null if not found
	 */
	getSessionStatus( sessionId ) {
		const session = this.sessions[ sessionId ];
		if ( ! session ) {
			return null;
		}

		const now = Date.now();
		const elapsed = now - session.startTime;
		const breaker = this.circuitBreakers[ sessionId ];

		return {
			id: session.id,
			planId: session.planId,
			status: session.status,
			health: session.health,
			iterations: session.iterations,
			maxIterations: session.config.maxIterations,
			tokensUsed: session.tokensUsed,
			tokenBudget: session.config.tokenBudget,
			elapsedMs: elapsed,
			circuitBreaker: {
				open: session.circuitBreakerOpen,
				stats: breaker ? breaker.stats : null,
			},
			lastActivity: session.lastActivity,
		};
	}

	/**
	 * Get queue status
	 * 
	 * @return {Object} Queue status
	 */
	getQueueStatus() {
		return {
			size: this.queue.size,
			pending: this.queue.pending,
			isPaused: this.queue.isPaused,
		};
	}

	/**
	 * Generate a unique session ID
	 * 
	 * @return {string} Session ID
	 */
	generateSessionId() {
		return `session_${Date.now()}_${Math.random().toString( 36 ).substr( 2, 9 )}`;
	}

	/**
	 * Clean up expired sessions
	 * 
	 * @param {number} maxAge Maximum age in milliseconds (default: 24 hours)
	 */
	cleanupExpiredSessions( maxAge = 24 * 60 * 60 * 1000 ) {
		const now = Date.now();
		const expired = [];

		Object.keys( this.sessions ).forEach( ( sessionId ) => {
			const session = this.sessions[ sessionId ];
			if ( now - session.lastActivity > maxAge ) {
				expired.push( sessionId );
				this.stopSession( sessionId, 'expired' );
				delete this.sessions[ sessionId ];
			}
		} );

		if ( expired.length > 0 ) {
			console.info( `[Orchestrator] Cleaned up ${expired.length} expired sessions` );
		}
	}
}

// Export for use in WordPress
if ( typeof window !== 'undefined' ) {
	window.WpMcpAiOrchestrator = AutonomousOrchestrator;
}

export default AutonomousOrchestrator;

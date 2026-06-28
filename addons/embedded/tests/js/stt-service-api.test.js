/**
 * Tests for STT Service API and Backend Registry (stt-service-api.js)
 *
 * Tests cover the abstract STTServiceAPI interface contract and the
 * STTBackendRegistry registration / lookup / discovery logic.
 *
 * Classes and registry are replicated as pure helpers mirroring
 * the implementations in assets/js/stt-service-api.js, avoiding
 * dependency on DOM window globals.
 *
 * @package NV_oOS_Embedded
 * @since   1.2.0
 */

// ---------------------------------------------------------------------------
// Pure extraction from assets/js/stt-service-api.js
// ---------------------------------------------------------------------------

/**
 * Abstract STT Backend interface.
 * Mirrors STTServiceAPI from stt-service-api.js exactly.
 */
class STTServiceAPI {
	getSlug() {
		throw new Error( 'STTServiceAPI.getSlug() must be implemented by subclass' );
	}

	getName() {
		throw new Error( 'STTServiceAPI.getName() must be implemented by subclass' );
	}

	async isAvailable() {
		throw new Error( 'STTServiceAPI.isAvailable() must be implemented by subclass' );
	}

	async initialize( options ) {
		throw new Error( 'STTServiceAPI.initialize() must be implemented by subclass' );
	}

	async transcribe( audioData, options ) {
		throw new Error( 'STTServiceAPI.transcribe() must be implemented by subclass' );
	}

	createStream( callbacks, options ) {
		throw new Error( 'STTServiceAPI.createStream() must be implemented by subclass' );
	}

	async destroy() {
		// Default no-op; override if needed.
	}

	getModelSize( model ) {
		return 0;
	}

	getSupportedFormats() {
		return [ { mimeType: 'audio/x-float32', sampleRate: 16000 } ];
	}

	// ── Callback registration ──────────────────────────────────────

	/** @type {Function|null} */
	onProgress = null;

	/** @type {Function|null} */
	onError = null;
}

/**
 * Creates a fresh STTBackendRegistry.
 * Mirrors STTBackendRegistry from stt-service-api.js.
 *
 * Returns a new object on each call so tests do not share state.
 *
 * @return {Object} Fresh registry with no registered backends.
 */
function createBackendRegistry() {
	const _backends = {};

	return {
		/** @type {Object<string, typeof STTServiceAPI>} */
		_backends,

		/**
		 * Register a backend class.
		 *
		 * @param {typeof STTServiceAPI} BackendClass
		 */
		register( BackendClass ) {
			const instance = new BackendClass();
			const slug = instance.getSlug();
			_backends[ slug ] = BackendClass;
		},

		/**
		 * Create a backend instance by slug.
		 *
		 * @param {string} slug Backend slug.
		 * @return {STTServiceAPI|null} Instance or null if not found.
		 */
		create( slug ) {
			const BackendClass = _backends[ slug ];
			if ( ! BackendClass ) {
				return null;
			}
			return new BackendClass();
		},

		/**
		 * Get all registered backend slugs.
		 *
		 * @return {string[]}
		 */
		getSlugs() {
			return Object.keys( _backends );
		},

		/**
		 * Auto-detect the best available backend.
		 *
		 * @return {Promise<string|null>}
		 */
		async detectBest() {
			const slugs = this.getSlugs();
			for ( let i = 0; i < slugs.length; i++ ) {
				try {
					const backend = this.create( slugs[ i ] );
					if ( backend && ( await backend.isAvailable() ) ) {
						return slugs[ i ];
					}
				} catch ( err ) {
					// Swallow and continue to next backend.
				}
			}
			return null;
		},
	};
}

// ---------------------------------------------------------------------------
// Helper – concrete subclass for integration tests
// ---------------------------------------------------------------------------

class MockSTTBackend extends STTServiceAPI {
	getSlug() {
		return 'mock_stt';
	}

	getName() {
		return 'Mock STT Backend';
	}

	async isAvailable() {
		return this._available;
	}

	async initialize( options ) {
		this._initializedWith = options;
		this._initCalled = true;
	}

	async transcribe( audioData, options ) {
		return {
			text: 'mock transcription',
			partial: options && options.partial ? true : false,
			confidence: 0.95,
			language: ( options && options.language ) || 'en',
		};
	}

	createStream( callbacks, options ) {
		return {
			_callbacks: callbacks,
			_options: options,
			push( chunk ) {
				callbacks.onPartialResult( { text: 'partial', confidence: 0.5 } );
			},
			flush() {
				callbacks.onFinalResult( { text: 'final text', confidence: 0.9, language: 'en' } );
			},
			close() {},
		};
	}

	async destroy() {
		this._destroyed = true;
	}

	_available = true;
	_initializedWith = null;
	_initCalled = false;
	_destroyed = false;
}

// ---------------------------------------------------------------------------
// Tests – STTServiceAPI (abstract interface)
// ---------------------------------------------------------------------------

describe( 'STTServiceAPI – abstract interface', () => {
	let api;

	beforeEach( () => {
		api = new STTServiceAPI();
	} );

	describe( 'abstract methods that MUST be overridden', () => {
		it( 'getSlug() throws an error', () => {
			expect( () => api.getSlug() ).toThrow(
				'STTServiceAPI.getSlug() must be implemented by subclass'
			);
		} );

		it( 'getName() throws an error', () => {
			expect( () => api.getName() ).toThrow(
				'STTServiceAPI.getName() must be implemented by subclass'
			);
		} );

		it( 'isAvailable() throws an error', async () => {
			await expect( api.isAvailable() ).rejects.toThrow(
				'STTServiceAPI.isAvailable() must be implemented by subclass'
			);
		} );

		it( 'initialize() throws an error', async () => {
			await expect( api.initialize( {} ) ).rejects.toThrow(
				'STTServiceAPI.initialize() must be implemented by subclass'
			);
		} );

		it( 'transcribe() throws an error', async () => {
			await expect( api.transcribe( new Float32Array( [ 0 ] ), {} ) ).rejects.toThrow(
				'STTServiceAPI.transcribe() must be implemented by subclass'
			);
		} );

		it( 'createStream() throws an error', () => {
			expect( () => api.createStream( {} ) ).toThrow(
				'STTServiceAPI.createStream() must be implemented by subclass'
			);
		} );
	} );

	describe( 'default implementations that do NOT throw', () => {
		it( 'destroy() resolves without error (default no-op)', async () => {
			await expect( api.destroy() ).resolves.toBeUndefined();
		} );

		it( 'getModelSize() returns 0 for any model', () => {
			expect( api.getModelSize( 'tiny.en' ) ).toBe( 0 );
			expect( api.getModelSize( 'base' ) ).toBe( 0 );
			expect( api.getModelSize() ).toBe( 0 );
		} );

		it( 'getSupportedFormats() returns the default float32 format', () => {
			const formats = api.getSupportedFormats();

			expect( formats ).toHaveLength( 1 );
			expect( formats[ 0 ] ).toEqual( {
				mimeType: 'audio/x-float32',
				sampleRate: 16000,
			} );
		} );
	} );

	describe( 'callback slots', () => {
		it( 'onProgress defaults to null', () => {
			expect( api.onProgress ).toBeNull();
		} );

		it( 'onError defaults to null', () => {
			expect( api.onError ).toBeNull();
		} );

		it( 'onProgress can be assigned a function', () => {
			const cb = () => {};
			api.onProgress = cb;
			expect( api.onProgress ).toBe( cb );
		} );

		it( 'onError can be assigned a function', () => {
			const cb = () => {};
			api.onError = cb;
			expect( api.onError ).toBe( cb );
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – STTServiceAPI subclassing
// ---------------------------------------------------------------------------

describe( 'STTServiceAPI – subclassing', () => {
	it( 'can be extended by a concrete subclass', () => {
		const backend = new MockSTTBackend();

		expect( backend ).toBeInstanceOf( STTServiceAPI );
		expect( backend.getSlug() ).toBe( 'mock_stt' );
		expect( backend.getName() ).toBe( 'Mock STT Backend' );
	} );

	it( 'subclass overrides abstract methods without throwing', async () => {
		const backend = new MockSTTBackend();

		// None of these should throw.
		expect( () => backend.getSlug() ).not.toThrow();
		expect( () => backend.getName() ).not.toThrow();
		await expect( backend.isAvailable() ).resolves.toBe( true );
		await expect( backend.initialize( { model: 'base' } ) ).resolves.toBeUndefined();
		await expect(
			backend.transcribe( new Float32Array( [ 0.5 ] ), { sampleRate: 16000 } )
		).resolves.toEqual( {
			text: 'mock transcription',
			partial: false,
			confidence: 0.95,
			language: 'en',
		} );
		expect( () => backend.createStream( {}, {} ) ).not.toThrow();
	} );

	it( 'subclass can override destroy()', async () => {
		const backend = new MockSTTBackend();

		expect( backend._destroyed ).toBe( false );
		await backend.destroy();
		expect( backend._destroyed ).toBe( true );
	} );

	it( 'subclass receives initialize options correctly', async () => {
		const backend = new MockSTTBackend();
		const options = { model: 'tiny.en', language: 'fr' };

		await backend.initialize( options );

		expect( backend._initializedWith ).toBe( options );
		expect( backend._initCalled ).toBe( true );
	} );

	it( 'subclass transcribe respects partial flag', async () => {
		const backend = new MockSTTBackend();

		const result = await backend.transcribe(
			new Float32Array( [ 0.5 ] ),
			{ sampleRate: 16000, partial: true }
		);

		expect( result.partial ).toBe( true );
	} );

	it( 'subclass transcribe respects language option', async () => {
		const backend = new MockSTTBackend();

		const result = await backend.transcribe(
			new Float32Array( [ 0.5 ] ),
			{ sampleRate: 16000, language: 'de' }
		);

		expect( result.language ).toBe( 'de' );
	} );

	it( 'subclass createStream returns a controller with push/flush/close', () => {
		const backend = new MockSTTBackend();
		const stream = backend.createStream( {}, {} );

		expect( stream ).toBeDefined();
		expect( typeof stream.push ).toBe( 'function' );
		expect( typeof stream.flush ).toBe( 'function' );
		expect( typeof stream.close ).toBe( 'function' );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – STTBackendRegistry
// ---------------------------------------------------------------------------

describe( 'STTBackendRegistry', () => {
	let registry;

	beforeEach( () => {
		registry = createBackendRegistry();
	} );

	describe( 'register() and create()', () => {
		it( 'registers a backend class and creates an instance by slug', () => {
			registry.register( MockSTTBackend );

			const instance = registry.create( 'mock_stt' );

			expect( instance ).toBeInstanceOf( STTServiceAPI );
			expect( instance.getSlug() ).toBe( 'mock_stt' );
		} );

		it( 'create() returns null for an unknown slug', () => {
			expect( registry.create( 'nonexistent' ) ).toBeNull();
			expect( registry.create( '' ) ).toBeNull();
		} );

		it( 'create() returns null when no backends are registered', () => {
			expect( registry.create( 'anything' ) ).toBeNull();
		} );

		it( 'create() returns a new instance each time', () => {
			registry.register( MockSTTBackend );

			const instance1 = registry.create( 'mock_stt' );
			const instance2 = registry.create( 'mock_stt' );

			expect( instance1 ).not.toBe( instance2 );
			expect( instance1 ).toBeInstanceOf( STTServiceAPI );
			expect( instance2 ).toBeInstanceOf( STTServiceAPI );
		} );

		it( 'register() uses the slug from the backend instance, not the class name', () => {
			class CustomSlugBackend extends STTServiceAPI {
				getSlug() { return 'custom_slug'; }
				getName() { return 'Custom'; }
			}

			registry.register( CustomSlugBackend );

			// Should be accessible by the slug returned by the instance.
			const instance = registry.create( 'custom_slug' );
			expect( instance ).toBeInstanceOf( STTServiceAPI );
		} );
	} );

	describe( 'getSlugs()', () => {
		it( 'returns an empty array when no backends are registered', () => {
			expect( registry.getSlugs() ).toEqual( [] );
		} );

		it( 'returns all registered slugs', () => {
			class BackendA extends STTServiceAPI {
				getSlug() { return 'backend_a'; }
				getName() { return 'A'; }
			}
			class BackendB extends STTServiceAPI {
				getSlug() { return 'backend_b'; }
				getName() { return 'B'; }
			}

			registry.register( BackendA );
			registry.register( BackendB );

			const slugs = registry.getSlugs();

			expect( slugs ).toHaveLength( 2 );
			expect( slugs ).toContain( 'backend_a' );
			expect( slugs ).toContain( 'backend_b' );
		} );

		it( 'does not return the _backends property itself as a slug', () => {
			registry.register( MockSTTBackend );

			const slugs = registry.getSlugs();

			expect( slugs ).not.toContain( '_backends' );
		} );
	} );

	describe( 'detectBest()', () => {
		it( 'returns null when no backends are registered', async () => {
			const best = await registry.detectBest();

			expect( best ).toBeNull();
		} );

		it( 'returns the slug of the first available backend', async () => {
			class AvailableBackend extends STTServiceAPI {
				getSlug() { return 'available'; }
				getName() { return 'Available'; }
				async isAvailable() { return true; }
			}

			registry.register( AvailableBackend );

			const best = await registry.detectBest();

			expect( best ).toBe( 'available' );
		} );

		it( 'skips unavailable backends and returns the first available one', async () => {
			let isAvailableCallCount = 0;

			class UnavailableBackend extends STTServiceAPI {
				getSlug() { return 'unavailable'; }
				getName() { return 'Unavailable'; }
				async isAvailable() {
					isAvailableCallCount++;
					return false;
				}
			}
			class AvailableBackend extends STTServiceAPI {
				getSlug() { return 'available'; }
				getName() { return 'Available'; }
				async isAvailable() {
					isAvailableCallCount++;
					return true;
				}
			}

			registry.register( UnavailableBackend );
			registry.register( AvailableBackend );

			const best = await registry.detectBest();

			expect( best ).toBe( 'available' );
			expect( isAvailableCallCount ).toBe( 2 );
		} );

		it( 'skips backends whose isAvailable() throws and continues', async () => {
			class BrokenBackend extends STTServiceAPI {
				getSlug() { return 'broken'; }
				getName() { return 'Broken'; }
				async isAvailable() { throw new Error( 'Boom' ); }
			}
			class WorkingBackend extends STTServiceAPI {
				getSlug() { return 'working'; }
				getName() { return 'Working'; }
				async isAvailable() { return true; }
			}

			registry.register( BrokenBackend );
			registry.register( WorkingBackend );

			const best = await registry.detectBest();

			expect( best ).toBe( 'working' );
		} );

		it( 'returns null when all backends are unavailable', async () => {
			class NA1 extends STTServiceAPI {
				getSlug() { return 'na1'; }
				getName() { return 'NA1'; }
				async isAvailable() { return false; }
			}
			class NA2 extends STTServiceAPI {
				getSlug() { return 'na2'; }
				getName() { return 'NA2'; }
				async isAvailable() { return false; }
			}

			registry.register( NA1 );
			registry.register( NA2 );

			const best = await registry.detectBest();

			expect( best ).toBeNull();
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Tests – full lifecycle integration (MockBackend through Registry)
// ---------------------------------------------------------------------------

describe( 'STTServiceAPI + STTBackendRegistry – full lifecycle integration', () => {
	let registry;
	let backend;

	beforeEach( () => {
		registry = createBackendRegistry();
		registry.register( MockSTTBackend );
	} );

	it( 'complete lifecycle: register → create → isAvailable → initialize → transcribe → destroy', async () => {
		// 1. Create from registry.
		backend = registry.create( 'mock_stt' );
		expect( backend ).not.toBeNull();

		// 2. Check availability.
		const available = await backend.isAvailable();
		expect( available ).toBe( true );

		// 3. Initialize with model.
		await backend.initialize( { model: 'base', language: 'en' } );
		expect( backend._initCalled ).toBe( true );
		expect( backend._initializedWith.model ).toBe( 'base' );

		// 4. Transcribe audio.
		const samples = new Float32Array( [ 0.1, -0.2, 0.3 ] );
		const result = await backend.transcribe( samples, {
			sampleRate: 16000,
			language: 'en',
		} );
		expect( result ).toEqual( {
			text: 'mock transcription',
			partial: false,
			confidence: 0.95,
			language: 'en',
		} );

		// 5. Stream transcription.
		const stream = backend.createStream(
			{
				onPartialResult: jest.fn(),
				onFinalResult: jest.fn(),
				onError: jest.fn(),
			},
			{ sampleRate: 16000 }
		);
		expect( stream.push ).toBeDefined();
		expect( stream.flush ).toBeDefined();

		// 6. Destroy.
		expect( backend._destroyed ).toBe( false );
		await backend.destroy();
		expect( backend._destroyed ).toBe( true );
	} );

	it( 'detectBest finds and returns the mock backend', async () => {
		const best = await registry.detectBest();

		expect( best ).toBe( 'mock_stt' );
	} );

	it( 'multiple backends can coexist in the registry', () => {
		class SecondBackend extends STTServiceAPI {
			getSlug() { return 'second'; }
			getName() { return 'Second'; }
		}

		registry.register( SecondBackend );

		const slugs = registry.getSlugs();
		expect( slugs ).toHaveLength( 2 );

		const mockInstance = registry.create( 'mock_stt' );
		const secondInstance = registry.create( 'second' );

		expect( mockInstance.getSlug() ).toBe( 'mock_stt' );
		expect( secondInstance.getSlug() ).toBe( 'second' );
	} );

	it( 'backend instances are independent (no shared state)', () => {
		const instance1 = registry.create( 'mock_stt' );
		const instance2 = registry.create( 'mock_stt' );

		instance1._available = false;
		instance2._available = true;

		expect( instance1._available ).toBe( false );
		expect( instance2._available ).toBe( true );
	} );
} );

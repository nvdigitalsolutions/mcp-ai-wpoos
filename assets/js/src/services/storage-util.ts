/**
 * Storage Utility for NV oOS Chat — TypeScript edition.
 *
 * Handles localStorage operations with optional Web Worker offloading
 * for heavy JSON parsing/stringifying.  Self-contained module.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 * @since   1.1.62 Wired to the chat storage service with a per-call threshold override (proposal 032).
 */

interface PendingOperation {
	resolve: ( value: unknown ) => void;
	reject: ( reason: Error ) => void;
}

interface StorageUtilType {
	worker: Worker | null;
	workerSupported: boolean;
	pendingOperations: Record< number, PendingOperation >;
	operationId: number;
	WORKER_THRESHOLD: number;
	initWorker(): void;
	handleWorkerMessage( e: MessageEvent< { id: number; success: boolean; result?: unknown; error?: string } > ): void;
	handleWorkerError( error: ErrorEvent ): void;
	postToWorker( action: string, data: unknown ): Promise< unknown >;
	parseJSON( jsonString: string, threshold?: number ): Promise< unknown >;
	stringifyJSON( obj: unknown, threshold?: number ): Promise< string >;
	cleanup(): void;
}

const StorageUtil: StorageUtilType = {
	worker: null,
	workerSupported: typeof Worker !== 'undefined',
	pendingOperations: {},
	operationId: 0,
	WORKER_THRESHOLD: 10000, // 10 KB

	initWorker() {
		if ( ! this.workerSupported || this.worker ) { return; }

		try {
			const config = window.wpMcpAiChat as Record< string, unknown > | undefined;
			const workerUrl = config?.storageWorkerUrl as string | undefined;
			if ( ! workerUrl ) {
				console.warn( 'NV oOS: Storage worker URL not configured' );
				return;
			}

			this.worker = new Worker( workerUrl );
			this.worker.addEventListener( 'message', this.handleWorkerMessage.bind( this ) );
			this.worker.addEventListener( 'error', this.handleWorkerError.bind( this ) );
		} catch ( error ) {
			console.error( 'NV oOS: Failed to initialize storage worker:', error );
			this.workerSupported = false;
		}
	},

	handleWorkerMessage( e ) {
		const { id, success, result, error } = e.data;
		const pending = this.pendingOperations[ id ];
		if ( ! pending ) { return; }
		delete this.pendingOperations[ id ];

		if ( success ) {
			pending.resolve( result );
		} else {
			pending.reject( new Error( error || 'Worker operation failed' ) );
		}
	},

	handleWorkerError( error ) {
		console.error( 'NV oOS: Storage worker error:', error );
		const ids = Object.keys( this.pendingOperations ).map( Number );
		for ( const id of ids ) {
			const pending = this.pendingOperations[ id ];
			delete this.pendingOperations[ id ];
			pending.reject( new Error( 'Worker error: ' + error.message ) );
		}
	},

	postToWorker( action, data ) {
		this.initWorker();
		if ( ! this.worker ) {
			return Promise.reject( new Error( 'Worker not available' ) );
		}

		const id = ++this.operationId;
		return new Promise< unknown >( ( resolve, reject ) => {
			this.pendingOperations[ id ] = { resolve, reject };
			this.worker!.postMessage( { action, data, id } );
		} );
	},

	parseJSON( jsonString, threshold ) {
		if ( ! jsonString ) { return Promise.resolve( null ); }

		const size = jsonString.length;
		const effectiveThreshold = typeof threshold === 'number' ? threshold : this.WORKER_THRESHOLD;
		if ( effectiveThreshold <= 0 || size < effectiveThreshold || ! this.workerSupported ) {
			try {
				return Promise.resolve( JSON.parse( jsonString ) );
			} catch ( error ) {
				return Promise.reject( error );
			}
		}

		return this.postToWorker( 'parse', jsonString ).catch( ( error ) => {
			console.warn( 'NV oOS: Worker parse failed, using fallback:', error );
			return JSON.parse( jsonString );
		} );
	},

	stringifyJSON( obj, threshold ) {
		if ( obj === null || obj === undefined ) { return Promise.resolve( '' ); }

		const effectiveThreshold = typeof threshold === 'number' ? threshold : this.WORKER_THRESHOLD;

		// Non-positive thresholds (or unsupported workers) stay synchronous.
		if ( effectiveThreshold <= 0 || ! this.workerSupported ) {
			try {
				return Promise.resolve( JSON.stringify( obj ) );
			} catch ( error ) {
				return Promise.reject( error );
			}
		}

		// An explicit threshold means the caller already measured the payload —
		// skip the expensive estimate and go straight to the worker (avoids a
		// second main-thread stringify).
		if ( typeof threshold === 'number' ) {
			return this.postToWorker( 'stringify', obj ).catch( ( error ) => {
				console.warn( 'NV oOS: Worker stringify failed, using fallback:', error );
				return JSON.stringify( obj );
			} ) as Promise< string >;
		}

		// Estimate size (rough approximation) for direct callers.
		const estimatedSize = JSON.stringify( obj ).length;
		if ( estimatedSize < effectiveThreshold ) {
			try {
				return Promise.resolve( JSON.stringify( obj ) );
			} catch ( error ) {
				return Promise.reject( error );
			}
		}

		return this.postToWorker( 'stringify', obj ).catch( ( error ) => {
			console.warn( 'NV oOS: Worker stringify failed, using fallback:', error );
			return JSON.stringify( obj );
		} ) as Promise< string >;
	},

	cleanup() {
		if ( this.worker ) {
			this.worker.terminate();
			this.worker = null;
		}
		this.pendingOperations = {};
	},
};

// ── Backward-compatible global ───────────────────────────────────────

( window as unknown as Record< string, unknown > ).wpMcpAiStorageUtil = StorageUtil;

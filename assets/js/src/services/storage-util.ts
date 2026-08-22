/**
 * Storage Utility for NV oOS Chat — TypeScript edition.
 *
 * Handles localStorage operations with optional Web Worker offloading
 * for heavy JSON parsing/stringifying.  Self-contained module.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
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
	parseJSON( jsonString: string ): Promise< unknown >;
	stringifyJSON( obj: unknown ): Promise< string >;
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

	parseJSON( jsonString ) {
		if ( ! jsonString ) { return Promise.resolve( null ); }

		const size = jsonString.length;
		if ( size < this.WORKER_THRESHOLD || ! this.workerSupported ) {
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

	stringifyJSON( obj ) {
		if ( obj === null || obj === undefined ) { return Promise.resolve( '' ); }

		const estimatedSize = JSON.stringify( obj ).length;
		if ( estimatedSize < this.WORKER_THRESHOLD || ! this.workerSupported ) {
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

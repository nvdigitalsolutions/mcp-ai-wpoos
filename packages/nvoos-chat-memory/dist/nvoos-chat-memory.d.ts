/**
 * Promise-based client for the NV oOS chat-memory REST bridge.
 * @package @nvdigitalsolutions/nvoos-chat-memory
 */

export interface ChatMemoryEndpoints {
	wakeUp: string;
	recall: string;
	store: string;
	/** Base URL for /{contextId} item operations. MUST end with a slash. */
	itemBase: string;
	preferences: string;
	audit?: string;
}

export interface ChatMemoryConfig {
	endpoints: ChatMemoryEndpoints;
	headers?: Record<string, string>;
	fetch?: typeof fetch;
	credentials?: RequestCredentials;
}

export interface WakeUpParams {
	agentId?: string;
	wing?: string;
	room?: string;
}

export interface RecallFilters {
	agentId?: string;
	wing?: string;
	room?: string;
	limit?: number;
}

export interface StorePayload {
	agentId?: string;
	wing?: string;
	room?: string;
	title?: string;
	content?: string;
	tags?: string[];
	importance?: number | string;
	contextType?: string;
	verbatim?: boolean;
	summarize?: boolean;
}

export interface UpdatePatch {
	agentId?: string;
	title?: string;
	content?: string;
	tags?: string[];
	importance?: number | string;
}

export interface AuditOptions {
	agentId?: string;
	limit?: number;
	actionType?: string;
}

export interface MemoryPreferences {
	enabled?: boolean;
	autosummarize?: boolean;
}

export declare function configure(options: ChatMemoryConfig): void;
export declare function isAvailable(): boolean;
export declare function wakeUp(params?: WakeUpParams): Promise<any>;
export declare function recall(query: string, filters?: RecallFilters): Promise<any>;
export declare function store(payload: StorePayload): Promise<any>;
export declare function storeBeacon(payload: StorePayload): Promise<any>;
export declare function update(contextId: string, patch: UpdatePatch): Promise<any>;
export declare function remove(contextId: string, options?: { agentId?: string }): Promise<any>;
export { remove as delete_ };
export declare function audit(options?: AuditOptions): Promise<any>;
export declare function getPreferences(): Promise<MemoryPreferences>;
export declare function setPreferences(prefs: MemoryPreferences): Promise<any>;
export declare function isMemoryRetrievalResult(result: unknown): boolean;

declare const _default: {
	configure: typeof configure;
	isAvailable: typeof isAvailable;
	wakeUp: typeof wakeUp;
	recall: typeof recall;
	store: typeof store;
	storeBeacon: typeof storeBeacon;
	update: typeof update;
	remove: typeof remove;
	'delete': typeof remove;
	audit: typeof audit;
	getPreferences: typeof getPreferences;
	setPreferences: typeof setPreferences;
	isMemoryRetrievalResult: typeof isMemoryRetrievalResult;
};

export default _default;

/**
 * SSE-first cron/job status monitor with REST polling fallback.
 * @package @nvdigitalsolutions/nvoos-cron-status
 */

export interface SSEAdapter {
	isSupported(): boolean;
	connect(url: string, options: {
		eventHandlers?: Record<string, (data: any) => void>;
		onError?: (err?: any) => void;
		onOpen?: () => void;
	}): { close: () => void } | null;
}

export interface JobBusAdapter {
	handleJobUpdate(jobId: string | number, payload: any): void;
}

export interface CronStatusConfig {
	sseAdapter?: SSEAdapter | null;
	jobBus?: JobBusAdapter | null;
	jobClickableClass?: string;
}

export type StatusCallback = (data: any) => void;

export interface CronStatusServiceShape {
	fallbackPollingInterval: number;
	maxPollingInterval: number;
	backoffMultiplier: number;
	maxPollingAttempts: number;

	fetchStatusREST(
		endpoint: string,
		nonce: string | null,
		limit?: number,
		assistantId?: string | number,
		guestToken?: string
	): Promise<any | null>;

	startMonitoring(
		containerId: string,
		endpoint: string,
		nonce: string | null,
		callback: StatusCallback,
		assistantId?: string | number,
		guestToken?: string
	): void;

	stopMonitoring(containerId: string): void;
	stopSSE(containerId: string): void;

	emitJobUpdates(data: { jobs?: Array<{ job_id?: string | number }> }): void;

	/** @deprecated use startMonitoring */
	startPolling(...args: any[]): void;
	/** @deprecated use stopMonitoring */
	stopPolling(containerId: string): void;
}

export declare function configure(options: CronStatusConfig): void;
export declare const CronStatusService: CronStatusServiceShape;
export default CronStatusService;

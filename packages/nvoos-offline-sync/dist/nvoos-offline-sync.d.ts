/**
 * IndexedDB-backed offline-first sync manager.
 * Saves data locally immediately and syncs to a server endpoint when online.
 * Zero external dependencies — uses only IndexedDB, fetch, and standard browser APIs.
 * @package @nvdigitalsolutions/nvoos-offline-sync
 */

export interface OfflineSyncOptions {
  /**
   * Server endpoint URL that receives POST requests with the message body.
   * Required for server sync. If omitted, messages are stored locally only.
   */
  syncUrl?: string;

  /**
   * Additional HTTP headers sent with every sync request.
   * Use this to pass authorization tokens, CSRF tokens, etc.
   * @example { 'Authorization': 'Bearer token', 'X-CSRF-Token': 'abc' }
   */
  syncHeaders?: Record<string, string>;

  /**
   * IndexedDB database name. Default: 'nvoos-offline'
   */
  dbName?: string;

  /**
   * IndexedDB schema version. Increment when changing the schema. Default: 1
   */
  dbVersion?: number;

  /**
   * Show the built-in offline banner when the device goes offline.
   * Set to false to implement your own offline UI. Default: true
   */
  showOfflineUI?: boolean;
}

export interface OfflineMessage {
  id?: number;
  timestamp?: number;
  synced?: boolean;
  [key: string]: unknown;
}

export declare class OfflineChatManager {
  options: OfflineSyncOptions;
  db: IDBDatabase | null;
  syncQueue: OfflineMessage[];
  isOnline: boolean;
  dbName: string;
  dbVersion: number;
  showOfflineUI: boolean;

  constructor(options?: OfflineSyncOptions);

  /** Open (or upgrade) the IndexedDB database. Called by initialize(). */
  openDatabase(): Promise<IDBDatabase>;

  /** Initialize the manager. Must be called before saving messages. */
  initialize(): Promise<void>;

  /**
   * Save a message locally and sync to server when online.
   * If offline, the message is queued and sent when connectivity is restored.
   */
  saveMessage(message: Record<string, unknown>): Promise<void>;

  /** Persist a message to the local IndexedDB store. */
  saveToLocal(message: Record<string, unknown>): Promise<number>;

  /** Send a single message to the configured syncUrl. */
  syncToServer(message: OfflineMessage): Promise<void>;

  /** Mark a locally-stored message as successfully synced. */
  markAsSynced(messageId: number): Promise<void>;

  /** Handle the browser coming back online — drains the sync queue. */
  handleOnline(): Promise<void>;

  /** Handle the browser going offline. */
  handleOffline(): void;

  /** Display the built-in offline notification banner. */
  showOfflineNotice(): void;

  /** Retrieve all locally stored messages. */
  getAllMessages(): Promise<OfflineMessage[]>;

  /** Erase all locally stored messages and conversations. */
  clearAllData(): Promise<void>;
}

export default OfflineChatManager;

/**
 * Typed reader for the `window.NVOOS_CHAT_SPA` global localized by the
 * shortcode/block PHP renderer.
 *
 * Keep this file the single source of truth for the SPA's runtime config
 * shape so every component imports the same `ChatSpaConfig` type.
 */

export interface ChatSpaPerInstanceConfig {
	assistantId?: number;
	theme?: 'auto' | 'light' | 'dark' | string;
	height?: string;
	guest?: boolean;
}

export interface ChatSpaEndpoints {
	chat: string;
	chatClient: string;
	transcripts: string;
	memory: string;
	/** Only present (non-empty) for users with manage_options. */
	approvals: string;
}

export interface ChatSpaRuntime {
	apiUrl: string;
	proApi: string;
	nonce: string;
	config: ChatSpaPerInstanceConfig;
	endpoints: ChatSpaEndpoints;
}

// Re-export the per-instance shape under the App-friendly name.
export type ChatSpaConfig = ChatSpaPerInstanceConfig;

/**
 * Read the localized runtime config emitted by `wp_localize_script()`.
 *
 * Returns `null` when the global is missing or malformed so the App can render
 * a graceful fallback instead of throwing.
 */
export function readChatSpaConfig(): ChatSpaRuntime | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const g = ( window as unknown as { NVOOS_CHAT_SPA?: Partial< ChatSpaRuntime > } )
		.NVOOS_CHAT_SPA;
	if ( ! g || typeof g.apiUrl !== 'string' || ! g.endpoints ) {
		return null;
	}
	const e = g.endpoints as Partial< ChatSpaEndpoints >;
	if (
		typeof e.chatClient !== 'string' ||
		typeof e.chat !== 'string' ||
		typeof e.transcripts !== 'string' ||
		typeof e.memory !== 'string'
	) {
		return null;
	}
	return {
		apiUrl: g.apiUrl,
		proApi: typeof g.proApi === 'string' ? g.proApi : '',
		nonce: typeof g.nonce === 'string' ? g.nonce : '',
		config: ( g.config ?? {} ) as ChatSpaPerInstanceConfig,
		endpoints: {
			chat: e.chat,
			chatClient: e.chatClient,
			transcripts: e.transcripts,
			memory: e.memory,
			approvals: typeof e.approvals === 'string' ? e.approvals : '',
		},
	};
}

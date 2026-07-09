/**
 * Pro SPA v2 — typed runtime config reader.
 *
 * Reads `window.NVOOS_PRO_SPA` localized by the PHP admin page enqueue.
 * Keep this file the single source of truth for the SPA's runtime config shape.
 */

export interface ProSpaPerInstanceConfig {
	assistantId?: number;
	theme?: 'auto' | 'light' | 'dark' | string;
}

export interface ProSpaEndpoints {
	chat: string;
	chatClient: string;
	transcripts: string;
	memory: string;
	threads: string;
	tools: string;
	assistants: string;
	settings: string;
	workflows: string;
	analytics: string;
	approvals: string;
	shortcuts: string;
	slashCommands: string;
}

export interface RuntimeAssistantSummary {
	id: number;
	title: string;
	provider?: string;
	model?: string;
}

export interface ProSpaRuntime {
	apiUrl: string;
	proApi: string;
	nonce: string;
	config: ProSpaPerInstanceConfig;
	endpoints: ProSpaEndpoints;
	user: ProSpaUser;
	mentionTypes: MentionType[];
	/** Pre-loaded assistants from the server — avoids a separate REST round-trip. */
	assistants?: RuntimeAssistantSummary[];
}

export interface ProSpaUser {
	id: number;
	login: string;
	displayName: string;
	capabilities: string[];
	assistant_id?: number;
}

export interface MentionType {
	type: string;
	label: string;
	icon?: string;
}

export type ProSpaConfig = ProSpaPerInstanceConfig;

/**
 * Read the localized runtime config emitted by `wp_localize_script()`.
 *
 * Returns `null` when the global is missing or malformed so the App can render
 * a graceful fallback instead of throwing.
 */
export function readProSpaConfig(): ProSpaRuntime | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const g = ( window as unknown as { NVOOS_PRO_SPA?: Partial< ProSpaRuntime > } )
		.NVOOS_PRO_SPA;
	if ( ! g || typeof g.apiUrl !== 'string' || ! g.endpoints ) {
		return null;
	}
	const e = g.endpoints as Partial< ProSpaEndpoints >;
	if (
		typeof e.chatClient !== 'string' ||
		typeof e.chat !== 'string' ||
		typeof e.transcripts !== 'string' ||
		typeof e.threads !== 'string' ||
		typeof e.tools !== 'string' ||
		typeof e.assistants !== 'string' ||
		typeof e.settings !== 'string'
	) {
		return null;
	}
	return {
		apiUrl: g.apiUrl,
		proApi: typeof g.proApi === 'string' ? g.proApi : '',
		nonce: typeof g.nonce === 'string' ? g.nonce : '',
		config: ( g.config ?? {} ) as ProSpaPerInstanceConfig,
		endpoints: {
			chat: e.chat,
			chatClient: e.chatClient,
			transcripts: e.transcripts,
			memory: typeof e.memory === 'string' ? e.memory : '',
			threads: e.threads,
			tools: e.tools,
			assistants: e.assistants,
			settings: e.settings,
			workflows: typeof e.workflows === 'string' ? e.workflows : '',
			analytics: typeof e.analytics === 'string' ? e.analytics : '',
			approvals: typeof e.approvals === 'string' ? e.approvals : '',
			shortcuts: typeof e.shortcuts === 'string' ? e.shortcuts : '',
			slashCommands: typeof e.slashCommands === 'string' ? e.slashCommands : '',
		},
		user: ( g.user ?? { id: 0, login: '', displayName: '', capabilities: [] } ) as ProSpaUser,
		mentionTypes: Array.isArray( g.mentionTypes ) ? g.mentionTypes : [],
		assistants: Array.isArray( g.assistants ) ? g.assistants : undefined,
	};
}
